<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class PhpExecutableEmitterTest extends TestCase
{
    /** @return array<string, string> */
    private function files(string $fixture = 'accounts-swagger2.json'): array
    {
        $source = file_get_contents(__DIR__ . '/fixtures/' . $fixture);
        self::assertIsString($source);
        $contract = (new SpecificationNormalizer())->fromJson($source);
        return (new PhpExecutableEmitter('Example\\ExecutableSdk', 'example/executable-sdk'))->emit($contract);
    }

    private function loadGeneratedClasses(): void
    {
        // The generator is a build-time dependency. The generated code does
        // not depend on sdk-generator in order to run.
        $files = $this->files();
        foreach ([
            'src/Core/ApiClient.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
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
            'src/Support/JsonHydrator.php',
            'src/Api/Operations.php',
            'src/Api/ApiClient.php',
        ] as $file) {
            $source = $files[$file];
            // eval only tests our generated source; it never evaluates a
            // third-party Swagger payload or untrusted arbitrary PHP input.
            eval(substr($source, strlen('<?php')));
        }
    }

    public function testOutputIsIdenticalForThreeEquivalentDialects(): void
    {
        self::assertSame($this->files(), $this->files('accounts-openapi30.json'));
        self::assertSame($this->files(), $this->files('accounts-openapi31.json'));
        self::assertArrayHasKey('src/Api/ApiClient.php', $this->files());
        self::assertArrayHasKey('src/Transport/TransportInterface.php', $this->files());
        self::assertArrayHasKey('src/Support/JsonHydrator.php', $this->files());
    }

    public function testEveryGeneratedPhpFileIsSyntacticallyValid(): void
    {
        foreach ($this->files() as $path => $source) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'sdk-executable-');
            self::assertNotFalse($file);
            try {
                file_put_contents($file, $source);
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lines, $exit);
                self::assertSame(0, $exit, "$path: " . implode("\n", $lines));
                $lines = [];
                $exit = 0;
            } finally {
                unlink($file);
            }
        }
    }

    public function testGeneratedClientPerformsGETAndPOSTAndHydratesTypedResponses(): void
    {
        $this->loadGeneratedClasses();

        $fake = new class implements \Example\ExecutableSdk\Transport\TransportInterface {
            public array $calls = [];

            public function send(string $method, string $url, array $headers, ?string $body): \Example\ExecutableSdk\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                $payload = json_encode([
                    'id' => 42,
                    'name' => 'Ada',
                    'role' => 'admin',
                    'profile' => ['city' => 'Madrid', 'visits' => 3],
                    'labels' => ['php', 'sdk'],
                ], JSON_THROW_ON_ERROR);
                return new \Example\ExecutableSdk\Transport\HttpResponse(
                    $method === 'GET' ? 200 : 201,
                    $payload,
                );
            }
        };
        $client = new \Example\ExecutableSdk\Api\ApiClient($fake, 'https://api.example.test/v1/');
        $user = $client->getUser(42, false);
        self::assertInstanceOf(\Example\ExecutableSdk\Models\User::class, $user);
        self::assertSame(42, $user->id);
        self::assertSame('Ada', $user->name);
        self::assertSame('Madrid', $user->profile->city);
        self::assertSame(3, $user->profile->visits);
        self::assertSame(\Example\ExecutableSdk\Enums\UserRoleEnum::Admin, $user->role);
        self::assertInstanceOf(\Example\ExecutableSdk\Collections\UserLabelsArray::class, $user->labels);
        self::assertSame(['php', 'sdk'], $user->labels->getArrayCopy());
        self::assertFalse(isset($user->nickname));
        self::assertSame('GET', $fake->calls[0]['method']);
        self::assertSame('https://api.example.test/v1/users/42?verbose=false', $fake->calls[0]['url']);
        self::assertNull($fake->calls[0]['body']);

        $input = new \Example\ExecutableSdk\Models\NewUser();
        $input->name = 'Grace';
        $input->role = \Example\ExecutableSdk\Enums\NewUserRoleEnum::Viewer;
        $result = $client->createUser($input);
        self::assertInstanceOf(\Example\ExecutableSdk\Models\User::class, $result);
        self::assertSame('POST', $fake->calls[1]['method']);
        self::assertSame('https://api.example.test/v1/users', $fake->calls[1]['url']);
        self::assertSame('application/json', $fake->calls[1]['headers']['Content-Type']);
        self::assertSame(['name' => 'Grace', 'role' => 'viewer'], json_decode(
            $fake->calls[1]['body'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testGeneratedClientRejectsUnexpectedHttpStatus(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Api\ApiClient::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Example\ExecutableSdk\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Example\ExecutableSdk\Transport\HttpResponse
            {
                return new \Example\ExecutableSdk\Transport\HttpResponse(404, '{"error":"not found"}');
            }
        };
        $client = new \Example\ExecutableSdk\Api\ApiClient($fake, 'https://api.example.test/v1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected HTTP status from getUser: 404');
        $client->getUser(1);
    }

    public function testHydratorRejectsMissingRequiredJsonFields(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Api\ApiClient::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Example\ExecutableSdk\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Example\ExecutableSdk\Transport\HttpResponse
            {
                return new \Example\ExecutableSdk\Transport\HttpResponse(200, '{"name":"Ada"}');
            }
        };
        $client = new \Example\ExecutableSdk\Api\ApiClient($fake, 'https://api.example.test/v1');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Required JSON property');
        $client->getUser(1);
    }

    public function testHydratorRejectsInvalidScalarTypes(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Api\ApiClient::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Example\ExecutableSdk\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Example\ExecutableSdk\Transport\HttpResponse
            {
                return new \Example\ExecutableSdk\Transport\HttpResponse(200, '{"id":"oops","name":"Ada"}');
            }
        };
        $client = new \Example\ExecutableSdk\Api\ApiClient($fake, 'https://api.example.test/v1');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid scalar value');
        $client->getUser(1);
    }
}
