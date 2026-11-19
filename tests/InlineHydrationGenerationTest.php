<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** A generated model converts the common JSON members inline; every other member and every unexpected value takes the general path. */
final class InlineHydrationGenerationTest extends TestCase
{
    /** @return array<string, string> */
    private static function files(string $namespace = 'Example\\Inline'): array
    {
        $document = ['openapi' => '3.0.3', 'info' => ['title' => 'Inline', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/users' => ['get' => ['operationId' => 'getUser', 'responses' => ['200' => ['description' => 'ok',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]]]]]],
            'components' => ['schemas' => [
                'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                'Pet' => ['type' => 'object', 'discriminator' => ['propertyName' => 'kind'], 'required' => ['kind'],
                    'properties' => ['kind' => ['type' => 'string']]],
                'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['bark' => ['type' => 'boolean']]]]],
                'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
                'User' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 3],
                    'age' => ['type' => 'integer', 'nullable' => true],
                    'score' => ['type' => 'number'],
                    'active' => ['type' => 'boolean'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'viewer']],
                    'level' => ['type' => 'integer', 'enum' => [1, 2]],
                    'address' => ['$ref' => '#/components/schemas/Address'],
                    'pet' => ['$ref' => '#/components/schemas/Pet'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'display-name' => ['type' => 'string'],
                ]],
            ]]];
        return (new PhpExecutableEmitter($namespace, 'example/inline', []))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }

    public function testScalarsEnumsAndPlainModelsAreConvertedInline(): void
    {
        $model = self::files()['src/DTOs/User.php'];
        self::assertStringContainsString("'name' => \$this->name = is_string(\$value) ? \$value : ", $model);
        self::assertStringContainsString("'age' => \$this->age = \$value === null || is_int(\$value) ? \$value : ", $model);
        self::assertStringContainsString("'score' => \$this->score = is_float(\$value) ? \$value : ", $model);
        self::assertStringContainsString("'active' => \$this->active = is_bool(\$value) ? \$value : ", $model);
        self::assertStringContainsString("'role' => \$this->role = is_string(\$value) ? \\Example\\Inline\\Enums\\User\\RoleEnum::from(\$value) : ", $model);
        self::assertStringContainsString("'level' => \$this->level = is_int(\$value) ? \\Example\\Inline\\Enums\\User\\LevelEnum::from(\$value) : ", $model);
        self::assertStringContainsString("'address' => \$this->address = \$value instanceof \\stdClass ? new \\Example\\Inline\\DTOs\\Address(\$value) : ", $model);
        self::assertStringContainsString("'display-name' => \$this->displayName = is_string(\$value) ? ", $model);
        self::assertStringContainsString('default => parent::assignMember($name, $value),', $model);
    }

    public function testListsAndDiscriminatedModelsKeepTheGeneralPath(): void
    {
        $model = self::files()['src/DTOs/User.php'];
        self::assertStringNotContainsString("'tags' =>", $model);
        self::assertStringNotContainsString("'pet' =>", $model);
    }

    public function testAGeneratedModelBuildsTheSameValuesAndRefusesTheSameValuesAsTheGeneralPath(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Generated models use property hooks (PHP 8.4).');
        }
        $files = self::files('Example\\InlineRun');
        $directory = sys_get_temp_dir() . '/inline-sdk-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\InlineRun\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $class = 'Example\\InlineRun\\DTOs\\User';
            $json = '{"name":"Ada","age":null,"score":1,"active":true,"role":"admin","level":2,"address":{"city":"Madrid"},"tags":["a"],"display-name":"Dee","extra":1}';
            // `score` is an integer in the JSON and a float in the model: the general path converts it.
            $user = \Dmorenof\SdkRuntime\JsonHydrator::decode($json, $class);
            self::assertSame('Ada', $user->name);
            self::assertNull($user->age);
            self::assertSame(1.0, $user->score);
            self::assertSame('Madrid', $user->address->city);
            self::assertSame('Dee', $user->displayName);
            self::assertSame('admin', $user->role->value);

            foreach ([['name' => 5], ['name' => 'Ada', 'role' => 'nope'], ['name' => 'Ada', 'age' => 'x'], ['name' => 'Ada', 'address' => 'x']] as $bad) {
                try {
                    new $class((object) $bad);
                    self::fail('A bad value was accepted: ' . json_encode($bad));
                } catch (\UnexpectedValueException | \ValueError | \TypeError) {
                    self::assertTrue(true);
                }
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
