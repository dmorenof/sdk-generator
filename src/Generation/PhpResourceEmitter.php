<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;

/** Emits instance clients with inherited execution and typed resource methods. */
final readonly class PhpResourceEmitter
{
    /** Must equal the runtime's Operation::DEFAULTS; a test enforces it. */
    public const OPERATION_DEFAULTS = [
        'security' => [],
        'success' => [200],
        'errors' => [],
        'responseType' => null,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
    ];

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
        /** @var array<string,string> */
        private array $groupOverrides = [],
        /** @var array<string,array{identifier:?array,payload:?array}> operationId => public arguments */
        private array $requestTypes = [],
    ) {}

    /** @return array<string,string> */
    public function emit(ApiContract $contract): array
    {
        $resolved = PhpResourceNameResolver::resolve($contract);
        $files = [
            'src/Client.php' => $this->renderConnector(),
            'src/Configuration.php' => $this->renderConfiguration($contract),
            'src/' . PhpApiNameResolver::sdkClassFromTitle($contract->title) . '.php'
                => $this->renderSdkRoot($contract),
            'src/StaticClient.php' => $this->renderStaticConnector(),
        ];
        foreach (PhpApiGroupingResolver::resolve($contract, $this->groupOverrides) as $class => $entries) {
            $path = "src/$class.php";
            if (isset($files[$path]) || in_array(strtolower($class), ['client', 'configuration', 'staticclient'], true)
                || strtolower($class) === strtolower(PhpApiNameResolver::sdkClassFromTitle($contract->title))) {
                throw new InvalidArgumentException("Generated grouped API conflicts with $path.");
            }
            $files[$path] = $this->renderGroupedApi($class, $entries, $resolved, PhpApiNameResolver::sdkClassFromTitle($contract->title));
        }
        foreach ($resolved['nodes'] as $node) {
            if (strtolower($node['segments'][0]) === 'api' && count($node['segments']) > 1) {
                array_shift($node['segments']);
            }
            $path = 'src/Api/' . implode('/', array_slice($node['segments'], 0, -1))
                . (count($node['segments']) > 1 ? '/' : '')
                . end($node['segments']) . '.php';
            $files[$path] = $this->renderResource($node, $resolved['operationMethods']);
            $staticPath = 'src/StaticApi/' . implode('/', array_slice($node['segments'], 0, -1))
                . (count($node['segments']) > 1 ? '/' : '')
                . end($node['segments']) . '.php';
            $files[$staticPath] = $this->renderStaticResource($node, $resolved['operationMethods']);
        }
        return $files;
    }

    private function renderSdkRoot(ApiContract $contract): string
    {
        $class = PhpApiNameResolver::sdkClassFromTitle($contract->title);
        return $this->header($this->namespace)
            . "abstract class $class\n{\n"
            . "    public static function configure(Configuration \$configuration): void\n"
            . "    {\n        Client::configure(\$configuration);\n    }\n\n"
            . "    protected static function client(): Client\n"
            . "    {\n        return Client::current();\n    }\n"
            . "}\n";
    }

    private function renderConnector(): string
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
        $methods = <<<'PHP'

    private static ?self $currentConnection = null;

    /** Replace the current connection using a snapshot of the configuration. */
    public static function configure(Configuration $configuration): void
    {
        if ($configuration->baseUrl === '') {
            throw new \InvalidArgumentException('Configuration::baseUrl must not be empty.');
        }

        $client = self::connect(
            $configuration->baseUrl,
            $configuration->httpClient,
            $configuration->headers,
            $configuration->securityCredentials,
            $configuration->transport,
        );
        foreach ($configuration->middleware as $middleware) {
            $client->withMiddleware($middleware);
        }
        self::$currentConnection = $client;
    }

    public static function current(): self
    {
        return self::$currentConnection ?? throw new \LogicException(
            'Configure the SDK with Client::configure(Configuration) first.',
        );
    }

    /**
     * Transfer only initialized properties, preserving OpenAPI wire names.
     * Shared runtime DTO validation checks required and nested fields.
     *
     * @return array{
     *   path:array<string,bool|float|int|string|null>,
     *   query:array<string,bool|float|int|string|null>,
     *   header:array<string,bool|float|int|string|null>,
     *   body:?\Dmorenof\SdkRuntime\DTO
     * }
     */
    private static function operationFields(\Dmorenof\SdkRuntime\Request $dto): array
    {
        $dto->validateForRequest();
        $values = get_object_vars($dto);
        $bodyValue = $values['body'] ?? null;
        // An OpenAPI path parameter can itself be named "body".
        $body = $bodyValue instanceof \Dmorenof\SdkRuntime\DTO ? $bodyValue : null;
        /** @var array{path:array<string,bool|float|int|string|null>,query:array<string,bool|float|int|string|null>,header:array<string,bool|float|int|string|null>,body:?\Dmorenof\SdkRuntime\DTO} $result */
        $result = ['path' => [], 'query' => [], 'header' => [], 'body' => $body];
        /** @var array{path:array<string,string>,query:array<string,string>,header:array<string,string>} $locations */
        $locations = constant($dto::class . '::OPERATION_FIELDS');
        foreach ($locations as $location => $fields) {
            foreach ($fields as $wireName => $property) {
                if (!array_key_exists($property, $values)) {
                    continue;
                }
                $value = $values[$property];
                if ($value !== null && !is_scalar($value)) {
                    throw new \UnexpectedValueException("Non-scalar operation parameter: $wireName");
                }
                $result[$location][$wireName] = $value;
            }
        }
        return $result;
    }

    /**
     * Map identifier + payload to the shared sdk-runtime executor.
     * @param OperationSpec $operation
     */
    protected function executeArguments(
        array $operation,
        string|int|float|bool|\Dmorenof\SdkRuntime\Request|null $identifier = null,
        ?string $identifierName = null,
        ?\Dmorenof\SdkRuntime\Request $payload = null,
        ?\Dmorenof\SdkRuntime\DTO $body = null,
    ): mixed {
        /** @var array<string,bool|float|int|string|null> $path */
        $path = [];
        if ($identifier instanceof \Dmorenof\SdkRuntime\Request) {
            $path = self::operationFields($identifier)['path'];
        } elseif ($identifierName !== null) {
            $path[$identifierName] = $identifier;
        }
        /** @var array<string,bool|float|int|string|null> $query */
        $query = [];
        /** @var array<string,bool|float|int|string|null> $headers */
        $headers = [];
        if ($payload !== null) {
            $fields = self::operationFields($payload);
            $query = $fields['query'];
            $headers = $fields['header'];
            $body = $fields['body'];
        }
        return $this->executeOperation(
            $operation,
            pathParameters: $path,
            queryParameters: $query,
            headerParameters: $headers,
            body: $body,
        );
    }
PHP;

        return $this->header($this->namespace)
            . '/** @phpstan-import-type OperationSpec from \\Dmorenof\\SdkRuntime\\Operation */' . "\n"
            . 'class Client extends \\' . PhpContractEmitter::RUNTIME_NAMESPACE . "\\ApiClient\n{\n"
            . '    protected const array SECURITY_SCHEMES = ' . PhpLiteral::export($schemes) . ";\n"
            . $methods . "\n}\n";
    }

    private function renderConfiguration(ApiContract $contract): string
    {
        $baseUrl = PhpLiteral::export($contract->servers[0] ?? '');
        $body = <<<'PHP'
    public ?\Psr\Http\Client\ClientInterface $httpClient = null;

    /** @var array<string, string> */
    public array $headers = [];

    public ?\Dmorenof\SdkRuntime\Security\SecurityCredentials $securityCredentials = null;
    public ?\Dmorenof\SdkRuntime\Transport\TransportInterface $transport = null;

    /** @var list<\Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface> */
    public array $middleware = [];
PHP;

        return $this->header($this->namespace)
            . "final class Configuration\n{\n"
            . "    public string \$baseUrl = $baseUrl;\n\n"
            . $body . "\n}\n";
    }

    /**
     * @param list<array{operation:OperationDefinition,method:string,prefix:string}> $entries
     * @param array<string,mixed> $resolved
     */
    private function renderGroupedApi(string $class, array $entries, array $resolved, string $root): string
    {
        $source = $this->header($this->namespace) . "final class $class extends $root\n{\n";
        foreach ($entries as $entry) {
            $operation = $entry['operation'];
            $nodeKey = $resolved['operationNodes'][$operation->operationId];
            $segments = $resolved['nodes'][$nodeKey]['segments'];
            if (strtolower($segments[0]) === 'api' && count($segments) > 1) {
                array_shift($segments);
            }
            $resourceClass = '\\' . $this->namespace . '\\Api\\' . implode('\\', $segments);
            $resourceMethod = $resolved['operationMethods'][$operation->operationId];
            $label = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'],
                $operation->method . ' ' . $operation->path . ' [operationId: ' . $operation->operationId . ']');
            $source .= "    /** $label */\n";
            $source .= $this->renderOperation($operation, $entry['method'], $resourceClass, $resourceMethod, true);
        }
        return $source . "}\n";
    }

    /** The optional static facade shares one current connection per PHP execution scope. */
    private function renderStaticConnector(): string
    {
        $body = <<<'PHP'
final class StaticClient
{
    private static ?Client $connection = null;

    /** @param array<string, string> $headers */
    public static function connect(
        string $baseUrl,
        ?\Psr\Http\Client\ClientInterface $httpClient = null,
        array $headers = [],
        ?\Dmorenof\SdkRuntime\Security\SecurityCredentials $securityCredentials = null,
        ?\Dmorenof\SdkRuntime\Transport\TransportInterface $transport = null,
    ): void {
        self::configure(Client::connect($baseUrl, $httpClient, $headers, $securityCredentials, $transport));
    }

    public static function configure(Client $client): void
    {
        self::$connection = $client;
    }

    public static function client(): Client
    {
        return self::$connection ?? throw new \LogicException(
            'Configure the SDK with StaticClient::connect() or StaticClient::configure() first.',
        );
    }
}
PHP;
        return $this->header($this->namespace) . $body . "\n";
    }

    /**
     * @param array{segments:list<string>,path:string,operations:list<OperationDefinition>} $node
     * @param array<string,string> $operationMethods
     */
    private function renderStaticResource(array $node, array $operationMethods): string
    {
        $segments = $node['segments'];
        $class = end($segments);
        $parentSegments = array_slice($segments, 0, -1);
        $namespace = $this->namespace . '\\StaticApi'
            . ($parentSegments === [] ? '' : '\\' . implode('\\', $parentSegments));
        $resourceClass = '\\' . $this->namespace . '\\Api\\' . implode('\\', $segments);
        $source = $this->header($namespace) . "final class $class\n{\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing resource method for $operation->operationId.");
            $source .= $this->renderOperation($operation, $method, $resourceClass);
        }
        return $source . "}\n";
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
            . "final class $class extends \\{$this->namespace}\\Client\n{\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing resource method for $operation->operationId.");
            $source .= $this->renderOperation($operation, $method);
        }
        return $source . "}\n";
    }

    private function renderOperation(OperationDefinition $operation, string $method, ?string $resourceClass = null, ?string $resourceMethod = null, bool $grouped = false): string
    {
        $signature = $this->requestTypes[$operation->operationId]
            ?? throw new InvalidArgumentException("Missing operation signature: $operation->operationId");
        $identifier = $signature['identifier'];
        $payload = $signature['payload'];
        $arguments = [];
        $forward = [];
        if ($identifier !== null) {
            $arguments[] = $identifier['type'] . ' $' . $identifier['name'];
            $forward[] = '$' . $identifier['name'];
        }
        if ($payload !== null) {
            $arguments[] = ($payload['required'] ? '' : '?') . $payload['type'] . ' $payload'
                . ($payload['required'] ? '' : ' = null');
            $forward[] = '$payload';
        }
        $success = $this->successTypes[$operation->operationId]
            ?? throw new InvalidArgumentException("Missing success response metadata at $operation->operationId");
        $returnType = $success['type'] ?? 'void';
        if ($success['nullable'] && $success['type'] !== null) {
            $returnType = '?' . $returnType;
        }
        if ($resourceClass !== null) {
            $connector = $grouped ? 'self::client()'
                : ($resourceMethod === null ? 'StaticClient::client()' : 'Client::current()');
            $call = ($grouped ? '' : '\\' . $this->namespace . '\\') . $connector . '->resource('
                . $resourceClass . '::class)->' . ($resourceMethod ?? $method)
                . '(' . implode(', ', $forward) . ')';
            return "    public static function $method(" . implode(', ', $arguments) . "): $returnType\n    {\n"
                . ($success['type'] === null ? "        $call;\n" : "        return $call;\n")
                . "    }\n\n";
        }

        $validation = [];
        foreach ($operation->parameters as $parameter) {
            if ($parameter->location !== 'path') {
                continue;
            }
            $resolved = PhpParameterTypeResolver::resolve(
                $parameter->schema, $operation->operationId . '.' . $parameter->wireName,
            );
            $rules = $parameter->schema->validation;
            if ($resolved['enumValues'] !== []) {
                $rules['enum'] = $resolved['enumValues'];
            }
            if ($rules !== []) {
                $validation[$parameter->wireName] = $rules;
            }
        }
        $successStatuses = array_values(array_map(
            static fn ($response): int => (int) $response->status,
            array_filter($operation->responses, static fn ($response): bool =>
                preg_match('/^2[0-9]{2}$/', $response->status) === 1),
        ));
        $metadata = [
            'method' => strtoupper($operation->method),
            'path' => $operation->path,
            'security' => $operation->securityRequirements,
            'success' => $successStatuses,
            'errors' => $this->errorTypes[$operation->operationId] ?? [],
            'responseType' => $success['type'],
            'schemaNullable' => $success['schemaNullable'],
            'noContentAllowed' => $success['noContentAllowed'],
            'validation' => $validation,
        ];
        foreach (self::OPERATION_DEFAULTS as $key => $default) {
            if ($metadata[$key] === $default) {
                unset($metadata[$key]);
            }
        }
        $constant = strtoupper(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $method));
        $source = '    private const array ' . $constant . ' = ' . PhpLiteral::export($metadata, 1) . ";\n\n";
        if ($identifier === null && $payload === null) {
            $call = '$this->executeOperation(self::' . $constant . ')';
        } elseif ($identifier === null && $payload['kind'] === 'body') {
            $call = '$this->executeOperation(self::' . $constant . ', body: $payload)';
        } else {
            $parts = ['self::' . $constant];
            if ($identifier !== null) {
                $parts[] = 'identifier: $' . $identifier['name'];
                if ($identifier['kind'] === 'scalar') {
                    $parts[] = 'identifierName: ' . var_export($identifier['wireName'], true);
                }
            }
            if ($payload !== null) {
                $parts[] = ($payload['kind'] === 'body' ? 'body: ' : 'payload: ') . '$payload';
            }
            $call = '$this->executeArguments(' . implode(', ', $parts) . ')';
        }
        $source .= "    public function $method(" . implode(', ', $arguments) . "): $returnType\n    {\n";
        $source .= $success['type'] === null
            ? "        $call;\n        return;\n"
            : "        /** @var $returnType \$result */\n        \$result = $call;\n        return \$result;\n";
        return $source . "    }\n\n";
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private static function variableName(string $wireName): string
    {
        return PhpNameTransliterator::property($wireName);
    }
}
