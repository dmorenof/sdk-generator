<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class StaticFacadeGenerationTest extends TestCase
{
    public function testRouteFacadeSharesMiddlewareAndConfiguredConnectionSwitching(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        $contract = (new SpecificationNormalizer())->fromJson($source);
        $files = (new PhpExecutableEmitter('Example\\StaticFacadeSdk', 'example/static-facade-sdk'))->emit($contract);
        foreach ([
            'src/Enums/NewUser/RoleEnum.php', 'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php', 'src/DTOs/Profile.php',
            'src/DTOs/NewUser.php', 'src/DTOs/User.php',
            'src/GetParams/Users/ById.php',
            'src/Client.php', 'src/Configuration.php', 'src/ExampleAccountsSDK.php',
            'src/Resources/Users.php', 'src/Users.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }

        self::assertTrue((new \ReflectionMethod(\Example\StaticFacadeSdk\Users::class, 'getById'))->isStatic());
        self::assertFalse((new \ReflectionMethod(\Example\StaticFacadeSdk\Resources\Users::class, 'getById'))->isStatic());
        self::assertArrayNotHasKey('src/StaticClient.php', $files);
        self::assertArrayNotHasKey('src/StaticApi/Users/User.php', $files);
        try {
            \Example\StaticFacadeSdk\Users::getById(88);
            self::fail('An unconfigured call must fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Configure the SDK', $exception->getMessage());
        }

        $response = json_encode([
            'id' => 88, 'name' => 'Ada', 'role' => 'admin',
            'profile' => ['city' => 'Madrid', 'visits' => 2], 'labels' => ['php', 'sdk'],
        ], JSON_THROW_ON_ERROR);
        $history = [];
        $events = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $response),
            new Response(201, ['Content-Type' => 'application/json'], $response),
        ]));
        $stack->push(Middleware::history($history));

        $config = new \Example\StaticFacadeSdk\Configuration();
        $config->baseUrl = 'https://api.example.test/v1';
        $config->httpClient = new GuzzleClient(['handler' => $stack]);
        $config->headers = ['Authorization' => 'Bearer test'];
        $config->middleware[] = new class($events) implements \Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface {
            public function __construct(private \ArrayObject $events) {}
            public function beforeRequest(RequestInterface $request): RequestInterface
            {
                $this->events->append('request:' . $request->getMethod());
                return $request;
            }
            public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                $this->events->append('response:' . $response->getStatusCode());
                return $response;
            }
        };
        \Example\StaticFacadeSdk\ExampleAccountsSDK::configure($config);
        $first = \Example\StaticFacadeSdk\Client::current();
        $params = new \Example\StaticFacadeSdk\GetParams\Users\ById((object) ['verbose' => false]);
        $user = \Example\StaticFacadeSdk\Users::getById(88, $params);
        self::assertSame('Ada', $user->name);
        self::assertSame('Madrid', $user->profile->city);
        $body = new \Example\StaticFacadeSdk\DTOs\NewUser();
        $body->name = 'Grace';
        self::assertSame('Ada', \Example\StaticFacadeSdk\Users::post($body)->name);
        self::assertSame(['request:GET', 'response:200', 'request:POST', 'response:201'], $events->getArrayCopy());
        self::assertSame('https://api.example.test/v1/users/88?verbose=false', (string) $history[0]['request']->getUri());
        self::assertSame('https://api.example.test/v1/users', (string) $history[1]['request']->getUri());
        self::assertSame('Bearer test', $history[0]['request']->getHeaderLine('Authorization'));

        $secondHistory = [];
        $secondStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $response),
        ]));
        $secondStack->push(Middleware::history($secondHistory));
        $second = new \Example\StaticFacadeSdk\Configuration();
        $second->baseUrl = 'https://other-account.test/api';
        $second->httpClient = new GuzzleClient(['handler' => $secondStack]);
        $second->headers = ['Authorization' => 'Bearer second'];
        \Example\StaticFacadeSdk\Client::configure($second);

        self::assertNotSame($first, \Example\StaticFacadeSdk\Client::current());
        self::assertSame('Ada', \Example\StaticFacadeSdk\Users::getById(88)->name);
        self::assertCount(2, $history);
        self::assertCount(1, $secondHistory);
        self::assertSame('https://other-account.test/api/users/88', (string) $secondHistory[0]['request']->getUri());
        self::assertSame('Bearer second', $secondHistory[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(['request:GET', 'response:200', 'request:POST', 'response:201'], $events->getArrayCopy());

        $thirdHistory = [];
        $thirdStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $response),
        ]));
        $thirdStack->push(Middleware::history($thirdHistory));
        $third = new \Example\StaticFacadeSdk\Configuration();
        $third->baseUrl = 'https://third-account.test';
        $third->httpClient = new GuzzleClient(['handler' => $thirdStack]);
        $third->headers = ['Authorization' => 'Bearer third'];
        \Example\StaticFacadeSdk\Client::configure($third);

        self::assertSame('Ada', \Example\StaticFacadeSdk\Users::getById(88)->name);
        self::assertSame('https://third-account.test/users/88', (string) $thirdHistory[0]['request']->getUri());
        self::assertSame('Bearer third', $thirdHistory[0]['request']->getHeaderLine('Authorization'));
    }
}
