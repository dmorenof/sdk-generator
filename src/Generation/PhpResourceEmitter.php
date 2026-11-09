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
        'serverUrl' => null,
        'querySerialization' => [],
        'requestMediaType' => 'application/json',
        'bodySerialization' => [],
        'bodyParts' => [],
        'responseTypes' => [],
        'pathSerialization' => [],
        'headerSerialization' => [],
    ];

    /**
     * @param array<string,array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool,byStatus?:array<int,array{type:?string,schemaNullable:bool}>}> $successTypes
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
        /** @var array<string,array<string,string>> */
        private array $queryTypes = [],
        /** @var list<\Dmorenof\SdkGenerator\Contract\ModelDefinition> */
        private array $formModels = [],
        /** @var array<string,array<string,array<array-key,string>>> */
        private array $parameterTypes = [],
    ) {}

    /** @return array<string,string> */
    public function emit(ApiContract $contract): array
    {
        if ($this->groupOverrides !== []) {
            throw new InvalidArgumentException(
                'Grouped API overrides are obsolete: SDK namespaces now follow route segments.'
            );
        }
        $resolved = PhpRouteNameResolver::resolve($contract);
        $root = PhpApiNameResolver::sdkClassFromTitle($contract->title);
        $files = [
            'src/Client.php' => $this->renderConnector(),
            'src/Configuration.php' => $this->renderConfiguration($contract),
            "src/$root.php" => $this->renderSdkRoot($contract),
        ];
        $paths = [];
        foreach ($resolved['nodes'] as $node) {
            $segments = $node['segments'];
            $resourcePath = 'src/Resources/' . implode('/', $segments) . '.php';
            $facadePath = 'src/' . implode('/', $segments) . '.php';
            $facadeKey = strtolower($facadePath);
            if (isset($files[$facadePath]) || isset($paths[$facadeKey])) {
                throw new InvalidArgumentException("API route conflicts with reserved class: $facadePath.");
            }
            $paths[$facadeKey] = true;
            $files[$resourcePath] = $this->renderResource($node, $resolved['operationMethods'], $contract->servers[0] ?? null);
            $files[$facadePath] = $this->renderRouteFacade($node, $resolved['operationMethods'], $root);
        }
        ksort($files, SORT_STRING);
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
            $configuration->serverOverrides,
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
PHP;

        return $this->header($this->namespace)
            . 'class Client extends \\' . PhpContractEmitter::RUNTIME_NAMESPACE . "\\ApiClient\n{\n"
            . '    protected const array SECURITY_SCHEMES = ' . PhpLiteral::export($schemes) . ";\n"
            . $methods . "\n}\n";
    }

    private function renderConfiguration(ApiContract $contract): string
    {
        $baseUrl = PhpLiteral::export($contract->servers[0] ?? '');
        return $this->header($this->namespace)
            . "final class Configuration extends \\Dmorenof\\SdkRuntime\\Configuration\n{\n"
            . "    public string \$baseUrl = $baseUrl;\n}\n";
    }

    /**
     * A route has exactly one public static facade that mirrors the URL.
     * All HTTP behavior stays in the reusable instance resource / runtime.
     *
     * @param array{segments:list<string>,operations:list<OperationDefinition>} $node
     * @param array<string,string> $operationMethods
     */
    private function renderRouteFacade(array $node, array $operationMethods, string $root): string
    {
        $segments = $node['segments'];
        $class = end($segments);
        $parent = array_slice($segments, 0, -1);
        $namespace = $this->namespace . ($parent === [] ? '' : '\\' . implode('\\', $parent));
        $resourceClass = '\\' . $this->namespace . '\\Resources\\' . implode('\\', $segments);
        $source = $this->header($namespace) . "final class $class extends \\{$this->namespace}\\$root\n{\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing route method: $operation->operationId");
            $label = str_replace(["\r", "\n", '*/'], [' ', ' ', '* /'],
                strtoupper($operation->method) . ' ' . $operation->path
                    . ' [operationId: ' . $operation->operationId . ']');
            $source .= "    /** $label */\n";
            $source .= $this->renderOperation($operation, $method, $resourceClass, $method, true);
        }
        return $source . "}\n";
    }

    /**
     * @param array{segments:list<string>,operations:list<OperationDefinition>} $node
     * @param array<string,string> $operationMethods
     */
    private function renderResource(array $node, array $operationMethods, ?string $rootServer): string
    {
        $segments = $node['segments'];
        $class = end($segments);
        $parent = array_slice($segments, 0, -1);
        $namespace = $this->namespace . '\\Resources'
            . ($parent === [] ? '' : '\\' . implode('\\', $parent));
        $source = $this->header($namespace)
            . "final class $class extends \\{$this->namespace}\\Client\n{\n";
        foreach ($node['operations'] as $operation) {
            $method = $operationMethods[$operation->operationId]
                ?? throw new InvalidArgumentException("Missing route method: $operation->operationId");
            $serverUrl = null;
            if ($operation->serverSource !== 'root') {
                $selected = $operation->servers[0] ?? null;
                if ($selected === null || !\Dmorenof\SdkGenerator\Parsing\ServerUrl::isSupported($selected)
                    || !empty($operation->serverDefinitions[0]['variables'])) {
                    throw new InvalidArgumentException("Unsupported operation server at $operation->operationId: only literal absolute HTTP(S) servers are executable.");
                }
                // Equivalent root declarations still follow the configured client baseUrl.
                if (rtrim($selected, '/') !== ($rootServer === null ? null : rtrim($rootServer, '/'))) {
                    $serverUrl = rtrim($selected, '/');
                }
            }
            $source .= $this->renderOperation($operation, $method, serverUrl: $serverUrl);
        }
        return $source . "}\n";
    }

    private function renderOperation(OperationDefinition $operation, string $method, ?string $resourceClass = null, ?string $resourceMethod = null, bool $grouped = false, ?string $serverUrl = null): string
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
            $parameterName = $payload['kind'] === 'dto' ? 'params' : 'payload';
            $arguments[] = ($payload['required'] ? '' : '?') . $payload['type'] . ' $' . $parameterName
                . ($payload['required'] ? '' : ' = null');
            $forward[] = '$' . $parameterName;
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
        $querySerialization = [];
        $pathSerialization = [];
        $headerSerialization = [];
        $bodySerialization = [];
        $bodyParts = [];
        if ($operation->requestBody?->mediaType === \Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA) {
            $bodySerialization = \Dmorenof\SdkGenerator\Parsing\FormSupport::formats($operation->requestBody, $this->formModels);
            if ($bodySerialization === null) {
                throw new InvalidArgumentException("Unsupported form body schema or encoding at $operation->operationId.");
            }
        }
        if ($operation->requestBody?->mediaType === \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART) {
            $bodyParts = \Dmorenof\SdkGenerator\Parsing\FormSupport::parts($operation->requestBody, $this->formModels);
            if ($bodyParts === null) {
                throw new InvalidArgumentException("Unsupported multipart body schema or encoding at $operation->operationId.");
            }
        }
        foreach ($operation->parameters as $parameter) {
            $objectType = $this->parameterTypes[$operation->operationId][$parameter->location][$parameter->wireName]
                ?? ($parameter->location === 'query' ? ($this->queryTypes[$operation->operationId][$parameter->wireName] ?? null) : null);
            if ($objectType !== null) {
                $format = \Dmorenof\SdkGenerator\Parsing\QueryObjectSupport::format($parameter->serialization, $parameter->location);
                if ($format === null) { throw new InvalidArgumentException("Unsupported object parameter at $operation->operationId.$parameter->wireName."); }
                if ($parameter->location === 'query') {
                    $querySerialization[$parameter->wireName] = $format;
                } elseif ($parameter->location === 'path') {
                    $pathSerialization[$parameter->wireName] = $format;
                } else {
                    $headerSerialization[$parameter->wireName] = $format;
                }
                continue;
            }
            if ($parameter->location === 'query') {
                $resolved = PhpParameterTypeResolver::resolve($parameter->schema, $operation->operationId . '.' . $parameter->wireName, true);
                if ($resolved['type'] === 'array') {
                    $format = \Dmorenof\SdkGenerator\Parsing\QueryArraySupport::format($parameter->serialization);
                    if ($format === null || $parameter->content !== []) {
                        throw new InvalidArgumentException("Unsupported query array serialization at $operation->operationId.$parameter->wireName.");
                    }
                    $querySerialization[$parameter->wireName] = $format;
                }
            }
            if (!in_array($parameter->location, ['path', 'header'], true)) {
                continue;
            }
            $resolved = PhpParameterTypeResolver::resolve(
                $parameter->schema, $operation->operationId . '.' . $parameter->wireName, true,
            );
            if ($resolved['type'] === 'array') {
                if ($parameter->location === 'path') {
                    $pathSerialization[$parameter->wireName] = 'csv';
                } else {
                    $headerSerialization[$parameter->wireName] = 'csv';
                }
            }
            if ($parameter->location !== 'path') {
                continue;
            }
            $rules = $resolved['validation'] ?? $parameter->schema->validation;
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
            'responseType' => isset($success['byStatus']) ? null : $success['type'],
            'responseTypes' => $success['byStatus'] ?? [],
            'schemaNullable' => $success['schemaNullable'],
            'noContentAllowed' => $success['noContentAllowed'],
            'validation' => $validation,
            'serverUrl' => $serverUrl,
            'querySerialization' => $querySerialization,
            'pathSerialization' => $pathSerialization,
            'headerSerialization' => $headerSerialization,
            'requestMediaType' => in_array($operation->requestBody?->mediaType, [\Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA, \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART], true)
                ? $operation->requestBody->mediaType : 'application/json',
            'bodySerialization' => $bodySerialization,
            'bodyParts' => $bodyParts,
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
                $parts[] = $payload['kind'] === 'body' ? 'body: $payload' : 'payload: $params';
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
