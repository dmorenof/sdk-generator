<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Patterns found by generating from real public APIs (PokeAPI, weather.gov, Wikimedia, met.no).
 */
final class RealWorldPatternsTest extends TestCase
{
    private static int $count = 0;

    /**
     * @param array<string, mixed> $schemas
     * @param array<string, mixed> $paths
     * @param array<string, mixed> $extra
     */
    private static function spec(array $schemas, array $paths = [], array $extra = [], string $version = '3.1.0'): string
    {
        $paths += ['/things' => ['get' => [
            'operationId' => 'things',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . array_key_first($schemas)]]]]],
        ]]];
        return json_encode($extra + [
            'openapi' => $version,
            'info' => ['title' => 'Real', 'version' => '1'],
            'servers' => [['url' => 'https://real.example.com']],
            'paths' => $paths,
            'components' => ['schemas' => $schemas],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private static function emit(string $json, ?SpecificationNormalizer $normalizer = null): array
    {
        return (new PhpExecutableEmitter('Example\\Real' . ++self::$count, 'example/real', []))->emit(($normalizer ?? new SpecificationNormalizer())->fromJson($json));
    }

    public function testAReadOnlyReferenceWrittenAsAOneBranchAllOfKeepsTheReferencedType(): void
    {
        $files = self::emit(self::spec([
            'Detail' => ['type' => 'object', 'properties' => ['flavor' => ['allOf' => [['$ref' => '#/components/schemas/Flavor']], 'readOnly' => true, 'description' => 'd']]],
            'Flavor' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]));
        self::assertMatchesRegularExpression('/public \??\\\\Example\\\\Real\d+\\\\DTOs\\\\Flavor \$flavor/', implode("\n", array_filter($files, static fn (string $p): bool => str_ends_with($p, 'DTOs/Detail.php'), ARRAY_FILTER_USE_KEY)));
    }

    public function testANullableReferenceMarkedReadOnlyIsAReferenceThatMayBeNull(): void
    {
        $files = self::emit(self::spec([
            'Detail' => ['type' => 'object', 'properties' => ['chain' => ['oneOf' => [['$ref' => '#/components/schemas/Chain'], ['type' => 'null']], 'readOnly' => true]]],
            'Chain' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]));
        self::assertNotSame([], $files);
    }

    public function testRequiredBesideAnAllOfIsReadAsRequiredOfTheComposition(): void
    {
        $files = self::emit(self::spec([
            'Story' => [
                'required' => ['title', 'order'],
                'allOf' => [['$ref' => '#/components/schemas/Base'], ['type' => 'object', 'properties' => ['order' => ['type' => 'integer']]]],
            ],
            'Base' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
        ]));
        self::assertNotSame([], $files);
    }

    public function testAUnionOfStringEnumsIsOneEnumAndWorksAsAnArrayParameterItem(): void
    {
        $json = self::spec(
            [
                'Code' => ['oneOf' => [['$ref' => '#/components/schemas/Land'], ['$ref' => '#/components/schemas/Marine']], 'description' => 'Codes'],
                'Land' => ['type' => 'string', 'enum' => ['AL', 'AM']],
                'Marine' => ['type' => 'string', 'enum' => ['GM', 'PH']],
            ],
            ['/zones' => ['get' => [
                'operationId' => 'zones',
                'parameters' => [['name' => 'region', 'in' => 'query', 'style' => 'form', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Code']]]],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'string']]]]],
            ]]],
        );
        $files = self::emit($json);
        $enum = implode("\n", array_filter($files, static fn (string $p): bool => str_ends_with($p, 'Enums/Code.php'), ARRAY_FILTER_USE_KEY));
        foreach (['AL', 'AM', 'GM', 'PH'] as $value) {
            self::assertStringContainsString("'$value'", $enum);
        }
    }

    public function testOverlappingOneOfEnumsAreNotMerged(): void
    {
        $this->expectNotToPerformAssertions();
        // oneOf of enums that share a value refuses that value; one enum would accept it, so the union stays a union.
        self::emit(self::spec([
            'Code' => ['oneOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]],
            'A' => ['type' => 'string', 'enum' => ['X', 'Y']],
            'B' => ['type' => 'string', 'enum' => ['Y', 'Z']],
        ]));
    }

    public function testANameThatWouldSplitIntoAReservedClassNameStaysWhole(): void
    {
        $files = self::emit(self::spec([
            'Point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'number']]],
            'PointString' => ['type' => 'string', 'pattern' => '^-?[0-9.]+,-?[0-9.]+$'],
            'PointList' => ['type' => 'object', 'properties' => ['at' => ['$ref' => '#/components/schemas/PointString']]],
        ]));
        self::assertNotSame([], $files);
    }

    public function testAnOperationTheGeneratorCannotExecuteIsLeftOutAndReportedOnRequest(): void
    {
        $paths = ['/image' => ['get' => [
            'operationId' => 'image',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]],
        ]]];
        $json = self::spec(['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]], $paths);
        try {
            (new SpecificationNormalizer())->fromJson($json);
            self::fail('Without the option the unsupported operation refuses the document.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $normalizer = new SpecificationNormalizer(false, true);
        $files = self::emit($json, $normalizer);
        self::assertArrayHasKey('get /image', $normalizer->skippedOperations());
        self::assertSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Image')));
        self::assertNotSame([], array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Things')));
    }

    public function testASwaggerDocumentWithoutAHostIsServedWhereTheCallerSays(): void
    {
        $swagger = json_encode([
            'swagger' => '2.0', 'info' => ['title' => 'Host less', 'version' => '1'], 'basePath' => '/weatherapi',
            'paths' => ['/status' => ['get' => ['operationId' => 'status', 'produces' => ['application/json'], 'responses' => ['200' => ['description' => 'ok', 'schema' => ['type' => 'string']]]]]],
        ], JSON_THROW_ON_ERROR);
        try {
            (new SpecificationNormalizer())->fromJson($swagger);
            self::fail('Without a server the document names no host.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $contract = (new SpecificationNormalizer(false, false, 'https://api.example.org'))->fromJson($swagger);
        self::assertSame(['https://api.example.org/weatherapi'], $contract->servers);
    }
}
