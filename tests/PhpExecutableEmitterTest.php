<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class PhpExecutableEmitterTest extends TestCase
{
    private static bool $defaultClassesLoaded = false;

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
        if (self::$defaultClassesLoaded) {
            return;
        }
        $files = $this->files();
        foreach ([
            'src/Enums/NewUser/RoleEnum.php',
            'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php',
            'src/DTOs/Profile.php',
            'src/Requests/NewUser.php',
            'src/Responses/User.php',
            'src/Client.php',
            'src/Api/Users.php',
            'src/Api/Users/User.php',
        ] as $file) {
            $source = $files[$file];
            eval(substr($source, strlen('<?php')));
        }
        self::$defaultClassesLoaded = true;
    }

    public function testInvalidOperationIdentifiersAreMappedToStablePhpNames(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['paths']['/users']['post']['operationId'] = 'operation-with-hyphens';
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter())->emit($contract);

        self::assertStringContainsString("public function post(", $files['src/Api/Users.php']);
        self::assertStringContainsString("'POST /users'", $files['src/Api/Users.php']);
        self::assertStringNotContainsString("'operation-with-hyphens'", $files['src/Api/Users.php']);
        self::assertStringContainsString(
            'extends \\Generated\\Sdk\\Client',
            $files['src/Api/Users.php'],
        );
        self::assertStringContainsString('$this->executeOperation(', $files['src/Api/Users.php']);
    }

    public function testNullablePrimitiveAnyOfIsSupportedForHttpParameters(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['paths']['/users/{id}']['get']['parameters'][1]['schema'] = [
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter())->emit($contract);

        self::assertStringContainsString(
            '?string $verbose = null',
            $files['src/Api/Users/User.php'],
        );
    }

    public function testEnumHttpParametersAreValidatedAsTheirPrimitiveType(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['paths']['/users/{id}']['get']['parameters'][1]['schema'] = [
            'type' => 'string',
            'enum' => ['brief', 'full'],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter(
            'Example\\EnumParameterSdk',
            'example/enum-parameter-sdk',
        ))->emit($contract);

        self::assertStringContainsString(
            "'enum' => [",
            $files['src/Api/Users/User.php'],
        );
        self::assertStringContainsString("'brief'", $files['src/Api/Users/User.php']);
        self::assertStringContainsString("'full'", $files['src/Api/Users/User.php']);

        foreach ([
            'src/Client.php', 'src/Api/Users/User.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
        try {
            $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
                public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
                {
                    throw new \RuntimeException('Valid enum value reached transport.');
                }
            };
            $client = \Example\EnumParameterSdk\Client::connect(
                'https://api.example.test',
                transport: $transport,
            );
            $client->resource(\Example\EnumParameterSdk\Api\Users\User::class)->getById(1, 'invalid');
            self::fail('An enum value outside the declared set was accepted.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('Validation enum failed', $exception->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Valid enum value reached transport.');
        $client->resource(\Example\EnumParameterSdk\Api\Users\User::class)->getById(1, 'brief');
    }

    public function testInlineSuccessAndErrorResponsesGenerateAndHydrateNamedTypes(): void
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi30.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $raw['paths']['/users/{id}']['get']['responses']['200']['content']['application/json']['schema'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ];
        $raw['paths']['/users/{id}']['get']['responses']['404'] = [
            'description' => 'Not found',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['detail' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
        $raw['paths']['/users/{id}']['get']['responses']['204'] = ['description' => 'No content'];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter(
            'Example\\InlineResponseSdk',
            'example/inline-response-sdk',
        ))->emit($contract);

        self::assertArrayHasKey('src/Collections/UserCollection.php', $files);
        self::assertArrayHasKey('src/Responses/UserResponse.php', $files);
        self::assertArrayHasKey('src/Responses/Errors/Users/ById/GetStatus404Response.php', $files);
        self::assertStringContainsString(
            '): ?\\Example\\InlineResponseSdk\\Collections\\UserCollection',
            $files['src/Api/Users/User.php'],
        );
        self::assertStringContainsString('    204,', $files['src/Api/Users/User.php']);
        self::assertStringContainsString("'noContentAllowed' => true", $files['src/Api/Users/User.php']);
        self::assertStringContainsString('GetStatus404Response', $files['src/Api/Users/User.php']);

        foreach ([
            'src/Responses/UserResponse.php',
            'src/Collections/UserCollection.php',
            'src/Responses/Errors/Users/ById/GetStatus404Response.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
        $response = \Dmorenof\SdkRuntime\JsonHydrator::decode(
            '[{"id":7,"name":"Ada"}]',
            \Example\InlineResponseSdk\Collections\UserCollection::class,
        );
        self::assertInstanceOf(\Example\InlineResponseSdk\Collections\UserCollection::class, $response);
        self::assertInstanceOf(
            \Example\InlineResponseSdk\Responses\UserResponse::class,
            $response->getArrayCopy()[0],
        );
        self::assertSame(7, $response->getArrayCopy()[0]->id);
        $error = new \Example\InlineResponseSdk\Responses\Errors\Users\ById\GetStatus404Response(
            (object) ['detail' => 'Not found'],
        );
        self::assertInstanceOf(\Dmorenof\SdkRuntime\Response::class, $error);
        self::assertSame('Not found', $error->detail);
    }

    public function testOutputIsIdenticalForThreeEquivalentDialects(): void
    {
        self::assertSame($this->files(), $this->files('accounts-openapi30.json'));
        self::assertSame($this->files(), $this->files('accounts-openapi31.json'));
        self::assertArrayHasKey('src/Client.php', $this->files());
        self::assertArrayNotHasKey('src/Core/DTO.php', $this->files());
        self::assertArrayNotHasKey('src/Transport/Psr18Transport.php', $this->files());
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

        $fake = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];

            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                $payload = json_encode([
                    'id' => 42,
                    'name' => 'Ada',
                    'role' => 'admin',
                    'profile' => ['city' => 'Madrid', 'visits' => 3],
                    'labels' => ['php', 'sdk'],
                ], JSON_THROW_ON_ERROR);
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                    $method === 'GET' ? 200 : 201,
                    $payload,
                );
            }
        };
        $client = \Example\ExecutableSdk\Client::connect('https://api.example.test/v1/', transport: $fake);
        $user = $client->resource(\Example\ExecutableSdk\Api\Users\User::class)->getById(42, false);
        self::assertInstanceOf(\Example\ExecutableSdk\Responses\User::class, $user);
        self::assertSame(42, $user->id);
        self::assertSame('Ada', $user->name);
        self::assertSame('Madrid', $user->profile->city);
        self::assertSame(3, $user->profile->visits);
        self::assertSame(\Example\ExecutableSdk\Enums\User\RoleEnum::Admin, $user->role);
        self::assertInstanceOf(\Example\ExecutableSdk\Collections\StringCollection::class, $user->labels);
        self::assertSame(['php', 'sdk'], $user->labels->getArrayCopy());
        self::assertFalse(isset($user->nickname));
        self::assertSame('GET', $fake->calls[0]['method']);
        self::assertSame('https://api.example.test/v1/users/42?verbose=false', $fake->calls[0]['url']);
        self::assertNull($fake->calls[0]['body']);

        $input = new \Example\ExecutableSdk\Requests\NewUser();
        $input->name = 'Grace';
        $input->role = \Example\ExecutableSdk\Enums\NewUser\RoleEnum::Viewer;
        $result = $client->resource(\Example\ExecutableSdk\Api\Users::class)->post($input);
        self::assertInstanceOf(\Example\ExecutableSdk\Responses\User::class, $result);
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
        if (!class_exists(\Example\ExecutableSdk\Client::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(404, '{"error":"not found"}');
            }
        };
        $client = \Example\ExecutableSdk\Client::connect('https://api.example.test/v1', transport: $fake);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected HTTP status from GET /users/{id}: 404');
        $client->resource(\Example\ExecutableSdk\Api\Users\User::class)->getById(1);
    }

    public function testUndeclaredHTTPErrorRetainsStatusAndResponseWithoutAssumingErrorDTO(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Client::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(429, '{"reason":"throttled"}');
            }
        };
        $client = \Example\ExecutableSdk\Client::connect('https://api.example.test/v1', transport: $fake);
        try {
            $client->resource(\Example\ExecutableSdk\Api\Users\User::class)->getById(1);
            self::fail('Unexpected status did not throw');
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            self::assertSame(429, $e->getCode());
            self::assertNull($e->error);
            self::assertSame('{"reason":"throttled"}', $e->response->body);
        }
    }

    public function testHydratorRejectsMissingRequiredJsonFields(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Client::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(200, '{"name":"Ada"}');
            }
        };
        $client = \Example\ExecutableSdk\Client::connect('https://api.example.test/v1', transport: $fake);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Required JSON property');
        $client->resource(\Example\ExecutableSdk\Api\Users\User::class)->getById(1);
    }

    public function testHydratorRejectsInvalidScalarTypes(): void
    {
        if (!class_exists(\Example\ExecutableSdk\Client::class, false)) {
            $this->loadGeneratedClasses();
        }
        $fake = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(200, '{"id":"oops","name":"Ada"}');
            }
        };
        $client = \Example\ExecutableSdk\Client::connect('https://api.example.test/v1', transport: $fake);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid scalar value');
        $client->resource(\Example\ExecutableSdk\Api\Users\User::class)->getById(1);
    }
}
