<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** The generated client carries the `openIdConnectUrl` of an OpenID Connect scheme, so the SDK can run the discovery. */
final class OpenIdConnectDiscoveryGenerationTest extends TestCase
{
    public function testTheUrlOfTheDiscoveryDocumentIsInTheSchemeMetadata(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson((string) file_get_contents(__DIR__ . '/fixtures/openid-connect-openapi31.json'));
        $files = (new PhpExecutableEmitter('Example\\OidcEmit', 'example/oidc', []))->emit($contract);
        self::assertStringContainsString("'type' => 'openIdConnect'", $files['src/Client.php']);
        self::assertStringContainsString("'openIdConnectUrl' => 'https://login.example.test/tenant/.well-known/openid-configuration'", $files['src/Client.php']);
    }

    public function testOtherSchemesCarryNoUrl(): void
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/openid-connect-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['components']['securitySchemes'] = ['oidc' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => ['tokenUrl' => 'https://a.example/token', 'scopes' => ['openid' => 'x']]]]];
        $files = (new PhpExecutableEmitter('Example\\OidcOther', 'example/oidc', []))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('openIdConnectUrl', $files['src/Client.php']);
    }

    public function testAGeneratedClientRunsTheDiscoveryOfItsScheme(): void
    {
        $files = (new PhpExecutableEmitter('Example\\OidcRun', 'example/oidc', []))->emit(
            (new SpecificationNormalizer())->fromJson((string) file_get_contents(__DIR__ . '/fixtures/openid-connect-openapi31.json')),
        );
        $directory = sys_get_temp_dir() . '/oidc-sdk-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\OidcRun\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $issuer = 'https://login.example.test/tenant';
            $http = new class ($issuer) implements \Psr\Http\Client\ClientInterface {
                /** @var list<string> */
                public array $urls = [];

                public function __construct(private string $issuer) {}

                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $this->urls[] = (string) $request->getUri();
                    return new \GuzzleHttp\Psr7\Response(200, [], json_encode(['issuer' => $this->issuer, 'authorization_endpoint' => $this->issuer . '/authorize', 'token_endpoint' => $this->issuer . '/token'], JSON_THROW_ON_ERROR));
                }
            };
            $endpoints = \Example\OidcRun\Client::openIdDiscovery('oidc', new \Dmorenof\SdkRuntime\Security\DiscoveryOptions($http))->configuration()->endpoints();
            self::assertSame(['https://login.example.test/tenant/.well-known/openid-configuration'], $http->urls);
            self::assertSame($issuer . '/token', $endpoints->tokenUrl);
            self::assertSame($issuer, $endpoints->issuer);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
