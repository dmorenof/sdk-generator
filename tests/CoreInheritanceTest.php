<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Casafari-inspired contract tests: actual inheritance and inherited behavior,
 * not simply matching a generated source-code string.
 */
final class CoreInheritanceTest extends TestCase
{
    /** @return array<string, string> */
    private static function generated(): array
    {
        $json = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        self::assertIsString($json);
        $contract = (new SpecificationNormalizer())->fromJson($json);
        return (new PhpExecutableEmitter('Example\\InheritanceSdk', 'example/inheritance-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generated();
        foreach ([
            'src/Core/DTO.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/NewUserRoleEnum.php',
            'src/Enums/UserRoleEnum.php',
            'src/Collections/UserLabelsArray.php',
            'src/Models/Profile.php',
            'src/Models/NewUser.php',
            'src/Models/User.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/ApiClient.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Api/Operations.php',
            'src/Api/ApiClient.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
    }

    public function testModelAndCollectionInheritReusableCoreAndExposeNativeTypes(): void
    {
        $files = self::generated();
        foreach (['DTO', 'Request', 'Response', 'TypedArray'] as $name) {
            self::assertArrayHasKey('src/Core/' . $name . '.php', $files);
        }
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Models\User::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Core\Request::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Core\Response::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Collections\UserLabelsArray::class, \Example\InheritanceSdk\Core\TypedArray::class));
        self::assertSame('string', (new \Example\InheritanceSdk\Collections\UserLabelsArray())->getExpectedType());
        $property = (new \ReflectionClass(\Example\InheritanceSdk\Models\User::class))->getProperty('profile');
        self::assertSame(\Example\InheritanceSdk\Models\Profile::class, $property->getType()->getName());
    }

    public function testConstructorRecursivelyHydratesModelsEnumsAndCollections(): void
    {
        $user = new \Example\InheritanceSdk\Models\User(json_decode(
            '{"id":27,"name":"Ada","profile":{"city":"Madrid","visits":3},"role":"admin","labels":["php","sdk"]}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(27, $user->id);
        self::assertSame(\Example\InheritanceSdk\Enums\UserRoleEnum::Admin, $user->role);
        self::assertInstanceOf(\Example\InheritanceSdk\Models\Profile::class, $user->profile);
        self::assertSame('Madrid', $user->profile->city);
        self::assertSame(['php', 'sdk'], $user->labels->getArrayCopy());
        self::assertSame('["php","sdk"]', (string) $user->labels);
        self::assertSame(27, json_decode((string) $user, true, 512, JSON_THROW_ON_ERROR)['id']);
        self::assertFalse(isset($user->nickname));

        $user->nickname = null;
        self::assertArrayHasKey('nickname', json_decode((string) $user, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testConstructorRejectsInvalidScalarTypes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid scalar value');
        new \Example\InheritanceSdk\Models\User((object) ['id' => 'not-an-integer', 'name' => 'Ada']);
    }

    public function testConstructorRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown JSON property');
        new \Example\InheritanceSdk\Models\Profile((object) ['city' => 'Madrid', 'surprise' => 'hidden']);
    }

    public function testRequestAndResponseInheritJsonBehavior(): void
    {
        $request = new class((object) ['name' => 'example']) extends \Example\InheritanceSdk\Core\Request {
            public string $name;
        };
        $response = new class((object) ['result' => 'accepted']) extends \Example\InheritanceSdk\Core\Response {
            public string $result;
        };
        self::assertSame('{"name":"example"}', (string) $request);
        self::assertSame('{"result":"accepted"}', (string) $response);
    }

    public function testMissingRequiredOutgoingFieldStopsBeforeHttp(): void
    {
        $transport = new class implements \Example\InheritanceSdk\Transport\TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Example\InheritanceSdk\Transport\HttpResponse
            {
                $this->calls++;
                throw new \LogicException('Should not send invalid DTO');
            }
        };
        $api = new \Example\InheritanceSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
        try {
            $api->createUser(new \Example\InheritanceSdk\Models\NewUser());
            self::fail('Missing required field was transmitted');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('Required JSON property', $e->getMessage());
        }
        self::assertSame(0, $transport->calls);
    }

    public function testNestedTypedArraysAcceptHydratedElements(): void
    {
        $source = file_get_contents(__DIR__ . '/fixtures/typed-collections-openapi31.json');
        self::assertIsString($source);
        $contract = (new SpecificationNormalizer())->fromJson($source);
        $files = (new PhpExecutableEmitter('Example\\NestedSdk', 'example/nested-sdk'))->emit($contract);
        foreach ([
            'src/Core/DTO.php', 'src/Core/TypedArray.php',
            'src/Enums/BundleRolesItemEnum.php',
            'src/Collections/BundleEntriesArray.php',
            'src/Collections/BundleRolesArray.php',
            'src/Models/Entry.php', 'src/Models/Bundle.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }

        $entry = new \Example\NestedSdk\Models\Entry((object) ['name' => 'Ada']);
        $role = \Example\NestedSdk\Enums\BundleRolesItemEnum::Admin;
        $bundle = new \Example\NestedSdk\Models\Bundle((object) [
            'entries' => [$entry], 'roles' => [$role],
        ]);
        self::assertSame($entry, $bundle->entries[0]);
        self::assertSame($role, $bundle->roles[0]);
        self::assertSame(
            ['entries' => [['name' => 'Ada']], 'roles' => ['admin']],
            json_decode((string) $bundle, true, 512, JSON_THROW_ON_ERROR),
        );

        $fromJson = new \Example\NestedSdk\Models\Bundle(
            json_decode('{"entries":[{"name":"Grace"}],"roles":["viewer"]}', false, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('Grace', $fromJson->entries[0]->name);
        self::assertSame(\Example\NestedSdk\Enums\BundleRolesItemEnum::Viewer, $fromJson->roles[0]);
    }

    public function testTypedFieldRejectsUnrelatedClass(): void
    {
        $user = new \Example\InheritanceSdk\Models\User();
        $this->expectException(\TypeError::class);
        $user->profile = new \stdClass();
    }
}
