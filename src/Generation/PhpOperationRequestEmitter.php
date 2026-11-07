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
        /** @var array<string,string> */
        private array $bodyTypes = [],
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
     * Executable SDK arguments follow the URL tree, not operation IDs or
     * inferred API groups. GET /v1/facturas => GetParams\V1\Facturas;
     * GET /v1/facturas/{id} => GetParams\V1\Facturas\ById.
     *
     * A body-only operation uses the original referenced schema DTO;
     * if query/header parameters exist, the verb-specific Params DTO holds
     * those parameters and optionally the typed body.
     *
     * @return array{files:array<string,string>,types:array<string,array{identifier:?array,payload:?array}>}
     */
    public function emitExecutable(ApiContract $contract): array
    {
        $files = [];
        $types = [];
        $routes = PhpRouteNameResolver::resolve($contract);
        $names = $this->modelNames ?? new PhpModelNameResolver($this->namespace, $contract->models);
        foreach ($contract->operations as $operation) {
            $pathParameters = array_values(array_filter($operation->parameters,
                static fn ($parameter): bool => $parameter->location === 'path'));
            $otherParameters = array_values(array_filter($operation->parameters,
                static fn ($parameter): bool => $parameter->location !== 'path'));

            $segments = $routes['parameterSegments'][$operation->operationId];
            $path = implode('/', $segments);
            $namespace = implode('\\', $segments);
            $identifier = null;
            if (count($pathParameters) === 1) {
                $parameter = $pathParameters[0];
                $resolved = PhpParameterTypeResolver::resolve(
                    $parameter->schema, $operation->operationId . '.' . $parameter->wireName,
                );
                $variable = PhpNameTransliterator::property($parameter->wireName);
                $identifier = [
                    'kind' => 'scalar',
                    'type' => $resolved['type'],
                    'name' => $variable === 'payload' || $variable === 'params'
                        ? 'identifier' : $variable,
                    'wireName' => $parameter->wireName,
                ];
            } elseif (count($pathParameters) > 1) {
                $type = '\\' . $this->namespace . '\\PathParams\\' . $namespace;
                $filePath = "src/PathParams/$path.php";
                $source = $this->render(
                    $operation, end($segments), $this->namespace . '\\PathParams'
                        . (count($segments) > 1 ? '\\' . implode('\\', array_slice($segments, 0, -1)) : ''),
                    $contract, $pathParameters, false,
                );
                if (isset($files[$filePath]) && $files[$filePath] !== $source) {
                    throw new InvalidArgumentException(
                        "Conflicting path parameter definitions for $filePath."
                    );
                }
                $files[$filePath] = $source;
                $identifier = ['kind' => 'dto', 'type' => $type,
                    'name' => 'identifier', 'wireName' => null];
            }

            $payload = null;
            if ($otherParameters !== []) {
                $verb = ucfirst(strtolower($operation->method));
                $root = $verb . 'Params';
                $fullType = '\\' . $this->namespace . '\\' . $root . '\\' . $namespace;
                $filePath = "src/$root/$path.php";
                $ns = $this->namespace . '\\' . $root
                    . (count($segments) > 1 ? '\\' . implode('\\', array_slice($segments, 0, -1)) : '');
                if (isset($files[$filePath])) {
                    throw new InvalidArgumentException("Duplicate operation parameters: $filePath");
                }
                $files[$filePath] = $this->render(
                    $operation, end($segments), $ns, $contract, $otherParameters, true,
                );
                $hasRequired = count(array_filter($otherParameters,
                    static fn ($p): bool => $p->required)) > 0;
                $payload = [
                    'kind' => 'dto', 'type' => $fullType,
                    'required' => $hasRequired || ($operation->requestBody?->required ?? false),
                ];
            } elseif ($operation->requestBody !== null) {
                $body = $operation->requestBody;
                $payload = [
                    'kind' => 'body',
                    'type' => $this->bodyTypes[$operation->operationId]
                        ?? throw new InvalidArgumentException(
                            "Request body has no generated type at $operation->operationId"
                        ),
                    'required' => $body->required,
                ];
            }
            $types[$operation->operationId] = [
                'identifier' => $identifier,
                'payload' => $payload,
            ];
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
                $parameter->schema, $operation->operationId . '.' . $parameter->wireName, $parameter->location === 'query',
            );
            $nullable = !$parameter->required || $resolved['nullable'];
            if (isset($resolved['phpDoc'])) {
                $fields[] = '    /** @var ' . $resolved['phpDoc'] . ($nullable ? '|null' : '') . ' */';
            }
            $fields[] = '    public ' . ($nullable ? '?' : '') . $resolved['type'] . ' $' . $field . ';';
            $rules = $resolved['validation'] ?? $parameter->schema->validation;
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
            $type = $this->bodyTypes[$operation->operationId]
                ?? ($body->schema->kind === SchemaKind::Reference && $body->schema->reference !== null
                    ? ($this->modelNames ?? new PhpModelNameResolver($this->namespace, $contract->models))
                        ->fqcn($body->schema->reference)
                    : throw new InvalidArgumentException("Missing inline request body type at $operation->operationId"));
            $fields[] = '    public ' . ($body->required ? '' : '?') . $type . ' $body;';
            if ($body->required) {
                $required[] = 'body';
            }
        }
        $source = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n"
            . "final class $class extends \\Dmorenof\\SdkRuntime\\DTO\n{\n"
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
