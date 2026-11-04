<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/**
 * One source of truth for typed operation Request DTOs.
 *
 * The contract-only emitter uses Api\Requests; executable SDKs use route-based
 * namespaces beneath DTOs. Body-only operations reuse their schema model.
 */
final readonly class PhpOperationRequestEmitter
{
    public function __construct(
        private string $namespace,
        private ?PhpModelNameResolver $modelNames = null,
    ) {}

    /** @return array<string,string> */
    public function emit(ApiContract $contract): array
    {
        $files = [];
        foreach ($contract->operations as $operation) {
            if ($operation->parameters === []) {
                continue;
            }
            $class = self::name($operation);
            $path = "src/Api/Requests/$class.php";
            if (isset($files[strtolower($path)])) {
                throw new InvalidArgumentException("Duplicate generated Request: $path");
            }
            $files[$path] = $this->render(
                $operation, $class, $this->namespace . '\\Api\\Requests', $contract,
            );
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * @param array<string,string> $overrides
     * @return array{
     *   files:array<string,string>,
     *   types:array<string,array{type:string,required:bool}>
     * }
     */
    public function emitExecutable(ApiContract $contract, array $overrides = []): array
    {
        $files = [];
        $types = [];
        $occupied = [];
        foreach (PhpApiGroupingResolver::resolve($contract, $overrides) as $group => $entries) {
            foreach ($entries as $entry) {
                $operation = $entry['operation'];
                if ($operation->parameters === []) {
                    continue;
                }
                $pathParts = self::segments($operation->path);
                $prefixParts = self::segments($entry['prefix']);
                $relative = array_slice($pathParts, count($prefixParts));
                $folders = [];
                $placeholders = '';
                foreach ($relative as $part) {
                    if (preg_match('/^\{([^{}]+)\}$/D', $part, $matches) === 1) {
                        $placeholders .= 'By' . PhpResourceNameResolver::pascal($matches[1]);
                    } else {
                        $folders[] = PhpResourceNameResolver::pascal($part);
                    }
                }
                $class = ucfirst(strtolower($operation->method)) . $placeholders . 'Request';
                $namespaceParts = [$group, ...$folders];
                $directory = implode('/', $namespaceParts);
                $key = strtolower("src/DTOs/$directory/$class.php");
                if (isset($occupied[$key])) {
                    $class .= ucfirst(substr(hash('sha256', $operation->method . ' ' . $operation->path), 0, 10));
                    $key = strtolower("src/DTOs/$directory/$class.php");
                }
                if (isset($occupied[$key])) {
                    throw new InvalidArgumentException("Ambiguous operation Request DTO for $operation->path");
                }
                $occupied[$key] = true;
                $relativePath = "src/DTOs/$directory/$class.php";
                $namespace = $this->namespace . '\\DTOs\\' . implode('\\', $namespaceParts);
                $files[$relativePath] = $this->render($operation, $class, $namespace, $contract);
                $types[$operation->operationId] = [
                    'type' => '\\' . $namespace . '\\' . $class,
                    'required' => self::requiresRequest($operation),
                ];
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'types' => $types];
    }

    public static function name(OperationDefinition $operation): string
    {
        return ucfirst(PhpOperationNameResolver::resolve($operation->operationId)) . 'Request';
    }

    public function fqcn(OperationDefinition $operation): string
    {
        return '\\' . $this->namespace . '\\Api\\Requests\\' . self::name($operation);
    }

    public static function requiresRequest(OperationDefinition $operation): bool
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter->required) {
                return true;
            }
        }
        return $operation->requestBody?->required ?? false;
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
    }

    private function render(OperationDefinition $operation, string $class, string $namespace, ApiContract $contract): string
    {
        $fields = [];
        $required = [];
        $validation = [];
        $propertyMap = [];
        $wirePropertyMap = [];
        $locations = ['path' => [], 'query' => [], 'header' => []];
        $occupied = [];
        $wireOccurrences = [];
        foreach ($operation->parameters as $parameter) {
            $wireOccurrences[$parameter->wireName] = ($wireOccurrences[$parameter->wireName] ?? 0) + 1;
        }
        if ($operation->requestBody !== null) {
            $occupied['body'] = true;
        }
        foreach ($operation->parameters as $parameter) {
            if (!isset($locations[$parameter->location])) {
                throw new InvalidArgumentException("Unsupported HTTP parameter location: $parameter->location");
            }
            if (isset($locations[$parameter->location][$parameter->wireName])) {
                throw new InvalidArgumentException(
                    "Duplicate $parameter->location parameter $parameter->wireName at $operation->operationId",
                );
            }
            $base = PhpNameTransliterator::property($parameter->wireName);
            $field = $base;
            if (isset($occupied[strtolower($field)])) {
                $field = PhpNameTransliterator::property($parameter->location . ucfirst($base));
            }
            $index = 2;
            $candidate = $field;
            while (isset($occupied[strtolower($field)])) {
                $field = $candidate . $index++;
            }
            $occupied[strtolower($field)] = true;
            $locations[$parameter->location][$parameter->wireName] = $field;
            if ($field !== $parameter->wireName && $wireOccurrences[$parameter->wireName] === 1) {
                $propertyMap[$parameter->wireName] = $field;
                $wirePropertyMap[$field] = $parameter->wireName;
            }
            $resolved = PhpParameterTypeResolver::resolve(
                $parameter->schema, $operation->operationId . '.' . $parameter->wireName,
            );
            $nullable = !$parameter->required || $resolved['nullable'];
            $fields[] = '    public ' . ($nullable ? '?' : '') . $resolved['type'] . ' $' . $field . ';';
            $rules = $parameter->schema->validation;
            if ($resolved['enumValues'] !== []) {
                $rules['enum'] = $resolved['enumValues'];
            }
            if ($rules !== []) {
                $validation[$field] = $rules;
            }
            if ($parameter->required) {
                $required[] = $field;
            }
        }
        if ($operation->requestBody !== null) {
            $body = $operation->requestBody;
            if ($body->schema->kind !== SchemaKind::Reference
                || $body->schema->nullable || $body->schema->reference === null) {
                throw new InvalidArgumentException("Unsupported operation Request body at $operation->operationId");
            }
            $type = ($this->modelNames ?? new PhpModelNameResolver($this->namespace, $contract->models))
                ->fqcn($body->schema->reference);
            $fields[] = '    public ' . ($body->required ? '' : '?') . $type . ' $body;';
            if ($body->required) {
                $required[] = 'body';
            }
        }
        $source = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n"
            . "final class $class extends \\Dmorenof\\SdkRuntime\\Request\n{\n"
            . '    public const array REQUIRED_FIELDS = ' . PhpLiteral::export($required) . ";\n"
            . '    public const array OPERATION_FIELDS = ' . PhpLiteral::export($locations) . ";\n\n";
        if ($propertyMap !== []) {
            $source .= '    protected const array PROPERTY_MAP = ' . PhpLiteral::export($propertyMap) . ";\n"
                . '    protected const array WIRE_PROPERTY_MAP = ' . PhpLiteral::export($wirePropertyMap) . ";\n\n";
        }
        if ($validation !== []) {
            $source .= '    protected const array VALIDATION = ' . PhpLiteral::export($validation) . ";\n\n";
        }
        return $source . implode("\n", $fields) . "\n}\n";
    }
}
