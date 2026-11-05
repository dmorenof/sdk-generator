<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class DiscriminatorGenerationTest extends TestCase
{
    private static function source(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/pets-discriminator-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function load(array $source, string $namespace): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($source, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter($namespace, 'example/discriminated-pets'))->emit($contract);
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $file = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$file])) {
                    eval(substr($files[$file], strlen('<?php')));
                }
            }
        });
        return $files;
    }

    public static function setUpBeforeClass(): void
    {
        self::load(self::source(), 'Example\\DiscriminatedPets');
        $ambiguous = self::source();
        foreach (['Dog', 'Cat'] as $name) {
            unset($ambiguous['components']['schemas'][$name]['allOf'][1]['properties']['pet_type']);
        }
        self::load($ambiguous, 'Example\\AmbiguousPets');
    }

    public function testNativeInheritanceUsesOriginalPropertyTypeAndExplicitMapping(): void
    {
        $pet = \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['pet_type' => 'canine', 'bark' => true]);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, $pet);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Pet::class, $pet);
        $property = new \ReflectionProperty($pet, 'pet_type');
        self::assertSame(\Example\DiscriminatedPets\DTOs\Pet::class, $property->getDeclaringClass()->getName());
        self::assertSame('string', $property->getType()->getName());
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Cat::class, \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['pet_type' => 'feline']));
    }

    public function testImplicitMappingAndConcreteConstructionDoNotRedispatch(): void
    {
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['pet_type' => 'Dog']));
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Cat::class, \Example\DiscriminatedPets\DTOs\Cat::fromValue((object) ['pet_type' => 'Cat']));
        $base = new \Example\DiscriminatedPets\DTOs\Pet((object) ['pet_type' => 'Dog']);
        self::assertSame(\Example\DiscriminatedPets\DTOs\Pet::class, $base::class);
    }

    public function testNestedModelsCollectionsAndMapsDispatchThroughSharedHydrator(): void
    {
        $house = new \Example\DiscriminatedPets\DTOs\Household((object) [
            'pet' => (object) ['pet_type' => 'Dog'],
            'pets' => [(object) ['pet_type' => 'Cat']],
            'byName' => (object) ['rex' => (object) ['pet_type' => 'canine']],
            'choice' => (object) ['pet_type' => 'feline'],
        ]);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, $house->pet);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Cat::class, $house->pets[0]);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, $house->byName['rex']);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Cat::class, $house->choice->matchedVariants()[0]);
    }

    public function testMissingDiscriminatorFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing discriminator');
        \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['name' => 'Rex']);
    }

    public function testUnknownDiscriminatorFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown discriminator');
        \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['pet_type' => 'Bird']);
    }

    public function testNonStringDiscriminatorFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        \Example\DiscriminatedPets\DTOs\Pet::fromValue((object) ['pet_type' => 1]);
    }

    public function testOneOfMappingRetainsOriginalAndSerializesMutations(): void
    {
        $json = (object) ['pet_type' => 'canine', 'bark' => true, 'extra' => (object) ['note' => 'keep']];
        $choice = \Example\DiscriminatedPets\DTOs\OneOfPetChoice::fromValue($json);
        $json->extra->note = 'changed';
        $dog = $choice->matchedVariants()[0];
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, $dog);
        $dog->bark = false;
        $serialized = $choice->jsonSerialize();
        self::assertFalse($serialized->bark);
        self::assertSame('keep', $serialized->extra->note);
        self::assertSame('canine', $serialized->pet_type);
    }

    public function testOneOfCannotUseDiscriminatorToHideTwoValidBranches(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('found 2');
        \Example\AmbiguousPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Dog', 'bark' => true, 'hunts' => true]);
    }

    public function testOneOfRejectsZeroValidBranches(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('found 0');
        \Example\DiscriminatedPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Bird']);
    }

    public function testAnyOfAllowsAmbiguityAndUsesDiscriminatorForClassSelection(): void
    {
        $choice = \Example\AmbiguousPets\DTOs\AnyOfPetOptions::fromValue((object) ['pet_type' => 'Dog', 'bark' => true, 'hunts' => true]);
        self::assertInstanceOf(\Example\AmbiguousPets\DTOs\Dog::class, $choice->matchedVariants()[0]);
        self::assertTrue($choice->jsonSerialize()->hunts);
    }

    public function testDiscriminatorMutationCannotChangeConcreteClass(): void
    {
        $choice = \Example\AmbiguousPets\DTOs\AnyOfPetOptions::fromValue((object) ['pet_type' => 'Dog']);
        $choice->matchedVariants()[0]->pet_type = 'Cat';
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Discriminator disagrees');
        $choice->jsonSerialize();
    }

    public function testInheritedEnumRefinementValidatesAfterMutation(): void
    {
        $dog = new \Example\DiscriminatedPets\DTOs\Dog((object) ['pet_type' => 'Dog']);
        $dog->pet_type = 'Cat';
        $this->expectException(\UnexpectedValueException::class);
        $dog->jsonSerialize();
    }

    public function testMappingCannotTargetUnrelatedClass(): void
    {
        $source = self::source();
        $source['components']['schemas']['Pet']['discriminator']['mapping']['bad'] = 'Household';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('compatible named subtype');
        self::load($source, 'Example\\InvalidPets');
    }

    public function testMappingMustResolve(): void
    {
        $source = self::source();
        $source['components']['schemas']['Pet']['discriminator']['mapping']['bad'] = 'Missing';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved discriminator mapping');
        self::load($source, 'Example\\UnresolvedPets');
    }
}
