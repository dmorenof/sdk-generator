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
            'src/Enums/NewUser/RoleEnum.php',
            'src/Enums/User/RoleEnum.php',
            'src/Collections/User/LabelsCollection.php',
            'src/DTOs/Profile.php',
            'src/Requests/NewUser.php',
            'src/Responses/User.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/BasicAuthCredentials.php',
            'src/Core/SecurityCredentials.php',
            'src/Core/Security.php',
            'src/Core/ApiClient.php', 'src/Core/ApiException.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/CasafariSDK.php',
            'src/Api/Users.php',
            'src/Api/Users/User.php',
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
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Responses\User::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Requests\NewUser::class, \Example\InheritanceSdk\Core\Request::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Responses\User::class, \Example\InheritanceSdk\Core\Response::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\DTOs\Profile::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Core\Request::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Core\Response::class, \Example\InheritanceSdk\Core\DTO::class));
        self::assertTrue(is_subclass_of(\Example\InheritanceSdk\Collections\User\LabelsCollection::class, \Example\InheritanceSdk\Core\TypedArray::class));
        self::assertSame('string', (new \Example\InheritanceSdk\Collections\User\LabelsCollection())->getExpectedType());
        $property = (new \ReflectionClass(\Example\InheritanceSdk\Responses\User::class))->getProperty('profile');
        self::assertSame(\Example\InheritanceSdk\DTOs\Profile::class, $property->getType()->getName());
    }

    public function testConstructorRecursivelyHydratesModelsEnumsAndCollections(): void
    {
        $user = new \Example\InheritanceSdk\Responses\User(json_decode(
            '{"id":27,"name":"Ada","profile":{"city":"Madrid","visits":3},"role":"admin","labels":["php","sdk"]}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(27, $user->id);
        self::assertSame(\Example\InheritanceSdk\Enums\User\RoleEnum::Admin, $user->role);
        self::assertInstanceOf(\Example\InheritanceSdk\DTOs\Profile::class, $user->profile);
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
        new \Example\InheritanceSdk\Responses\User((object) ['id' => 'not-an-integer', 'name' => 'Ada']);
    }

    public function testConstructorRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown JSON property');
        new \Example\InheritanceSdk\DTOs\Profile((object) ['city' => 'Madrid', 'surprise' => 'hidden']);
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
        \Example\InheritanceSdk\CasafariSDK::connect('https://api.example.test/v1', transport: $transport);
        try {
            \Example\InheritanceSdk\Api\Users::post(new \Example\InheritanceSdk\Requests\NewUser());
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
            'src/Enums/Bundle/RolesEnum.php',
            'src/Collections/Bundle/EntriesCollection.php',
            'src/Collections/Bundle/RolesCollection.php',
            'src/DTOs/Entry.php', 'src/DTOs/Bundle.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }

        $entry = new \Example\NestedSdk\DTOs\Entry((object) ['name' => 'Ada']);
        $role = \Example\NestedSdk\Enums\Bundle\RolesEnum::Admin;
        $bundle = new \Example\NestedSdk\DTOs\Bundle((object) [
            'entries' => [$entry], 'roles' => [$role],
        ]);
        self::assertSame($entry, $bundle->entries[0]);
        self::assertSame($role, $bundle->roles[0]);
        self::assertSame(
            ['entries' => [['name' => 'Ada']], 'roles' => ['admin']],
            json_decode((string) $bundle, true, 512, JSON_THROW_ON_ERROR),
        );

        $fromJson = new \Example\NestedSdk\DTOs\Bundle(
            json_decode('{"entries":[{"name":"Grace"}],"roles":["viewer"]}', false, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('Grace', $fromJson->entries[0]->name);
        self::assertSame(\Example\NestedSdk\Enums\Bundle\RolesEnum::Viewer, $fromJson->roles[0]);
    }

    public function testOperationRequestRetainsRequiredPathAndOptionalQueryTypes(): void
    {
        $method = new \ReflectionMethod(\Example\InheritanceSdk\Api\Users\User::class, 'getById');
        $parameters = $method->getParameters();
        self::assertSame(['id', 'verbose'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));
        self::assertSame('int', $parameters[0]->getType()->getName());
        self::assertSame('bool', $parameters[1]->getType()->getName());
        self::assertTrue($parameters[1]->allowsNull());
        self::assertSame(null, $parameters[1]->getDefaultValue());
    }

    public function testTypedFieldRejectsUnrelatedClass(): void
    {
        $user = new \Example\InheritanceSdk\Responses\User();
        $this->expectException(\TypeError::class);
        $user->profile = new \stdClass();
    }
}
