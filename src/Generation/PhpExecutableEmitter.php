<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Contract\SecuritySchemeDefinition;
use InvalidArgumentException;

/**
 * Experimental end-to-end HTTP SDK emitter.
 *
 * It adds a minimal injectable transport, JSON hydration, and executable
 * endpoint methods to the contract emitter. No API-specific rules are coded
 * here. The reusable support types are emitted with the SDK temporarily;
 * factoring them into sdk-runtime is an explicit next milestone.
 */
final class PhpExecutableEmitter
{
    private ?PhpModelNameResolver $modelNames = null;
    /** @var list<SecuritySchemeDefinition> */
    private array $securitySchemes = [];
    /** @var array<string, array{type:?string,nullable:bool,schemaNullable:bool,noContentAllowed:bool}> */
    private array $operationSuccessTypes = [];
    /** @var array<string, array<string, string>> */
    private array $operationErrorTypes = [];
    public function __construct(
        private readonly string $namespace = 'Generated\\Sdk',
        private readonly string $packageName = 'example/generated-sdk',
    ) {}

    /** @return array<string, string> */
    public function emit(ApiContract $contract): array
    {
        $this->securitySchemes = $contract->securitySchemes;
        $contractEmitter = new PhpContractEmitter($this->namespace, $this->packageName);
        $files = $contractEmitter->emit($contract);
        $this->modelNames = new PhpModelNameResolver(
            $this->namespace,
            $contract->models,
            PhpContractEmitter::modelRolesFor($contract),
        );
        $this->operationSuccessTypes = $contractEmitter->operationSuccessTypes();
        $this->operationErrorTypes = $contractEmitter->operationErrorTypes();
        unset(
            $files['src/Api/Operations.php'],
            $files['src/Api/OperationCatalog.php'],
            $files['src/Api/ApiClient.php'],
        );
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'src/Api/')) {
                unset($files[$path]);
            }
        }
        $ns = $this->namespace;
        $files['src/Transport/TransportInterface.php'] = $this->header($ns . '\\Transport')
            . "interface TransportInterface\n{\n"
            . "    /** @param array<string, string> \$headers */\n"
            . "    public function send(string \$method, string \$url, array \$headers, ?string \$body): HttpResponse;\n"
            . "}\n";
        $files['src/Transport/HttpResponse.php'] = $this->header($ns . '\\Transport')
            . "final readonly class HttpResponse\n{\n"
            . "    /** @param array<string, string> \$headers */\n"
            . "    public function __construct(public int \$status, public string \$body, public array \$headers = []) {}\n"
            . "}\n";
        // The default adapter is PSR-18-compatible and uses Guzzle 7 out of the box.
        // Build-time generator code is not loaded by the emitted package.
        $files = array_replace($files, (new PhpHttpCoreEmitter($ns))->emit());
        $files['src/Transport/Psr18Transport.php'] = $this->renderPsr18Transport();

        $manifest = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['description'] = 'Standalone PHP SDK generated from an API contract, with typed endpoints and HTTP runtime.';
        $manifest['require']['guzzlehttp/guzzle'] = '^7.9';
        $manifest['require']['psr/http-client'] = '^1.0';
        ksort($manifest['require']);
        $files['composer.json'] = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $files['src/Support/JsonHydrator.php'] = $this->renderHydrator();
        $files = array_replace(
            $files,
            (new PhpResourceEmitter(
                $this->namespace,
                $this->securitySchemes,
                $this->operationSuccessTypes,
                $this->operationErrorTypes,
                $this->modelNames,
            ))->emit($contract),
        );
        ksort($files, SORT_STRING);
        return $files;
    }

    private function renderPsr18Transport(): string
    {
        $ns = $this->namespace;
        return $this->header($ns . '\\Transport')
            . "final class Psr18Transport extends \\" . $ns . "\\Core\\HttpClient\n{\n}\n";
    }

    private function renderClient(ApiContract $contract): string
    {
        $ns = $this->namespace;
        $source = $this->header($ns . '\\Api')
            . "use $ns\\Transport\\TransportInterface;\n"
            . "use $ns\\Support\\JsonHydrator;\n\n"
            . "final readonly class ApiClient extends \\" . $ns . "\Core\ApiClient implements Operations\n{\n"
            . "    /**\n"
            . "     * Ready-to-use SDK, Guzzle by default, PSR-18 injection optional.\n"
            . "     * @param array<string, string> \$headers\n"
            . "     */\n"
            . "    public static function connect(\n"
            . "        string \$baseUrl,\n"
            . "        ?\\Psr\\Http\\Client\\ClientInterface \$httpClient = null,\n"
            . "        array \$headers = [],\n"
            . "        ?\\" . $ns . "\\Core\\SecurityCredentials \$securityCredentials = null,\n"
            . "    ): self\n"
            . "    {\n"
            . "        \$client = \$httpClient ?? new \\GuzzleHttp\\Client(['http_errors' => false]);\n"
            . "        return new self(new \\$ns\\Transport\\Psr18Transport(\$client, \$headers), \$baseUrl, \$securityCredentials);\n"
            . "    }\n\n";

        foreach ($contract->operations as $operation) {
            $source .= $this->renderOperation($operation);
        }

        return $source . "}\n";
    }

    private function renderOperation(OperationDefinition $operation): string
    {
        $args = [];
        $argumentMetadata = [];
        foreach ($operation->parameters as $p) {
            $resolvedType = PhpParameterTypeResolver::resolve(
                $p->schema,
                $operation->operationId . '.' . $p->wireName,
            );
            $argumentMetadata[] = [
                'name' => $p->wireName,
                'location' => $p->location,
                'required' => $p->required,
                'type' => $resolvedType['type'],
                'nullable' => $resolvedType['nullable'],
            ];
        }
        if ($operation->requestBody !== null) {
            $body = $operation->requestBody;
            if ($body->schema->kind !== SchemaKind::Reference || $body->schema->nullable) {
                throw new InvalidArgumentException("Unsupported request body at $operation->operationId");
            }
            $argumentMetadata[] = [
                'name' => 'body',
                'location' => 'body',
                'required' => $body->required,
                'type' => $this->reference($body->schema),
                'nullable' => false,
            ];
        }
        // Match the exact required-first argument ordering of Operations.
        usort($argumentMetadata, static fn ($a, $b): int
            => (int) $b['required'] <=> (int) $a['required']);
        $seen = [];
        if ($operation->parameters !== []) {
            $args[] = (new PhpOperationRequestEmitter($this->namespace))->fqcn($operation) . ' $request';
        }
        foreach ($argumentMetadata as $p) {
            $name = $p['name'];
            if (isset($seen[strtolower($name)])) {
                throw new InvalidArgumentException("Duplicate argument $name at $operation->operationId");
            }
            $seen[strtolower($name)] = true;
            if ($operation->parameters === []) {
                $type = (!$p['required'] || $p['nullable']) ? '?' . $p['type'] : $p['type'];
                $args[] = $type
                    . ' $' . $name . ($p['required'] ? '' : ' = null');
            }
        }
        $success = array_values(array_filter(
            $operation->responses,
            static fn ($r): bool => preg_match('/^2[0-9]{2}$/', $r->status) === 1,
        ));
        if ($success === []) {
            throw new InvalidArgumentException("Unsupported operation without a success response at $operation->operationId");
        }
        $responsesWithSchema = array_values(array_filter(
            $success,
            static fn ($r): bool => $r->schema !== null,
        ));
        $response = $responsesWithSchema[0] ?? $success[0];
        $successType = $this->operationSuccessTypes[$operation->operationId]
            ?? throw new InvalidArgumentException("Missing generated response type at $operation->operationId");
        $responseType = $successType['type'];
        $return = $responseType === null
            ? 'void'
            : ($successType['nullable'] ? '?' : '') . $responseType;
        $successStatuses = array_map(static fn ($r): int => (int) $r->status, $success);
        $errorTypes = $this->operationErrorTypes[$operation->operationId] ?? [];

        $path = var_export($operation->path, true);
        $method = var_export($operation->method, true);
        $source = '    public function ' . PhpOperationNameResolver::resolve($operation->operationId)
            . '(' . implode(', ', $args) . '): ' . $return . "\n    {\n"
            . '        $path = ' . $path . ";\n"
            . "        \$query = [];\n"
            . "        \$headers = ['Accept' => 'application/json'];\n"
            . "        \$bodyJson = null;\n";

        if ($operation->parameters !== []) {
            // Core\Request validates required fields before any path, query
            // or header is read. Absent optional fields remain absent, not null.
            $source .= "        \$request->jsonSerialize();\n";
            foreach ($operation->parameters as $parameter) {
                $name = $parameter->wireName;
                $source .= '        $' . $name . ' = $request->' . $name
                    . ($parameter->required ? ';' : ' ?? null;') . "\n";
            }
            if ($operation->requestBody !== null) {
                $source .= "        \$body = \$request->body ?? null;\n";
            }
        }

        foreach ($argumentMetadata as $p) {
            $name = $p['name'];
            $var = '$' . $name;
            $wire = var_export($name, true);
            if ($p['location'] === 'path') {
                $source .= "        \$path = str_replace('{' . $wire . '}', rawurlencode((string) $var), \$path);\n";
            } elseif ($p['location'] === 'query') {
                $source .= '        if (' . $var . ' !== null) {' . "\n"
                    . '            $query[' . $wire . '] = is_bool(' . $var . ') ? ('
                    . $var . " ? 'true' : 'false') : (string) " . $var . ";\n"
                    . "        }\n";
            } elseif ($p['location'] === 'header') {
                $source .= '        if (' . $var . ' !== null) {' . "\n"
                    . '            $headers[' . $wire . '] = is_bool(' . $var . ') ? ('
                    . $var . " ? 'true' : 'false') : (string) " . $var . ";\n"
                    . "        }\n";
            } elseif ($p['location'] === 'body') {
                $source .= "        if (\$body !== null) {\n"
                    . "            \$headers['Content-Type'] = 'application/json';\n"
                    . "            \$bodyJson = json_encode(\$body, JSON_THROW_ON_ERROR);\n"
                    . "        }\n";
            }
        }
        if ($operation->securityRequirements !== []) {
            $source .= "        \\" . $this->namespace . "\\Core\\Security::apply(\n"
                . "            \$this->securityCredentials,\n"
                . '            ' . PhpLiteral::export($operation->securityRequirements) . ",\n"
                . '            ' . PhpLiteral::export($this->securitySchemeMap()) . ",\n"
                . "            \$headers,\n"
                . "            \$query,\n"
                . "        );\n";
        }
        $source .= "        \$url = rtrim(\$this->baseUrl, '/') . \$path;\n"
            . "        if (\$query !== []) {\n"
            . "            \$url .= '?' . http_build_query(\$query, '', '&', PHP_QUERY_RFC3986);\n"
            . "        }\n"
            . "        \$response = \$this->transport->send($method, \$url, \$headers, \$bodyJson);\n"
            . '        if (!in_array($response->status, ' . PhpLiteral::export($successStatuses) . ", true)) {\n"
            . "            \$errorTypes = " . PhpLiteral::export($errorTypes) . ";\n"
            . "            throw \\{$this->namespace}\\Core\\ApiException::fromResponse(\$response, "
            . var_export($operation->operationId, true)
            . ", \$errorTypes[\$response->status] ?? \$errorTypes['default'] ?? null);\n"
            . "        }\n";

        if ($responseType === null) {
            $source .= "        return;\n";
        } else {
            if ($successType['noContentAllowed']) {
                $source .= "        if (\$response->body === '') {\n"
                    . "            return null;\n"
                    . "        }\n";
            }
            $source .= "        return JsonHydrator::decode(\$response->body, "
                . var_export($responseType, true) . ', '
                . ($successType['schemaNullable'] ? 'true' : 'false') . ");\n";
        }
        return $source . "    }\n\n";
    }

    /**
     * @return array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}>
     */
    private function securitySchemeMap(): array
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
        return $schemes;
    }

    private function reference(SchemaDefinition $schema): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        return $this->modelNames->fqcn($schema->reference);
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private function renderHydrator(): string
    {
        $namespace = $this->namespace . '\\Support';
        $core = '\\' . $this->namespace . '\\Core\\';
        return $this->header($namespace)
            . "use {$core}AnyOf;\n"
            . "use {$core}DTO;\n"
            . "use {$core}Map;\n"
            . "use {$core}TypedArray;\n\n"
            . <<<'PHP'
/**
 * Decode JSON responses into their generated model, enum or collection type.
 */
final class JsonHydrator
{
    public static function decode(string $body, string $type, bool $nullable = false): mixed
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if ($data === null && $nullable) {
            return null;
        }

        return self::hydrate($data, $type);
    }

    private static function hydrate(mixed $data, string $type): mixed
    {
        $type = match ($type) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            default => $type,
        };
        if (in_array($type, ['mixed', 'string', 'int', 'float', 'bool'], true)) {
            $valid = match ($type) {
                'mixed' => true,
                'string' => is_string($data),
                'int' => is_int($data),
                'float' => is_float($data) || is_int($data),
                'bool' => is_bool($data),
            };
            if (!$valid) {
                throw new \UnexpectedValueException("Expected JSON $type response.");
            }
            return $type === 'float' ? (float) $data : $data;
        }
        if (enum_exists($type)) {
            if (!is_string($data) && !is_int($data)) {
                throw new \UnexpectedValueException("Expected a scalar enum response for $type.");
            }
            return $type::from($data);
        }
        if (!class_exists($type)) {
            throw new \LogicException("Unknown generated response type $type.");
        }
        if (is_subclass_of($type, AnyOf::class)) {
            return $type::fromValue($data);
        }
        if (is_subclass_of($type, TypedArray::class)) {
            if (!is_array($data) || !array_is_list($data)) {
                throw new \UnexpectedValueException("Expected JSON array response for $type.");
            }
            $result = new $type();
            $itemType = $result->getExpectedType();
            foreach ($data as $item) {
                $result->append(self::hydrate($item, $itemType));
            }
            return $result;
        }
        if (is_subclass_of($type, Map::class)) {
            if (!is_object($data) && !is_array($data)) {
                throw new \UnexpectedValueException("Expected JSON object response for $type.");
            }
            return new $type($data);
        }
        if (is_subclass_of($type, DTO::class)) {
            if (!is_object($data)) {
                throw new \UnexpectedValueException("Expected JSON object response for $type.");
            }
            return new $type($data);
        }
        throw new \LogicException("Unsupported generated response type $type.");
    }
}
PHP;
    }
}
