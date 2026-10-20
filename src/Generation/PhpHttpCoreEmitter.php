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
abstract readonly class ApiClient
{
    public function __construct(
        protected TransportInterface $transport,
        protected string $baseUrl,
    ) {}

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

        return [
            'src/Core/HttpMiddlewareInterface.php' => $middleware,
            'src/Core/HttpClient.php' => $client,
            'src/Core/ApiClient.php' => $base,
            'src/Core/ApiException.php' => $apiException,
        ];
    }
}
