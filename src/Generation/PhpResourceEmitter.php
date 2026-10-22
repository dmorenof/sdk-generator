<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/** Emits a shared API connection and resource classes grouped by route path. */
final readonly class PhpResourceEmitter
{
    /**
     * @param array<string,array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> $successTypes
     * @param array<string,array<string,string>> $errorTypes
     */
    public function __construct(
        private string $namespace,
        private array $securitySchemes,
        private array $successTypes,
        private array $errorTypes,
        private PhpModelNameResolver $modelNames,
    ) {}

    /** @return array<string,string> */
    public function emit(ApiContract $contract): array
    {
        $resolved = PhpResourceNameResolver::resolve($contract);
        $files = ['src/CasafariSDK.php' => $this->renderConnector()];
        foreach ($resolved['nodes'] as $node) {
            if (strtolower($node['segments'][0]) === 'api' && count($node['segments']) > 1) {
                array_shift($node['segments']);
            }
            $path = 'src/Api/' . implode('/', array_slice($node['segments'], 0, -1))
                . (count($node['segments']) > 1 ? '/' : '')
                . end($node['segments']) . '.php';
            $files[$path] = $this->renderResource($node, $resolved['operationMethods']);
        }
        return $files;
    }

    private function renderConnector(): string
    {
        $source = $this->header($this->namespace)
            . "class CasafariSDK extends \\{$this->namespace}\\Core\\ApiClient\n{\n"
            . "    private static ?self \$connection = null;\n\n"
            . "    /**\n"
            . "     * Ready-to-use SDK, Guzzle by default, PSR-18 injection optional.\n"
            . "     * @param array<string, string> \$headers\n"
            . "     */\n"
            . "    public static function connect(\n"
            . "        string \$baseUrl,\n"
            . "        ?\\Psr\\Http\\Client\\ClientInterface \$httpClient = null,\n"
            . "        array \$headers = [],\n"
            . "        ?\\{$this->namespace}\\Core\\SecurityCredentials \$securityCredentials = null,\n"
            . "        ?\\{$this->namespace}\\Transport\\TransportInterface \$transport = null,\n"
            . "    ): self {\n"
            . "        \$client = \$httpClient ?? new \\GuzzleHttp\\Client(['http_errors' => false]);\n"
            . "        \$transport ??= new \\{$this->namespace}\\Transport\\Psr18Transport(\$client, \$headers);\n"
            . "        return self::\$connection = new self(\$transport, \$baseUrl, \$securityCredentials);\n"
            . "    }\n\n"
            . "    public static function connection(): self\n"
            . "    {\n"
            . "        return self::\$connection ?? throw new \\LogicException('Call CasafariSDK::connect() before using API operations.');\n"
            . "    }\n\n";
        $source .= $this->renderExecuteOperation();
        $source .= $this->renderSendOperation();
        return $source . "}\n";
    }

    private function renderExecuteOperation(): string
    {
        $source = <<<'PHP'
    /** @param array<string,mixed> $pathParameters @param array<string,mixed> $queryParameters @param array<string,mixed> $headerParameters @param list<array<string,list<string>>> $securityRequirements @param list<int> $successStatuses @param array<int|string,string> $errorTypes @param array<string,array<string,mixed>> $parameterValidations */
    protected static function executeOperation(
        self $connection,
        string $operation,
        string $method,
        string $path,
        array $pathParameters,
        array $queryParameters,
        array $headerParameters,
        ?\NS\Core\DTO $body,
        array $securityRequirements,
        array $successStatuses,
        array $errorTypes,
        ?string $responseType,
        bool $schemaNullable,
        bool $noContentAllowed,
        array $parameterValidations,
    ): mixed {
        $parameters = $pathParameters + $queryParameters + $headerParameters;
        foreach ($parameterValidations as $name => $validation) {
            $value = $parameters[$name] ?? null;
            if ($value !== null) {
                \NS\Core\DTO::validateMapConstraints($value, $validation, $operation . '.' . $name);
            }
        }

        foreach ($pathParameters as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
        }
        $query = [];
        foreach ($queryParameters as $name => $value) {
            if ($value !== null) {
                $query[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        $headers = ['Accept' => 'application/json'];
        foreach ($headerParameters as $name => $value) {
            if ($value !== null) {
                $headers[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        $bodyJson = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $bodyJson = json_encode($body, JSON_THROW_ON_ERROR);
        }

        return $connection->sendOperation(
            $method,
            $path,
            $operation,
            $securityRequirements,
            $query,
            $headers,
            $bodyJson,
            $successStatuses,
            $errorTypes,
            $responseType,
            $schemaNullable,
            $noContentAllowed,
        );
    }

PHP;
        return str_replace('\\NS\\', '\\' . $this->namespace . '\\', $source);
    }

    /**
     * @param array{segments:list<string>,path:string,operations:list<OperationDefinition>} $node
     * @param array<string,string> $operationMethods
     */
    private function renderResource(array $node, array $operationMethods): string
    {
        $segments = $node['segments'];
        $class = end($segments);
        $parentSegments = array_slice($segments, 0, -1);
        $namespace = $this->namespace . '\\Api'
            . ($parentSegments === [] ? '' : '\\' . implode('\\', $parentSegments));
        $source = $this->header($namespace)
            . "final class $class extends \\{$this->namespace}\\CasafariSDK\n{\n"
            . "    private function __construct() {}\n\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing resource method for $operation->operationId.");
            $source .= $this->renderOperation($operation, $method);
        }
        return $source . "}\n";
    }

    private function renderOperation(OperationDefinition $operation, string $method): string
    {
        $parameters = [];
        foreach ($operation->parameters as $parameter) {
            $resolved = PhpParameterTypeResolver::resolve(
                $parameter->schema,
                $operation->operationId . '.' . $parameter->wireName,
            );
            $name = self::variableName($parameter->wireName);
            $parameters[] = [
                'wireName' => $parameter->wireName,
                'name' => $name,
                'location' => $parameter->location,
                'type' => $resolved['type'],
                'nullable' => !$parameter->required || $resolved['nullable'],
                'required' => $parameter->required,
                'validation' => $parameter->schema->validation
                    + ($resolved['enumValues'] !== [] ? ['enum' => $resolved['enumValues']] : []),
            ];
        }
        if ($operation->requestBody !== null) {
            $body = $operation->requestBody;
            if ($body->schema->kind !== SchemaKind::Reference || $body->schema->nullable) {
                throw new InvalidArgumentException("Unsupported request body at $operation->operationId");
            }
            $parameters[] = [
                'wireName' => 'body',
                'name' => 'body',
                'location' => 'body',
                'type' => $this->modelNames->fqcn($body->schema->reference
                    ?? throw new InvalidArgumentException("Missing request body reference at $operation->operationId")),
                'nullable' => !$body->required,
                'required' => $body->required,
                'validation' => [],
            ];
        }
        usort($parameters, static fn (array $a, array $b): int => (int) $b['required'] <=> (int) $a['required']);

        $argumentList = [];
        $seenArguments = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'];
            if (isset($seenArguments[strtolower($name)])) {
                throw new InvalidArgumentException("Duplicate parameter $name at $operation->operationId");
            }
            $seenArguments[strtolower($name)] = true;
            $argumentList[] = ($parameter['nullable'] ? '?' : '') . $parameter['type'] . ' $' . $name
                . ($parameter['required'] ? '' : ' = null');
        }

        $success = $this->successTypes[$operation->operationId]
            ?? throw new InvalidArgumentException("Missing success response metadata at $operation->operationId");
        $returnType = $success['type'] ?? 'void';
        if ($success['nullable'] && $success['type'] !== null) {
            $returnType = '?' . $returnType;
        }
        $pathParameters = [];
        $queryParameters = [];
        $headerParameters = [];
        $parameterValidations = [];
        $body = 'null';
        foreach ($parameters as $parameter) {
            $name = '$' . $parameter['name'];
            if ($parameter['validation'] !== []) {
                $parameterValidations[$parameter['wireName']] = $parameter['validation'];
            }
            if ($parameter['location'] === 'path') {
                $pathParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'query') {
                $queryParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'header') {
                $headerParameters[$parameter['wireName']] = $name;
            } elseif ($parameter['location'] === 'body') {
                $body = $name;
            }
        }
        $pathParameters = self::formatParameterMap($pathParameters);
        $queryParameters = self::formatParameterMap($queryParameters);
        $headerParameters = self::formatParameterMap($headerParameters);
        $successStatuses = array_values(array_map(
            static fn ($response): int => (int) $response->status,
            array_filter(
                $operation->responses,
                static fn ($response): bool => preg_match('/^2[0-9]{2}$/', $response->status) === 1,
            ),
        ));
        $call = "self::executeOperation(\n"
            . "            \\{$this->namespace}\\CasafariSDK::connection(),\n"
            . '            ' . var_export(strtoupper($operation->method) . ' ' . $operation->path, true) . ",\n"
            . '            ' . var_export($operation->method, true) . ",\n"
            . '            ' . var_export($operation->path, true) . ",\n"
            . "            $pathParameters,\n"
            . "            $queryParameters,\n"
            . "            $headerParameters,\n"
            . "            $body,\n"
            . '            ' . PhpLiteral::export($operation->securityRequirements) . ",\n"
            . '            ' . PhpLiteral::export($successStatuses) . ",\n"
            . '            ' . PhpLiteral::export($this->errorTypes[$operation->operationId] ?? []) . ",\n"
            . '            ' . var_export($success['type'], true) . ",\n"
            . '            ' . PhpLiteral::export($success['schemaNullable']) . ",\n"
            . '            ' . PhpLiteral::export($success['noContentAllowed']) . ",\n"
            . '            ' . PhpLiteral::export($parameterValidations) . ",\n"
            . "        )";
        $source = "    public static function $method(" . implode(', ', $argumentList) . "): $returnType\n    {\n";
        $source .= $success['type'] === null
            ? "        $call;\n        return;\n"
            : "        return $call;\n";
        return $source . "    }\n\n";
    }

    /** @param array<string,string> $parameters */
    private static function formatParameterMap(array $parameters): string
    {
        $entries = [];
        foreach ($parameters as $wireName => $variable) {
            $entries[] = var_export($wireName, true) . ' => ' . $variable;
        }
        return '[' . implode(', ', $entries) . ']';
    }

    private function renderSendOperation(): string
    {
        $schemes = [];
        foreach ($this->securitySchemes as $scheme) {
            $schemes[$scheme->name] = [
                'type' => $scheme->type,
                'scheme' => $scheme->scheme,
                'location' => $scheme->location,
                'parameterName' => $scheme->parameterName,
            ];
        }
        return "    /** @param list<array<string,list<string>>> \$securityRequirements */\n"
            . "    public function sendOperation(\n"
            . "        string \$method,\n"
            . "        string \$path,\n"
            . "        string \$operation,\n"
            . "        array \$securityRequirements,\n"
            . "        array \$query,\n"
            . "        array \$headers,\n"
            . "        ?string \$body,\n"
            . "        array \$successStatuses,\n"
            . "        array \$errorTypes,\n"
            . "        ?string \$responseType,\n"
            . "        bool \$schemaNullable,\n"
            . "        bool \$noContentAllowed,\n"
            . "    ): mixed {\n"
            . "        \\{$this->namespace}\\Core\\Security::apply(\n"
            . "            \$this->securityCredentials,\n"
            . "            \$securityRequirements,\n"
            . '            ' . PhpLiteral::export($schemes) . ",\n"
            . "            \$headers,\n"
            . "            \$query,\n"
            . "        );\n"
            . "        \$url = rtrim(\$this->baseUrl, '/') . \$path;\n"
            . "        if (\$query !== []) {\n"
            . "            \$url .= '?' . http_build_query(\$query, '', '&', PHP_QUERY_RFC3986);\n"
            . "        }\n"
            . "        \$response = \$this->transport->send(\$method, \$url, \$headers, \$body);\n"
            . "        if (!in_array(\$response->status, \$successStatuses, true)) {\n"
            . "            throw \\{$this->namespace}\\Core\\ApiException::fromResponse(\n"
            . "                \$response,\n"
            . "                \$operation,\n"
            . "                \$errorTypes[\$response->status] ?? \$errorTypes['default'] ?? null,\n"
            . "            );\n"
            . "        }\n"
            . "        if (\$responseType === null) {\n"
            . "            return null;\n"
            . "        }\n"
            . "        if (\$noContentAllowed && \$response->body === '') {\n"
            . "            return null;\n"
            . "        }\n"
            . "        return \\{$this->namespace}\\Support\\JsonHydrator::decode(\n"
            . "            \$response->body,\n"
            . "            \$responseType,\n"
            . "            \$schemaNullable,\n"
            . "        );\n"
            . "    }\n\n";
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private static function variableName(string $wireName): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $wireName) === 1) {
            return $wireName;
        }
        $name = lcfirst(PhpResourceNameResolver::pascal($wireName));
        return $name;
    }
}
