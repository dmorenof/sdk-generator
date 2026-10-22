<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpContractEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class MapGenerationTest extends TestCase
{
    /** @return array<string, string> */
    private static function generated(): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Maps', 'version' => '1.0'],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'StringMap' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'string', 'minLength' => 2],
                    ],
                    'Envelope' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'labels' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string'],
                            ],
                            'words' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'minLength' => 2],
                            ],
                        ],
                        'additionalProperties' => ['type' => 'integer'],
                    ],
                    'OpenObject' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string']],
                        'additionalProperties' => true,
                    ],
                    'ClosedObject' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'string']],
                        'additionalProperties' => false,
                    ],
                    'ReservedProperty' => [
                        'type' => 'object',
                        'required' => ['private'],
                        'properties' => ['private' => ['type' => 'string']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        return (new PhpContractEmitter('Example\\MapsSdk', 'example/maps-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generated();
        foreach ([
            'src/Core/DTO.php',
            'src/Core/Map.php',
            'src/Core/TypedArray.php',
            'src/Collections/Envelope/WordsCollection.php',
            'src/DTOs/StringMap.php',
            'src/DTOs/Envelope/LabelsMap.php',
            'src/DTOs/Envelope.php',
            'src/DTOs/OpenObject.php',
            'src/DTOs/ClosedObject.php',
            'src/DTOs/ReservedProperty.php',
        ] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public function testStandaloneMapWrapperEnforcesValueTypeAndConstraints(): void
    {
        $map = new \Example\MapsSdk\DTOs\StringMap((object) ['short' => 'long']);
        self::assertSame('long', $map['short']);
        self::assertEquals((object) ['short' => 'long'], $map->jsonSerialize());

        $this->expectException(\UnexpectedValueException::class);
        $map['invalid'] = 'x';
    }

    public function testDtoKeepsTypedAdditionalPropertiesAndMapFields(): void
    {
        $envelope = new \Example\MapsSdk\DTOs\Envelope((object) [
            'id' => 'item-1',
            'labels' => (object) ['en' => 'name'],
            'count' => 3,
        ]);
        self::assertSame(3, $envelope->getAdditionalProperty('count'));
        self::assertInstanceOf(\Example\MapsSdk\DTOs\Envelope\LabelsMap::class, $envelope->labels);
        self::assertEquals(
            (object) [
                'id' => 'item-1',
                'labels' => (object) ['en' => 'name'],
                'count' => 3,
            ],
            $envelope->jsonSerialize(),
        );

        $envelope->setAdditionalProperty('total', 4);
        self::assertSame(4, $envelope->getAdditionalProperty('total'));
        $this->expectException(\UnexpectedValueException::class);
        $envelope->setAdditionalProperty('invalid', 'not an integer');
    }

    public function testBooleanAdditionalPropertiesAndClosedObjectsBehaveDistinctly(): void
    {
        $open = new \Example\MapsSdk\DTOs\OpenObject((object) ['id' => 'x', 'arbitrary' => ['json']]);
        self::assertSame(['json'], $open->getAdditionalProperty('arbitrary'));
        self::assertEquals(
            (object) ['id' => 'x', 'arbitrary' => ['json']],
            $open->jsonSerialize(),
        );

        $this->expectException(\InvalidArgumentException::class);
        new \Example\MapsSdk\DTOs\ClosedObject((object) ['id' => 'x', 'unknown' => true]);
    }

    public function testArrayItemConstraintsAreEnforcedOnHydrationAndMutation(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new \Example\MapsSdk\DTOs\Envelope((object) [
            'id' => 'item-2',
            'labels' => (object) [],
            'words' => ['x'],
        ]);
    }

    public function testReservedPhpPropertyNamesMapToSafePropertiesAndPreserveWireKeys(): void
    {
        $model = new \Example\MapsSdk\DTOs\ReservedProperty((object) ['private' => 'value']);

        self::assertSame('value', $model->private_);
        self::assertEquals((object) ['private' => 'value'], $model->jsonSerialize());
    }
}
