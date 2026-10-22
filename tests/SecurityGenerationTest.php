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
final class SecurityGenerationTest extends TestCase
{
    /** @return array<string, string> */
    private static function generatedFiles(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json');
        $spec = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $spec['components']['securitySchemes'] = [
            'Bearer' => ['type' => 'http', 'scheme' => 'bearer'],
            'HeaderKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            'QueryKey' => ['type' => 'apiKey', 'in' => 'query', 'name' => 'api_key'],
            'Basic' => ['type' => 'http', 'scheme' => 'basic'],
        ];
        $spec['paths']['/users/{id}']['get']['security'] = [
            ['Bearer' => [], 'HeaderKey' => []],
            ['QueryKey' => []],
            ['Basic' => []],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\SecuritySdk', 'example/security-sdk'))->emit($contract);
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
            'src/Core/ApiClient.php',
            'src/Core/ApiException.php',
            'src/Core/BasicAuthCredentials.php',
            'src/Core/SecurityCredentials.php',
            'src/Core/Security.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Client.php',
            'src/Api/Users/User.php',
        ] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public function testBearerAndApiKeyAreAppliedAsAnAndRequirement(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response()]));
        $stack->push(Middleware::history($history));
        $client = \Example\SecuritySdk\Client::connect(
            'https://api.example.test/v1',
            new Client(['handler' => $stack]),
            ['Authorization' => 'caller-value'],
            new \Example\SecuritySdk\Core\SecurityCredentials([
                'Bearer' => 'test-token',
                'HeaderKey' => 'test-key',
            ]),
        );

        $client->resource(\Example\SecuritySdk\Api\Users\User::class)->getById(88);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        self::assertSame('test-key', $request->getHeaderLine('X-API-Key'));
    }

    public function testApiKeyQueryAlternativeIsSelectedWhenBearerGroupIsIncomplete(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response()]));
        $stack->push(Middleware::history($history));
        $client = \Example\SecuritySdk\Client::connect(
            'https://api.example.test/v1',
            new Client(['handler' => $stack]),
            [],
            new \Example\SecuritySdk\Core\SecurityCredentials(['QueryKey' => 'query-key']),
        );

        $client->resource(\Example\SecuritySdk\Api\Users\User::class)->getById(88);

        self::assertSame(
            'https://api.example.test/v1/users/88?api_key=query-key',
            (string) $history[0]['request']->getUri(),
        );
    }

    public function testBasicAuthAlternativeUsesEncodedCredentials(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response()]));
        $stack->push(Middleware::history($history));
        $client = \Example\SecuritySdk\Client::connect(
            'https://api.example.test/v1',
            new Client(['handler' => $stack]),
            [],
            new \Example\SecuritySdk\Core\SecurityCredentials([
                'Basic' => new \Example\SecuritySdk\Core\BasicAuthCredentials('user', 'secret'),
            ]),
        );

        $client->resource(\Example\SecuritySdk\Api\Users\User::class)->getById(88);

        self::assertSame('Basic ' . base64_encode('user:secret'), $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testMissingCredentialsFailBeforeSendingRequest(): void
    {
        $client = \Example\SecuritySdk\Client::connect(
            'https://api.example.test/v1',
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No configured credentials satisfy');
        $client->resource(\Example\SecuritySdk\Api\Users\User::class)->getById(88);
    }

    private static function response(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 88,
            'name' => 'Ada',
            'role' => 'admin',
            'profile' => ['city' => 'Madrid', 'visits' => 2],
            'labels' => ['php', 'sdk'],
        ], JSON_THROW_ON_ERROR));
    }
}
