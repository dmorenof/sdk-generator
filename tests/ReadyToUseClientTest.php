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
            'src/Enums/NewUserRoleEnum.php',
            'src/Enums/UserRoleEnum.php',
            'src/Collections/UserLabelsArray.php',
            'src/Models/Profile.php',
            'src/Models/NewUser.php',
            'src/Models/User.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Api/Operations.php',
            'src/Api/ApiClient.php',
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
        self::assertSame('^7.9', $meta['require']['guzzlehttp/guzzle']);
        self::assertSame('^1.0', $meta['require']['psr/http-client']);
        self::assertSame('^1.0', $meta['require']['typedarray/typedarray']);
        self::assertArrayNotHasKey('dmorenof/sdk-generator', $meta['require']);
    }

    public function testDefaultFactoryConstructsGuzzleAutomaticallyWithoutNetwork(): void
    {
        $api = \Example\ReadySdk\Api\ApiClient::connect('https://api.example.test/v1');
        self::assertInstanceOf(\Example\ReadySdk\Api\ApiClient::class, $api);
        $adapter = (new \ReflectionProperty($api, 'transport'))->getValue($api);
        self::assertInstanceOf(\Example\ReadySdk\Transport\Psr18Transport::class, $adapter);
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
        $api = \Example\ReadySdk\Api\ApiClient::connect(
            'https://api.example.test/v1',
            new Client(['handler' => $stack]),
            ['Authorization' => 'Bearer demo'],
        );
        $user = $api->getUser(88, false);
        self::assertInstanceOf(\Example\ReadySdk\Models\User::class, $user);
        self::assertSame('Madrid', $user->profile->city);
        self::assertSame(\Example\ReadySdk\Enums\UserRoleEnum::Admin, $user->role);
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
        $api = \Example\ReadySdk\Api\ApiClient::connect('https://api.example.test/v1', $http);
        $request = new \Example\ReadySdk\Models\NewUser();
        $request->name = 'Grace';
        $result = $api->createUser($request);
        self::assertInstanceOf(\Example\ReadySdk\Models\User::class, $result);
        self::assertSame(1, $http->calls);
    }

    public function testPsr18StatusErrorIsHandledBySdk(): void
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(404, [], '{"error":"missing"}'),
        ]))]);
        $api = \Example\ReadySdk\Api\ApiClient::connect('https://api.example.test/v1', $http);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected HTTP status from getUser: 404');
        $api->getUser(88);
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
