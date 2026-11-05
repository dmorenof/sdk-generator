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
        $mutable = $ambiguous;
        $mutable['components']['schemas']['Dog']['allOf'][1]['required'] = ['bark'];
        $mutable['components']['schemas']['Cat']['allOf'][1]['required'] = ['hunts'];
        self::load($mutable, 'Example\\MutablePets');
        $enums = self::source();
        $enums['components']['schemas']['Pet']['properties']['pet_type']['enum'] = ['Dog', 'Cat', 'canine', 'feline'];
        self::load($enums, 'Example\\EnumPets');
        $wrapperOnly = self::source();
        unset($wrapperOnly['components']['schemas']['Pet']['discriminator']);
        self::load($wrapperOnly, 'Example\\WrapperPets');
        $renamed = self::source();
        foreach ($renamed['components']['schemas'] as &$schema) {
            if (isset($schema['discriminator'])) {
                $schema['discriminator']['propertyName'] = 'pet-type';
            }
            if (isset($schema['allOf'])) {
                $schema['allOf'][1]['properties']['pet-type'] = $schema['allOf'][1]['properties']['pet_type'];
                unset($schema['allOf'][1]['properties']['pet_type']);
            }
        }
        unset($schema);
        $renamed['components']['schemas']['Pet']['properties']['pet-type'] = $renamed['components']['schemas']['Pet']['properties']['pet_type'];
        unset($renamed['components']['schemas']['Pet']['properties']['pet_type']);
        $renamed['components']['schemas']['Pet']['required'] = ['pet-type'];
        self::load($renamed, 'Example\\RenamedPets');
        $directional = self::source();
        $directional['components']['schemas']['Pet']['properties']['id'] = ['type' => 'integer', 'readOnly' => true];
        $directional['components']['schemas']['Pet']['properties']['secret'] = ['type' => 'string', 'writeOnly' => true];
        $directional['components']['schemas']['Pet']['required'] = ['pet_type', 'id', 'secret'];
        self::load($directional, 'Example\\DirectionalPets');
        $recursive = self::source();
        $recursive['components']['schemas']['Pet']['properties']['children'] = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Pet']];
        self::load($recursive, 'Example\\RecursivePets');
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

    public function testMutationThatIntroducesAnotherValidBranchFailsOneOf(): void
    {
        $choice = \Example\MutablePets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Dog', 'bark' => true]);
        $choice->matchedVariants()[0]->setAdditionalProperty('hunts', true);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('found 2');
        $choice->jsonSerialize();
    }

    public function testUnsetRequiredDiscriminatorFailsSerialization(): void
    {
        $choice = \Example\DiscriminatedPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Dog']);
        unset($choice->matchedVariants()[0]->pet_type);
        $this->expectException(\UnexpectedValueException::class);
        $choice->jsonSerialize();
    }

    public function testBackedEnumIsInheritedAndNarrowedByChildRules(): void
    {
        $dog = \Example\EnumPets\DTOs\Pet::fromValue((object) ['pet_type' => 'Dog']);
        self::assertInstanceOf(\Example\EnumPets\DTOs\Dog::class, $dog);
        $property = new \ReflectionProperty($dog, 'pet_type');
        self::assertSame(\Example\EnumPets\DTOs\Pet::class, $property->getDeclaringClass()->getName());
        $enum = $property->getType()->getName();
        self::assertTrue(is_subclass_of($enum, \BackedEnum::class));
        $dog->pet_type = $enum::from('Cat');
        $this->expectException(\UnexpectedValueException::class);
        $dog->jsonSerialize();
    }

    public function testCompositionOnlyDiscriminatorPreservesPermittedExtraFields(): void
    {
        $choice = \Example\WrapperPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Dog', 'extra' => 'keep']);
        self::assertInstanceOf(\Example\WrapperPets\DTOs\Dog::class, $choice->matchedVariants()[0]);
        self::assertSame('keep', $choice->jsonSerialize()->extra);
    }

    public function testJsonPropertyNameIsUsedForDispatchAndSerialization(): void
    {
        $choice = \Example\RenamedPets\DTOs\OneOfPetChoice::fromValue((object) ['pet-type' => 'Dog']);
        self::assertInstanceOf(\Example\RenamedPets\DTOs\Dog::class, $choice->matchedVariants()[0]);
        self::assertSame('Dog', $choice->jsonSerialize()->{'pet-type'});
        self::assertFalse(property_exists($choice->jsonSerialize(), 'petType'));
    }

    public function testAnyOfRejectsNoValidSchema(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('at least one');
        \Example\DiscriminatedPets\DTOs\AnyOfPetOptions::fromValue((object) ['pet_type' => 'Bird']);
    }

    public function testResponseHydrationSelectsSubtypeWithDeclaredBaseReturnType(): void
    {
        $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"pet_type":"canine","bark":true}'),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"pet_type":"feline"}'),
        ]))]);
        $client = \Example\DiscriminatedPets\Client::connect('https://example.test', $http);
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Dog::class, $client->resource(\Example\DiscriminatedPets\Resources\Pet::class)->get());
        $choice = $client->resource(\Example\DiscriminatedPets\Resources\Choice::class)->get();
        self::assertInstanceOf(\Example\DiscriminatedPets\DTOs\Cat::class, $choice->matchedVariants()[0]);
    }

    public function testResponseHydrationCannotHideOneOfAmbiguity(): void
    {
        $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"pet_type":"Dog"}'),
        ]))]);
        $client = \Example\AmbiguousPets\Client::connect('https://example.test', $http);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('found 2');
        $client->resource(\Example\AmbiguousPets\Resources\Choice::class)->get();
    }

    public function testDirectionalSerializationKeepsInheritedMetadataWithinComposition(): void
    {
        $house = new \Example\DirectionalPets\DTOs\Household((object) [
            'choice' => (object) ['pet_type' => 'Dog', 'id' => 7, 'secret' => 'private'],
        ]);
        $request = $house->jsonSerializeForRequest()->choice;
        $response = $house->jsonSerializeForResponse()->choice;
        self::assertFalse(property_exists($request, 'id'));
        self::assertSame('private', $request->secret);
        self::assertFalse(property_exists($response, 'secret'));
        self::assertSame(7, $response->id);
    }

    public function testDirectionalRequiredFieldsAreCheckedAfterNeutralHydration(): void
    {
        $house = new \Example\DirectionalPets\DTOs\Household((object) [
            'choice' => (object) ['pet_type' => 'Dog', 'secret' => 'private'],
        ]);
        self::assertSame('private', $house->jsonSerializeForRequest()->choice->secret);
        $this->expectException(\UnexpectedValueException::class);
        $house->jsonSerializeForResponse();
    }

    public function testRecursiveModelsPreserveLocalSchemaReferencesAndDispatch(): void
    {
        $choice = \Example\RecursivePets\DTOs\OneOfPetChoice::fromValue((object) [
            'pet_type' => 'Dog', 'children' => [(object) ['pet_type' => 'Cat']],
        ]);
        $dog = $choice->matchedVariants()[0];
        self::assertInstanceOf(\Example\RecursivePets\DTOs\Dog::class, $dog);
        self::assertInstanceOf(\Example\RecursivePets\DTOs\Cat::class, $dog->children[0]);
        self::assertSame('Cat', $choice->jsonSerialize()->children[0]->pet_type);
    }

    public function testExplicitMappingCannotOverrideOriginalSchemaValidity(): void
    {
        $source = self::source();
        $source['components']['schemas']['PetChoice']['discriminator']['mapping']['Cat'] = 'Dog';
        self::load($source, 'Example\\ContradictoryPets');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('does not match the original schema');
        \Example\ContradictoryPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'Cat']);
    }

    public function testExternalMappingFailsWithDiagnostic(): void
    {
        $source = self::source();
        $source['components']['schemas']['Pet']['discriminator']['mapping']['external'] = './animals.yaml#/Dog';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-local discriminator mapping');
        self::load($source, 'Example\\ExternalPets');
    }

    public function testEmptyEnumIntersectionFailsRatherThanWideningTheSchema(): void
    {
        $source = self::source();
        $source['components']['schemas']['Pet']['properties']['pet_type']['enum'] = ['Bird'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty allOf enum intersection');
        self::load($source, 'Example\\ImpossiblePets');
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
