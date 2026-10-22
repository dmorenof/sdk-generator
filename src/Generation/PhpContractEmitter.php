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
    private ?PhpModelNameResolver $modelNames = null;
    /** @var array<string, ModelDefinition> */
    private array $modelDefinitions = [];
    /** @var array<string, true> */
    private array $inlineModelReferences = [];
    /** @var array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> */
    private array $operationSuccessTypes = [];
    /** @var array<string, array<string, string>> */
    private array $operationErrorTypes = [];
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
        $files = (new PhpCoreEmitter($this->namespace))->emit();
        if (!$this->contractUsesUnion($contract)) {
            unset($files['src/Core/AnyOf.php'], $files['src/Core/OneOf.php']);
        }
        $roles = self::modelRolesFor($contract);
        $this->modelNames = new PhpModelNameResolver($this->namespace, $contract->models, $roles);
        $this->modelDefinitions = [];
        $this->operationSuccessTypes = [];
        $this->operationErrorTypes = [];
        foreach ($contract->models as $model) {
            $this->modelDefinitions[$model->name] = $model;
        }
        $this->inlineModelReferences = $this->inlineableModelReferences($contract);
        $names = [];
        foreach ($contract->models as $model) {
            $key = strtolower($model->name);
            if (isset($names[$key])) {
                throw new InvalidArgumentException("Duplicate PHP model name: $model->name");
            }
            $names[$key] = true;
            if (isset($this->inlineModelReferences[$model->name])) {
                continue;
            }
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
                throw new InvalidArgumentException("Generated class path collides with SDK Core: $path");
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

        $files = array_replace($files, (new PhpOperationRequestEmitter($this->namespace, $this->modelNames))->emit($contract));
        $files['src/Api/Operations.php'] = $this->renderOperations($contract, $files);
        $files['src/Api/OperationCatalog.php'] = $this->renderCatalog($contract);
        $manifest = [
            'name' => $this->packageName,
            'description' => 'Contract-generated PHP SDK (type declarations; HTTP runtime not yet provided).',
            'type' => 'library',
            'require' => ['php' => '^8.3', 'typedarray/typedarray' => '^1.0'],
            'autoload' => ['psr-4' => [$this->namespace . '\\' => 'src/']],
        ];
        $files['composer.json'] = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";

        ksort($files, SORT_STRING);
        return $files;
    }

    /** @return array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> */
    public function operationSuccessTypes(): array
    {
        return $this->operationSuccessTypes;
    }

    /** @return array<string, array<string, string>> */
    public function operationErrorTypes(): array
    {
        return $this->operationErrorTypes;
    }

    private function contractUsesUnion(ApiContract $contract): bool
    {
        foreach ($contract->models as $model) {
            if ($this->schemaUsesUnion($model->schema)) {
                return true;
            }
        }
        foreach ($contract->operations as $operation) {
            foreach ($operation->parameters as $parameter) {
                if ($this->schemaUsesUnion($parameter->schema)) {
                    return true;
                }
            }
            if ($operation->requestBody !== null && $this->schemaUsesUnion($operation->requestBody->schema)) {
                return true;
            }
            foreach ($operation->responses as $response) {
                if ($response->schema !== null && $this->schemaUsesUnion($response->schema)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function schemaUsesUnion(SchemaDefinition $schema): bool
    {
        if (in_array($schema->kind, [SchemaKind::AnyOf, SchemaKind::OneOf], true)) {
            return true;
        }
        if ($schema->items !== null && $this->schemaUsesUnion($schema->items)) {
            return true;
        }
        foreach ($schema->properties as $property) {
            if ($this->schemaUsesUnion($property->schema)) {
                return true;
            }
        }
        foreach ($schema->branches as $branch) {
            if ($this->schemaUsesUnion($branch)) {
                return true;
            }
        }
        return $schema->additionalProperties instanceof SchemaDefinition
            && $this->schemaUsesUnion($schema->additionalProperties);
    }

    /**
     * Infer roles from references reachable in operation request/response schemas.
     * Models used both for input and output retain a neutral DTO type.
     * Unreferenced nested models retain neutral DTO semantics.
     *
     * @return array<string, 'DTO'|'Request'|'Response'>
     */
    public static function modelRolesFor(ApiContract $contract): array
    {
        $uses = [];
        $collectReferences = function (SchemaDefinition $schema, string $role) use (&$collectReferences, &$uses): void {
            if ($schema->kind === SchemaKind::Reference && $schema->reference !== null) {
                $uses[$schema->reference][$role] = true;
            }
            if ($schema->items !== null) {
                $collectReferences($schema->items, $role);
            }
            foreach ($schema->properties as $property) {
                $collectReferences($property->schema, $role);
            }
            if ($schema->additionalProperties instanceof SchemaDefinition) {
                $collectReferences($schema->additionalProperties, $role);
            }
            foreach ($schema->branches as $branch) {
                $collectReferences($branch, $role);
            }
        };
        foreach ($contract->operations as $operation) {
            $request = $operation->requestBody?->schema;
            if ($request !== null) {
                $collectReferences($request, 'request');
            }
            foreach ($operation->responses as $response) {
                $schema = $response->schema;
                if ($schema !== null) {
                    $collectReferences($schema, 'response');
                }
            }
        }
        $roles = [];
        foreach ($contract->models as $model) {
            $request = $uses[$model->name]['request'] ?? false;
            $response = $uses[$model->name]['response'] ?? false;
            $roles[$model->name] = $request && !$response ? 'Request'
                : ($response && !$request ? 'Response' : 'DTO');
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
        );
    }

    /** @param array<string,string> $files */
    private function renderObjectModel(
        string $fqcn,
        SchemaDefinition $schema,
        array &$files,
        string $baseClass = 'DTO',
        string $hint = '',
    ): string {
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
            $inlineReference = $propertySchema->kind === SchemaKind::Reference
                && $propertySchema->reference !== null
                && isset($this->inlineModelReferences[$propertySchema->reference]);
            $preferredTypeName = $inlineReference
                ? PhpResourceNameResolver::pascal($property->wireName)
                : null;
            $type = $this->typeFor(
                $propertySchema,
                $hint . '.' . ucfirst($propertyName),
                $files,
                $inlineReference ? 'DTO' : $baseClass,
                $preferredTypeName,
            );
            if ($property->schema->nullable || $nullableBranch !== null || $propertySchema->nullable) {
                $type = '?' . $type;
            }
            // Deliberately uninitialized when absent, including optional fields.
            // Calling get_object_vars() preserves the absent-vs-null distinction.
            $fields[] = '    public ' . $type . ' $' . $propertyName . ';';
            $propertyValidation = $property->schema->validation;
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
        if ($schema->additionalProperties === true) {
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
        $source = $this->header($namespace)
            . 'final class ' . $class . ' extends \\' . $this->namespace . '\\Core\\' . $baseClass . "\n{\n"
            . "    public const array REQUIRED_FIELDS = " . PhpLiteral::export($required) . ";\n\n";
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

    /** @param array<string,string> $files */
    private function typeFor(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        string $baseClass = 'DTO',
        ?string $preferredName = null,
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
            SchemaKind::Reference => $this->referenceForType($schema, $hint, $files, $preferredName),
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
        $entry = $this->modelNames->derived(
            $hint,
            self::categoryForBaseClass($baseClass),
            $className,
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
        $entry = $this->modelNames->derived($hint . 'Map', self::categoryForBaseClass($baseClass));
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
            . "final class $class extends \\" . $this->namespace . "\\Core\\Map\n{\n"
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
        $prefix = $schema->kind === SchemaKind::AnyOf ? 'AnyOf' : 'OneOf';
        $name = PhpModelNameResolver::prefixLastSegment($hint, $prefix);
        $entry = $this->modelNames->derived($name, self::categoryForBaseClass($baseClass));
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
        return $this->header($namespace)
            . "final class $class extends \\" . $this->namespace . "\\Core\\$core\n{\n"
            . '    protected const array TYPES = [' . implode(', ', $types) . "];\n"
            . "}\n";
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
                    throw new InvalidArgumentException(
                        "Conflicting allOf property '$property->wireName' at $where",
                    );
                }
                $properties[$property->wireName] = $property;
                if ($property->required) {
                    $required[$property->wireName] = true;
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
        return new SchemaDefinition(SchemaKind::Object, properties: array_values($properties));
    }

    /** @return array<string,true> */
    private function inlineableModelReferences(ApiContract $contract): array
    {
        // Flatten only generated, single-use object components; authored and shared models keep their names.
        $counts = [];
        $protected = [];
        foreach ($contract->models as $model) {
            $this->countSchemaReferences($model->schema, $counts);
        }
        foreach ($contract->operations as $operation) {
            foreach ($operation->parameters as $parameter) {
                $this->countSchemaReferences($parameter->schema, $counts);
                if ($parameter->schema->kind === SchemaKind::Reference && $parameter->schema->reference !== null) {
                    $protected[$parameter->schema->reference] = true;
                }
            }
            if ($operation->requestBody !== null) {
                $schema = $operation->requestBody->schema;
                $this->countSchemaReferences($schema, $counts);
                if ($schema->kind === SchemaKind::Reference && $schema->reference !== null) {
                    $protected[$schema->reference] = true;
                }
            }
            foreach ($operation->responses as $response) {
                if ($response->schema === null) {
                    continue;
                }
                $this->countSchemaReferences($response->schema, $counts);
                if ($response->schema->kind === SchemaKind::Reference && $response->schema->reference !== null) {
                    $protected[$response->schema->reference] = true;
                }
            }
        }

        $inline = [];
        foreach ($counts as $name => $count) {
            $schema = $this->modelDefinitions[$name]->schema ?? null;
            if ($count === 1
                && !isset($protected[$name])
                && $schema?->kind === SchemaKind::Object
                && $schema->title !== null
                && preg_match('/__schema__/i', $name) === 1
                && !$this->schemaContainsReference($schema)) {
                $inline[$name] = true;
            }
        }
        return $inline;
    }

    /** @param array<string,int> $counts */
    private function countSchemaReferences(SchemaDefinition $schema, array &$counts): void
    {
        if ($schema->kind === SchemaKind::Reference && $schema->reference !== null) {
            $counts[$schema->reference] = ($counts[$schema->reference] ?? 0) + 1;
        }
        if ($schema->items !== null) {
            $this->countSchemaReferences($schema->items, $counts);
        }
        foreach ($schema->properties as $property) {
            $this->countSchemaReferences($property->schema, $counts);
        }
        foreach ($schema->branches as $branch) {
            $this->countSchemaReferences($branch, $counts);
        }
        if ($schema->additionalProperties instanceof SchemaDefinition) {
            $this->countSchemaReferences($schema->additionalProperties, $counts);
        }
    }

    private function schemaContainsReference(SchemaDefinition $schema): bool
    {
        if ($schema->kind === SchemaKind::Reference) {
            return true;
        }
        if ($schema->items !== null && $this->schemaContainsReference($schema->items)) {
            return true;
        }
        foreach ($schema->properties as $property) {
            if ($this->schemaContainsReference($property->schema)) {
                return true;
            }
        }
        foreach ($schema->branches as $branch) {
            if ($this->schemaContainsReference($branch)) {
                return true;
            }
        }
        return $schema->additionalProperties instanceof SchemaDefinition
            && $this->schemaContainsReference($schema->additionalProperties);
    }

    private function reference(SchemaDefinition $schema): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        return $this->modelNames->fqcn($schema->reference);
    }

    /** @param array<string,string> $files */
    private function referenceForType(
        SchemaDefinition $schema,
        string $hint,
        array &$files,
        ?string $preferredName = null,
    ): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        if (isset($this->inlineModelReferences[$schema->reference])) {
            $model = $this->modelDefinitions[$schema->reference];
            $segments = explode('.', $hint);
            $name = $preferredName ?? PhpResourceNameResolver::pascal((string) end($segments));
            return $this->inlineObjectType(
                $model->schema,
                $hint,
                $files,
                'DTO',
                $name,
            );
        }
        return $this->reference($schema);
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
        $entry = $this->modelNames->derived($hint . 'Array', 'Collections');
        $name = $entry['class'];
        $itemType = $this->typeFor($schema->items, $hint . 'Item', $files, $baseClass);
        $expected = match ($itemType) {
            'string' => "'string'",
            'int' => "'integer'",
            'float' => "'double'",
            'bool' => "'boolean'",
            default => $itemType . '::class',
        };
        $file = $this->header(ltrim($entry['namespace'], '\\'))
            . "/** @extends \\TypedArray\\TypedArray<" . $itemType . "> */\n"
            . "final class $name extends \\{$this->namespace}\\Core\\TypedArray\n"
            . "{\n"
            . "    protected string \$expected_type = $expected;\n";
        $itemValidation = $schema->items->validation;
        if ($schema->items->pattern !== null) {
            $itemValidation['pattern'] = $schema->items->pattern;
        }
        if ($itemValidation !== []) {
            $file .= "    protected const array ITEM_VALIDATION = "
                . PhpLiteral::export($itemValidation) . ";\n";
        }
        $file .= "}\n";
        $this->addFile($files, $entry['path'], $file);
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
                ? preg_replace('/[^A-Za-z0-9]+/', '', ucwords($value, '-_ '))
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
                    $this->bodyType($operation->requestBody->schema, $operation->operationId),
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
        if (count($withSchema) > 1) {
            throw new InvalidArgumentException(
                "Expected at most one body-bearing successful response for $operation->operationId",
            );
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
            return $this->inlineObjectType($schema, $hint, $files, 'Response');
        }
        if ($schema->kind === SchemaKind::AllOf) {
            return $this->inlineAllOfType($schema, $hint, $files, 'Response');
        }
        return $this->typeFor($schema, $hint, $files, 'Response');
    }

    private function operationResponseHint(OperationDefinition $operation, ?string $status = null): string
    {
        $name = ucfirst(PhpOperationNameResolver::resolve($operation->operationId));
        if ($status === null) {
            return $name . 'Response';
        }
        $statusName = preg_match('/^[A-Za-z]/', $status) === 1
            ? ucfirst(strtolower($status))
            : 'Status' . preg_replace('/[^A-Za-z0-9]/', '', $status);
        return $name . $statusName . 'ErrorResponse';
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
        $candidate = preg_replace_callback(
            '/[^A-Za-z0-9_]+([A-Za-z0-9_]?)/',
            static fn (array $match): string => strtoupper($match[1] ?? ''),
            $wireName,
        );
        if (!is_string($candidate) || $candidate === '') {
            $candidate = 'property';
        }
        if (preg_match('/^[A-Za-z_]/', $candidate) !== 1) {
            $candidate = '_' . $candidate;
        }
        if (in_array(strtolower($candidate), self::PHP_KEYWORDS, true)) {
            $candidate .= '_';
        }
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
