<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Functional acceptance of combined path/query/body Request semantics. */
final class MixedOperationRequestTest extends TestCase
{
    /** @return array<string, string> */
    private static function files(): array
    {
        $source = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        self::assertIsString($source);
        $spec = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $post = $spec['paths']['/users']['post'];
        $post['parameters'] = [
            ['name' => 'group', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'trace', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
        unset($spec['paths']['/users']);
        $spec['paths']['/groups/{group}/users'] = ['post' => $post];

        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\MixedSdk', 'example/mixed-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files();
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
            'src/Requests/GetUserRequest.php',
            'src/Requests/CreateUserRequest.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/ApiClient.php', 'src/Core/ApiException.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Api/Operations.php',
            'src/Api/ApiClient.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
    }

    public function testPathQueryAndBodyUseOneTypedRequestWithoutDoubleJsonEncoding(): void
    {
        $transport = new class implements \Example\MixedSdk\Transport\TransportInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function send(string $method, string $url, array $headers, ?string $body): \Example\MixedSdk\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                return new \Example\MixedSdk\Transport\HttpResponse(
                    201,
                    '{"id":88,"name":"Ada"}',
                );
            }
        };

        $api = new \Example\MixedSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
        $request = new \Example\MixedSdk\Requests\CreateUserRequest((object)[
            'group' => 'my group',
            'trace' => false,
            'body' => (object) ['name' => 'Ada', 'role' => 'viewer'],
        ]);
        self::assertInstanceOf(\Example\MixedSdk\Core\Request::class, $request);
        self::assertInstanceOf(\Example\MixedSdk\Models\NewUser::class, $request->body);
        self::assertInstanceOf(\Example\MixedSdk\Core\Request::class, $request->body);

        $result = $api->createUser($request);
        self::assertInstanceOf(\Example\MixedSdk\Core\Response::class, $result);
        self::assertSame('Ada', $result->name);
        self::assertCount(1, $transport->calls);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertSame('https://api.example.test/v1/groups/my%20group/users?trace=false', $transport->calls[0]['url']);
        self::assertSame(
            ['name' => 'Ada', 'role' => 'viewer'],
            json_decode($transport->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('application/json', $transport->calls[0]['headers']['Content-Type']);
    }

    public function testMissingRequiredOperationPathFieldFailsBeforeTransport(): void
    {
        $transport = new class implements \Example\MixedSdk\Transport\TransportInterface {
            public int $sent = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Example\MixedSdk\Transport\HttpResponse
            {
                $this->sent++;
                throw new \LogicException('Request should have been blocked');
            }
        };
        $api = new \Example\MixedSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
        $request = new \Example\MixedSdk\Requests\CreateUserRequest();
        $request->body = new \Example\MixedSdk\Models\NewUser((object)['name' => 'Ada']);
        try {
            $api->createUser($request);
            self::fail('Missing group was sent');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('Required JSON property', $e->getMessage());
        }
        self::assertSame(0, $transport->sent);
    }
}
