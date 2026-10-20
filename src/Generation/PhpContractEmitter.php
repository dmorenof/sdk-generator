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
        $names = [];
        foreach ($contract->models as $model) {
            $this->checkName($model->name, 'model');
            $key = strtolower($model->name);
            if (isset($names[$key])) {
                throw new InvalidArgumentException("Duplicate PHP model name: $model->name");
            }
            $names[$key] = true;
            if ($model->schema->kind !== SchemaKind::Object) {
                throw new InvalidArgumentException("Unsupported non-object model: $model->name");
            }
            if ($model->schema->additionalProperties !== null) {
                throw new InvalidArgumentException("Unsupported additionalProperties in model: $model->name");
            }
            $files['src/Models/' . $model->name . '.php'] = $this->renderModel($model, $files);
        }

        $files['src/Api/Operations.php'] = $this->renderOperations($contract);
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

    /** @param array<string,string> $files */
    private function renderModel(ModelDefinition $model, array &$files): string
    {
        $fields = [];
        $seen = [];
        foreach ($model->schema->properties as $property) {
            $this->checkName($property->wireName, 'model property');
            $key = strtolower($property->wireName);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Duplicate PHP property in $model->name: $property->wireName");
            }
            $seen[$key] = true;
            $type = $this->typeFor(
                $property->schema, $model->name . ucfirst($property->wireName), $files,
            );
            if ($property->schema->nullable) {
                $type = '?' . $type;
            }
            // Deliberately uninitialized when absent, including optional fields.
            // Calling get_object_vars() preserves the absent-vs-null distinction.
            $fields[] = '    public ' . $type . ' $' . $property->wireName . ';';
        }

        $required = [];
        foreach ($model->schema->properties as $property) {
            if ($property->required) {
                $required[] = $property->wireName;
            }
        }
        $source = $this->header($this->namespace . '\\Models')
            . 'final class ' . $model->name . ' extends \\' . $this->namespace . "\\Core\\DTO\n{\n"
            . "    public const array REQUIRED_FIELDS = " . var_export($required, true) . ";\n\n";
        if ($fields !== []) {
            $source .= implode("\n", $fields) . "\n\n";
        }
        $source .= "}\n";
        return $source;
    }

    /** @param array<string,string> $files */
    private function typeFor(SchemaDefinition $schema, string $hint, array &$files): string
    {
        if ($schema->enumValues !== []) {
            if (!in_array($schema->kind, [SchemaKind::String, SchemaKind::Integer], true)) {
                throw new InvalidArgumentException("Only string/integer backed enums are supported at $hint");
            }
            $this->checkName($hint, 'enum');
            $enumName = $hint . 'Enum';
            $this->addFile($files, 'src/Enums/' . $enumName . '.php', $this->renderEnum($enumName, $schema));
            return '\\' . $this->namespace . '\\Enums\\' . $enumName;
        }
        return match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            SchemaKind::Reference => $this->reference($schema),
            SchemaKind::Array => $this->collectionType($schema, $hint, $files),
            default => throw new InvalidArgumentException("Unsupported inline/object schema at $hint"),
        };
    }

    private function reference(SchemaDefinition $schema): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        $this->checkName($schema->reference, 'model reference');
        return '\\' . $this->namespace . '\\Models\\' . $schema->reference;
    }

    /** @param array<string,string> $files */
    private function collectionType(SchemaDefinition $schema, string $hint, array &$files): string
    {
        if ($schema->items === null) {
            throw new InvalidArgumentException("Missing collection items at $hint");
        }
        $this->checkName($hint, 'collection');
        $name = $hint . 'Array';
        $itemType = $this->typeFor($schema->items, $hint . 'Item', $files);
        $expected = match ($itemType) {
            'string' => "'string'",
            'int' => "'integer'",
            'float' => "'double'",
            'bool' => "'boolean'",
            default => $itemType . '::class',
        };
        $file = $this->header($this->namespace . '\\Collections')
            . "/** @extends \\TypedArray\\TypedArray<" . $itemType . "> */\n"
            . "final class $name extends \\{$this->namespace}\\Core\\TypedArray\n"
            . "{\n"
            . "    protected string \$expected_type = $expected;\n"
            . "}\n";
        $this->addFile($files, 'src/Collections/' . $name . '.php', $file);
        return '\\' . $this->namespace . '\\Collections\\' . $name;
    }

    private function renderEnum(string $name, SchemaDefinition $schema): string
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
            $this->checkName($case, 'enum case');
            if (isset($seen[strtolower($case)])) {
                throw new InvalidArgumentException("Duplicate PHP enum case in $name: $case");
            }
            $seen[strtolower($case)] = true;
            $cases[] = '    case ' . $case . ' = ' . var_export($value, true) . ';';
        }
        return $this->header($this->namespace . '\\Enums')
            . "enum $name: $backing\n{\n" . implode("\n", $cases) . "\n}\n";
    }

    private function renderOperations(ApiContract $contract): string
    {
        $methods = [];
        $seen = [];
        foreach ($contract->operations as $operation) {
            $this->checkName($operation->operationId, 'operationId');
            if (isset($seen[strtolower($operation->operationId)])) {
                throw new InvalidArgumentException("Duplicate PHP operation name: $operation->operationId");
            }
            $seen[strtolower($operation->operationId)] = true;
            $methodArgs = [];
            foreach ($operation->parameters as $parameter) {
                $this->checkName($parameter->wireName, 'operation parameter');
                // Parameters must be simple scalars in the initial generated API.
                $type = $this->primitiveParameterType($parameter->schema, $operation->operationId);
                $methodArgs[] = [$parameter->wireName, $type, $parameter->required];
            }
            if ($operation->requestBody !== null) {
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
            $return = $this->successResponseType($operation);
            $methods[] = '    public function ' . $operation->operationId
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

    private function successResponseType(OperationDefinition $operation): string
    {
        $success = [];
        foreach ($operation->responses as $response) {
            if (preg_match('/^2[0-9]{2}$/', $response->status) === 1) {
                $success[] = $response;
            }
        }
        if (count($success) !== 1) {
            throw new InvalidArgumentException("Expected one successful response for $operation->operationId");
        }
        $response = $success[0];
        if ($response->schema === null) {
            return 'void';
        }
        if ($response->schema->kind !== SchemaKind::Reference) {
            throw new InvalidArgumentException("Unsupported non-model success response for $operation->operationId");
        }
        return $this->reference($response->schema);
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
        $literal = var_export($routes, true);
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

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }
}
