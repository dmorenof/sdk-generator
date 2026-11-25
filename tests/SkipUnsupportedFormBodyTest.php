<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * `--skip-unsupported` leaves out the operations the generator cannot execute instead of refusing the whole API. A form body it
 * cannot build (Sendbird's bot message sends nested objects in `multipart/form-data`) is one of them: it used to abort the
 * emitter after the normalizer had let it through.
 */
final class SkipUnsupportedFormBodyTest extends TestCase
{
    private static int $count = 0;

    /** @param array<string, mixed> $multipart the schema of the multipart body of POST /upload */
    private static function spec(array $multipart): string
    {
        return json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Form', 'version' => '1'],
            'servers' => [['url' => 'https://form.example.com']],
            'paths' => [
                '/things' => ['get' => ['operationId' => 'things', 'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]]]]],
                '/upload' => ['post' => ['operationId' => 'upload',
                    'requestBody' => ['content' => ['multipart/form-data' => ['schema' => $multipart]]],
                    'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => ['schemas' => ['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private static function emit(string $json, SpecificationNormalizer $normalizer): array
    {
        return (new PhpExecutableEmitter('Example\\Form' . ++self::$count, 'example/form', []))->emit($normalizer->fromJson($json));
    }

    /** A form body that declares itself open: the SDK cannot send fields it does not know. */
    private static function nestedObject(): array
    {
        return ['type' => 'object', 'additionalProperties' => true, 'required' => ['channel'], 'properties' => [
            'channel' => ['type' => 'string'],
            'payload' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
        ]];
    }

    public function testWithoutTheOptionAFormBodyTheEmitterCannotBuildStillRefusesTheDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported form body schema or encoding at upload');
        self::emit(self::spec(self::nestedObject()), new SpecificationNormalizer());
    }

    public function testTheOptionLeavesThatOperationOutAndKeepsTheRest(): void
    {
        $normalizer = new SpecificationNormalizer(false, true);
        $files = self::emit(self::spec(self::nestedObject()), $normalizer);
        self::assertArrayHasKey('post /upload', $normalizer->skippedOperations());
        self::assertStringContainsString('form body', $normalizer->skippedOperations()['post /upload']);
        self::assertSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Upload')));
        self::assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Things')));
    }

    public function testAFormBodyThatDoesNotSayItIsOpenIsBuiltFromItsDeclaredFields(): void
    {
        // Sendbird's bot message declares no additionalProperties: that is not "open", it is silent, and the SDK sends what is declared.
        $schema = self::nestedObject();
        unset($schema['additionalProperties']);
        $normalizer = new SpecificationNormalizer();
        $files = self::emit(self::spec($schema), $normalizer);
        self::assertSame([], $normalizer->skippedOperations());
        self::assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Upload')));
    }

    public function testAFormBodyTheEmitterCanBuildIsKeptEvenWithTheOption(): void
    {
        $normalizer = new SpecificationNormalizer(false, true);
        // Forms accept an explicitly closed, flat object (docs/FORMS.md): the closed one stays, the open nested one is left out.
        $files = self::emit(self::spec(['type' => 'object', 'additionalProperties' => false, 'required' => ['file'], 'properties' => [
            'file' => ['type' => 'string', 'format' => 'binary'],
            'name' => ['type' => 'string'],
        ]]), $normalizer);
        self::assertSame([], $normalizer->skippedOperations());
        self::assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Upload')));
    }
}
