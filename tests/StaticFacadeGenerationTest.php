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
    public function testTypedStaticCallsShareConfiguredMiddlewareAndAllowConnectionSwitching(): void
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        $contract = (new SpecificationNormalizer())->fromJson($json);
        $files = (new PhpExecutableEmitter('Example\\StaticFacadeSdk', 'example/static-facade-sdk'))->emit($contract);

        foreach ([
            'src/Enums/NewUser/RoleEnum.php', 'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php', 'src/DTOs/Profile.php',
            'src/Requests/NewUser.php', 'src/Responses/User.php',
            'src/Client.php', 'src/Api/Users.php', 'src/Api/Users/User.php',
            'src/StaticClient.php', 'src/StaticApi/Users.php', 'src/StaticApi/Users/User.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }

        self::assertTrue((new \ReflectionMethod(\Example\StaticFacadeSdk\StaticApi\Users\User::class, 'getById'))->isStatic());
        self::assertFalse((new \ReflectionMethod(\Example\StaticFacadeSdk\Api\Users\User::class, 'getById'))->isStatic());
        try {
            \Example\StaticFacadeSdk\StaticApi\Users\User::getById(88);
            self::fail('An unconfigured static call must fail');
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

        $client = \Example\StaticFacadeSdk\Client::connect(
            'https://api.example.test/v1',
            new GuzzleClient(['handler' => $stack]),
            ['Authorization' => 'Bearer test'],
        );
        $client->withMiddleware(new class($events) implements \Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface {
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
        });
        \Example\StaticFacadeSdk\StaticClient::configure($client);

        $user = \Example\StaticFacadeSdk\StaticApi\Users\User::getById(88, false);
        self::assertInstanceOf(\Example\StaticFacadeSdk\Responses\User::class, $user);
        self::assertSame('Ada', $user->name);
        self::assertSame('Madrid', $user->profile->city);
        $input = new \Example\StaticFacadeSdk\Requests\NewUser();
        $input->name = 'Grace';
        $created = \Example\StaticFacadeSdk\StaticApi\Users::post($input);
        self::assertSame('Ada', $created->name);
        self::assertSame(['request:GET', 'response:200', 'request:POST', 'response:201'], $events->getArrayCopy());
        self::assertSame('https://api.example.test/v1/users/88?verbose=false', (string) $history[0]['request']->getUri());
        self::assertSame('https://api.example.test/v1/users', (string) $history[1]['request']->getUri());
        self::assertSame('Bearer test', $history[0]['request']->getHeaderLine('Authorization'));

        $secondHistory = [];
        $secondStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $response),
        ]));
        $secondStack->push(Middleware::history($secondHistory));
        $second = \Example\StaticFacadeSdk\Client::connect(
            'https://other-account.test/api',
            new GuzzleClient(['handler' => $secondStack]),
            ['Authorization' => 'Bearer second'],
        );

        \Example\StaticFacadeSdk\StaticClient::configure($second);
        self::assertSame($second, \Example\StaticFacadeSdk\StaticClient::client());
        $other = \Example\StaticFacadeSdk\StaticApi\Users\User::getById(88);
        self::assertSame('Ada', $other->name);
        self::assertCount(2, $history);
        self::assertCount(1, $secondHistory);
        self::assertSame('https://other-account.test/api/users/88', (string) $secondHistory[0]['request']->getUri());
        self::assertSame('Bearer second', $secondHistory[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(['request:GET', 'response:200', 'request:POST', 'response:201'], $events->getArrayCopy());

        // connect() can also replace the current client, including custom HTTP adapters.
        $thirdHistory = [];
        $thirdStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $response),
        ]));
        $thirdStack->push(Middleware::history($thirdHistory));
        \Example\StaticFacadeSdk\StaticClient::connect(
            'https://third-account.test',
            new GuzzleClient(['handler' => $thirdStack]),
            ['Authorization' => 'Bearer third'],
        );
        self::assertSame('Ada', \Example\StaticFacadeSdk\StaticApi\Users\User::getById(88)->name);
        self::assertSame('https://third-account.test/users/88', (string) $thirdHistory[0]['request']->getUri());
        self::assertSame('Bearer third', $thirdHistory[0]['request']->getHeaderLine('Authorization'));
        self::assertCount(1, $secondHistory);
    }
}
