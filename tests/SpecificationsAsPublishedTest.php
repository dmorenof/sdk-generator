<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\EcmaPattern;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What SendGrid, the real contracts of our integrations, write beside a reference or in a schema, and how it is read. Each case here
 * was a document the generator refused, found with `--inspect` on the specifications as published.
 */
final class SpecificationsAsPublishedTest extends TestCase
{
    private const STATUS = ['type' => 'string', 'enum' => ['open', 'closed']];

    /** @param array<string, mixed> $property @param array<string, mixed> $schemas @return array<string, mixed> */
    private static function document(array $property, string $openapi = '3.1.0', array $schemas = []): array
    {
        return [
            'openapi' => $openapi,
            'info' => ['title' => 'Published API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/items' => ['get' => [
                'operationId' => 'listItems',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Item']]]]],
            ]]],
            'components' => ['schemas' => $schemas + [
                'Status' => self::STATUS,
                'Score' => ['type' => 'number', 'enum' => [1.1, 1.2]],
                'Stamp' => ['type' => 'string', 'format' => 'date-time'],
                'Item' => ['type' => 'object', 'properties' => ['status' => $property, 'name' => ['type' => 'string']]],
            ]],
        ];
    }

    /** @param array<string, mixed> $document @return array<string, string> */
    private static function emit(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\Published', 'example/published'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true),
        );
    }

    /** @param array<string, mixed> $document @return array{generatable: bool, diagnostics: list<object>} */
    private static function inspect(array $document): array
    {
        return (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
    }

    private static function statusRef(): array
    {
        return ['$ref' => '#/components/schemas/Status'];
    }

    // -- summary beside a reference (OpenAPI 3.1 Reference Object) -------------------------------------------------------------------

    public function testASummaryBesideAReferenceIsDocumentationOfIt(): void
    {
        $bare = self::emit(self::document(self::statusRef() + ['title' => 'Where it stands']));
        $summarised = self::emit(self::document(self::statusRef() + ['title' => 'Where it stands', 'summary' => 'Single Sign-on state']));
        self::assertStringNotContainsString('Single Sign-on state', $bare['src/DTOs/Item.php']);
        self::assertStringContainsString('Single Sign-on state', $summarised['src/DTOs/Item.php']);
        // Nothing else about the generated SDK changes.
        self::assertSame(array_keys($bare), array_keys($summarised));
        unset($bare['src/DTOs/Item.php'], $summarised['src/DTOs/Item.php']);
        self::assertSame($bare, $summarised);
    }

    // -- keywords JSON Schema 2020-12 does not define (OpenAPI 3.1 reads them as annotations) --------------------------------------------

    public function testAnUnknownKeywordInAnOpenApi31SchemaIsAnAnnotationAndIsReported(): void
    {
        $document = self::document(['type' => 'string', 'example-1' => ['k' => 'v']]);
        self::assertNotSame([], self::emit($document));
        $report = self::inspect($document);
        self::assertTrue($report['generatable']);
        $codes = array_map(static fn ($d): string => $d->code . ':' . $d->severity, $report['diagnostics']);
        self::assertContains('uninterpreted_keyword:warning', $codes);
    }

    #[DataProvider('keywordsThatMeanSomething')]
    public function testAKeywordWithMeaningThatThe31VocabularyDefinesIsStillRefused(string $keyword, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported schema keyword '$keyword'");
        self::emit(self::document(['type' => 'string', $keyword => $value]));
    }

    /** @return array<string, array{string, mixed}> */
    public static function keywordsThatMeanSomething(): array
    {
        return [
            '$defs' => ['$defs', ['x' => ['type' => 'string']]],
            '$id' => ['$id', 'https://example.test/s'],
        ];
    }

    public function testAnUnknownKeywordIsStillRefusedBeforeOpenApi31(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported schema keyword 'example-1'");
        self::emit(self::document(['type' => 'string', 'example-1' => 'x'], '3.0.3'));
    }

    // -- nullable beside a reference -----------------------------------------------------------------------------------------------------

    #[DataProvider('dialects')]
    public function testNullableBesideAReferenceMakesThePropertyNullable(string $openapi): void
    {
        $files = self::emit(self::document(self::statusRef() + ['type' => 'string', 'nullable' => true], $openapi));
        self::assertMatchesRegularExpression('/public \?\\\\Example\\\\Published\\\\Enums\\\\Status \$status/', $files['src/DTOs/Item.php']);
    }

    /** @return array<string, array{string}> */
    public static function dialects(): array
    {
        return ['OpenAPI 3.1' => ['3.1.0'], 'OpenAPI 3.0' => ['3.0.3']];
    }

    public function testWithoutNullableThePropertyIsNotNullable(): void
    {
        $files = self::emit(self::document(self::statusRef() + ['type' => 'string']));
        self::assertMatchesRegularExpression('/public \\\\Example\\\\Published\\\\Enums\\\\Status \$status/', $files['src/DTOs/Item.php']);
    }

    public function testNullableThatIsNotABooleanIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::document(self::statusRef() + ['nullable' => 'yes']));
    }

    // -- format beside a reference -----------------------------------------------------------------------------------------------------------

    public function testAFormatThatRepeatsTheReferencedOneIsTheBareReference(): void
    {
        $bare = self::emit(self::document(['$ref' => '#/components/schemas/Stamp']));
        $repeated = self::emit(self::document(['$ref' => '#/components/schemas/Stamp', 'type' => 'string', 'format' => 'date-time']));
        self::assertSame($bare, $repeated);
    }

    public function testANumericWidthHintBesideAReferenceToANumberIsTheBareReference(): void
    {
        $bare = self::emit(self::document(['$ref' => '#/components/schemas/Score']));
        $hinted = self::emit(self::document(['$ref' => '#/components/schemas/Score', 'type' => 'number', 'format' => 'float', 'default' => 1.1]));
        // Only the default differs from the bare reference: the hint adds nothing.
        $withDefault = self::emit(self::document(['$ref' => '#/components/schemas/Score', 'default' => 1.1]));
        self::assertNotSame([], $bare);
        self::assertSame($withDefault, $hinted);
    }

    public function testAFormatThatWouldAssertSomethingNewBesideAReferenceIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reference siblings are not supported');
        self::emit(self::document(['$ref' => '#/components/schemas/Status', 'type' => 'string', 'format' => 'uuid']));
    }

    // -- ECMA-262 patterns (JSON Schema) are not PCRE ---------------------------------------------------------------------------------------

    public function testAnEcmaUnicodeEscapeIsTranslatedForPcre(): void
    {
        self::assertSame('^[a-z\\x{0080}-\\x{024F}]+$', EcmaPattern::toPcre('^[a-z\\u0080-\\u024F]+$'));
        self::assertSame(1, preg_match('~' . EcmaPattern::toPcre('^[a-z\\u0080-\\u024F]+$') . '~u', 'caf' . "\u{00E9}"));
        self::assertSame(0, preg_match('~' . EcmaPattern::toPcre('^[a-z\\u0080-\\u024F]+$') . '~u', 'cafe1'));
    }

    public function testAnEscapedBackslashBeforeAULetterUIsNotAnEscape(): void
    {
        self::assertSame('^\\\\u0041$', EcmaPattern::toPcre('^\\\\u0041$'));
    }

    public function testAPatternWithAnEcmaEscapeGeneratesAndKeepsItsRule(): void
    {
        $dto = self::emit(self::document(['type' => 'string', 'pattern' => '^[a-zA-Z\\u0080-\\u024F\\s]+$']))['src/DTOs/Item.php'];
        // No ECMA escape is left in the code PHP will compile, and the rule it carries is the one the contract wrote.
        self::assertStringNotContainsString('\\u0080', $dto);
        self::assertStringContainsString('x{0080}-', $dto);
        self::assertSame(1, preg_match('/\'pattern\' => \'(.*)\',/', $dto, $found));
        $regex = '~' . stripcslashes($found[1]) . '~u';
        self::assertSame(1, preg_match($regex, 'caf' . "\u{00E9}"));
        self::assertSame(0, preg_match($regex, 'cafe1'));
    }

    // -- a reference inside patternProperties (SendGrid's contact search) -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function contactsDocument(array $contact, ?array $extraSchemas = null): array
    {
        return self::document(['type' => 'string'], '3.1.0', ($extraSchemas ?? []) + [
            'Contact' => $contact,
            'Item' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'],
                'result' => ['type' => 'object', 'patternProperties' => [
                    '^[a-z]+@example\\.com$' => ['type' => 'object', 'properties' => [
                        'contact' => ['$ref' => '#/components/schemas/Contact'],
                        'error' => ['type' => 'string'],
                    ]],
                ]],
            ]],
        ]);
    }

    public function testAReferenceInsidePatternPropertiesIsResolvedInsideTheAssertion(): void
    {
        $document = self::contactsDocument(['type' => 'object', 'required' => ['email'], 'properties' => ['email' => ['type' => 'string', 'maxLength' => 12]]]);
        $files = self::emit($document);
        $everything = implode("\n", $files);
        // The rule that checks the values keeps what the referenced schema says...
        self::assertStringContainsString('maxLength', $everything);
        self::assertStringContainsString('email', $everything);
        // ...and nothing else about the generated SDK needed the reference to leave the schema.
        self::assertTrue(self::inspect($document)['generatable']);
    }

    public function testAReferenceThatLoopsInsidePatternPropertiesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::contactsDocument(
            ['type' => 'object', 'properties' => ['again' => ['$ref' => '#/components/schemas/Contact']]],
        ));
    }

    public function testAReferenceToNothingInsidePatternPropertiesIsRefused(): void
    {
        $document = self::contactsDocument(['type' => 'object']);
        $document['components']['schemas']['Item']['properties']['result']['patternProperties']['^[a-z]+@example\\.com$']['properties']['contact'] = ['$ref' => '#/components/schemas/Missing'];
        $this->expectException(\InvalidArgumentException::class);
        self::emit($document);
    }

    // -- a type beside a oneOf that every branch repeats (SendGrid's email logs) ------------------------------------------------------------

    /** @param array<string, mixed> $pet @return array<string, mixed> */
    private static function petsDocument(array $pet, string $openapi): array
    {
        return self::document(['$ref' => '#/components/schemas/Pet'], $openapi, [
            'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'boolean'], 'petType' => ['type' => 'string', 'enum' => ['cat']]], 'required' => ['petType']],
            'Dog' => ['type' => 'object', 'properties' => ['bark' => ['type' => 'integer'], 'petType' => ['type' => 'string', 'enum' => ['dog']]], 'required' => ['petType']],
            'Pet' => $pet,
        ]);
    }

    private static function oneOfPets(): array
    {
        return ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']]];
    }

    #[DataProvider('dialects')]
    public function testATypeThatEveryBranchOfAOneOfRepeatsIsTheBareComposition(string $openapi): void
    {
        $bare = self::emit(self::petsDocument(self::oneOfPets(), $openapi));
        $typed = self::emit(self::petsDocument(['type' => 'object'] + self::oneOfPets(), $openapi));
        self::assertSame($bare, $typed);
    }

    public function testTheSameForAnyOf(): void
    {
        $anyOf = ['anyOf' => self::oneOfPets()['oneOf']];
        self::assertSame(
            self::emit(self::petsDocument($anyOf, '3.1.0')),
            self::emit(self::petsDocument(['type' => 'object'] + $anyOf, '3.1.0')),
        );
    }

    #[DataProvider('dialects')]
    public function testATypeThatABranchContradictsIsStillRefused(string $openapi): void
    {
        // Sendbird's push template: `type: string` beside a oneOf whose other branch is an object.
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::petsDocument(['type' => 'string', 'oneOf' => [['type' => 'string'], ['$ref' => '#/components/schemas/Cat']]], $openapi));
    }

    public function testADiscriminatorTagWrittenAsAReferenceToAStringEnumIsAStringTag(): void
    {
        // SendGrid: every branch fixes its `event` with `{type: string, $ref: Event}`, an enum of one value.
        $tag = static fn (string $enum): array => ['type' => 'string', '$ref' => '#/components/schemas/' . $enum];
        $document = self::document(['$ref' => '#/components/schemas/Pet'], '3.1.0', [
            'KindCat' => ['type' => 'string', 'enum' => ['cat']],
            'KindDog' => ['type' => 'string', 'enum' => ['dog']],
            'Cat' => ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => $tag('KindCat'), 'meow' => ['type' => 'boolean']]],
            'Dog' => ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => $tag('KindDog'), 'bark' => ['type' => 'integer']]],
            'Pet' => ['type' => 'object'] + self::oneOfPets(),
        ]);
        $wrapper = self::emit($document)['src/DTOs/OneOfPet.php'];
        self::assertStringContainsString("'cat'", $wrapper);
        self::assertStringContainsString("'dog'", $wrapper);
        self::assertTrue(self::inspect($document)['generatable']);
    }

    public function testATagThatIsNotAStringStillIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string tag property');
        self::emit(self::document(['$ref' => '#/components/schemas/Pet'], '3.1.0', [
            'Cat' => ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => ['type' => 'integer']]],
            'Dog' => ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => ['type' => 'string']]],
            'Pet' => self::oneOfPets(),
        ]));
    }

    public function testATypeBesideABranchThatHasNoTypeIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::petsDocument(['type' => 'object', 'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['x' => ['type' => 'string']]]]], '3.1.0'));
    }

    // -- a description beside a one-branch allOf (DeepL) --------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function aliasDocument(array $alias, string $openapi): array
    {
        return self::document(['$ref' => '#/components/schemas/Tags'], $openapi, [
            'Tags' => ['type' => 'string', 'description' => 'Comma-separated list of tags.'],
            'Tag' => ['type' => 'string'],
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'Alias' => $alias,
            'Item' => ['type' => 'object', 'properties' => ['tags' => ['$ref' => '#/components/schemas/Alias'], 'name' => ['type' => 'string']]],
        ]);
    }

    #[DataProvider('dialects')]
    public function testADescriptionOnAOneBranchAllOfOverANonObjectIsTheReferenceWithThatDescription(string $openapi): void
    {
        $wrapped = self::emit(self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Tag']], 'description' => 'Tags that are not translated.'], $openapi));
        $direct = self::emit(self::aliasDocument(['$ref' => '#/components/schemas/Tag', 'description' => 'Tags that are not translated.'], $openapi));
        self::assertSame($direct, $wrapped);
    }

    #[DataProvider('dialects')]
    public function testABranchThatOnlyAnnotatesBesideTheReferenceIsTheReferenceWithThatAnnotation(string $openapi): void
    {
        // DeepL: `allOf: [{$ref: SourceLanguage}, {description: "Language of the source audio."}]`.
        $wrapped = self::emit(self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Tag'], ['description' => 'Language of the source.']]], $openapi));
        $direct = self::emit(self::aliasDocument(['$ref' => '#/components/schemas/Tag', 'description' => 'Language of the source.'], $openapi));
        self::assertSame($direct, $wrapped);
    }

    public function testAnAnnotatingBranchBesideAReferenceToAnObjectIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Base'], ['description' => 'x']]], '3.1.0'));
    }

    public function testABranchThatAssertsSomethingBesideTheReferenceIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Tag'], ['description' => 'x', 'maxLength' => 3]]], '3.1.0'));
    }

    public function testTheSameOverAnArrayOrANumber(): void
    {
        foreach ([['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'integer']] as $target) {
            $document = self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Tag']], 'title' => 'Tags'], '3.0.3');
            $document['components']['schemas']['Tag'] = $target;
            self::assertNotSame([], self::emit($document));
        }
    }

    public function testADescriptionOnAOneBranchAllOfOverAnObjectIsStillInheritance(): void
    {
        $files = self::emit(self::aliasDocument(['allOf' => [['$ref' => '#/components/schemas/Base']], 'description' => 'A base with a description.'], '3.1.0'));
        self::assertStringContainsString('extends \\Example\\Published\\DTOs\\Base', $files['src/DTOs/Alias.php']);
    }

    // -- a nullable reference written as anyOf (Stripe: 426 times) --------------------------------------------------------------------------

    #[DataProvider('dialects')]
    public function testAOneBranchAnyOfOfAReferenceThatMayBeNullIsTheNullableReference(string $openapi): void
    {
        // Stripe: `{anyOf: [{$ref: account_business_profile}], nullable: true, description: ...}`.
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $wrapped = self::emit(self::document([$keyword => [self::statusRef()], 'nullable' => true, 'description' => 'Where it stands'], $openapi));
            $direct = self::emit(self::document(self::statusRef() + ['nullable' => true, 'description' => 'Where it stands'], $openapi));
            self::assertSame($direct, $wrapped, $keyword);
            self::assertMatchesRegularExpression('/public \?\\\\Example\\\\Published\\\\Enums\\\\Status \$status/', $wrapped['src/DTOs/Item.php']);
        }
    }

    public function testAOneBranchAnyOfWithAnExtensionBesideIt(): void
    {
        // Stripe adds `x-expansionResources` to its expandable fields.
        $files = self::emit(self::document(['anyOf' => [self::statusRef()], 'x-expansionResources' => ['oneOf' => [['$ref' => '#/components/schemas/Status']]]], '3.0.0'));
        self::assertDoesNotMatchRegularExpression('/\?\\\\Example\\\\Published\\\\Enums\\\\Status \$status/', $files['src/DTOs/Item.php']);
        self::assertStringContainsString('Enums\\Status $status', $files['src/DTOs/Item.php']);
    }

    #[DataProvider('dialects')]
    public function testAUnionThatMayBeNullIsANullableUnion(string $openapi): void
    {
        // Stripe's expandable fields: an id, or the object, and possibly null.
        $files = self::emit(self::document(['anyOf' => [['type' => 'string'], ['$ref' => '#/components/schemas/Base']], 'nullable' => true], $openapi, [
            'Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ]));
        self::assertMatchesRegularExpression('/public \?\\\\Example\\\\Published\\\\DTOs\\\\AnyOf\w*Item\w* \$status|public \?[\\\\\w]+ \$status/', $files['src/DTOs/Item.php']);
    }

    public function testNullableBesideACompositionMustBeABoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::document(['anyOf' => [self::statusRef()], 'nullable' => 'yes'], '3.0.3'));
    }

    public function testAnotherSiblingBesideACompositionIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Composition siblings are not supported');
        self::emit(self::document(['anyOf' => [self::statusRef()], 'maxLength' => 3], '3.0.3'));
    }

    // -- a JSON media type that declares no schema ------------------------------------------------------------------------------------------

    public function testAJsonResponseWithoutASchemaIsAnyJsonValue(): void
    {
        $document = self::document(['type' => 'string']);
        $document['paths']['/scores'] = ['get' => ['operationId' => 'scores', 'responses' => ['202' => [
            'description' => 'accepted',
            'content' => ['application/json' => ['example' => ['queued' => true]]],
        ]]]];
        $files = self::emit($document);
        self::assertStringContainsString('mixed', implode("\n", array_filter($files, static fn (string $p): bool => str_contains($p, 'Scores'), ARRAY_FILTER_USE_KEY)));
    }
}
