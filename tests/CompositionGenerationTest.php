<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class CompositionGenerationTest extends TestCase
{
    /** @return array<string, string> */
    private static function generated(): array
    {
        $schema = static fn (array $required, array $properties): array => [
            'type' => 'object',
            'required' => $required,
            'properties' => $properties,
        ];
        $ref = static fn (string $name): array => ['$ref' => "#/components/schemas/$name"];
        $source = json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Compositions', 'version' => '1.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'CardPayment' => $schema(
                        ['token'],
                        ['token' => ['type' => 'string'], 'iban' => ['type' => 'string']],
                    ),
                    'BankPayment' => $schema(
                        ['iban'],
                        ['iban' => ['type' => 'string'], 'token' => ['type' => 'string']],
                    ),
                    'DuplicateCardPayment' => $schema(['token'], ['token' => ['type' => 'string']]),
                    'Payment' => ['anyOf' => [$ref('CardPayment'), $ref('DuplicateCardPayment')]],
                    'InlinePayment' => ['anyOf' => [
                        $schema(['cashCode'], ['cashCode' => ['type' => 'string']]),
                        $ref('BankPayment'),
                    ]],
                    'ScalarPayment' => ['anyOf' => [
                        ['type' => 'string'],
                        $ref('BankPayment'),
                    ]],
                    'NullablePayment' => ['anyOf' => [
                        ['type' => 'null'],
                        $ref('BankPayment'),
                    ]],
                    'UniquePayment' => ['oneOf' => [$ref('CardPayment'), $ref('BankPayment')]],
                    'Invoice' => $schema(
                        ['payment', 'uniquePayment'],
                        [
                            'payment' => $ref('Payment'),
                            'uniquePayment' => $ref('UniquePayment'),
                            'paymentDetails' => ['allOf' => [$ref('CardPayment'), $ref('BankPayment')]],
                        ],
                    ),
                    'ScalarContainer' => $schema([], [
                        'limit' => ['anyOf' => [
                            ['type' => 'integer', 'minimum' => 0],
                            ['type' => 'null'],
                        ]],
                    ]),
                    'MarketAnalyticsApiapi__schema__ranges__IntRangeSchema__3' => [
                        ...$schema([], [
                            'min' => ['anyOf' => [
                                ['type' => 'integer', 'minimum' => 0],
                                ['type' => 'null'],
                            ]],
                            'max' => ['anyOf' => [
                                ['type' => 'integer', 'minimum' => 0],
                                ['type' => 'null'],
                            ]],
                        ]),
                        'title' => 'IntRangeSchema',
                    ],
                    'RangeInput' => $schema([], [
                        'bathrooms_range' => ['anyOf' => [
                            $ref('MarketAnalyticsApiapi__schema__ranges__IntRangeSchema__3'),
                            ['type' => 'null'],
                        ]],
                    ]),
                    'SharedRange' => $schema([], [
                        'min' => ['anyOf' => [
                            ['type' => 'integer'],
                            ['type' => 'null'],
                        ]],
                        'max' => ['anyOf' => [
                            ['type' => 'integer'],
                            ['type' => 'null'],
                        ]],
                    ]),
                    'SharedRangeInput' => $schema([], [
                        'bathrooms_range' => ['anyOf' => [
                            $ref('SharedRange'),
                            ['type' => 'null'],
                        ]],
                        'bedrooms_range' => ['anyOf' => [
                            $ref('SharedRange'),
                            ['type' => 'null'],
                        ]],
                    ]),
                    'InvoiceBase' => $schema(['id'], ['id' => ['type' => 'integer']]),
                    'InvoiceOwner' => $schema(['owner'], ['owner' => ['type' => 'string']]),
                    'FullInvoice' => ['allOf' => [$ref('InvoiceBase'), $ref('InvoiceOwner')]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $contract = (new SpecificationNormalizer())->fromJson($source);
        return (new PhpExecutableEmitter('Example\\CompositionSdk', 'example/composition-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generated();
        foreach ([
            'src/Core/DTO.php',
            'src/Core/TypedArray.php',
            'src/Core/AnyOf.php',
            'src/Core/OneOf.php',
            'src/DTOs/CardPayment.php',
            'src/DTOs/BankPayment.php',
            'src/DTOs/DuplicateCardPayment.php',
            'src/DTOs/AnyOfPayment.php',
            'src/DTOs/AnyOfInlinePayment.php',
            'src/DTOs/PaymentVariant1DTO.php',
            'src/DTOs/AnyOfScalarPayment.php',
            'src/DTOs/AnyOfNullablePayment.php',
            'src/DTOs/OneOfUniquePayment.php',
            'src/DTOs/Invoice/PaymentDetailsDTO.php',
            'src/DTOs/Invoice.php',
            'src/DTOs/InvoiceBase.php',
            'src/DTOs/InvoiceOwner.php',
            'src/DTOs/FullInvoice.php',
            'src/DTOs/ScalarContainer.php',
            'src/DTOs/RangeInput.php',
            'src/DTOs/RangeInput/BathroomsRange.php',
            'src/DTOs/SharedRange.php',
            'src/DTOs/SharedRangeInput.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
    }

    public function testCompositionSchemasNormalizeAndEmitDedicatedWrappers(): void
    {
        $files = self::generated();
        self::assertArrayHasKey('src/Core/AnyOf.php', $files);
        self::assertArrayHasKey('src/Core/OneOf.php', $files);
        self::assertStringContainsString('extends \\Example\\CompositionSdk\\Core\\AnyOf', $files['src/DTOs/AnyOfPayment.php']);
        self::assertStringContainsString('extends \\Example\\CompositionSdk\\Core\\OneOf', $files['src/DTOs/OneOfUniquePayment.php']);
        self::assertStringContainsString('\\Example\\CompositionSdk\\DTOs\\AnyOfPayment $payment', $files['src/DTOs/Invoice.php']);
        self::assertStringContainsString('public ?int $limit;', $files['src/DTOs/ScalarContainer.php']);
        self::assertStringContainsString("'limit' =>", $files['src/DTOs/ScalarContainer.php']);
        self::assertStringNotContainsString('LimitDTO', $files['src/DTOs/ScalarContainer.php']);

        $contract = (new SpecificationNormalizer())->fromJson(self::sourceForNormalization());
        $payment = $contract;
        self::assertSame(SchemaKind::AnyOf, $payment->models[2]->schema->kind);
        self::assertCount(2, $payment->models[2]->schema->branches);
    }

    public function testAnyOfRetainsOriginalJsonAndAllMatchingVariants(): void
    {
        $source = (object) ['token' => 'secret'];
        $payment = \Example\CompositionSdk\DTOs\AnyOfPayment::fromValue($source);

        self::assertCount(2, $payment->matchedVariants());
        self::assertInstanceOf(\Example\CompositionSdk\DTOs\CardPayment::class, $payment->matchedVariants()[0]);
        self::assertInstanceOf(\Example\CompositionSdk\DTOs\DuplicateCardPayment::class, $payment->matchedVariants()[1]);
        self::assertSame($source, $payment->jsonSerialize());
    }

    public function testAnyOfSupportsInlineObjectVariants(): void
    {
        $payment = \Example\CompositionSdk\DTOs\AnyOfInlinePayment::fromValue(
            (object) ['cashCode' => 'cash-1'],
        );

        self::assertCount(1, $payment->matchedVariants());
        self::assertInstanceOf(
            \Example\CompositionSdk\DTOs\PaymentVariant1DTO::class,
            $payment->matchedVariants()[0],
        );
    }

    public function testAnyOfSupportsPrimitiveVariants(): void
    {
        $payment = \Example\CompositionSdk\DTOs\AnyOfScalarPayment::fromValue('cash');

        self::assertSame(['cash'], $payment->matchedVariants());
        self::assertSame('cash', $payment->jsonSerialize());
    }

    public function testAnyOfCanRepresentNullVariant(): void
    {
        $payment = \Example\CompositionSdk\DTOs\AnyOfNullablePayment::fromValue(null);

        self::assertSame([null], $payment->matchedVariants());
        self::assertNull($payment->jsonSerialize());
    }

    public function testNullableScalarAnyOfUsesNativeNullableTypeAndKeepsConstraints(): void
    {
        $container = new \Example\CompositionSdk\DTOs\ScalarContainer((object) ['limit' => null]);
        self::assertNull($container->limit);

        $container->limit = -1;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('minimum');
        $container->validate();
    }

    public function testSingleUseObjectReferenceIsNamedAtItsFieldAndNullableUnionsAreNative(): void
    {
        $files = self::generated();

        self::assertArrayNotHasKey(        'src/DTOs/MarketAnalyticsApiapi__schema__ranges__IntRangeSchema__3.php', $files);
        self::assertArrayHasKey('src/DTOs/RangeInput/BathroomsRange.php', $files);
        self::assertStringContainsString(
            'public ?\\Example\\CompositionSdk\\DTOs\\RangeInput\\BathroomsRange $bathrooms_range;',
            $files['src/DTOs/RangeInput.php'],
        );
        self::assertStringContainsString('final class BathroomsRange extends \\Example\\CompositionSdk\\Core\\DTO', $files['src/DTOs/RangeInput/BathroomsRange.php']);
        self::assertStringContainsString('public ?int $min;', $files['src/DTOs/RangeInput/BathroomsRange.php']);
        self::assertStringContainsString('public ?int $max;', $files['src/DTOs/RangeInput/BathroomsRange.php']);

        $input = new \Example\CompositionSdk\DTOs\RangeInput((object) [
            'bathrooms_range' => (object) ['min' => null, 'max' => 4],
        ]);
        self::assertInstanceOf(\Example\CompositionSdk\DTOs\RangeInput\BathroomsRange::class, $input->bathrooms_range);
        self::assertNull($input->bathrooms_range->min);
        self::assertSame(4, $input->bathrooms_range->max);
    }

    public function testSharedObjectReferenceKeepsItsNamedSchema(): void
    {
        $files = self::generated();

        self::assertArrayHasKey('src/DTOs/SharedRange.php', $files);
        self::assertArrayNotHasKey('src/DTOs/SharedRangeInput/BathroomsRange.php', $files);
        self::assertStringContainsString('public ?int $min;', $files['src/DTOs/SharedRange.php']);
        self::assertStringContainsString(
            'public ?\\Example\\CompositionSdk\\DTOs\\SharedRange $bathrooms_range;',
            $files['src/DTOs/SharedRangeInput.php'],
        );
        self::assertStringContainsString(
            'public ?\\Example\\CompositionSdk\\DTOs\\SharedRange $bedrooms_range;',
            $files['src/DTOs/SharedRangeInput.php'],
        );
    }

    public function testOneOfRequiresExactlyOneMatch(): void
    {
        $one = \Example\CompositionSdk\DTOs\OneOfUniquePayment::fromValue((object) ['iban' => 'FI123']);
        self::assertCount(1, $one->matchedVariants());
        self::assertInstanceOf(\Example\CompositionSdk\DTOs\BankPayment::class, $one->matchedVariants()[0]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected exactly one oneOf match');
        \Example\CompositionSdk\DTOs\OneOfUniquePayment::fromValue((object) [
            'token' => 'secret',
            'iban' => 'FI123',
        ]);
    }

    public function testOneOfRejectsValuesMatchingNoVariant(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected exactly one oneOf match');
        \Example\CompositionSdk\DTOs\OneOfUniquePayment::fromValue((object) ['unknown' => true]);
    }

    public function testAllOfCombinesObjectFieldsAndRequiredProperties(): void
    {
        $model = new \Example\CompositionSdk\DTOs\FullInvoice((object) ['id' => 7, 'owner' => 'Ada']);
        self::assertSame(7, $model->id);
        self::assertSame('Ada', $model->owner);

        $nested = new \Example\CompositionSdk\DTOs\Invoice((object) [
            'payment' => (object) ['token' => 'card'],
            'uniquePayment' => (object) ['iban' => 'FI123'],
            'paymentDetails' => (object) ['token' => 'card', 'iban' => 'FI123'],
        ]);
        self::assertSame('FI123', $nested->paymentDetails->iban);

        $this->expectException(\UnexpectedValueException::class);
        new \Example\CompositionSdk\DTOs\FullInvoice((object) ['id' => 7]);
    }

    private static function sourceForNormalization(): string
    {
        return json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Compositions', 'version' => '1.0'],
            'paths' => [],
            'components' => ['schemas' => [
                'Card' => ['type' => 'object'],
                'Bank' => ['type' => 'object'],
                'Payment' => ['anyOf' => [
                    ['$ref' => '#/components/schemas/Card'],
                    ['$ref' => '#/components/schemas/Bank'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
