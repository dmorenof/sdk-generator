<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Patterns that Swagger tooling (Swashbuckle and similar) emits and real contracts therefore contain. */
final class SwaggerCompatibilityTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(): array
    {
        $json = ['application/json', 'text/json'];
        return [
            'swagger' => '2.0',
            'info' => ['title' => 'Compat API', 'version' => '1'],
            'host' => 'api.example.test',
            'schemes' => ['https'],
            'paths' => ['/items' => ['post' => [
                'operationId' => 'createItem',
                'consumes' => $json,
                'produces' => $json,
                'parameters' => [['in' => 'body', 'name' => 'body', 'schema' => ['$ref' => '#/definitions/Item']]],
                'responses' => ['200' => ['description' => 'ok', 'schema' => ['$ref' => '#/definitions/Item']]],
            ]]],
            'definitions' => [
                'Detail' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]],
                'Item' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'detail' => ['$ref' => '#/definitions/Detail', 'description' => 'Computed by the server', 'readOnly' => true],
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private static function emit(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\Compat', 'example/compat'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true),
        );
    }

    public function testSeveralNamesForJsonAreOneJsonRepresentation(): void
    {
        $files = self::emit(self::document());
        $resource = implode("\n", array_filter($files, static fn (string $path): bool => str_contains($path, 'Resources/'), ARRAY_FILTER_USE_KEY));
        self::assertStringContainsString("'path' => '/items'", $resource);
        self::assertStringNotContainsString('alternatives', $resource);
        self::assertStringNotContainsString('text/json', $resource);
    }

    public function testReadOnlyBesideAReferenceMarksThePropertyReadOnly(): void
    {
        $dto = implode("\n", array_filter(self::emit(self::document()), static fn (string $path): bool => str_contains($path, 'DTOs/Item')
            || str_contains($path, 'Requests/Item') || str_contains($path, 'Responses/Item'), ARRAY_FILTER_USE_KEY));
        self::assertMatchesRegularExpression("/READ_ONLY_FIELDS = \\[\\s*'detail',/", $dto);
    }

    public function testOtherSiblingsOfAReferenceAreStillRefused(): void
    {
        $document = self::document();
        $document['definitions']['Item']['properties']['detail']['maxItems'] = 3;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit($document);
    }

    public function testReadOnlyBesideAReferenceMustBeABoolean(): void
    {
        $document = self::document();
        $document['definitions']['Item']['properties']['detail']['readOnly'] = 'yes';
        $this->expectException(\InvalidArgumentException::class);
        self::emit($document);
    }

    public function testAnXmlTwinAfterJsonIsIgnoredAsInOpenApi3(): void
    {
        $document = self::document();
        $document['paths']['/items']['post']['consumes'] = ['application/json', 'application/xml'];
        self::assertNotSame([], self::emit($document));
    }

    public function testAListThatDoesNotStartWithJsonIsStillRefused(): void
    {
        $document = self::document();
        $document['paths']['/items']['post']['consumes'] = ['application/xml', 'application/json'];
        $this->expectException(\InvalidArgumentException::class);
        self::emit($document);
    }

    public function testTheCasafariCrmV1ContractGeneratesStrictly(): void
    {
        // The contract of the hand-written casafari-sdk 0.1.0 (14 operations, 82 schemas); it uses both patterns above.
        $source = (string) file_get_contents(__DIR__ . '/fixtures/casafari-crm-v1-swagger2.json');
        $files = (new PhpExecutableEmitter('Example\\CasafariCrm', 'example/casafari-crm'))->emit(
            (new SpecificationNormalizer())->fromJson($source, strict: true),
        );
        $resources = array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'src/Resources/'));
        self::assertCount(14, $resources);
        self::assertStringContainsString("'rq.includeBusinessTypes' => 'rqIncludeBusinessTypes'", implode("\n", $files));
    }
}
