<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Generation\PhpContractEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhpContractEmitterTest extends TestCase
{
    private function contract(string $format = 'accounts-swagger2.json'): ApiContract
    {
        $json = file_get_contents(__DIR__ . '/fixtures/' . $format);
        self::assertIsString($json);
        return (new SpecificationNormalizer())->fromJson($json);
    }

    /** @return array<string, string> */
    private function generated(string $format = 'accounts-swagger2.json'): array
    {
        return (new PhpContractEmitter('Example\\AccountsSdk', 'example/accounts-sdk'))
            ->emit($this->contract($format));
    }

    public function testThreeInputFormatsGenerateByteIdenticalPHPFiles(): void
    {
        $swagger = $this->generated();
        self::assertSame($swagger, $this->generated('accounts-openapi30.json'));
        self::assertSame($swagger, $this->generated('accounts-openapi31.json'));
        self::assertSame($swagger, $this->generated()); // Repeated runs deterministic.
        self::assertSame([
            'composer.json',
            'src/Api/OperationCatalog.php',
            'src/Api/Operations.php',
            'src/Api/Requests/GetUserRequest.php',
            'src/Collections/StringCollection.php',
            'src/Core/DTO.php',
            'src/Core/Map.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/DTOs/Profile.php',
            'src/Enums/NewUser/RoleEnum.php',
            'src/Enums/User/RoleEnum.php',
            'src/Requests/NewUser.php',
            'src/Responses/User.php',
        ], array_keys($swagger));
    }

    public function testGeneratedDTOsHaveNativeTypesAndRetainWireNames(): void
    {
        $files = $this->generated();
        $user = $files['src/Responses/User.php'];
        self::assertStringContainsString('extends \\Example\\AccountsSdk\\Core\\Response', $user);
        self::assertStringContainsString(
            'extends \\Example\\AccountsSdk\\Core\\Request',
            $files['src/Requests/NewUser.php'],
        );
        self::assertStringContainsString(
            'extends \\Example\\AccountsSdk\\Core\\DTO',
            $files['src/DTOs/Profile.php'],
        );
        self::assertStringContainsString('public int $id;', $user);
        self::assertStringContainsString('public string $name;', $user);
        self::assertStringContainsString('public ?string $nickname;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\DTOs\\Profile $profile;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\Enums\\User\\RoleEnum $role;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\Collections\\StringCollection $labels;', $user);
        self::assertStringContainsString('return self::filterFields(', $files['src/Core/DTO.php']);
        self::assertStringContainsString('extends \\Example\\AccountsSdk\\Core\\TypedArray', $files['src/Collections/StringCollection.php']);
        self::assertStringNotContainsString('= null;', $user);
        self::assertStringContainsString("case Admin = 'admin';", $files['src/Enums/User/RoleEnum.php']);
        self::assertStringContainsString("case Viewer = 'viewer';", $files['src/Enums/User/RoleEnum.php']);
        self::assertStringContainsString("protected string \$expected_type = 'string';", $files['src/Collections/StringCollection.php']);
    }

    public function testGeneratedEndpointContractsExposeTypedArgumentsAndRoutes(): void
    {
        $files = $this->generated();
        $operations = $files['src/Api/Operations.php'];
        self::assertStringContainsString(
            'public function getUser(\\Example\\AccountsSdk\\Api\\Requests\\GetUserRequest $request): \\Example\\AccountsSdk\\Responses\\User;',
            $operations,
        );
        self::assertStringContainsString(
            'public function createUser(\\Example\\AccountsSdk\\Requests\\NewUser $body): \\Example\\AccountsSdk\\Responses\\User;',
            $operations,
        );
        $catalog = $files['src/Api/OperationCatalog.php'];
        self::assertStringContainsString("'method' => 'GET'", $catalog);
        self::assertStringContainsString("'method' => 'POST'", $catalog);
        self::assertStringContainsString("'path' => '/users/{id}'", $catalog);
        self::assertStringContainsString("'status' => '201'", $catalog);
    }

    public function testGeneratedComposerMetadataIsInstallableLibraryMetadata(): void
    {
        $data = json_decode($this->generated()['composer.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('example/accounts-sdk', $data['name']);
        self::assertSame('^8.3', $data['require']['php']);
        self::assertSame('^1.0', $data['require']['typedarray/typedarray']);
        self::assertSame('src/', $data['autoload']['psr-4']['Example\\AccountsSdk\\']);
        self::assertArrayNotHasKey('version', $data);
    }

    public function testAllGeneratedPHPFilesPassNativeSyntaxCheck(): void
    {
        foreach ($this->generated() as $relative => $source) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'sdk-php-');
            self::assertNotFalse($tmp);
            try {
                self::assertNotFalse(file_put_contents($tmp, $source));
                $output = [];
                $exit = 0;
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
                self::assertSame(0, $exit, $relative . ': ' . implode("\n", $output));
            } finally {
                unlink($tmp);
            }
        }
    }

    public function testGeneratedDTOCanSerializeNestedValuesAndDistinguishAbsentFromNull(): void
    {
        $files = $this->generated();
        // Load only the PHP classes this smoke test needs (no HTTP runtime).
        foreach ([
            'src/Core/DTO.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/User/RoleEnum.php',
            'src/DTOs/Profile.php',
            'src/Responses/User.php',
        ] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }

        $profileClass = 'Example\\AccountsSdk\\DTOs\\Profile';
        $userClass = 'Example\\AccountsSdk\\Responses\\User';
        $roleEnum = 'Example\\AccountsSdk\\Enums\\User\\RoleEnum';

        $profile = new $profileClass();
        $profile->city = 'Madrid';
        $profile->visits = 3;

        $user = new $userClass();
        $user->id = 42;
        $user->name = 'Ada';
        $user->profile = $profile;
        $user->role = $roleEnum::Admin;

        $json = json_encode($user, JSON_THROW_ON_ERROR);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(42, $data['id']);
        self::assertSame('admin', $data['role']);
        self::assertSame('Madrid', $data['profile']['city']);
        self::assertArrayNotHasKey('nickname', $data);
        self::assertArrayNotHasKey('labels', $data);

        $user->nickname = null;
        $data = json_decode(json_encode($user, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('nickname', $data);
        self::assertNull($data['nickname']);
    }

    public function testSharedInputOutputModelStaysNeutralDto(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['paths']['/users']['post']['requestBody']['content']['application/json']['schema']['$ref']
            = '#/components/schemas/User';
        $modified = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpContractEmitter('Example\\BothRoles', 'example/both-roles'))->emit($modified);
        self::assertStringContainsString(
            'final class User extends \\Example\\BothRoles\\Core\\DTO',
            $files['src/DTOs/User.php'],
        );
        self::assertStringNotContainsString(
            'extends \\Example\\BothRoles\\Core\\Response',
            $files['src/DTOs/User.php'],
        );
    }

    public function testWirePropertyNamesAreMappedToSafePhpIdentifiers(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['User']['properties']['not-valid-name'] = ['type' => 'string'];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpContractEmitter())->emit($contract);

        self::assertStringContainsString('public string $notValidName;', $files['src/Responses/User.php']);
        self::assertStringContainsString("'not-valid-name' => 'notValidName'", $files['src/Responses/User.php']);
    }

    public function testReservedEnumCaseNamesAreMappedSafely(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['User']['properties']['role']['enum'][] = 'class';
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpContractEmitter())->emit($contract);

        self::assertStringContainsString("case Class_ = 'class';", $files['src/Enums/User/RoleEnum.php']);
    }

    public function testCollidingSanitizedEnumCaseNamesGetStableSuffixes(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['User']['properties']['role']['enum'][] = 'a';
        $raw['components']['schemas']['User']['properties']['role']['enum'][] = 'a+';
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpContractEmitter())->emit($contract);

        self::assertStringContainsString("case A = 'a';", $files['src/Enums/User/RoleEnum.php']);
        self::assertStringContainsString("case A2 = 'a+';", $files['src/Enums/User/RoleEnum.php']);
    }

    public function testTopLevelStringEnumsGenerateBackedEnums(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['UserStatus'] = [
            'type' => 'string',
            'enum' => ['active', 'new'],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpContractEmitter())->emit($contract);

        self::assertStringContainsString("case New_ = 'new';", $files['src/Enums/UserStatus.php']);
    }

    public function testRejectsNonObjectTopLevelModelUntilAliasesSupported(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['UserId'] = ['type' => 'integer'];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported non-object model: UserId');
        (new PhpContractEmitter())->emit($contract);
    }
}
