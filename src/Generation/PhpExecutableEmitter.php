<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
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
    public function __construct(
        private readonly string $namespace = 'Generated\\Sdk',
        private readonly string $packageName = 'example/generated-sdk',
    ) {}

    /** @return array<string, string> */
    public function emit(ApiContract $contract): array
    {
        $files = (new PhpContractEmitter($this->namespace, $this->packageName))->emit($contract);
        foreach ($contract->models as $model) {
            $path = 'src/Models/' . $model->name . '.php';
            $required = [];
            foreach ($model->schema->properties as $property) {
                if ($property->required) {
                    $required[] = $property->wireName;
                }
            }
            $anchor = 'final class ' . $model->name . " implements \\JsonSerializable\n{\n";
            if (!str_contains($files[$path], $anchor)) {
                throw new InvalidArgumentException("Unexpected generated model shape: $path");
            }
            $files[$path] = str_replace(
                $anchor,
                $anchor . '    public const array REQUIRED_FIELDS = ' . var_export($required, true) . ";\n\n",
                $files[$path],
            );
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
        $files['src/Transport/Psr18Transport.php'] = $this->renderPsr18Transport();

        $manifest = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['require']['guzzlehttp/guzzle'] = '^7.9';
        $manifest['require']['psr/http-client'] = '^1.0';
        ksort($manifest['require']);
        $files['composer.json'] = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $files['src/Support/JsonHydrator.php'] = $this->renderHydrator();
        $files['src/Api/ApiClient.php'] = $this->renderClient($contract);
        ksort($files, SORT_STRING);
        return $files;
    }

    private function renderPsr18Transport(): string
    {
        $ns = $this->namespace;
        return $this->header($ns . '\Transport') . <<<'PHP'
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/**
 * Uses a real PSR-18 HTTP client. A configured Guzzle client with HandlerStack
 * can be injected for middleware, but no client setup is required by default.
 */
final readonly class Psr18Transport implements TransportInterface
{
    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        private ClientInterface $client,
        private array $defaultHeaders = [],
    ) {}

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $request = new Request(
            $method,
            $url,
            array_replace($this->defaultHeaders, $headers),
            $body ?? '',
        );
        // Guzzle's PSR-18 sendRequest() does not throw on HTTP status codes.
        $response = $this->client->sendRequest($request);
        $resultHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $resultHeaders[$name] = implode(', ', $values);
        }

        return new HttpResponse(
            $response->getStatusCode(),
            (string) $response->getBody(),
            $resultHeaders,
        );
    }
}
PHP;
    }

    private function renderClient(ApiContract $contract): string
    {
        $ns = $this->namespace;
        $source = $this->header($ns . '\\Api')
            . "use $ns\\Transport\\TransportInterface;\n"
            . "use $ns\\Support\\JsonHydrator;\n\n"
            . "final readonly class ApiClient implements Operations\n{\n"
            . "    public function __construct(\n"
            . "        private TransportInterface \$transport,\n"
            . "        private string \$baseUrl,\n"
            . "    ) {}\n\n"
            . "    /**\n"
            . "     * Ready-to-use SDK, Guzzle by default, PSR-18 injection optional.\n"
            . "     * @param array<string, string> \\$headers\n"
            . "     */\n"
            . "    public static function connect(\n"
            . "        string \\$baseUrl,\n"
            . "        ?\\Psr\\Http\\Client\\ClientInterface \\$httpClient = null,\n"
            . "        array \\$headers = [],\n"
            . "    ): self\n"
            . "    {\n"
            . "        \\$client = \\$httpClient ?? new \\GuzzleHttp\\Client(['http_errors' => false]);\n"
            . "        return new self(new $ns\\Transport\\Psr18Transport(\\$client, \\$headers), \\$baseUrl);\n"
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
            if ($p->schema->nullable || $p->schema->enumValues !== []) {
                throw new InvalidArgumentException("Unsupported parameter type at $operation->operationId.$p->wireName");
            }
            $type = $this->primitiveType($p->schema, $operation->operationId);
            $argumentMetadata[] = [
                'name' => $p->wireName,
                'location' => $p->location,
                'required' => $p->required,
                'type' => $type,
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
            ];
        }
        // Match the exact required-first argument ordering of Operations.
        usort($argumentMetadata, static fn ($a, $b): int
            => (int) $b['required'] <=> (int) $a['required']);
        $seen = [];
        foreach ($argumentMetadata as $p) {
            $name = $p['name'];
            if (isset($seen[strtolower($name)])) {
                throw new InvalidArgumentException("Duplicate argument $name at $operation->operationId");
            }
            $seen[strtolower($name)] = true;
            $args[] = ($p['required'] ? $p['type'] : '?' . $p['type'])
                . ' $' . $name . ($p['required'] ? '' : ' = null');
        }
        $success = array_values(array_filter(
            $operation->responses,
            static fn ($r): bool => preg_match('/^2[0-9]{2}$/', $r->status) === 1,
        ));
        if (count($success) !== 1) {
            throw new InvalidArgumentException("Unsupported multiple/no success responses at $operation->operationId");
        }
        $response = $success[0];
        if ($response->schema !== null && $response->schema->kind !== SchemaKind::Reference) {
            throw new InvalidArgumentException("Unsupported response schema at $operation->operationId");
        }
        $return = $response->schema === null ? 'void' : $this->reference($response->schema);
        $path = var_export($operation->path, true);
        $method = var_export($operation->method, true);
        $source = '    public function ' . $operation->operationId
            . '(' . implode(', ', $args) . '): ' . $return . "\n    {\n"
            . '        $path = ' . $path . ";\n"
            . "        \$query = [];\n"
            . "        \$headers = ['Accept' => 'application/json'];\n"
            . "        \$bodyJson = null;\n";

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
        $source .= "        \$url = rtrim(\$this->baseUrl, '/') . \$path;\n"
            . "        if (\$query !== []) {\n"
            . "            \$url .= '?' . http_build_query(\$query, '', '&', PHP_QUERY_RFC3986);\n"
            . "        }\n"
            . "        \$response = \$this->transport->send($method, \$url, \$headers, \$bodyJson);\n"
            . '        if ($response->status !== ' . (int) $response->status . ") {\n"
            . '            throw new \RuntimeException('
            . var_export("Unexpected HTTP status from $operation->operationId: ", true)
            . " . \$response->status, \$response->status);\n"
            . "        }\n";

        if ($response->schema === null) {
            $source .= "        return;\n";
        } else {
            $source .= "        return JsonHydrator::decode(\$response->body, "
                . $this->reference($response->schema) . "::class);\n";
        }
        return $source . "    }\n\n";
    }

    private function primitiveType(SchemaDefinition $schema, string $where): string
    {
        return match ($schema->kind) {
            SchemaKind::String => 'string',
            SchemaKind::Integer => 'int',
            SchemaKind::Number => 'float',
            SchemaKind::Boolean => 'bool',
            default => throw new InvalidArgumentException("Unsupported parameter schema at $where"),
        };
    }

    private function reference(SchemaDefinition $schema): string
    {
        if ($schema->reference === null) {
            throw new InvalidArgumentException('Missing model reference.');
        }
        return '\\' . $this->namespace . '\\Models\\' . $schema->reference;
    }

    private function header(string $namespace): string
    {
        return "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $namespace;\n\n";
    }

    private function renderHydrator(): string
    {
        // This implementation is used for the synthetic end-to-end test.
        // It is not yet the stable public runtime API.
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Initial generated JSON-object hydrator.
 *
 * Only maps named, public, typed properties. Additional fields are ignored
 * by this experimental slice; schema-driven policies come later.
 */
final class JsonHydrator
{
    /** @template T of object @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        return self::hydrate($data, $type);
    }

    /** @template T of object @param class-string<T> $type @return T */
    private static function hydrate(mixed $data, string $type): object
    {
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }

        $result = new $type();
        $reflection = new \ReflectionClass($type);
        foreach ($type::REQUIRED_FIELDS as $required) {
            if (!property_exists($data, $required)) {
                throw new \UnexpectedValueException("Required JSON property $type::$required is absent.");
            }
        }
        foreach (get_object_vars($data) as $name => $value) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }
            $property = $reflection->getProperty($name);
            $declaration = $property->getType();
            if (!$declaration instanceof \ReflectionNamedType) {
                throw new \UnexpectedValueException("Unsupported property declaration $type::$name.");
            }
            if ($value === null) {
                if (!$declaration->allowsNull()) {
                    throw new \UnexpectedValueException("Unexpected null at $type::$name.");
                }
                $result->{$name} = null;
                continue;
            }

            $kind = $declaration->getName();
            if ($declaration->isBuiltin()) {
                if ($kind === 'float' && is_int($value)) {
                    $value = (float) $value;
                }
                $valid = match ($kind) {
                    'int' => is_int($value),
                    'float' => is_float($value),
                    'string' => is_string($value),
                    'bool' => is_bool($value),
                    default => false,
                };
                if (!$valid) {
                    throw new \UnexpectedValueException("Invalid scalar value at $type::$name.");
                }
            } elseif (enum_exists($kind)) {
                $value = $kind::from($value);
            } elseif (is_subclass_of($kind, \TypedArray\TypedArray::class)) {
                if (!is_array($value)) {
                    throw new \UnexpectedValueException("Expected a JSON array at $type::$name.");
                }
                $array = new $kind();
                $itemType = (new \ReflectionProperty($kind, 'expected_type'))->getValue($array);
                foreach ($value as $item) {
                    if (enum_exists($itemType)) {
                        $item = $itemType::from($item);
                    } elseif (class_exists($itemType)) {
                        $item = self::hydrate($item, $itemType);
                    }
                    $array->append($item);
                }
                $value = $array;
            } else {
                $value = self::hydrate($value, $kind);
            }
            $result->{$name} = $value;
        }
        return $result;
    }
}
PHP;
    }
}
