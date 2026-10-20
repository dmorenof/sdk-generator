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
            'src/Collections/UserLabelsArray.php',
            'src/Core/DTO.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/NewUserRoleEnum.php',
            'src/Enums/UserRoleEnum.php',
            'src/Models/NewUser.php',
            'src/Models/Profile.php',
            'src/Models/User.php',
        ], array_keys($swagger));
    }

    public function testGeneratedDTOsHaveNativeTypesAndRetainWireNames(): void
    {
        $files = $this->generated();
        $user = $files['src/Models/User.php'];
        self::assertStringContainsString('public int $id;', $user);
        self::assertStringContainsString('public string $name;', $user);
        self::assertStringContainsString('public ?string $nickname;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\Models\\Profile $profile;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\Enums\\UserRoleEnum $role;', $user);
        self::assertStringContainsString('public \\Example\\AccountsSdk\\Collections\\UserLabelsArray $labels;', $user);
        self::assertStringContainsString('extends \\Example\\AccountsSdk\\Core\\DTO', $user);
        self::assertStringContainsString('(object) get_object_vars($this)', $files['src/Core/DTO.php']);
        self::assertStringContainsString('extends \\Example\\AccountsSdk\\Core\\TypedArray', $files['src/Collections/UserLabelsArray.php']);
        self::assertStringNotContainsString('= null;', $user);
        self::assertStringContainsString("case Admin = 'admin';", $files['src/Enums/UserRoleEnum.php']);
        self::assertStringContainsString("case Viewer = 'viewer';", $files['src/Enums/UserRoleEnum.php']);
        self::assertStringContainsString("protected string \$expected_type = 'string';", $files['src/Collections/UserLabelsArray.php']);
    }

    public function testGeneratedEndpointContractsExposeTypedArgumentsAndRoutes(): void
    {
        $files = $this->generated();
        $operations = $files['src/Api/Operations.php'];
        self::assertStringContainsString(
            'public function getUser(int $id, ?bool $verbose = null): \\Example\\AccountsSdk\\Models\\User;',
            $operations,
        );
        self::assertStringContainsString(
            'public function createUser(\\Example\\AccountsSdk\\Models\\NewUser $body): \\Example\\AccountsSdk\\Models\\User;',
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
            'src/Enums/UserRoleEnum.php',
            'src/Models/Profile.php',
            'src/Models/User.php',
        ] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }

        $profileClass = 'Example\\AccountsSdk\\Models\\Profile';
        $userClass = 'Example\\AccountsSdk\\Models\\User';
        $roleEnum = 'Example\\AccountsSdk\\Enums\\UserRoleEnum';

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

    public function testRejectsUnrepresentableWireNameInsteadOfEmittingBrokenPhp(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['User']['properties']['not-valid-name'] = ['type' => 'string'];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP model property identifier');
        (new PhpContractEmitter())->emit($contract);
    }

    public function testRejectsEnumValuesWhichWouldGenerateReservedPHPCaseNames(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['components']['schemas']['User']['properties']['role']['enum'][] = 'class';
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP enum case identifier: Class');
        (new PhpContractEmitter())->emit($contract);
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
