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
                $operation->parameters, true,
            );
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Generate DTOs only for composite identifiers and request payloads.
     * Direct body-only requests reuse the schema's original DTO.
     *
     * @param array<string,string> $overrides
     * @return array{files:array<string,string>,types:array<string,array{identifier:?array,payload:?array}>}
     */
    public function emitExecutable(ApiContract $contract, array $overrides = []): array
    {
        $files = [];
        $types = [];
        $occupied = [];
        foreach (PhpApiGroupingResolver::resolve($contract, $overrides) as $group => $entries) {
            foreach ($entries as $entry) {
                $operation = $entry['operation'];
                $pathParameters = array_values(array_filter($operation->parameters,
                    static fn ($parameter): bool => $parameter->location === 'path'));
                $otherParameters = array_values(array_filter($operation->parameters,
                    static fn ($parameter): bool => $parameter->location !== 'path'));
                $segments = self::segments($operation->path);
                $relative = array_slice($segments, count(self::segments($entry['prefix'])));
                $folders = [];
                foreach ($relative as $part) {
                    if (preg_match('/^\{[^{}]+\}$/D', $part) !== 1) {
                        $folders[] = PhpResourceNameResolver::pascal($part);
                    }
                }
                $directory = implode('/', [$group, ...$folders]);
                $namespace = $this->namespace . '\\DTOs\\' . str_replace('/', '\\', $directory);

                $identifier = null;
                if (count($pathParameters) === 1) {
                    $parameter = $pathParameters[0];
                    $resolved = PhpParameterTypeResolver::resolve($parameter->schema,
                        $operation->operationId . '.' . $parameter->wireName);
                    $identifier = [
                        'kind' => 'scalar',
                        'type' => $resolved['type'],
                        'name' => PhpNameTransliterator::property($parameter->wireName) === 'payload'
                            ? 'identifier' : PhpNameTransliterator::property($parameter->wireName),
                        'wireName' => $parameter->wireName,
                    ];
                } elseif (count($pathParameters) > 1) {
                    $class = self::reserve($occupied, $directory, $operation, 'Identifier');
                    $files["src/DTOs/$directory/$class.php"] = $this->render(
                        $operation, $class, $namespace, $contract, $pathParameters, false,
                    );
                    $identifier = [
                        'kind' => 'dto',
                        'type' => '\\' . $namespace . '\\' . $class,
                        'name' => 'identifier',
                        'wireName' => null,
                    ];
                }

                $payload = null;
                if ($otherParameters !== []) {
                    $class = self::reserve($occupied, $directory, $operation, 'Payload');
                    $files["src/DTOs/$directory/$class.php"] = $this->render(
                        $operation, $class, $namespace, $contract, $otherParameters, true,
                    );
                    $hasRequired = count(array_filter($otherParameters, static fn ($p): bool => $p->required)) > 0;
                    $payload = [
                        'kind' => 'dto',
                        'type' => '\\' . $namespace . '\\' . $class,
                        'required' => $hasRequired || ($operation->requestBody?->required ?? false),
                    ];
                } elseif ($operation->requestBody !== null) {
                    $body = $operation->requestBody;
                    if ($body->schema->kind !== SchemaKind::Reference
                        || $body->schema->nullable || $body->schema->reference === null) {
                        throw new InvalidArgumentException("Unsupported request body at $operation->operationId");
                    }
                    $payload = [
                        'kind' => 'body',
                        'type' => ($this->modelNames ?? new PhpModelNameResolver($this->namespace, $contract->models))
                            ->fqcn($body->schema->reference),
                        'required' => $body->required,
                    ];
                }
                $types[$operation->operationId] = ['identifier' => $identifier, 'payload' => $payload];
            }
        }
        ksort($files, SORT_STRING);
        return ['files' => $files, 'types' => $types];
    }

    /** @param array<string,bool> $occupied */
    private static function reserve(array &$occupied, string $directory, OperationDefinition $operation, string $suffix): string
    {
        $class = ucfirst(strtolower($operation->method)) . $suffix;
        $path = strtolower("src/DTOs/$directory/$class.php");
        if (isset($occupied[$path])) {
            $class .= ucfirst(substr(hash('sha256', $operation->method . ' ' . $operation->path), 0, 10));
            $path = strtolower("src/DTOs/$directory/$class.php");
        }
        if (isset($occupied[$path])) {
            throw new InvalidArgumentException("Ambiguous operation DTO for $operation->path");
        }
        $occupied[$path] = true;
        return $class;
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

    private function render(OperationDefinition $operation, string $class, string $namespace, ApiContract $contract, array $parameters, bool $includeBody): string
    {
        $fields = [];
        $required = [];
        $validation = [];
        $propertyMap = [];
        $wirePropertyMap = [];
        $locations = ['path' => [], 'query' => [], 'header' => []];
        $occupied = [];
        $wireOccurrences = [];
        foreach ($parameters as $parameter) {
            $wireOccurrences[$parameter->wireName] = ($wireOccurrences[$parameter->wireName] ?? 0) + 1;
        }
        if ($includeBody && $operation->requestBody !== null) {
            $occupied['body'] = true;
        }
        foreach ($parameters as $parameter) {
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
        if ($includeBody && $operation->requestBody !== null) {
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
