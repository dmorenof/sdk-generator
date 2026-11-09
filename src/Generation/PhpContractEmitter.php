<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/**
 * Deterministic source emitter for the explicitly supported canonical IR subset.
 *
 * Output is a path => UTF-8 source map, independent of input API dialect
 * and of the filesystem. Endpoint interfaces describe typed signatures;
 * executable HTTP transport and JSON hydration are a separate milestone.
 */
final class PhpContractEmitter
{
    /** Namespace of the shared runtime that generated SDKs build on. */
    public const RUNTIME_NAMESPACE = 'Dmorenof\\SdkRuntime';
    public const RUNTIME_CONSTRAINT = '^0.17.1';
    /**
     * Where Composer finds the runtime until it is registered on Packagist.
     * Set to null once Packagist serves it; generated SDKs then need no repositories entry.
     */
    public const RUNTIME_REPOSITORY = 'https://github.com/dmorenof/sdk-runtime';

    private ?PhpModelNameResolver $modelNames = null;
    /** @var array<string, ModelDefinition> */
    private array $modelDefinitions = [];
    /** @var array<string, string> Child schema name => parent schema name. */
    private array $modelParents = [];
    /** @var array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool,byStatus?:array<int,array{type:?string,schemaNullable:bool}>}> */
    private array $operationSuccessTypes = [];
    /** @var array<string, array<string, string>> */
    private array $operationErrorTypes = [];
    /** @var array<string,string> Operation ID => fully-qualified request body DTO. */
    private array $operationBodyTypes = [];
    /** @var array<string,array<string,string>> Operation ID / wire name => query object type. */
    private array $operationQueryTypes = [];
    /** @var array<string, string> Collection item type and rules => generated FQCN. */
    private array $collectionTypes = [];
    /** @var array<int,true> Body schema identities requiring native multipart file lists. */
    private array $multipartSchemas = [];
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const PHP_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die',
        'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include',
        'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
        'list', 'match', 'namespace', 'new', 'or', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset',
        'use', 'var', 'while', 'xor', 'yield', 'true', 'false', 'null',
        'int', 'float', 'string', 'bool', 'void', 'object', 'mixed',
        'never', 'self', 'parent',
    ];

    public function __construct(
        private readonly string $namespace = 'Generated\\Sdk',
        private readonly string $packageName = 'example/generated-sdk',
    ) {
        foreach (explode('\\', $this->namespace) as $component) {
            $this->checkName($component, 'namespace segment');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/D', $packageName) !== 1) {
            throw new InvalidArgumentException("Invalid Composer package name: $packageName");
        }
    }

    /** @return array<string, string> Stable generated relative paths => UTF-8 contents. */
    public function emit(ApiContract $contract): array
    {
        $files = [];
        $roles = self::modelRolesFor($contract);
        $this->modelNames = new PhpModelNameResolver($this->namespace, $contract->models, $roles);
        $this->modelDefinitions = [];
        $this->operationSuccessTypes = [];
        $this->operationErrorTypes = [];
        $this->operationBodyTypes = [];
        $this->operationQueryTypes = [];
        $this->collectionTypes = [];
        foreach ($contract->models as $model) {
            $this->modelDefinitions[$model->name] = $model;
        }
        $this->multipartSchemas = [];
        foreach ($contract->operations as $operation) {
            if ($operation->requestBody?->mediaType !== \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART) {
                continue;
            }
            $bodySchema = $operation->requestBody->schema;
            if ($bodySchema->kind === SchemaKind::Reference) {
                $bodySchema = $this->modelDefinitions[$bodySchema->reference]->schema ?? $bodySchema;
            }
            $this->multipartSchemas[spl_object_id($bodySchema)] = true;
        }
        $this->modelParents = [];
        foreach ($contract->models as $model) {
            $parent = $this->inheritanceParent($model->schema, $model->name);
            if ($parent !== null) {
                $this->modelParents[$model->name] = $parent;
            }
        }
        $names = [];
        foreach ($contract->models as $model) {
            $key = strtolower($model->name);
            if (isset($names[$key])) {
                throw new InvalidArgumentException("Duplicate PHP model name: $model->name");
            }
            $names[$key] = true;
            if ($model->schema->kind === SchemaKind::AnyOf || $model->schema->kind === SchemaKind::OneOf) {
                $path = $this->modelNames->path($model->name);
                $files[$path] = $this->renderCompositionClass(
                    $this->modelNames->fqcn($model->name),
                    $model->schema,
                    $files,
                );
                continue;
            }
            if ($model->schema->enumValues !== []) {
                $fqcn = $this->modelNames->fqcn($model->name);
                $class = substr($fqcn, strrpos($fqcn, '\\') + 1);
                $namespace = substr($fqcn, 1, strrpos($fqcn, '\\') - 1);
                $path = $this->modelNames->path($model->name);
                $files[$path] = $this->renderEnum($class, $model->schema, $namespace);
                continue;
            }
            if ($model->schema->kind === SchemaKind::AllOf) {
                $model = new ModelDefinition($model->name, $this->expandAllOf($model->schema, $model->name));
            }
            if ($model->schema->kind !== SchemaKind::Object) {
                throw new InvalidArgumentException("Unsupported non-object model: $model->name");
            }
            $path = $this->modelNames->path($model->name);
            if (isset($files[$path])) {
                throw new InvalidArgumentException("Generated class path collides with another generated class: $path");
            }
            if ($model->schema->properties === [] && $this->hasOpenAdditionalProperties($model->schema)) {
                $files[$path] = $this->renderMapClass(
                    $this->modelNames->fqcn($model->name),
                    $model->schema->additionalProperties,
                    $files,
                    $model->name,
                );
            } else {
                $files[$path] = $this->renderModel($model, $files, $roles[$model->name] ?? 'DTO');
            }
        }

        $files['src/Api/Operations.php'] = $this->renderOperations($contract, $files);
        $files = array_replace($files,
            (new PhpOperationRequestEmitter($this->namespace, $this->modelNames, $this->operationBodyTypes, $this->operationQueryTypes))->emit($contract));
        $files['src/Api/OperationCatalog.php'] = $this->renderCatalog($contract);
        $manifest = [
            'name' => $this->packageName,
            'description' => 'Contract-generated PHP SDK built on the shared dmorenof/sdk-runtime.',
            'type' => 'library',
            'require' => ['dmorenof/sdk-runtime' => self::RUNTIME_CONSTRAINT, 'php' => '^8.3'],
            'autoload' => ['psr-4' => [$this->namespace . '\\' => 'src/']],
        ];
        if (self::RUNTIME_REPOSITORY !== null) {
            // no-api: plain git over HTTPS; the GitHub API rate limit would otherwise make Composer fall back to SSH.
            $manifest['repositories'] = [['type' => 'vcs', 'url' => self::RUNTIME_REPOSITORY, 'no-api' => true]];
        }
        $files['composer.json'] = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";

        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool,byStatus?:array<int,array{type:?string,schemaNullable:bool}>}> */
    public function operationSuccessTypes(): array
    {
        return $this->operationSuccessTypes;
    }

    /** @return array<string, array<string, string>> */
    public function operationErrorTypes(): array
    {
        return $this->operationErrorTypes;
    }
    /** @return array<string,string> */
    public function operationBodyTypes(): array
    {
        return $this->operationBodyTypes;
    }

    /** @return array<string,array<string,string>> */
    public function operationQueryTypes(): array
    {
        return $this->operationQueryTypes;
    }


    /**
     * Schema identities must remain reusable independent of input/output
     * position. Request and response modes belong to the runtime operation.
     *
     * @return array<string, 'DTO'>
     */
    public static function modelRolesFor(ApiContract $contract): array
    {
        $roles = [];
        foreach ($contract->models as $model) {
            $roles[$model->name] = 'DTO';
        }
        return $roles;
    }

    /** @param array<string,string> $files */
    private function renderModel(ModelDefinition $model, array &$files, string $baseClass): string
    {
        return $this->renderObjectModel(
            $this->modelNames->fqcn($model->name),
            $model->schema,
            $files,
            $baseClass,
            $model->name,
            $this->modelParents[$model->name] ?? null,
            in_array($model->name, $this->modelParents, true),
        );
    }

    /** @param array<string,string> $files */
    private function renderObjectModel(
        string $fqcn,
        SchemaDefinition $schema,
        array &$files,
        string $baseClass = 'DTO',
        string $hint = '',
        ?string $parent = null,
        bool $extensible = false,
    ): string {
        $inherited = [];
        if ($parent !== null) {
            foreach ($this->expandAllOf($this->modelDefinitions[$parent]->schema, $parent)->properties as $property) {
                $inherited[$property->wireName] = true;
            }
        }
        $fields = [];
        $validation = [];
        $readOnly = [];
        $writeOnly = [];
        $propertyMap = [];
        $wirePropertyMap = [];
        $seen = [];
        foreach ($schema->properties as $property) {
            $propertyName = $this->propertyName($property->wireName);
            $key = strtolower($propertyName);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate PHP property in $fqcn: $property->wireName");
            }
            $seen[$key] = true;
            if ($propertyName !== $property->wireName) {
                $propertyMap[$property->wireName] = $propertyName;
                $wirePropertyMap[$propertyName] = $property->wireName;
            }
            $nullableBranch = self::nullableCompositionBranch($property->schema);
            $propertySchema = $nullableBranch ?? $property->schema;
            $fileList = $propertySchema->kind === SchemaKind::Array
                && \Dmorenof\SdkGenerator\Parsing\FormSupport::fileItem($propertySchema) !== null
                && (isset($this->multipartSchemas[spl_object_id($schema)]) || $this->inheritsMultipartFileList($parent, $property->wireName));
            if (!isset($inherited[$property->wireName])) {
                $type = $fileList ? 'array' : $this->typeFor(
                    $propertySchema,
                    $hint . '.' . ucfirst($propertyName),
                    $files,
                    $baseClass,
                );
                $nullable = $property->schema->nullable || $nullableBranch !== null || $propertySchema->nullable;
                if ($type === 'string' && $propertySchema->format === 'binary') {
                    $type .= '|\\Psr\\Http\\Message\\UploadedFileInterface' . ($nullable ? '|null' : '');
                } elseif ($nullable) {
                    $type = '?' . $type;
                }
                // Deliberately uninitialized when absent, including optional fields.
                // Calling get_object_vars() preserves the absent-vs-null distinction.
                $fields[] = ($fileList ? '    /** @var list<string|\\Psr\\Http\\Message\\UploadedFileInterface>' . ($nullable ? '|null' : '') . " */\n" : '')
                    . '    public ' . $type . ' $' . $propertyName . ';';
            }
            $propertyValidation = $property->schema->validation;
            if ($fileList) {
                $propertyValidation['items'] = ['type' => 'binary', 'rules' => []];
            }
            if ($propertySchema->enumValues !== []) {
                $propertyValidation['enum'] = $propertySchema->enumValues;
            }
            if ($nullableBranch !== null) {
                $propertyValidation += $nullableBranch->validation;
                if ($nullableBranch->pattern !== null) {
                    $propertyValidation['pattern'] = $nullableBranch->pattern;
                }
            }
            if ($propertyValidation !== []) {
                $validation[$propertyName] = $propertyValidation;
            }
            if ($property->schema->pattern !== null) {
                $validation[$propertyName]['pattern'] = $property->schema->pattern;
            }
            if ($property->schema->readOnly) {
                $readOnly[] = $propertyName;
            }
            if ($property->schema->writeOnly) {
                $writeOnly[] = $propertyName;
            }
        }

        $required = [];
        foreach ($schema->properties as $property) {
            if ($property->required) {
                $required[] = $this->propertyName($property->wireName);
            }
        }
        $additionalPropertiesType = null;
        $additionalPropertiesNullable = false;
        $additionalPropertiesValidation = [];
        if ($schema->additionalProperties === true
            || ($schema->additionalProperties === null && $this->discriminatedModel($hint))) {
            $additionalPropertiesType = 'mixed';
        } elseif ($schema->additionalProperties instanceof SchemaDefinition) {
            $additionalSchema = $schema->additionalProperties;
            $additionalPropertiesType = $this->typeFor(
                $additionalSchema,
                $hint . 'AdditionalProperty',
                $files,
                $baseClass,
            );
            $additionalPropertiesNullable = $additionalSchema->nullable;
            $additionalPropertiesValidation = $additionalSchema->validation;
            if ($additionalSchema->pattern !== null) {
                $additionalPropertiesValidation['pattern'] = $additionalSchema->pattern;
            }
        }
        $class = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $namespace = substr($fqcn, 1, strrpos($fqcn, '\\') - 1);
        $extends = $parent !== null ? $this->modelNames->fqcn($parent) : '\\' . self::RUNTIME_NAMESPACE . '\\DTO';
        $source = $this->header($namespace)
            . ($extensible ? 'class ' : 'final class ') . $class . ' extends ' . $extends . "\n{\n"
            . "    public const array REQUIRED_FIELDS = " . PhpLiteral::export($required) . ";\n\n";
        if ($schema->discriminator !== null) {
            $mapping = $this->discriminatorMapping($schema, $hint);
            $source .= "    public const string DISCRIMINATOR_BASE = self::class;\n"
                . "    public const string DISCRIMINATOR_PROPERTY = " . PhpLiteral::export($schema->discriminator->propertyName) . ";\n"
                . "    public const array DISCRIMINATOR_MAPPING = " . PhpLiteral::export($mapping) . ";\n\n";
        }
        if ($propertyMap !== []) {
            $source .= "    protected const array PROPERTY_MAP = " . PhpLiteral::export($propertyMap) . ";\n"
                . "    protected const array WIRE_PROPERTY_MAP = " . PhpLiteral::export($wirePropertyMap) . ";\n\n";
        }
        if ($validation !== []) {
            $source .= "    protected const array VALIDATION = " . PhpLiteral::export($validation) . ";\n\n";
        }
        if ($readOnly !== []) {
            $source .= "    protected const array READ_ONLY_FIELDS = " . PhpLiteral::export($readOnly) . ";\n\n";
        }
        if ($writeOnly !== []) {
            $source .= "    protected const array WRITE_ONLY_FIELDS = " . PhpLiteral::export($writeOnly) . ";\n\n";
        }
        if ($additionalPropertiesType !== null) {
            $source .= "    protected const string ADDITIONAL_PROPERTIES_TYPE = "
                . PhpLiteral::export($additionalPropertiesType) . ";\n"
                . "    protected const bool ADDITIONAL_PROPERTIES_NULLABLE = "
                . PhpLiteral::export($additionalPropertiesNullable) . ";\n";
            if ($additionalPropertiesValidation !== []) {
                $source .= "    protected const array ADDITIONAL_PROPERTIES_VALIDATION = "
                    . PhpLiteral::export($additionalPropertiesValidation) . ";\n";
            }
            $source .= "\n";
        }
        if ($fields !== []) {
            $source .= implode("\n", $fields) . "\n\n";
        }
        $source .= "}\n";
        return $source;
    }

    private function inheritsMultipartFileList(?string $parent, string $wireName): bool
    {
        $seen = [];
        while ($parent !== null && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $schema = $this->modelDefinitions[$parent]->schema;
            if (isset($this->multipartSchemas[spl_object_id($schema)])) {
                foreach ($schema->properties as $property) {
                    if ($property->wireName === $wireName && $property->schema->kind === SchemaKind::Array
                        && \Dmorenof\SdkGenerator\Parsing\FormSupport::fileItem($property->schema) !== null) {
                        return true;
                    }
                }
            }
            $parent = $this->modelParents[$parent] ?? null;
        }
        return false;
    }

    /** @param array<string,string> $files */
    private function typeFor(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
    ): string
    {
        if ($schema->enumValues !== []) {
            if (!in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true)) {
                throw new InvalidArgumentException("Only string/integer backed enums are supported at $hint");
            }
            $entry = $this->modelNames->derived($hint, 'Enums');
            $this->addFile($files, $entry['path'], $this->renderEnum($entry['class'], $schema, ltrim($entry['namespace'], '\\')));
            return $entry['type'];
        }
        return match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            SchemaKind::Mixed => 'mixed',
            SchemaKind::Reference => $this->reference($schema),
            SchemaKind::Array => $this->collectionType($schema, $hint, $files, $baseClass),
            SchemaKind::AnyOf, SchemaKind::OneOf => $this->compositionType($schema, $hint, $files, $baseClass),
            SchemaKind::AllOf => $this->inlineAllOfType($schema, $hint, $files, $baseClass),
            SchemaKind::Object => $this->inlineObjectType($schema, $hint, $files, $baseClass),
            default => throw new InvalidArgumentException("Unsupported inline/object schema at $hint"),
        };
    }

    private static function nullableCompositionBranch(SchemaDefinition $schema): ?SchemaDefinition
    {
        if (!in_array($schema->kind, [SchemaKind::AnyOf, SchemaKind::OneOf], true)
            || count($schema->branches) !== 2) {
            return null;
        }
        $nonNull = array_values(array_filter(
            $schema->branches,
            static fn (SchemaDefinition $branch): bool => $branch->kind !== SchemaKind::Null,
        ));
        if (count($nonNull) !== 1) {
            return null;
        }
        return $nonNull[0];
    }

    private static function categoryForBaseClass(string $baseClass): string
    {
        // Inline operation envelopes have their own identity. Reusable named
        // schemas always remain in DTOs irrespective of request/response usage.
        return match ($baseClass) {
            'Request' => 'Requests',
            'Response' => 'Responses',
            default => 'DTOs',
        };
    }

    private static function nameCategoryForBaseClass(string $baseClass): string
    {
        return match ($baseClass) {
            'Request' => 'Requests',
            'Response' => 'Responses',
            default => 'DTOs',
        };
    }

    /** @param array<string,string> $files */
    private function inlineObjectType(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
        ?string $className = null,
    ): string
    {
        if ($schema->properties === [] && $this->hasOpenAdditionalProperties($schema)) {
            return $this->inlineMapType($schema->additionalProperties, $hint, $files, $baseClass);
        }
        if ($className === null && str_starts_with($hint, 'Errors.')) {
            $segments = explode('.', $hint);
            $className = end($segments) . 'Response';
        }
        $entry = $this->modelNames->derived(
            $hint,
            self::categoryForBaseClass($baseClass),
            $className,
            self::nameCategoryForBaseClass($baseClass),
        );
        $this->addFile(
            $files,
            $entry['path'],
            $this->renderObjectModel($entry['type'], $schema, $files, $baseClass, $hint),
        );
        return $entry['type'];
    }

    /** @param array<string,string> $files */
    private function inlineMapType(
        bool|SchemaDefinition $valueSchema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
    ): string
    {
        $entry = $this->modelNames->derived($hint . 'Map', self::categoryForBaseClass($baseClass), null, self::nameCategoryForBaseClass($baseClass));
        $this->addFile(
            $files,
            $entry['path'],
            $this->renderMapClass($entry['type'], $valueSchema, $files, $hint, $baseClass),
        );
        return $entry['type'];
    }

    /** @param array<string,string> $files */
    private function renderMapClass(
        string $fqcn,
        bool|SchemaDefinition $valueSchema,
        array &$files,
        string $hint,
        string $baseClass = 'DTO',
    ): string {
        $valueType = 'mixed';
        $nullable = false;
        $validation = [];
        if ($valueSchema instanceof SchemaDefinition) {
            $valueType = $this->typeFor($valueSchema, $hint . 'Value', $files, $baseClass);
            $nullable = $valueSchema->nullable;
            $validation = $valueSchema->validation;
            if ($valueSchema->pattern !== null) {
                $validation['pattern'] = $valueSchema->pattern;
            }
        }
        $class = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $namespace = substr($fqcn, 1, strrpos($fqcn, '\\') - 1);
        $source = $this->header($namespace)
            . "final class $class extends \\" . self::RUNTIME_NAMESPACE . "\\Map\n{\n"
            . "    protected const string VALUE_TYPE = " . PhpLiteral::export($valueType) . ";\n"
            . "    protected const bool VALUE_NULLABLE = " . PhpLiteral::export($nullable) . ";\n";
        if ($validation !== []) {
            $source .= "    protected const array VALUE_VALIDATION = " . PhpLiteral::export($validation) . ";\n";
        }
        return $source . "}\n";
    }

    private function hasOpenAdditionalProperties(SchemaDefinition $schema): bool
    {
        return $schema->additionalProperties === true
            || $schema->additionalProperties instanceof SchemaDefinition;
    }

    /** @param array<string,string> $files */
    private function inlineAllOfType(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
    ): string
    {
        $expanded = $this->expandAllOf($schema, $hint);
        return $this->inlineObjectType(
            $expanded,
            PhpModelNameResolver::prefixLastSegment($hint, 'AllOf'),
            $files,
            $baseClass,
        );
    }

    /** @param array<string,string> $files */
    private function compositionType(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
    ): string
    {
        // A union of finite enums with the same backing type is itself a finite
        // enum. Keep anyOf wrappers only when they add actual model semantics.
        if ($schema->kind === SchemaKind::AnyOf) {
            $kind = null;
            $values = [];
            foreach ($schema->branches as $branch) {
                if ($branch->kind === SchemaKind::Reference) {
                    $branch = $this->modelDefinitions[$branch->reference]->schema;
                }
                if ($branch->enumValues === [] || $branch->nullable
                    || $branch->validation !== [] || $branch->pattern !== null
                    || !in_array($branch->kind, [SchemaKind::String, SchemaKind::Integer], true)
                    || ($kind !== null && $kind !== $branch->kind)) {
                    $values = [];
                    break;
                }
                $kind = $branch->kind;
                foreach ($branch->enumValues as $value) {
                    if (!in_array($value, $values, true)) {
                        $values[] = $value;
                    }
                }
            }
            if ($values !== []) {
                return $this->typeFor(new SchemaDefinition($kind, enumValues: $values), $hint, $files, $baseClass);
            }
        }
        $prefix = $schema->kind === SchemaKind::AnyOf ? 'AnyOf' : 'OneOf';
        $name = PhpModelNameResolver::prefixLastSegment($hint, $prefix);
        $entry = $this->modelNames->derived($name, self::categoryForBaseClass($baseClass), null, self::nameCategoryForBaseClass($baseClass));
        $this->addFile(
            $files,
            $entry['path'],
            $this->renderCompositionClass($entry['type'], $schema, $files, $baseClass),
        );
        return $entry['type'];
    }

    /** @param array<string,string> $files */
    private function renderCompositionClass(
        string $fqcn,
        SchemaDefinition $schema,
        array &$files,
        string $baseClass = 'DTO',
    ): string {
        if (!in_array($schema->kind, [SchemaKind::AnyOf, SchemaKind::OneOf], true) || $schema->branches === []) {
            throw new InvalidArgumentException('Expected a nonempty anyOf/oneOf schema.');
        }
        $class = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $namespace = substr($fqcn, 1, strrpos($fqcn, '\\') - 1);
        $types = [];
        foreach ($schema->branches as $index => $branch) {
            $type = $branch->kind === SchemaKind::Null
                ? 'null'
                : $this->typeFor($branch, $class . 'Variant' . ($index + 1), $files, $baseClass);
            $types[] = str_starts_with($type, '\\')
                ? $type . '::class'
                : var_export($type, true);
        }
        $core = $schema->kind === SchemaKind::AnyOf ? 'AnyOf' : 'OneOf';
        $references = [];
        $branches = [];
        foreach ($schema->branches as $branch) {
            $branches[] = $this->schemaDescriptor($branch, $references);
        }
        // Collect only the reachable local definitions; references stay symbolic,
        // so recursive object models do not expand infinitely.
        $definitions = [];
        while (array_diff_key($references, $definitions) !== []) {
            foreach (array_diff_key($references, $definitions) as $name => $_) {
                $definitions[$name] = $this->schemaDescriptor($this->modelDefinitions[$name]->schema, $references);
            }
        }
        ksort($definitions);
        $source = $this->header($namespace)
            . "final class $class extends \\" . self::RUNTIME_NAMESPACE . "\\$core\n{\n"
            . '    protected const array TYPES = [' . implode(', ', $types) . "];\n"
            . '    protected const array BRANCH_SCHEMAS = ' . PhpLiteral::export($branches) . ";\n"
            . '    protected const array SCHEMA_DEFINITIONS = ' . PhpLiteral::export($definitions) . ";\n";
        if ($schema->discriminator !== null) {
            $source .= "    protected const string DISCRIMINATOR_PROPERTY = " . PhpLiteral::export($schema->discriminator->propertyName) . ";\n"
                . "    protected const array DISCRIMINATOR_MAPPING = " . PhpLiteral::export($this->discriminatorMapping($schema, $fqcn)) . ";\n";
        }
        return $source . "}\n";
    }

    /** @param array<string, true> $references @return array<string, mixed> */
    private function schemaDescriptor(SchemaDefinition $schema, array &$references): array
    {
        $node = ['kind' => $schema->kind->value];
        if ($schema->kind === SchemaKind::Reference) {
            $references[$schema->reference] = true;
            $node['ref'] = $schema->reference;
            return $node;
        }
        if ($schema->nullable) {
            $node['nullable'] = true;
        }
        if ($schema->enumValues !== []) {
            $node['enum'] = $schema->enumValues;
        }
        $rules = $schema->validation;
        if ($schema->pattern !== null) {
            $rules['pattern'] = $schema->pattern;
        }
        if ($rules !== []) {
            $node['rules'] = $rules;
        }
        if ($schema->items !== null) {
            $node['items'] = $this->schemaDescriptor($schema->items, $references);
        }
        foreach ($schema->properties as $property) {
            $node['properties'][$property->wireName] = $this->schemaDescriptor($property->schema, $references);
            if ($property->required) {
                $node['required'][] = $property->wireName;
            }
            if ($property->schema->readOnly) {
                $node['readOnly'][] = $property->wireName;
            }
            if ($property->schema->writeOnly) {
                $node['writeOnly'][] = $property->wireName;
            }
        }
        if ($schema->additionalProperties !== null) {
            $node['additional'] = $schema->additionalProperties instanceof SchemaDefinition
                ? $this->schemaDescriptor($schema->additionalProperties, $references) : $schema->additionalProperties;
        }
        foreach ($schema->branches as $branch) {
            $node['branches'][] = $this->schemaDescriptor($branch, $references);
        }
        return $node;
    }

    /** @return array<string, string> Discriminator values to generated class names. */
    private function discriminatorMapping(SchemaDefinition $schema, string $where): array
    {
        $discriminator = $schema->discriminator;
        if ($discriminator === null) {
            throw new \LogicException('Missing discriminator metadata.');
        }
        $candidates = [];
        if (in_array($schema->kind, [SchemaKind::OneOf, SchemaKind::AnyOf], true)) {
            foreach ($schema->branches as $branch) {
                if ($branch->kind !== SchemaKind::Reference) {
                    throw new InvalidArgumentException("Discriminator requires named reference branches at $where.");
                }
                $targetSchema = $this->expandAllOf($this->modelDefinitions[$branch->reference]->schema, $where);
                $tagProperty = null;
                foreach ($targetSchema->properties as $property) {
                    if ($property->wireName === $discriminator->propertyName) {
                        $tagProperty = $property->schema;
                    }
                }
                if ($targetSchema->kind !== SchemaKind::Object || $tagProperty?->kind !== SchemaKind::String) {
                    throw new InvalidArgumentException("Discriminator branches must declare a string tag property at $where.");
                }
                $candidates[] = $branch->reference;
            }
        } else {
            foreach ($this->modelParents as $child => $parent) {
                while (true) {
                    if ($parent === $where) {
                        $candidates[] = $child;
                        break;
                    }
                    if (!isset($this->modelParents[$parent])) {
                        break;
                    }
                    $parent = $this->modelParents[$parent];
                }
            }
            $properties = [];
            foreach ($schema->properties as $property) {
                $properties[$property->wireName] = $property->schema;
            }
            if (!isset($properties[$discriminator->propertyName])
                || $properties[$discriminator->propertyName]->kind !== SchemaKind::String) {
                throw new InvalidArgumentException("Discriminator property must be a declared string at $where.");
            }
        }
        $mapping = [];
        foreach ($candidates as $name) {
            $mapping[$name] = ltrim($this->modelNames->fqcn($name), '\\');
        }
        foreach ($discriminator->mapping as $tag => $target) {
            if (!in_array($target, $candidates, true)) {
                throw new InvalidArgumentException("Discriminator mapping '$tag' must target a compatible named subtype/branch at $where.");
            }
            $mapping[$tag] = ltrim($this->modelNames->fqcn($target), '\\');
        }
        if ($mapping === []) {
            throw new InvalidArgumentException("Discriminator has no compatible named subtypes at $where.");
        }
        ksort($mapping);
        return $mapping;
    }

    private function discriminatedModel(string $name): bool
    {
        while (isset($this->modelDefinitions[$name])) {
            foreach ($this->modelDefinitions as $model) {
                if ($model->schema->discriminator !== null
                    && in_array($model->schema->kind, [SchemaKind::OneOf, SchemaKind::AnyOf], true)) {
                    foreach ($model->schema->branches as $branch) {
                        if ($branch->kind === SchemaKind::Reference && $branch->reference === $name) {
                            return true;
                        }
                    }
                }
            }
            if ($this->modelDefinitions[$name]->schema->discriminator !== null) {
                return true;
            }
            if (!isset($this->modelParents[$name])) {
                break;
            }
            $name = $this->modelParents[$name];
        }
        return false;
    }

    /** Bounded scalar enum intersection; the inherited PHP property keeps its original type. */
    private function refineProperty(SchemaDefinition $left, SchemaDefinition $right, string $where, string $name): SchemaDefinition
    {
        $a = get_object_vars($left);
        $b = get_object_vars($right);
        unset($a['enumValues'], $b['enumValues']);
        if ($a !== $b || !in_array($left->kind, [SchemaKind::String, SchemaKind::Integer], true)) {
            throw new InvalidArgumentException("Conflicting allOf property '$name' at $where");
        }
        $values = $left->enumValues === [] ? $right->enumValues
            : ($right->enumValues === [] ? $left->enumValues
                : array_values(array_filter($left->enumValues, static fn ($value): bool => in_array($value, $right->enumValues, true))));
        if ($values === []) {
            throw new InvalidArgumentException("Empty allOf enum intersection for '$name' at $where");
        }
        return new SchemaDefinition(...array_replace(get_object_vars($left), ['enumValues' => $values]));
    }

    /** Only a single referenced object plus inline object extensions maps to PHP inheritance. */
    private function inheritanceParent(SchemaDefinition $schema, string $where): ?string
    {
        if ($schema->kind !== SchemaKind::AllOf) {
            return null;
        }
        $this->expandAllOf($schema, $where);
        $references = array_values(array_filter(
            $schema->branches,
            static fn (SchemaDefinition $branch): bool => $branch->kind === SchemaKind::Reference,
        ));
        if (count($references) !== 1) {
            return null;
        }
        foreach ($schema->branches as $branch) {
            if (!in_array($branch->kind, [SchemaKind::Reference, SchemaKind::Object], true)) {
                return null;
            }
        }
        $parent = $references[0]->reference;
        if ($parent === null) {
            return null;
        }
        $base = $this->expandAllOf($this->modelDefinitions[$parent]->schema, $parent);
        if ($base->kind !== SchemaKind::Object || $base->properties === []) {
            return null;
        }
        return $parent;
    }

    private function expandAllOf(SchemaDefinition $schema, string $where, array $seen = []): SchemaDefinition
    {
        if ($schema->kind !== SchemaKind::AllOf) {
            return $schema;
        }
        $properties = [];
        $required = [];
        foreach ($schema->branches as $index => $branch) {
            if ($branch->kind === SchemaKind::Reference) {
                $reference = $branch->reference;
                if ($reference === null || isset($seen[$reference]) || !isset($this->modelDefinitions[$reference])) {
                    throw new InvalidArgumentException("Invalid or cyclic allOf reference at $where.allOf.$index");
                }
                $seen[$reference] = true;
                $branch = $this->modelDefinitions[$reference]->schema;
            }
            if ($branch->kind === SchemaKind::AllOf) {
                $branch = $this->expandAllOf($branch, "$where.allOf.$index", $seen);
            }
            if ($branch->kind !== SchemaKind::Object
                || ($branch->additionalProperties !== null && $branch->additionalProperties !== false)) {
                throw new InvalidArgumentException("Unsupported allOf branch at $where.allOf.$index");
            }
            foreach ($branch->properties as $property) {
                if (isset($properties[$property->wireName])
                    && $properties[$property->wireName]->schema != $property->schema) {
                    $property = new \Dmorenof\SdkGenerator\Contract\PropertyDefinition(
                        $property->wireName,
                        $this->refineProperty($properties[$property->wireName]->schema, $property->schema, $where, $property->wireName),
                        $property->required,
                    );
                }
                $properties[$property->wireName] = $property;
                if ($property->required) {
                    $required[$property->wireName] = true;
                }
            }
        }
        $closed = false;
        foreach ($schema->branches as $branch) {
            if ($branch->kind === SchemaKind::Reference) {
                $branch = $this->modelDefinitions[$branch->reference]->schema;
            }
            if ($branch->kind === SchemaKind::AllOf) {
                $branch = $this->expandAllOf($branch, $where);
            }
            if ($branch->additionalProperties === false) {
                $closed = true;
                $allowed = array_map(static fn ($property): string => $property->wireName, $branch->properties);
                if (array_diff(array_keys($properties), $allowed) !== []) {
                    throw new InvalidArgumentException("Cannot extend a closed allOf branch at $where");
                }
            }
        }
        foreach ($properties as $name => $property) {
            if ($property->required !== isset($required[$name])) {
                $properties[$name] = new \Dmorenof\SdkGenerator\Contract\PropertyDefinition(
                    $property->wireName,
                    $property->schema,
                    isset($required[$name]),
                );
            }
        }
        ksort($properties);
        return new SchemaDefinition(
            SchemaKind::Object,
            properties: array_values($properties),
            additionalProperties: $closed ? false : null,
            discriminator: $schema->discriminator,
        );
    }

    private function reference(SchemaDefinition $schema): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        return $this->modelNames->fqcn($schema->reference);
    }

    /** @param array<string,string> $files */
    private function collectionType(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
    ): string
    {
        if ($schema->items === null) {
            throw new InvalidArgumentException("Missing collection items at $hint");
        }
        $itemType = $this->typeFor($schema->items, $hint . 'Item', $files, $baseClass);
        $itemValidation = $schema->items->validation;
        if ($schema->items->pattern !== null) {
            $itemValidation['pattern'] = $schema->items->pattern;
        }
        $rules = $itemValidation;
        ksort($rules);
        $key = serialize([$itemType, $schema->items->nullable, $rules]);
        if (isset($this->collectionTypes[$key])) {
            return $this->collectionTypes[$key];
        }
        $sharedName = $itemValidation === [] ? match ($itemType) {
            'string' => 'StringCollection',
            'int' => 'IntegerCollection',
            'float' => 'FloatCollection',
            'bool' => 'BooleanCollection',
            default => null,
        } : null;
        $entry = $this->modelNames->derived(
            $sharedName ?? $hint . 'Array', 'Collections', $sharedName,
        );
        $name = $entry['class'];
        $expected = match ($itemType) {
            'string' => "'string'",
            'int' => "'integer'",
            'float' => "'double'",
            'bool' => "'boolean'",
            default => $itemType . '::class',
        };
        $file = $this->header(ltrim($entry['namespace'], '\\'))
            . "final class $name extends \\" . self::RUNTIME_NAMESPACE . "\\TypedArray\n"
            . "{\n"
            . "    protected string \$expected_type = $expected;\n";
        if ($itemValidation !== []) {
            $file .= "    protected const array ITEM_VALIDATION = "
                . PhpLiteral::export($itemValidation) . ";\n";
        }
        $file .= "}\n";
        $this->addFile($files, $entry['path'], $file);
        $this->collectionTypes[$key] = $entry['type'];
        return $entry['type'];
    }

    private function renderEnum(string $name, SchemaDefinition $schema, string $namespace): string
    {
        $backing = $schema->kind === SchemaKind::String ? 'string' : 'int';
        $cases = [];
        $seen = [];
        foreach ($schema->enumValues as $value) {
            if (($backing === 'string' && !is_string($value))
                || ($backing === 'int' && !is_int($value))) {
                throw new InvalidArgumentException("Invalid $backing enum backing value in $name");
            }
            $case = is_string($value)
                ? preg_replace('/[^A-Za-z0-9]+/', '', ucwords(PhpNameTransliterator::latin($value), '-_ '))
                : 'Value' . ($value < 0 ? 'Neg' . abs($value) : $value);
            if ($case === '' || preg_match(self::IDENTIFIER, $case) !== 1) {
                throw new InvalidArgumentException("Cannot safely name enum case in $name");
            }
            if (in_array(strtolower($case), self::PHP_KEYWORDS, true)) {
                $case .= '_';
            }
            $baseCase = $case;
            $suffix = 2;
            while (isset($seen[strtolower($case)])) {
                $case = $baseCase . $suffix++;
            }
            $this->checkName($case, 'enum case');
            $seen[strtolower($case)] = true;
            $cases[] = '    case ' . $case . ' = ' . var_export($value, true) . ';';
        }
        return $this->header($namespace)
            . "enum $name: $backing\n{\n" . implode("\n", $cases) . "\n}\n";
    }

    private function renderOperations(ApiContract $contract, array &$files): string
    {
        $methods = [];
        $seen = [];
        foreach ($contract->operations as $operation) {
            if (in_array($operation->requestBody?->mediaType, [\Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA, \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART], true)
                && \Dmorenof\SdkGenerator\Parsing\FormSupport::formats($operation->requestBody, $contract->models) === null) {
                throw new InvalidArgumentException("Unsupported form body schema or encoding at $operation->operationId.");
            }
            preg_match_all('/\{([^{}]+)\}/', $operation->path, $matches);
            $pathNames = [];
            foreach ($operation->parameters as $parameter) {
                if ($parameter->location === 'path') {
                    if (!$parameter->required || $parameter->schema->nullable) {
                        throw new InvalidArgumentException("Path parameters must be required and nonnullable at $operation->operationId.");
                    }
                    $pathNames[] = $parameter->wireName;
                }
                if (in_array($parameter->location, ['path', 'header'], true)) {
                    $resolved = PhpParameterTypeResolver::resolve($parameter->schema, $operation->operationId . '.' . $parameter->wireName, true);
                    if ($parameter->location === 'path' && $resolved['nullable']) {
                        throw new InvalidArgumentException("Path parameters must be nonnullable at $operation->operationId.");
                    }
                    if ($resolved['type'] === 'array' && ($resolved['nullable'] || $parameter->content !== []
                        || \Dmorenof\SdkGenerator\Parsing\QueryArraySupport::format($parameter->serialization, $parameter->location) === null)) {
                        throw new InvalidArgumentException("Unsupported simple list at $operation->operationId.$parameter->wireName.");
                    }
                }
                if (\Dmorenof\SdkGenerator\Parsing\QueryObjectSupport::supported($parameter, $contract->models)) {
                    $segments = PhpRouteNameResolver::resolve($contract)['parameterSegments'][$operation->operationId];
                    $hint = implode('.', [...$segments, ucfirst(strtolower($operation->method)), 'Query', ucfirst($parameter->wireName)]);
                    $this->operationQueryTypes[$operation->operationId][$parameter->wireName] = $this->typeFor($parameter->schema, $hint, $files);
                }
            }
            if (array_diff($matches[1], $pathNames) !== [] || array_diff($pathNames, $matches[1]) !== []) {
                throw new InvalidArgumentException("Path placeholders and declared parameters differ at $operation->operationId.");
            }
            if ($operation->requestBody !== null) {
                $this->operationBodyTypes[$operation->operationId] = $this->requestBodyType($operation, $files);
            }
            $methodName = PhpOperationNameResolver::resolve($operation->operationId);
            if (isset($seen[strtolower($methodName)])) {
                throw new InvalidArgumentException("Duplicate PHP operation name: $operation->operationId");
            }
            $seen[strtolower($methodName)] = true;
            $methodArgs = [];
            if ($operation->parameters !== []) {
                // Keep URL/header fields together in a discoverable typed
                // Request instead of several unrelated scalar arguments.
                $methodArgs[] = [
                    'request',
                    (new PhpOperationRequestEmitter($this->namespace))->fqcn($operation),
                    true,
                ];
            } elseif ($operation->requestBody !== null) {
                // A body-only model already extends Core\Request.
                $methodArgs[] = [
                    'body',
                    $this->operationBodyTypes[$operation->operationId],
                    $operation->requestBody->required,
                ];
            }
            $args = [];
            $seenArgs = [];
            // Required arguments first, preserving order within each category.
            usort($methodArgs, static fn (array $a, array $b): int => (int) $b[2] <=> (int) $a[2]);
            foreach ($methodArgs as [$argName, $argType, $required]) {
                $key = strtolower($argName);
                if (isset($seenArgs[$key])) {
                    throw new InvalidArgumentException("Duplicate operation parameter $argName in $operation->operationId");
                }
                $seenArgs[$key] = true;
                $args[] = ($required ? $argType : '?' . $argType)
                    . ' $' . $argName . ($required ? '' : ' = null');
            }
            $return = $this->successResponseType($operation, $files);
            foreach ($operation->responses as $response) {
                if (preg_match('/^2[0-9]{2}$/', $response->status) === 1 || $response->schema === null) {
                    continue;
                }
                if ($response->schema->kind === SchemaKind::Reference) {
                    $this->operationErrorTypes[$operation->operationId][$response->status]
                        = $this->reference($response->schema);
                    continue;
                }
                if (($response->schema->kind === SchemaKind::Object
                        && !($response->schema->properties === [] && $this->hasOpenAdditionalProperties($response->schema)))
                    || $response->schema->kind === SchemaKind::AllOf) {
                    $hint = $this->operationResponseHint($operation, $response->status);
                    $this->operationErrorTypes[$operation->operationId][$response->status]
                        = $this->typeFor($response->schema, $hint, $files, 'Response');
                }
            }
            $methods[] = '    public function ' . $methodName
                . '(' . implode(', ', $args) . '): ' . $return . ';';
        }
        return $this->header($this->namespace . '\\Api')
            . "/** Typed operation signatures; runtime/transport implementation follows separately. */\n"
            . "interface Operations\n{\n"
            . implode("\n", $methods) . "\n}\n";
    }

    private function primitiveParameterType(SchemaDefinition $schema, string $where): string
    {
        if ($schema->nullable || $schema->enumValues !== []) {
            throw new InvalidArgumentException("Unsupported optional/enum parameter schema at $where");
        }
        return match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            default => throw new InvalidArgumentException("Unsupported complex parameter schema at $where"),
        };
    }

    /** @param array<string,string> $files */
    private function requestBodyType(OperationDefinition $operation, array &$files): string
    {
        $body = $operation->requestBody;
        if ($body === null) {
            throw new InvalidArgumentException("Request body is absent at $operation->operationId");
        }
        if ($body->schema->kind === SchemaKind::Reference) {
            return $this->bodyType($body->schema, $operation->operationId);
        }
        if ($body->schema->kind !== SchemaKind::Object) {
            throw new InvalidArgumentException(
                "Unsupported inline request body at $operation->operationId: expected object."
            );
        }
        $segments = [];
        foreach (explode('/', trim($operation->path, '/')) as $part) {
            $segments[] = preg_match('/^\{([^{}]+)\}$/D', $part, $match) === 1
                ? 'By' . PhpResourceNameResolver::pascal($match[1])
                : PhpResourceNameResolver::pascal($part);
        }
        $verb = ucfirst(strtolower($operation->method));
        return $this->inlineObjectType(
            $body->schema,
            implode('.', [...$segments, $verb]),
            $files,
            'Request',
            $verb,
        );
    }

    private function bodyType(SchemaDefinition $schema, string $where): string
    {
        if ($schema->nullable || $schema->kind !== SchemaKind::Reference) {
            throw new InvalidArgumentException("Unsupported non-reference request body at $where");
        }
        return $this->reference($schema);
    }

    private function successResponseType(OperationDefinition $operation, array &$files): string
    {
        $success = [];
        foreach ($operation->responses as $response) {
            if (preg_match('/^2[0-9]{2}$/', $response->status) === 1) {
                $success[] = $response;
            }
        }
        if ($success === []) {
            throw new InvalidArgumentException("Expected at least one successful response for $operation->operationId");
        }
        $withSchema = array_values(array_filter(
            $success,
            static fn ($response): bool => $response->schema !== null,
        ));
        if ($withSchema !== [] && count(array_filter($withSchema, static fn ($response): bool =>
            $response->mediaType === \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::MEDIA)) === count($withSchema)) {
            $type = '\\Psr\\Http\\Message\\ResponseInterface';
            $this->operationSuccessTypes[$operation->operationId] = [
                'type' => $type, 'nullable' => false, 'schemaNullable' => false, 'noContentAllowed' => false,
            ];
            return $type;
        }
        if ($withSchema === []) {
            $this->operationSuccessTypes[$operation->operationId] = [
                'type' => null,
                'nullable' => false,
                'schemaNullable' => false,
                'noContentAllowed' => false,
            ];
            return 'void';
        }
        if (count($success) > 1) {
            $byStatus = [];
            $types = [];
            $nullable = false;
            foreach ($success as $response) {
                if ($response->mediaType === \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::MEDIA) {
                    throw new InvalidArgumentException("Mixed binary/JSON success responses are unsupported for $operation->operationId");
                }
                $schema = $response->schema;
                $type = $schema === null ? null : $this->responseSchemaType($schema,
                    $this->operationResponseHint($operation, count($withSchema) > 1 ? $response->status : null), $files);
                $byStatus[(int) $response->status] = ['type' => $type, 'schemaNullable' => $schema?->nullable ?? false];
                $nullable = $nullable || $schema === null || $schema->nullable;
                if ($type !== null) {
                    $types[] = $type;
                }
            }
            $types = array_values(array_unique($types));
            if (in_array('mixed', $types, true)) {
                $types = ['mixed'];
                $nullable = false; // mixed already includes null.
            }
            if ($nullable && count($types) > 1) {
                $types[] = 'null';
                $nullable = false; // PHP nullable shorthand is only valid for a single type.
            }
            $type = implode('|', $types);
            $this->operationSuccessTypes[$operation->operationId] = [
                'type' => $type, 'nullable' => $nullable, 'schemaNullable' => false,
                'noContentAllowed' => false, 'byStatus' => $byStatus,
            ];
            return ($nullable ? '?' : '') . $type;
        }
        $response = $withSchema[0];
        $schemaNullable = $response->schema->nullable;
        $noContentAllowed = count($success) > 1;
        $nullable = $schemaNullable || $noContentAllowed;
        $type = $this->responseSchemaType(
            $response->schema,
            $this->operationResponseHint($operation),
            $files,
        );
        $this->operationSuccessTypes[$operation->operationId] = [
            'type' => $type,
            'nullable' => $nullable,
            'schemaNullable' => $schemaNullable,
            'noContentAllowed' => $noContentAllowed,
        ];
        return ($nullable ? '?' : '') . $type;
    }

    /** @param array<string,string> $files */
    private function responseSchemaType(SchemaDefinition $schema, string $hint, array &$files): string
    {
        if ($schema->kind === SchemaKind::Reference) {
            return $this->reference($schema);
        }
        if ($schema->kind === SchemaKind::Object) {
            // A response is an operation-owned envelope, not a reusable schema.
            // /api/v1/user POST -> Responses\Api\V1\User\Post.
            $segments = explode('.', $hint);
            if (count($segments) >= 2 && end($segments) === 'Response') {
                array_pop($segments);
                $verb = (string) end($segments);
                return $this->inlineObjectType(
                    $schema,
                    implode('.', $segments),
                    $files,
                    'Response',
                    $verb,
                );
            }
            return $this->inlineObjectType($schema, $hint, $files, 'Response');
        }
        if ($schema->kind === SchemaKind::AllOf) {
            return $this->inlineAllOfType($schema, $hint, $files, 'Response');
        }
        return $this->typeFor($schema, $hint, $files, 'Response');
    }

    /**
     * Derive response types from their structural URL hierarchy.
     * The HTTP verb is a namespace segment, avoiding GET/DELETE or v1/v2
     * collisions without opaque suffixes.
     */
    private function operationResponseHint(OperationDefinition $operation, ?string $status = null): string
    {
        $segments = [];
        foreach (explode('/', trim($operation->path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            $segments[] = preg_match('/^\{([^{}]+)\}$/D', $part, $match) === 1
                ? 'By' . PhpResourceNameResolver::pascal($match[1])
                : PhpResourceNameResolver::pascal($part);
        }
        $verb = ucfirst(strtolower($operation->method));
        if ($status === null) {
            return implode('.', [...$segments, $verb, 'Response']);
        }
        if (preg_match('/^2[0-9]{2}$/', $status) === 1) {
            return implode('.', [...$segments, $verb . 'Status' . $status, 'Response']);
        }
        $statusName = preg_match('/^[A-Za-z]/', $status) === 1
            ? ucfirst(strtolower($status))
            : 'Status' . preg_replace('/[^A-Za-z0-9]/', '', $status);
        return implode('.', ['Errors', ...$segments, $verb . $statusName]);
    }

    private function renderCatalog(ApiContract $contract): string
    {
        $routes = [];
        foreach ($contract->operations as $operation) {
            $routes[$operation->operationId] = [
                'method' => $operation->method,
                'path' => $operation->path,
                'parameters' => array_map(static fn ($p): array => [
                    'name' => $p->wireName,
                    'in' => $p->location,
                    'required' => $p->required,
                ], $operation->parameters),
                'requestMediaType' => $operation->requestBody?->mediaType,
                'responses' => array_map(static fn ($r): array => [
                    'status' => $r->status, 'mediaType' => $r->mediaType,
                ], $operation->responses),
            ];
        }
        ksort($routes);
        $literal = PhpLiteral::export($routes);
        return $this->header($this->namespace . '\\Api')
            . "final class OperationCatalog\n{\n"
            . "    public const array ROUTES = $literal;\n"
            . "}\n";
    }

    /** @param array<string,string> $files */
    private function addFile(array &$files, string $path, string $content): void
    {
        if (isset($files[$path]) && $files[$path] !== $content) {
            throw new InvalidArgumentException("Generated class collision at $path");
        }
        $files[$path] = $content;
    }

    private function checkName(string $value, string $kind): void
    {
        if (preg_match(self::IDENTIFIER, $value) !== 1
            || in_array(strtolower($value), self::PHP_KEYWORDS, true)) {
            throw new InvalidArgumentException("Invalid PHP $kind identifier: $value");
        }
    }

    private function propertyName(string $wireName): string
    {
        $candidate = PhpNameTransliterator::property($wireName);
        $this->checkName($candidate, 'model property');
        return $candidate;
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }
}
