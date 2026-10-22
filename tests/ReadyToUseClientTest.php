<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ReadyToUseClientTest extends TestCase
{
    /** @return array<string, string> */
    private static function generatedFiles(): array
    {
        $json = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        $contract = (new SpecificationNormalizer())->fromJson((string) $json);
        return (new PhpExecutableEmitter('Example\\ReadySdk', 'example/ready-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generatedFiles();
        foreach ([
            'src/Core/DTO.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/NewUser/RoleEnum.php',
            'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php',
            'src/DTOs/Profile.php',
            'src/Requests/NewUser.php',
            'src/Responses/User.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/ApiClient.php', 'src/Core/ApiException.php',
            'src/Core/BasicAuthCredentials.php',
            'src/Core/SecurityCredentials.php',
            'src/Core/Security.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Client.php',
            'src/Api/Users.php',
            'src/Api/Users/User.php',
        ] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public static function response(int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'id' => 88, 'name' => 'Ada', 'role' => 'admin',
            'profile' => ['city' => 'Madrid', 'visits' => 2],
            'labels' => ['php', 'sdk'],
        ], JSON_THROW_ON_ERROR));
    }

    public function testGeneratedPackageDeclaresRuntimeRequirements(): void
    {
        $files = self::generatedFiles();
        self::assertArrayHasKey('src/Transport/Psr18Transport.php', $files);
        $meta = json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('HTTP runtime', $meta['description']);
        self::assertSame('^7.9', $meta['require']['guzzlehttp/guzzle']);
        self::assertSame('^1.0', $meta['require']['psr/http-client']);
        self::assertSame('^1.0', $meta['require']['typedarray/typedarray']);
        self::assertArrayNotHasKey('dmorenof/sdk-generator', $meta['require']);
    }

    public function testDefaultFactoryConstructsGuzzleAutomaticallyWithoutNetwork(): void
    {
        $client = $connection = \Example\ReadySdk\Client::connect('https://api.example.test/v1');
        self::assertInstanceOf(\Example\ReadySdk\Core\ApiClient::class, $connection);
        $adapter = (new \ReflectionProperty($connection, 'transport'))->getValue($connection);
        self::assertInstanceOf(\Example\ReadySdk\Core\HttpClient::class, $adapter);
        self::assertInstanceOf(Client::class, (new \ReflectionProperty($adapter, 'client'))->getValue($adapter));
    }

    public function testConfiguredGuzzleMiddlewareRunsBeforeAndAfterTypedGet(): void
    {
        $events = [];
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response()]));
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) use (&$events): RequestInterface {
            $events[] = 'before';
            return $request->withHeader('X-Middleware', 'yes');
        }));
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) use (&$events): ResponseInterface {
            $events[] = 'after';
            return $response;
        }));
        $stack->push(Middleware::history($history));
        $client = \Example\ReadySdk\Client::connect(
            'https://api.example.test/v1',
            new Client(['handler' => $stack]),
            ['Authorization' => 'Bearer demo'],
        );
        $user = $client->resource(\Example\ReadySdk\Api\Users\User::class)->getById(88, false);
        self::assertInstanceOf(\Example\ReadySdk\Responses\User::class, $user);
        self::assertSame('Madrid', $user->profile->city);
        self::assertSame(\Example\ReadySdk\Enums\User\RoleEnum::Admin, $user->role);
        self::assertSame(['php', 'sdk'], $user->labels->getArrayCopy());
        self::assertSame(['before', 'after'], $events);
        self::assertCount(1, $history);
        $req = $history[0]['request'];
        self::assertSame('GET', $req->getMethod());
        self::assertSame('https://api.example.test/v1/users/88?verbose=false', (string) $req->getUri());
        self::assertSame('yes', $req->getHeaderLine('X-Middleware'));
        self::assertSame('Bearer demo', $req->getHeaderLine('Authorization'));
    }

    public function testCanInjectAnyPsr18ClientForTypedPost(): void
    {
        $http = new class implements ClientInterface {
            public int $calls = 0;
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls++;
                if ($request->getMethod() !== 'POST' || (string) $request->getUri() !== 'https://api.example.test/v1/users') {
                    throw new \RuntimeException('Unexpected HTTP request');
                }
                if ($request->getHeaderLine('Content-Type') !== 'application/json') {
                    throw new \RuntimeException('Missing content type');
                }
                $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                if ($body !== ['name' => 'Grace']) {
                    throw new \RuntimeException('Wrong request body');
                }
                return ReadyToUseClientTest::response(201);
            }
        };
        $client = \Example\ReadySdk\Client::connect('https://api.example.test/v1', $http);
        $request = new \Example\ReadySdk\Requests\NewUser();
        $request->name = 'Grace';
        $result = $client->resource(\Example\ReadySdk\Api\Users::class)->post($request);
        self::assertInstanceOf(\Example\ReadySdk\Responses\User::class, $result);
        self::assertSame(1, $http->calls);
    }

    public function testPsr18StatusErrorIsHandledBySdk(): void
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(404, [], '{"error":"missing"}'),
        ]))]);
        $client = \Example\ReadySdk\Client::connect('https://api.example.test/v1', $http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected HTTP status from GET /users/{id}: 404');
        $client->resource(\Example\ReadySdk\Api\Users\User::class)->getById(88);
    }

    public function testCasafariStyleCoreMiddlewareHooksRunOnPSR18WithoutDuplicatingTransport(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response()]));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $client = $connection = \Example\ReadySdk\Client::connect('https://api.example.test/v1', $http);

        $events = new \ArrayObject();
        $connection->withMiddleware(new class($events) implements \Example\ReadySdk\Core\HttpMiddlewareInterface {
            public function __construct(private \ArrayObject $events) {}

            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                $this->events->append('before');
                return $request->withHeader('X-Sdk-Core', 'yes');
            }

            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $this->events->append('after:' . $request->getHeaderLine('X-Sdk-Core'));
                return $response->withHeader('X-Sdk-Core-Response', 'processed');
            }
        });

        self::assertInstanceOf(\Example\ReadySdk\Core\ApiClient::class, $connection);
        self::assertSame('Ada', $client->resource(\Example\ReadySdk\Api\Users\User::class)->getById(88)->name);
        self::assertSame(['before', 'after:yes'], $events->getArrayCopy());
        self::assertSame('yes', $history[0]['request']->getHeaderLine('X-Sdk-Core'));
    }

    public function testMiddlewareMayAbortBeforeHttpWithoutChangingExceptionIdentity(): void
    {
        $sent = new \ArrayObject();
        $http = new class($sent) implements ClientInterface {
            public function __construct(private \ArrayObject $sent) {}
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent->append($request);
                throw new \LogicException('Transport must not be called');
            }
        };
        $client = $connection = \Example\ReadySdk\Client::connect('https://api.example.test/v1', $http);
        $exception = new \DomainException('SDK middleware stopped request');
        $connection->withMiddleware(new class($exception) implements \Example\ReadySdk\Core\HttpMiddlewareInterface {
            public function __construct(private \DomainException $reason) {}
            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                throw $this->reason;
            }
            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response;
            }
        });
        try {
            $client->resource(\Example\ReadySdk\Api\Users\User::class)->getById(88);
            self::fail('Middleware failed to abort the request');
        } catch (\DomainException $caught) {
            self::assertSame($exception, $caught);
        }
        self::assertCount(0, $sent);
    }

    public function testGeneratedPhpFilesAllPassSyntaxValidation(): void
    {
        foreach (self::generatedFiles() as $path => $content) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ready-sdk-');
            self::assertNotFalse($tmp);
            try {
                file_put_contents($tmp, $content);
                $output = [];
                $exit = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
                self::assertSame(0, $exit, $path . ': ' . implode("\n", $output));
            } finally {
                unlink($tmp);
            }
        }
    }
}
