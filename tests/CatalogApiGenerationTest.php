<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class CatalogApiGenerationTest extends TestCase
{
    /** @return array<string,string> */
    private function generated(string $filename): array
    {
        $spec = file_get_contents(__DIR__ . '/fixtures/' . $filename);
        self::assertIsString($spec);
        $contract = (new SpecificationNormalizer())->fromJson($spec);
        return (new PhpExecutableEmitter('Preview\\CatalogSdk', 'preview/catalog-sdk'))->emit($contract);
    }

    public function testUnrelatedCatalogSpecProducesTheSamePhpForThreeDialects(): void
    {
        $swagger = $this->generated('catalog-swagger2.json');
        self::assertSame($swagger, $this->generated('catalog-openapi30.json'));
        self::assertSame($swagger, $this->generated('catalog-openapi31.json'));
        self::assertSame($swagger, $this->generated('catalog-swagger2.json'));
        self::assertArrayHasKey('src/DTOs/Book.php', $swagger);
        self::assertArrayHasKey('src/DTOs/Warehouse.php', $swagger);
        self::assertArrayHasKey('src/DTOs/BookDraft.php', $swagger);
        self::assertArrayHasKey('src/Api/Books/Book.php', $swagger);
        self::assertArrayHasKey('src/Api/Books.php', $swagger);
        self::assertArrayHasKey('src/Collections/StringCollection.php', $swagger);
        self::assertArrayHasKey('src/Enums/Book/GenreEnum.php', $swagger);
    }

    public function testCatalogContractsPreserveFormatsAndTypedEndpointSignatures(): void
    {
        $spec = file_get_contents(__DIR__ . '/fixtures/catalog-openapi31.json');
        $contract = (new SpecificationNormalizer())->fromJson((string)$spec);
        self::assertCount(3, $contract->models);
        self::assertSame('addBook', $contract->operations[0]->operationId);
        $model = $contract->models[0];
        self::assertSame('Book', $model->name);
        $props = [];
        foreach ($model->schema->properties as $property) {
            $props[$property->wireName] = $property;
        }
        self::assertSame(SchemaKind::Number, $props['price']->schema->kind);
        self::assertSame('double', $props['price']->schema->format);
        self::assertSame(SchemaKind::Boolean, $props['available']->schema->kind);
        self::assertSame(SchemaKind::Array, $props['tags']->schema->kind);
        self::assertSame(SchemaKind::Reference, $props['warehouse']->schema->kind);
        $out = $this->generated('catalog-openapi31.json');
        self::assertStringContainsString('public float $price;', $out['src/DTOs/Book.php']);
        self::assertStringContainsString('public bool $available;', $out['src/DTOs/Book.php']);
        self::assertStringContainsString(
            'public function getBySku(string $sku, ?\\Preview\\CatalogSdk\\DTOs\\BooksApi\\GetPayload $payload = null): \\Preview\\CatalogSdk\\DTOs\\Book',
            $out['src/Api/Books/Book.php'],
        );
        self::assertStringContainsString(
            'public function post(\\Preview\\CatalogSdk\\DTOs\\BookDraft $payload): \\Preview\\CatalogSdk\\DTOs\\Book',
            $out['src/Api/Books.php'],
        );
        self::assertStringContainsString("'/books/{sku}'", $out['src/Api/Books/Book.php']);
    }

    public function testEveryCatalogPhpClassLintsSuccessfully(): void
    {
        foreach ($this->generated('catalog-openapi31.json') as $path => $source) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $temporary = tempnam(sys_get_temp_dir(), 'catalog-lint-');
            self::assertNotFalse($temporary);
            try {
                self::assertNotFalse(file_put_contents($temporary, $source));
                $output = [];
                $exit = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $output, $exit);
                self::assertSame(0, $exit, $path . ': ' . implode("\n", $output));
            } finally {
                unlink($temporary);
            }
        }
    }
}
