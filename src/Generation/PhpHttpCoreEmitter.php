<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Generation;

/**
 * Standalone HTTP services shared by every generated operation.
 * Guzzle and PSR-18 remain externally maintained dependencies.
 */
final readonly class PhpHttpCoreEmitter
{
    public function __construct(private string $namespace) {}

    /** @return array<string, string> */
    public function emit(): array
    {
        $ns = $this->namespace;
        $header = "<?php\n"
            . "declare(strict_types=1);\n\n"
            . "namespace $ns\\Core;\n\n";

        $middleware = $header . <<<'PHP'
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** An optional, SDK-level interception seam; Guzzle HandlerStack works too. */
interface HttpMiddlewareInterface
{
    public function beforeRequest(RequestInterface $request): RequestInterface;
    public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface;
}
PHP;

        $client = $header . <<<'PHP'
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
PHP;
        $client .= "\n"
            . "use \\{$ns}\\Transport\\TransportInterface;\n"
            . "use \\{$ns}\\Transport\\HttpResponse;\n\n";
        $client .= <<<'PHP'
/**
 * Common PSR-18/Guzzle adapter. Body is already serialized to JSON.
 * Interceptors run in registration order and may replace PSR-7 messages.
 */
class HttpClient implements TransportInterface
{
    /** @var list<HttpMiddlewareInterface> */
    private array $middlewares = [];

    /** @param array<string, string> $defaultHeaders */
    public function __construct(
        protected ClientInterface $client,
        private array $defaultHeaders = [],
    ) {}

    public function addMiddleware(HttpMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $request = new Request($method, $url, array_replace($this->defaultHeaders, $headers), $body ?? '');

        foreach ($this->middlewares as $middleware) {
            $request = $middleware->beforeRequest($request);
        }

        // PSR-18 returns non-2xx responses without throwing for status alone.
        // Endpoint-specific code retains control of expected status checking.
        $response = $this->client->sendRequest($request);

        foreach ($this->middlewares as $middleware) {
            $response = $middleware->afterResponse($request, $response);
        }

        $resultHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $resultHeaders[$name] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), (string)$response->getBody(), $resultHeaders);
    }
}
PHP;
        $base = $header
            . "use \\{$ns}\\Transport\\TransportInterface;\n\n"
            . <<<'PHP'
/**
 * Shared endpoint-client infrastructure; derived API classes only emit typed operations.
 */
abstract class ApiClient
{
    protected SecurityCredentials $securityCredentials;

    public function __construct(
        protected TransportInterface $transport,
        protected string $baseUrl,
        ?SecurityCredentials $securityCredentials = null,
    ) {
        $this->securityCredentials = $securityCredentials ?? new SecurityCredentials();
    }

    /** Attach an SDK-level interceptor when using the default PSR-18 adapter. */
    public function withMiddleware(HttpMiddlewareInterface $middleware): static
    {
        if (!$this->transport instanceof HttpClient) {
            throw new \LogicException('SDK middleware requires the PSR-18 HttpClient adapter.');
        }
        $this->transport->addMiddleware($middleware);
        return $this;
    }
}
PHP;

        $apiException = $header
            . "use \\{$ns}\\Transport\\HttpResponse;\n\n"
            . <<<'PHP'
/**
 * Typed HTTP API error that preserves the raw response even when error DTO
 * decoding fails. Connection/transport exceptions retain their original type.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly HttpResponse $response,
        public readonly ?DTO $error,
        string $operation,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Unexpected HTTP status from $operation: " . $response->status,
            $response->status,
            $previous,
        );
    }

    /** @param class-string<DTO>|null $errorType */
    public static function fromResponse(HttpResponse $response, string $operation, ?string $errorType): self
    {
        $error = null;
        $previous = null;
        if ($errorType !== null) {
            try {
                if (!is_a($errorType, DTO::class, true)) {
                    throw new \LogicException("Error type $errorType must extend Core\\DTO.");
                }
                $json = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
                if (!is_object($json)) {
                    throw new \UnexpectedValueException("Expected JSON object for error type $errorType.");
                }
                $error = new $errorType($json);
            } catch (\Throwable $exception) {
                // Always expose HTTP response information to the caller.
                $previous = $exception;
            }
        }
        return new self($response, $error, $operation, $previous);
    }
}
PHP;

        $basicAuthCredentials = $header . <<<'PHP'
final readonly class BasicAuthCredentials
{
    private string $username;
    private string $password;

    public function __construct(
        string $username,
        string $password,
    ) {
        if (str_contains($username, ':') || preg_match('/[\r\n]/', $username . $password) === 1) {
            throw new \InvalidArgumentException('Invalid HTTP Basic credentials.');
        }
        $this->username = $username;
        $this->password = $password;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]'];
    }
}
PHP;

        $securityCredentials = $header . <<<'PHP'
final readonly class SecurityCredentials
{
    /** @param array<string, string|BasicAuthCredentials> $credentials */
    public function __construct(private array $credentials = [])
    {
        foreach ($credentials as $name => $credential) {
            if (!is_string($name) || $name === ''
                || (!is_string($credential) && !($credential instanceof BasicAuthCredentials))
                || $credential === ''
                || (is_string($credential) && preg_match('/[\r\n]/', $credential) === 1)) {
                throw new \InvalidArgumentException('Invalid security credentials map.');
            }
        }
    }

    public function forScheme(string $name): string|BasicAuthCredentials|null
    {
        return $this->credentials[$name] ?? null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configuredSchemes' => array_keys($this->credentials)];
    }
}
PHP;

        $security = $header . <<<'PHP'
final class Security
{
    /**
     * @param list<array<string, list<string>>> $requirements Alternatives are OR; schemes within one entry are AND.
     * @param array<string, array{type:string,scheme:?string,location:?string,parameterName:?string}> $schemes
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public static function apply(
        SecurityCredentials $credentials,
        array $requirements,
        array $schemes,
        array &$headers,
        array &$query,
    ): void {
        if ($requirements === []) {
            return;
        }

        foreach ($requirements as $requirement) {
            if ($requirement === []) {
                return;
            }
            $selected = [];
            $satisfied = true;
            foreach ($requirement as $name => $_scopes) {
                $credential = $credentials->forScheme($name);
                if ($credential === null) {
                    $satisfied = false;
                    break;
                }
                $scheme = $schemes[$name] ?? null;
                if ($scheme === null) {
                    throw new \LogicException("Missing generated security scheme $name.");
                }
                $selected[] = [$name, $scheme, $credential];
            }
            if (!$satisfied) {
                continue;
            }

            $securityHeaders = [];
            $securityQuery = [];
            foreach ($selected as [$name, $scheme, $credential]) {
                if ($scheme['type'] === 'http' && $scheme['scheme'] === 'bearer') {
                    if (!is_string($credential) || $credential === '') {
                        throw new \InvalidArgumentException("Bearer credential $name must be a nonempty string.");
                    }
                    self::addHeader($securityHeaders, 'Authorization', 'Bearer ' . $credential);
                } elseif ($scheme['type'] === 'http' && $scheme['scheme'] === 'basic') {
                    if (!$credential instanceof BasicAuthCredentials) {
                        throw new \InvalidArgumentException("Basic credential $name must be BasicAuthCredentials.");
                    }
                    self::addHeader(
                        $securityHeaders,
                        'Authorization',
                        'Basic ' . base64_encode($credential->username() . ':' . $credential->password()),
                    );
                } elseif ($scheme['type'] === 'apiKey') {
                    if (!is_string($credential) || $credential === '') {
                        throw new \InvalidArgumentException("API key credential $name must be a nonempty string.");
                    }
                    if ($scheme['location'] === 'header') {
                        self::addHeader($securityHeaders, $scheme['parameterName'], $credential);
                    } elseif ($scheme['location'] === 'query') {
                        self::addQuery($securityQuery, $scheme['parameterName'], $credential);
                    } else {
                        throw new \LogicException("Unsupported generated API key location for $name.");
                    }
                } else {
                    throw new \LogicException("Unsupported generated security scheme $name.");
                }
            }
            foreach ($securityHeaders as $name => $value) {
                $headers[$name] = $value;
            }
            foreach ($securityQuery as $name => $value) {
                $query[$name] = $value;
            }
            return;
        }

        throw new \InvalidArgumentException(
            'No configured credentials satisfy this operation security requirement.',
        );
    }

    /** @param array<string, string> $headers */
    private static function addHeader(array &$headers, string $name, string $value): void
    {
        foreach ($headers as $existingName => $existingValue) {
            if (strcasecmp($existingName, $name) === 0) {
                if ($existingValue !== $value) {
                    throw new \InvalidArgumentException("Conflicting security values for header $name.");
                }
                return;
            }
        }
        $headers[$name] = $value;
    }

    /** @param array<string, string> $query */
    private static function addQuery(array &$query, string $name, string $value): void
    {
        if (array_key_exists($name, $query) && $query[$name] !== $value) {
            throw new \InvalidArgumentException("Conflicting security values for query parameter $name.");
        }
        $query[$name] = $value;
    }
}
PHP;

        return [
            'src/Core/HttpMiddlewareInterface.php' => $middleware,
            'src/Core/HttpClient.php' => $client,
            'src/Core/ApiClient.php' => $base,
            'src/Core/ApiException.php' => $apiException,
            'src/Core/BasicAuthCredentials.php' => $basicAuthCredentials,
            'src/Core/SecurityCredentials.php' => $securityCredentials,
            'src/Core/Security.php' => $security,
        ];
    }
}
