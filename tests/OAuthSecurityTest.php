<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Security\TokenProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OAuthSecurityTest extends TestCase
{
    private const OPENAPI_FLOWS = ['clientCredentials' => ['tokenUrl' => 'https://auth.example.test/token', 'scopes' => ['read' => 'Read items']]];

    /** @return array<string, mixed> */
    private static function document(): array
    {
        $ok = ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]]]]];
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'OAuth API', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => [
                '/items' => ['get' => ['operationId' => 'listItems', 'security' => [['OAuth2' => ['read']]], 'responses' => $ok]],
                '/me' => ['get' => ['operationId' => 'getMe', 'security' => [['Oidc' => []]], 'responses' => $ok]],
            ],
            'components' => ['securitySchemes' => [
                'OAuth2' => ['type' => 'oauth2', 'flows' => self::OPENAPI_FLOWS],
                'Oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://auth.example.test/.well-known/openid-configuration'],
                'Key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'],
            ]],
        ];
    }

    private static function normalize(array $document): \Dmorenof\SdkGenerator\Contract\ApiContract
    {
        return (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function testOAuth2AndOpenIdConnectSchemesAreAcceptedWithTheirScopes(): void
    {
        $contract = self::normalize(self::document());
        $types = [];
        foreach ($contract->securitySchemes as $scheme) {
            $types[$scheme->name] = $scheme->type;
        }
        self::assertSame(['OAuth2' => 'oauth2', 'Oidc' => 'openIdConnect', 'Key' => 'apiKey'], $types);
        self::assertSame([['OAuth2' => ['read']]], $contract->operations[0]->securityRequirements);
        self::assertSame([['Oidc' => []]], $contract->operations[1]->securityRequirements);
    }

    public function testSwagger2OAuth2IsAccepted(): void
    {
        $contract = self::normalize([
            'swagger' => '2.0',
            'info' => ['title' => 'Old API', 'version' => '1'],
            'host' => 'api.example.test',
            'schemes' => ['https'],
            'basePath' => '/v1',
            'paths' => ['/items' => ['get' => ['operationId' => 'listItems', 'security' => [['Auth' => ['read']]], 'responses' => ['204' => ['description' => 'none']]]]],
            'securityDefinitions' => ['Auth' => ['type' => 'oauth2', 'flow' => 'accessCode', 'authorizationUrl' => 'https://auth.example.test/authorize', 'tokenUrl' => 'https://auth.example.test/token', 'scopes' => ['read' => 'Read']]],
        ]);
        self::assertSame('oauth2', $contract->securitySchemes[0]->type);
    }

    /** @return iterable<string, array{callable(array): array, string}> */
    public static function malformed(): iterable
    {
        yield 'oauth2 without flows' => [static function (array $d): array { $d['components']['securitySchemes']['OAuth2'] = ['type' => 'oauth2']; return $d; }, 'at least one flow'];
        yield 'empty flows' => [static function (array $d): array { $d['components']['securitySchemes']['OAuth2']['flows'] = []; return $d; }, 'at least one flow'];
        yield 'unknown flow' => [static function (array $d): array { $d['components']['securitySchemes']['OAuth2']['flows'] = ['magic' => []]; return $d; }, 'Unknown OAuth2 flow'];
        yield 'missing token url' => [static function (array $d): array { $d['components']['securitySchemes']['OAuth2']['flows']['clientCredentials'] = ['scopes' => []]; return $d; }, 'must declare tokenUrl'];
        yield 'missing scopes' => [static function (array $d): array { $d['components']['securitySchemes']['OAuth2']['flows']['clientCredentials'] = ['tokenUrl' => 'https://x.test/t']; return $d; }, 'scopes as an object'];
        yield 'openid without url' => [static function (array $d): array { $d['components']['securitySchemes']['Oidc'] = ['type' => 'openIdConnect']; return $d; }, 'openIdConnectUrl'];
        yield 'scopes on an api key' => [static function (array $d): array { $d['paths']['/items']['get']['security'] = [['Key' => ['read']]]; return $d; }, 'Scopes are only valid'];
    }

    /** @param callable(array): array $break */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedDeclarationsAreRefused(callable $break, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        self::normalize($break(self::document()));
    }

    public function testTheAuditorAcceptsWellFormedSchemesAndReportsBrokenOnes(): void
    {
        $codes = static fn (array $document): array => array_map(
            static fn ($issue): string => $issue->code,
            (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR), sourceName: 'oauth.json')['diagnostics'],
        );
        self::assertNotContains('unsupported_security_scheme', $codes(self::document()));
        self::assertNotContains('unsupported_security_scopes', $codes(self::document()));
        $broken = self::document();
        $broken['components']['securitySchemes']['OAuth2'] = ['type' => 'oauth2', 'flows' => []];
        self::assertContains('unsupported_security_scheme', $codes($broken));
    }

    public function testGeneratedSdkSendsTheBearerTokenOfAProviderForOAuth2AndOpenIdConnect(): void
    {
        $directory = sys_get_temp_dir() . '/oauth-sdk-' . bin2hex(random_bytes(6));
        foreach ((new PhpExecutableEmitter('Example\\OAuthSdk', 'example/oauth-sdk'))->emit(self::normalize(self::document())) as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\OAuthSdk\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $provider = new class implements TokenProviderInterface {
                public int $invalidated = 0;
                public function accessToken(): string { return 'issued-token'; }
                public function invalidate(): void { $this->invalidated++; }
            };
            $history = [];
            $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"id":1}'), new Response(200, ['Content-Type' => 'application/json'], '{"id":2}')]));
            $stack->push(Middleware::history($history));
            $configuration = new \Dmorenof\SdkRuntime\Configuration();
            $configuration->baseUrl = 'https://api.example.test';
            $configuration->httpClient = new Client(['handler' => $stack]);
            $configuration->securityCredentials = new SecurityCredentials(['OAuth2' => $provider, 'Oidc' => $provider]);
            $client = \Example\OAuthSdk\Client::connect($configuration);
            $api = $client->resource(\Example\OAuthSdk\Resources\Items::class);
            self::assertSame(1, $api->get()->id);
            $me = $client->resource(\Example\OAuthSdk\Resources\Me::class);
            self::assertSame(2, $me->get()->id);
            self::assertSame('Bearer issued-token', $history[0]['request']->getHeaderLine('Authorization'));
            self::assertSame('Bearer issued-token', $history[1]['request']->getHeaderLine('Authorization'));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testTheGeneratedClientDeclaresTheSchemeTypes(): void
    {
        $files = (new PhpExecutableEmitter('Example\\OAuthTypes', 'example/oauth-sdk'))->emit(self::normalize(self::document()));
        self::assertStringContainsString("'type' => 'oauth2'", $files['src/Client.php']);
        self::assertStringContainsString("'type' => 'openIdConnect'", $files['src/Client.php']);
    }
}
