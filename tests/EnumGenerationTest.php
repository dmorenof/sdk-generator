<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Wherever PHP can hold the values (backed enums are strings or integers), the field is an enum, and only then. */
final class EnumGenerationTest extends TestCase
{
    /** @param array<string, mixed> $properties @param list<string> $required @return array<string, string> */
    private static function files(array $properties, array $required = [], string $namespace = 'Example\\Enums'): array
    {
        $document = [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Enum API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/m' => ['get' => ['operationId' => 'getM', 'responses' => ['200' => ['description' => 'ok',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/M']]]]]]]],
            'components' => ['schemas' => ['M' => ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : ['required' => $required])]],
        ];
        $document['components']['schemas']['M'] = ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : ['required' => $required]);
        return (new PhpExecutableEmitter($namespace, 'example/enums'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }

    /** @param array<string, string> $files */
    private static function model(array $files): string
    {
        foreach ($files as $path => $content) {
            if (str_ends_with($path, 'DTOs/M.php')) {
                return $content;
            }
        }
        self::fail('Model M was not generated.');
    }

    /** @param array<string, string> $files */
    private static function load(array $files, string $namespace): callable
    {
        $directory = sys_get_temp_dir() . '/enum-sdk-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        $loader = static function (string $class) use ($directory, $namespace): void {
            if (str_starts_with($class, $namespace . '\\') && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php')) {
                require $file;
            }
        };
        spl_autoload_register($loader);
        return $loader;
    }

    public function testAStringEnumIsAnEnumWithoutARepeatedRule(): void
    {
        $model = self::model(self::files(['p' => ['type' => 'string', 'enum' => ['a', 'b']]]));
        self::assertMatchesRegularExpression('/public \\\\Example\\\\Enums\\\\Enums\\\\M\\\\PEnum \$p;/', $model);
        self::assertStringNotContainsString("'enum'", $model);
        self::assertStringNotContainsString('VALIDATION', $model);
    }

    public function testANullAmongTheValuesMakesAnEnumThatMayBeNull(): void
    {
        $files = self::files(['p' => ['type' => 'string', 'nullable' => true, 'enum' => ['a', 'b', null]]], namespace: 'Example\\NullableEnum');
        self::assertMatchesRegularExpression('/public \?\\\\Example\\\\NullableEnum\\\\Enums\\\\M\\\\PEnum \$p;/', self::model($files));
        $loader = self::load($files, 'Example\\NullableEnum');
        try {
            $enum = 'Example\\NullableEnum\\Enums\\M\\PEnum';
            self::assertSame(['A', 'B'], array_map(static fn ($case): string => $case->name, $enum::cases()));
            $dto = 'Example\\NullableEnum\\DTOs\\M';
            self::assertNull($dto::fromValue((object) ['p' => null])->p);
            self::assertSame($enum::A, $dto::fromValue((object) ['p' => 'a'])->p);
            $this->expectException(\ValueError::class);
            $dto::fromValue((object) ['p' => 'zz']);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testAnEnumWithoutATypeIsAnEnumWhenAllItsValuesAreStrings(): void
    {
        $strings = self::model(self::files(['p' => ['enum' => ['x', 'y']]]));
        self::assertMatchesRegularExpression('/public \\\\Example\\\\Enums\\\\Enums\\\\M\\\\PEnum \$p;/', $strings);
        self::assertStringContainsString('enum PEnum: int', implode("\n", self::files(['p' => ['enum' => [1, 2]]])));
    }

    public function testCaseNamesAreAlwaysValidAndUnique(): void
    {
        $values = ['', '1abc', 'a-b', 'A', 'a', 'class', 'null', 'é', '!!!', '???'];
        $files = self::files(['p' => ['type' => 'string', 'enum' => $values]], namespace: 'Example\\OddNames');
        $loader = self::load($files, 'Example\\OddNames');
        try {
            $enum = 'Example\\OddNames\\Enums\\M\\PEnum';
            $cases = $enum::cases();
            self::assertCount(count($values), $cases);
            self::assertCount(count($values), array_unique(array_map(static fn ($case): string => strtolower($case->name), $cases)));
            self::assertEqualsCanonicalizing($values, array_map(static fn ($case): string => $case->value, $cases));
            foreach ($values as $value) {
                self::assertSame($value, $enum::from($value)->value);
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testListsPhpCannotHoldAsAnEnumStayPlainTypesWithAGuard(): void
    {
        foreach ([
            'fractional numbers' => ['type' => 'number', 'enum' => [1.5, 2.5]],
            'booleans' => ['type' => 'boolean', 'enum' => [true]],
            'mixed kinds' => ['enum' => ['a', 1]],
        ] as $name => $schema) {
            $model = self::model(self::files(['p' => $schema]));
            self::assertStringNotContainsString('PEnum', $model, $name);
            self::assertStringContainsString('"enum"', $model, $name);
        }
    }

    public function testRequiredFieldsAreListedOnlyWhenThereAreSome(): void
    {
        $none = self::model(self::files(['p' => ['type' => 'string']]));
        self::assertStringNotContainsString('REQUIRED_FIELDS', $none);
        $some = self::model(self::files(['p' => ['type' => 'string']], ['p']));
        self::assertStringContainsString("REQUIRED_FIELDS = [\n    'p',\n]", $some);
    }
}
