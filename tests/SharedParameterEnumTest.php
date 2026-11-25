<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Sendbird lists `GET` and `POST /v3/users/{user_id}/push/{token_type}` with the same inline `token_type` enum. The enum of a
 * parameter was named after its operation, so each operation made its own copy and the one file of path parameters of the route came
 * out different depending on which operation wrote it. The identical enum of a route is one class, as identical error bodies are.
 */
final class SharedParameterEnumTest extends TestCase
{
    /** @param list<string> $postValues @return array<string, mixed> */
    private static function document(array $postValues = ['gcm', 'huawei', 'apns']): array
    {
        $parameters = static fn (array $values): array => [
            ['name' => 'user_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'token_type', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => $values]],
        ];
        $ok = ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]]]]];
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Push API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/v3/users/{user_id}/push/{token_type}' => [
                'get' => ['operationId' => 'listTokens', 'parameters' => $parameters(['gcm', 'huawei', 'apns']), 'responses' => $ok],
                'post' => ['operationId' => 'registerToken', 'parameters' => $parameters($postValues), 'responses' => $ok],
            ]],
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private static function emit(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\Push', 'example/push'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }

    /** @param array<string, string> $files @return list<string> */
    private static function enumFiles(array $files): array
    {
        return array_values(array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'src/Enums/')));
    }

    public function testTheSameEnumOnTheSameRouteIsOneClass(): void
    {
        $files = self::emit(self::document());
        self::assertCount(1, self::enumFiles($files), 'one enum for token_type, not one per operation');
        $enum = $files[self::enumFiles($files)[0]];
        foreach (['gcm', 'huawei', 'apns'] as $value) {
            self::assertStringContainsString("'$value'", $enum);
        }
    }

    public function testBothOperationsUseThatOneEnum(): void
    {
        $files = self::emit(self::document());
        $enumClass = '\\' . str_replace('/', '\\', substr(self::enumFiles($files)[0], strlen('src/'), -strlen('.php')));
        $paramsFiles = array_filter($files, static fn (string $path): bool => str_starts_with($path, 'src/PathParams/'), ARRAY_FILTER_USE_KEY);
        self::assertCount(1, $paramsFiles, 'one file of path parameters for the route');
        self::assertStringContainsString('Example\\Push' . $enumClass, '\\Example\\Push' . $enumClass); // the namespace the enum lives under
        self::assertStringContainsString(ltrim($enumClass, '\\'), implode("\n", $paramsFiles));
    }

    public function testDifferentValuesOnTheSameRouteStayDifferentEnumsAndStillConflict(): void
    {
        // Two different enums for one parameter of one route cannot share the route's one file of path parameters: still an explicit error.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting path parameter definitions');
        self::emit(self::document(['gcm', 'apns']));
    }
}
