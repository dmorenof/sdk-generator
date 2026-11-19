<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** With property hooks (PHP 8.4) a property that has rules checks each value as it is assigned or hydrated. */
final class PropertyHooksGenerationTest extends TestCase
{
    /** @return array<string, string> */
    private static function files(string $namespace = 'Example\\Hooks'): array
    {
        $document = ['openapi' => '3.0.3', 'info' => ['title' => 'Hooks', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/users' => ['get' => ['operationId' => 'getUser', 'responses' => ['200' => ['description' => 'ok',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]]]]]],
            'components' => ['schemas' => ['User' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                'name' => ['type' => 'string', 'minLength' => 3],
                'age' => ['type' => 'integer', 'minimum' => 18, 'nullable' => true],
                'role' => ['type' => 'string', 'enum' => ['admin', 'viewer']],
                'note' => ['type' => 'string'],
                'count' => ['type' => 'integer', 'format' => 'int32'],
                'big' => ['type' => 'integer', 'format' => 'int64'],
            ]]]]];
        return (new PhpExecutableEmitter($namespace, 'example/hooks', []))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }

    public function testOnlyPropertiesWithRulesGetAHook(): void
    {
        $model = self::files()['src/DTOs/User.php'];
        self::assertStringContainsString("public string \$name {\n        set {\n            \$this->assigning('name', \$value);\n            \$this->name = \$value;\n        }\n    }", $model);
        self::assertStringContainsString("public ?int \$age {\n        set {\n            \$this->assigning('age', \$value);", $model);
        self::assertStringContainsString('public \\Example\\Hooks\\Enums\\User\\RoleEnum $role;', $model); // an enum type needs no hook
        self::assertStringContainsString('public string $note;', $model);
        self::assertStringNotContainsString('VALIDATES_ON_ASSIGNMENT', $model);
    }

    public function testTheRangeOfInt32IsAlwaysAssertedAndInt64NeedsNothing(): void
    {
        $model = self::files()['src/DTOs/User.php'];
        self::assertStringContainsString("public int \$count {\n        set {", $model);
        self::assertStringContainsString("'format' => 'int32'", $model);
        self::assertStringContainsString('public int $big;', $model); // int64 is PHP's own int
    }

    public function testASdkAsksForPhp84AndTheRuntimeThatUnderstandsHooks(): void
    {
        $require = json_decode(self::files()['composer.json'], true, 512, JSON_THROW_ON_ERROR)['require'];
        self::assertSame('^8.4', $require['php']);
        self::assertSame('^1.4.2', $require['dmorenof/sdk-runtime']);
    }

    public function testAGeneratedHookedModelChecksValuesWhenTheyAreAssignedOrHydrated(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks need PHP 8.4.');
        }
        $files = self::files('Example\\HookedRun');
        $directory = sys_get_temp_dir() . '/hooked-sdk-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\HookedRun\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $class = 'Example\\HookedRun\\DTOs\\User';
            $user = new $class((object) ['name' => 'Ada', 'age' => 40, 'role' => 'admin']);
            self::assertSame('Ada', $user->name);
            $user->age = null; // a nullable field may be null

            foreach ([['name' => 'Al'], ['name' => 'Ada', 'age' => 12]] as $bad) {
                try {
                    new $class((object) $bad);
                    self::fail('A value that breaks its rule was hydrated: ' . json_encode($bad));
                } catch (\UnexpectedValueException) {
                    self::assertTrue(true);
                }
            }
            try {
                $user->name = 'Al';
                self::fail('A value that breaks its rule was assigned.');
            } catch (\UnexpectedValueException) {
                self::assertSame('Ada', $user->name, 'a refused value is not stored');
            }
            try {
                $user->count = 2147483648;
                self::fail('A value outside int32 was assigned.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
            $user->count = 2147483647;
            $user->validate();
            $empty = new $class();
            $this->expectException(\UnexpectedValueException::class); // a required field that was never set
            $empty->validate();
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
