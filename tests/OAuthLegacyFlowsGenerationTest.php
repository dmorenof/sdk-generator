<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * A contract may declare the OAuth2 password and implicit flows. The SDK only needs a bearer token (a string, or one
 * of the runtime's token providers: OAuth2Password, OAuth2Implicit), so such a contract generates like any other.
 */
final class OAuthLegacyFlowsGenerationTest extends TestCase
{
    /** @param array<string, mixed> $scheme */
    private static function document(array $scheme, string $dialect): string
    {
        $paths = ['/me' => ['get' => ['operationId' => 'getMe', 'security' => [['oauth' => ['read']]],
            'responses' => ['200' => ['description' => 'ok', 'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]]]]];
        if ($dialect === 'swagger') {
            return json_encode(['swagger' => '2.0', 'info' => ['title' => 'Legacy', 'version' => '1'], 'host' => 'api.example.test', 'schemes' => ['https'],
                'paths' => $paths, 'securityDefinitions' => ['oauth' => $scheme]], JSON_THROW_ON_ERROR);
        }
        $paths['/me']['get']['responses']['200'] = ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]]];
        return json_encode(['openapi' => '3.0.3', 'info' => ['title' => 'Legacy', 'version' => '1'], 'servers' => [['url' => 'https://api.example.test']],
            'paths' => $paths, 'components' => ['securitySchemes' => ['oauth' => $scheme]]], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function schemes(): array
    {
        $scopes = ['read' => 'Read'];
        return [
            'OpenAPI password' => [['type' => 'oauth2', 'flows' => ['password' => ['tokenUrl' => 'https://a.example/token', 'scopes' => $scopes]]], 'openapi'],
            'OpenAPI implicit' => [['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'https://a.example/auth', 'scopes' => $scopes]]], 'openapi'],
            'OpenAPI all flows' => [['type' => 'oauth2', 'flows' => [
                'implicit' => ['authorizationUrl' => 'https://a.example/auth', 'scopes' => $scopes],
                'password' => ['tokenUrl' => 'https://a.example/token', 'scopes' => $scopes],
                'authorizationCode' => ['authorizationUrl' => 'https://a.example/auth', 'tokenUrl' => 'https://a.example/token', 'scopes' => $scopes],
            ]], 'openapi'],
            'Swagger password' => [['type' => 'oauth2', 'flow' => 'password', 'tokenUrl' => 'https://a.example/token', 'scopes' => $scopes], 'swagger'],
            'Swagger implicit' => [['type' => 'oauth2', 'flow' => 'implicit', 'authorizationUrl' => 'https://a.example/auth', 'scopes' => $scopes], 'swagger'],
        ];
    }

    /** @param array<string, mixed> $scheme */
    #[\PHPUnit\Framework\Attributes\DataProvider('schemes')]
    public function testAContractWithTheFlowGeneratesABearerClient(array $scheme, string $dialect): void
    {
        $files = (new PhpExecutableEmitter('Example\\Legacy', 'example/legacy', []))->emit((new SpecificationNormalizer())->fromJson(self::document($scheme, $dialect)));
        self::assertStringContainsString("'type' => 'oauth2'", $files['src/Client.php']);
    }

    public function testAFlowThatDoesNotExistIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(self::document(['type' => 'oauth2', 'flows' => ['device' => ['tokenUrl' => 'https://a.example/token', 'scopes' => []]]], 'openapi'));
    }
}
