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
            . "    ): self\n"
            . "    {\n"
            . "        \$client = \$httpClient ?? new \\GuzzleHttp\\Client(['http_errors' => false]);\n"
            . "        return new self(new \\$ns\\Transport\\Psr18Transport(\$client, \$headers), \$baseUrl);\n"
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
                $args[] = ($p['required'] ? $p['type'] : '?' . $p['type'])
                    . ' 
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

        if ($operation->parameters !== []) {
            // Core\Request validates required fields before any path, query
            // or header is read. Absent optional fields remain absent, not null.
            $source .= "        \$request->jsonSerialize();\n";
            foreach ($operation->parameters as $parameter) {
                $name = $parameter->wireName;
                $source .= '        
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
 . $name . ($p['required'] ? '' : ' = null');
            }
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
 . $name . ' = $request->' . $name
                    . ($parameter->required ? ';' : ' ?? null;') . "\n";
            }
            if ($operation->requestBody !== null) {
                $source .= "        \$body = \$request->body ?? null;\n";
            }
        }

        foreach ($argumentMetadata as $p) {
            $name = $p['name'];
            $var = '
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
 . $name . ($p['required'] ? '' : ' = null');
            }
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
 . $name;
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
 . $name . ($p['required'] ? '' : ' = null');
            }
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
        $namespace = $this->namespace . '\\Support';
        return $this->header($namespace) . <<<'PHP'
/**
 * Compatibility entry point. All JSON-to-object logic lives in Core\DTO,
 * so direct DTO construction and HTTP response hydration behave identically.
 */
final class JsonHydrator
{
    /** @template T of \JsonSerializable @param class-string<T> $type @return T */
    public static function decode(string $body, string $type): object
    {
        $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($data)) {
            throw new \UnexpectedValueException("Expected a JSON object for $type.");
        }
        if (!is_subclass_of($type, \JsonSerializable::class)) {
            throw new \InvalidArgumentException("Expected generated DTO class, got $type.");
        }
        return new $type($data);
    }
}
PHP;
    }
}
