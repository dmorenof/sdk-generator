<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenAPI 3.1 documents (SendGrid's, for one) write `{$ref: Status, type: string}`: the `type` repeats what the referenced schema
 * already says. It asserts nothing new, so it is read as the bare reference; any sibling that could change the meaning stays refused.
 */
final class ReferenceTypeSiblingTest extends TestCase
{
    /** @param array<string, mixed> $property what the `status` property holds @return array<string, mixed> */
    private static function document(string $openapi, array $property, ?array $statusSchema = null): array
    {
        return [
            'openapi' => $openapi,
            'info' => ['title' => 'Sibling API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/items' => ['get' => [
                'operationId' => 'listItems',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Item']]]]],
            ]]],
            'components' => ['schemas' => [
                'Status' => $statusSchema ?? ['type' => 'string', 'enum' => ['open', 'closed']],
                'StatusAlias' => ['$ref' => '#/components/schemas/Status'],
                'Item' => ['type' => 'object', 'properties' => ['status' => $property, 'name' => ['type' => 'string']]],
            ]],
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private static function emit(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\Sibling', 'example/sibling'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true),
        );
    }

    /** @return array<string, array{string}> */
    public static function dialects(): array
    {
        return ['OpenAPI 3.1' => ['3.1.0'], 'OpenAPI 3.0' => ['3.0.3']];
    }

    #[DataProvider('dialects')]
    public function testATypeThatRepeatsTheReferencedTypeIsTheBareReference(string $openapi): void
    {
        $bare = self::emit(self::document($openapi, ['$ref' => '#/components/schemas/Status']));
        $repeated = self::emit(self::document($openapi, ['$ref' => '#/components/schemas/Status', 'type' => 'string']));
        self::assertSame($bare, $repeated);
    }

    #[DataProvider('dialects')]
    public function testItKeepsTheDescriptionAndDefaultBesideIt(string $openapi): void
    {
        $bare = self::emit(self::document($openapi, ['$ref' => '#/components/schemas/Status', 'description' => 'Where it stands', 'default' => 'open']));
        $repeated = self::emit(self::document($openapi, ['$ref' => '#/components/schemas/Status', 'description' => 'Where it stands', 'default' => 'open', 'type' => 'string']));
        self::assertSame($bare, $repeated);
    }

    public function testItFollowsAnAliasToTheTypedSchema(): void
    {
        $bare = self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/StatusAlias']));
        $repeated = self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/StatusAlias', 'type' => 'string']));
        self::assertSame($bare, $repeated);
    }

    public function testATypeThatContradictsTheReferenceIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/Status', 'type' => 'integer']));
    }

    public function testATypeBesideATypelessSchemaIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/Status', 'type' => 'string'], ['enum' => ['open', 'closed']]));
    }

    public function testATypeBesideAnotherAssertionIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/Status', 'type' => 'string', 'format' => 'uuid']));
    }

    public function testATypeSetBesideAReferenceIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/Status', 'type' => ['string', 'null']]));
    }

    public function testATypeBesideAReferenceToNothingIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::document('3.1.0', ['$ref' => '#/components/schemas/Missing', 'type' => 'string']));
    }
}
