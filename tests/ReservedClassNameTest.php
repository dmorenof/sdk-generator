<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A schema called `Default` or `List` has no PHP class of that name. The last segment of its name gets a suffix: the singular of
 * its category folder at the top level (`Enums\Default` -> `Enums\DefaultEnum`), the singular of the folder that holds it below
 * that (`Enums\Assets\Default` -> `Enums\Assets\DefaultAsset`). Nothing else about the schema changes, and the rename is reported.
 */
final class ReservedClassNameTest extends TestCase
{
    private const STATUS = ['type' => 'string', 'enum' => ['open', 'closed']];

    /** @param array<string, mixed> $schemas @return array<string, mixed> */
    private static function document(array $schemas, string $statusRef = 'Default'): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Reserved API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/items' => ['get' => [
                'operationId' => 'listItems',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Item']]]]],
            ]]],
            'components' => ['schemas' => $schemas + ['Item' => ['type' => 'object', 'properties' => [
                'status' => ['$ref' => '#/components/schemas/' . $statusRef],
                'name' => ['type' => 'string'],
            ]]]],
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private static function emit(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\Reserved', 'example/reserved'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true),
        );
    }

    /** @param array<string, string> $files */
    private static function dto(array $files): string
    {
        return $files['src/DTOs/Item.php'];
    }

    public function testAReservedEnumNameGetsTheSingularOfItsCategory(): void
    {
        $files = self::emit(self::document(['Default' => self::STATUS]));
        self::assertArrayHasKey('src/Enums/DefaultEnum.php', $files);
        self::assertArrayNotHasKey('src/Enums/Default.php', $files);
        self::assertStringContainsString('enum DefaultEnum: string', $files['src/Enums/DefaultEnum.php']);
        self::assertStringContainsString("case Open = 'open';", $files['src/Enums/DefaultEnum.php']);
        self::assertStringContainsString('\\Example\\Reserved\\Enums\\DefaultEnum', self::dto($files));
    }

    public function testAReservedObjectNameGetsDto(): void
    {
        $files = self::emit(self::document(
            ['List' => ['type' => 'object', 'properties' => ['rows' => ['type' => 'integer']]]],
            'List',
        ));
        self::assertArrayHasKey('src/DTOs/ListDTO.php', $files);
        self::assertArrayNotHasKey('src/DTOs/List.php', $files);
        self::assertStringContainsString('\\Example\\Reserved\\DTOs\\ListDTO', self::dto($files));
    }

    public function testBelowAFolderTheSuffixIsTheSingularOfThatFolder(): void
    {
        $files = self::emit(self::document(['Assets.Default' => self::STATUS], 'Assets.Default'));
        self::assertArrayHasKey('src/Enums/Assets/DefaultAsset.php', $files);
        self::assertArrayNotHasKey('src/Enums/Assets/Default.php', $files);
        self::assertArrayNotHasKey('src/Enums/Assets/DefaultAssets.php', $files);
        self::assertStringContainsString('namespace Example\\Reserved\\Enums\\Assets;', $files['src/Enums/Assets/DefaultAsset.php']);
        self::assertStringContainsString('enum DefaultAsset: string', $files['src/Enums/Assets/DefaultAsset.php']);
    }

    public function testTheNearestFolderIsTheSuffix(): void
    {
        $files = self::emit(self::document(['Assets.Items.Default' => self::STATUS], 'Assets.Items.Default'));
        self::assertArrayHasKey('src/Enums/Assets/Items/DefaultItem.php', $files);
    }

    /** @return array<string, array{string, string}> The singular is the one the resource names already use. */
    public static function folders(): array
    {
        return [
            'plural s' => ['Assets', 'DefaultAsset'],
            'ies' => ['Categories', 'DefaultCategory'],
            'xes' => ['Boxes', 'DefaultBox'],
            'several words' => ['AssetGroups', 'DefaultAssetGroup'],
            'ends in us, kept' => ['Status', 'DefaultStatus'],
            'ends in ss, kept' => ['Address', 'DefaultAddress'],
            'no plural, kept' => ['Data', 'DefaultData'],
        ];
    }

    #[DataProvider('folders')]
    public function testTheFolderIsSingularisedLikeResourceNames(string $folder, string $class): void
    {
        $files = self::emit(self::document(["$folder.Default" => self::STATUS], "$folder.Default"));
        self::assertArrayHasKey("src/Enums/$folder/$class.php", $files);
    }

    public function testTheNameIsReservedWhateverItsCase(): void
    {
        $files = self::emit(self::document(['default' => self::STATUS], 'default'));
        self::assertArrayHasKey('src/Enums/DefaultEnum.php', $files);
    }

    public function testANameThatIsNotReservedIsLeftAlone(): void
    {
        $files = self::emit(self::document(['Status' => self::STATUS], 'Status'));
        self::assertArrayHasKey('src/Enums/Status.php', $files);
        self::assertArrayNotHasKey('src/Enums/StatusEnum.php', $files);
    }

    public function testAnotherSchemaThatTakesTheNewNameIsAnExplicitCollision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collision');
        self::emit(self::document(['Default' => self::STATUS, 'DefaultEnum' => ['type' => 'string', 'enum' => ['x']]]));
    }

    public function testAReservedFolderIsStillRefused(): void
    {
        // Only the class name is renamed: a folder that is a reserved word keeps the refusal it always had.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP schema namespace or class segment');
        self::emit(self::document(['List.Row' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]], 'List.Row'));
    }

    public function testACompositionWrapperIsAlreadyPrefixedSoItKeepsItsName(): void
    {
        $files = self::emit(self::document([
            'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'boolean']], 'required' => ['meow']],
            'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'integer']], 'required' => ['bark']],
            'List' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']]],
        ], 'List'));
        self::assertArrayHasKey('src/DTOs/OneOfList.php', $files);
        self::assertArrayNotHasKey('src/DTOs/OneOfListDTO.php', $files);
    }

    public function testTheRenameIsReportedAsAWarningAtTheSchema(): void
    {
        $json = json_encode(self::document(['Default' => self::STATUS]), JSON_THROW_ON_ERROR);
        $report = (new DocumentInspector())->inspect($json);
        self::assertTrue($report['generatable']);
        self::assertTrue($report['strictlyAccepted']);
        $renames = array_values(array_filter($report['diagnostics'], static fn ($d): bool => $d->code === 'renamed_reserved_class'));
        self::assertCount(1, $renames);
        self::assertSame('warning', $renames[0]->severity);
        self::assertSame('/components/schemas/Default', $renames[0]->pointer);
        self::assertStringContainsString('Default', $renames[0]->message);
        self::assertStringContainsString('DefaultEnum', $renames[0]->message);
    }

    public function testAnAliasOfANativeTypeIsNotAClassSoItIsNotReported(): void
    {
        // SendGrid names a boolean enum `Default`: PHP gets a bool, there is no class to rename and nothing to report.
        $document = self::document(['Default' => ['type' => 'boolean', 'enum' => [true, false]]]);
        $files = self::emit($document);
        self::assertSame([], array_values(array_filter(array_keys($files), static fn (string $path): bool => str_contains($path, 'Default'))));
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['generatable']);
        self::assertSame([], array_filter($report['diagnostics'], static fn ($d): bool => $d->code === 'renamed_reserved_class'));
    }

    public function testNothingIsReportedWhenNothingIsRenamed(): void
    {
        $json = json_encode(self::document(['Status' => self::STATUS], 'Status'), JSON_THROW_ON_ERROR);
        $report = (new DocumentInspector())->inspect($json);
        self::assertSame([], array_filter($report['diagnostics'], static fn ($d): bool => $d->code === 'renamed_reserved_class'));
    }
}
