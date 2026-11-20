<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two patterns real specifications use (Frankfurter's, for one): a default beside a reference written as a
 * one-branch `allOf`, and a `propertyNames` that points at an enum.
 */
final class ReferenceWrapperGenerationTest extends TestCase
{
    /** @param array<string, mixed> $override Replaces schemas by name. */
    private static function spec(string $version, array $override = []): string
    {
        $schemas = $override + [
            'Currency' => ['type' => 'string', 'enum' => ['EUR', 'USD', 'GBP']],
            'CurrencyIn' => ['allOf' => [['$ref' => '#/components/schemas/Currency']], 'default' => 'EUR'],
            // `propertyNames` is JSON Schema 2020-12: OpenAPI 3.1 has it, 3.0 does not.
            'Rates' => ['type' => 'object', 'additionalProperties' => ['type' => 'number']]
                + (str_starts_with($version, '3.1') ? ['propertyNames' => ['$ref' => '#/components/schemas/Currency']] : []),
        ];
        return json_encode([
            'openapi' => $version,
            'info' => ['title' => 'Rates', 'version' => '1'],
            'servers' => [['url' => 'https://rates.example.com']],
            'paths' => ['/latest' => ['get' => [
                'operationId' => 'latest',
                'parameters' => [['name' => 'base', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/CurrencyIn']]],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Rates']]]]],
            ]]],
            'components' => ['schemas' => $schemas],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string}> */
    public static function versions(): iterable
    {
        yield 'OpenAPI 3.0' => ['3.0.3'];
        yield 'OpenAPI 3.1' => ['3.1.0'];
    }

    #[DataProvider('versions')]
    public function testADefaultBesideAReferenceKeepsTheReferencedType(string $version): void
    {
        $files = (new PhpExecutableEmitter('Example\\Wrapper' . md5($version), 'example/wrapper', []))->emit((new SpecificationNormalizer())->fromJson(self::spec($version)));
        $params = implode("\n", $files);
        self::assertMatchesRegularExpression('/public \?\\\\Example\\\\Wrapper\w+\\\\Enums\\\\Currency \$base;/', $params);
    }

    public function testPropertyNamesThatReferenceAnEnumConstrainTheKeys(): void
    {
        $files = (new PhpExecutableEmitter('Example\\Names', 'example/names', []))->emit((new SpecificationNormalizer())->fromJson(self::spec('3.1.0')));
        $rates = implode("\n", array_filter($files, static fn (string $path): bool => str_ends_with($path, 'DTOs/Rates.php'), ARRAY_FILTER_USE_KEY));
        self::assertStringContainsString('"propertyNames":{"type":"string","enum":["EUR","USD","GBP"]}', $rates);
    }

    public function testAReferenceWithAnotherAssertionBesideItIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(self::spec('3.0.3', [
            'Currency' => ['type' => 'string'],
            'CurrencyIn' => ['allOf' => [['$ref' => '#/components/schemas/Currency']], 'maxLength' => 3],
        ]));
    }

    public function testKeyNamesThatReferenceAnotherReferenceAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('propertyNames');
        (new SpecificationNormalizer())->fromJson(self::spec('3.1.0', [
            'Inner' => ['type' => 'string'],
            'Currency' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Inner']],
        ]));
    }

    public function testABareOneBranchAllOfIsStillInheritanceNotAnAlias(): void
    {
        $spec = json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pets', 'version' => '1'],
            'servers' => [['url' => 'https://pets.example.com']],
            'paths' => ['/pet' => ['get' => ['operationId' => 'pet', 'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Dog']]]]]]]],
            'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Pet']]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $files = (new PhpExecutableEmitter('Example\\Inherit', 'example/inherit', []))->emit((new SpecificationNormalizer())->fromJson($spec));
        self::assertStringContainsString('extends \\Example\\Inherit\\DTOs\\Pet', implode("\n", $files));
    }
}
