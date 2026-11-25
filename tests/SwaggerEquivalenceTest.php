<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Swagger 2.0 and OpenAPI 3.1 read the same way. The same API written in both dialects (what both can say: parameters, bodies, answers and
 * their status codes, references, collection formats) must give the very same SDK, file by file. The fixtures that are the same API
 * in both dialects are listed here; the others (`forms`, `multipart`, `downloads`...) are written differently in each dialect on purpose,
 * and are exercised on their own by their generation tests and smoke scripts.
 */
final class SwaggerEquivalenceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function sameApi(): iterable
    {
        foreach (['accounts', 'catalog', 'query-arrays', 'query-delimited', 'simple-parameters', 'status-responses'] as $name) {
            yield $name => [__DIR__ . "/fixtures/$name-swagger2.json", __DIR__ . "/fixtures/$name-openapi31.json"];
        }
    }

    /** @return array<string, string> */
    private static function sdk(string $file): array
    {
        $contract = (new SpecificationNormalizer())->fromJson((string) file_get_contents($file));
        return (new PhpExecutableEmitter('Dialect\\Sdk', 'example/dialect-sdk'))->emit($contract);
    }

    #[DataProvider('sameApi')]
    public function testTheSameApiGivesTheSameSdkInBothDialects(string $swagger, string $openapi): void
    {
        $expected = self::sdk($openapi);
        $actual = self::sdk($swagger);

        self::assertSame(array_keys($expected), array_keys($actual), 'The two dialects generate different files.');
        foreach ($expected as $path => $code) {
            self::assertSame($code, $actual[$path], "The two dialects generate different code for $path.");
        }
    }
}
