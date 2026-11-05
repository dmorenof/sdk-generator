<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class NativeModelInheritanceTest extends TestCase
{
    private static function files(array $schemas): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pets', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => $schemas],
        ], JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\NativePets', 'example/native-pets'))->emit($contract);
    }

    private static function schemas(): array
    {
        return [
            'Pet' => [
                'type' => 'object',
                'required' => ['pet-name'],
                'properties' => [
                    'pet-name' => ['type' => 'string', 'minLength' => 2],
                    'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'secret' => ['type' => 'string', 'writeOnly' => true],
                ],
            ],
            'Dog' => ['allOf' => [
                ['$ref' => '#/components/schemas/Pet'],
                ['type' => 'object', 'required' => ['bark'], 'properties' => [
                    'bark' => ['type' => 'boolean'],
                ]],
            ]],
            'WorkingDog' => ['allOf' => [
                ['$ref' => '#/components/schemas/Dog'],
                ['type' => 'object', 'properties' => ['job' => ['type' => 'string']]],
            ]],
            'Cat' => ['allOf' => [
                ['$ref' => '#/components/schemas/Pet'],
                ['type' => 'object', 'properties' => ['hunts' => ['type' => 'boolean']]],
            ]],
        ];
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::schemas());
        spl_autoload_register(static function (string $class) use ($files): void {
            $prefix = 'Example\\NativePets\\';
            if (str_starts_with($class, $prefix)) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
    }

    public function testNativeInheritanceAcrossThreeLevels(): void
    {
        $dog = new \Example\NativePets\DTOs\WorkingDog((object) [
            'pet-name' => 'Rex', 'bark' => true, 'job' => 'rescue', 'status' => 'active',
        ]);
        self::assertInstanceOf(\Example\NativePets\DTOs\Pet::class, $dog);
        self::assertInstanceOf(\Example\NativePets\DTOs\Dog::class, $dog);
        $property = new \ReflectionProperty($dog, 'status');
        self::assertSame(\Example\NativePets\DTOs\Pet::class, $property->getDeclaringClass()->getName());
        self::assertSame('active', json_decode(json_encode($dog, JSON_THROW_ON_ERROR))->status);
        self::assertSame('Rex', $dog->jsonSerialize()->{'pet-name'});
        self::assertTrue((new \ReflectionClass(\Example\NativePets\DTOs\WorkingDog::class))->isFinal());
        self::assertFalse((new \ReflectionClass(\Example\NativePets\DTOs\Dog::class))->isFinal());
    }

    public function testParentRequiredFieldsRemainRequired(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new \Example\NativePets\DTOs\Dog((object) ['bark' => true]);
    }

    public function testChildRequiredFieldsRemainRequired(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        new \Example\NativePets\DTOs\WorkingDog((object) ['pet-name' => 'Rex']);
    }

    public function testInheritedValidationRunsAfterMutation(): void
    {
        $dog = new \Example\NativePets\DTOs\Dog((object) ['pet-name' => 'Rex', 'bark' => true]);
        $property = new \ReflectionProperty(\Example\NativePets\DTOs\Pet::class, 'status');
        self::assertSame($property->getType()->getName(), (new \ReflectionProperty($dog, 'status'))->getType()->getName());
        // Resolve the PHP field from its public reflection metadata, independently of transliteration.
        foreach ((new \ReflectionClass($dog))->getProperties(\ReflectionProperty::IS_PUBLIC) as $field) {
            if ($field->getType()?->getName() === 'string' && $field->isInitialized($dog)) {
                $field->setValue($dog, 'x');
            }
        }
        $this->expectException(\UnexpectedValueException::class);
        $dog->jsonSerialize();
    }

    public function testDirectionalMetadataIsPreserved(): void
    {
        $cat = new \Example\NativePets\DTOs\Cat((object) [
            'pet-name' => 'Mia', 'id' => 1, 'secret' => 'private', 'hunts' => true,
        ]);
        self::assertFalse(property_exists($cat->jsonSerializeForRequest(), 'id'));
        self::assertFalse(property_exists($cat->jsonSerializeForResponse(), 'secret'));
    }

    public function testIndependentBasesKeepFlattenedRepresentation(): void
    {
        $files = self::files([
            'Left' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            'Right' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            'Both' => ['allOf' => [
                ['$ref' => '#/components/schemas/Left'], ['$ref' => '#/components/schemas/Right'],
            ]],
        ]);
        self::assertStringContainsString('extends \\Dmorenof\\SdkRuntime\\DTO', $files['src/DTOs/Both.php']);
        self::assertStringContainsString('public string $a;', $files['src/DTOs/Both.php']);
        self::assertStringContainsString('public string $b;', $files['src/DTOs/Both.php']);
    }

    public function testCyclesStillFailWithDiagnostic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cyclic allOf');
        self::files(['Loop' => ['allOf' => [['$ref' => '#/components/schemas/Loop']]]]);
    }

    public function testClosedParentCannotSilentlyGainProperties(): void
    {
        $schemas = self::schemas();
        $schemas['Pet']['additionalProperties'] = false;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closed allOf');
        self::files($schemas);
    }
}
