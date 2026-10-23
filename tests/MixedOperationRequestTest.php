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
            'src/Enums/NewUser/RoleEnum.php',
            'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php',
            'src/DTOs/Profile.php',
            'src/Requests/NewUser.php',
            'src/Responses/User.php',
            'src/Client.php',
            'src/Api/Groups/ByGroup/Users.php',
        ] as $file) {
            eval(substr($files[$file], strlen('<?php')));
        }
    }

    public function testPathQueryAndBodyUseOneTypedRequestWithoutDoubleJsonEncoding(): void
    {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                    201,
                    '{"id":88,"name":"Ada"}',
                );
            }
        };

        $client = \Example\MixedSdk\Client::connect('https://api.example.test/v1', transport: $transport);
        $body = new \Example\MixedSdk\Requests\NewUser((object) ['name' => 'Ada', 'role' => 'viewer']);

        $result = $client->resource(\Example\MixedSdk\Api\Groups\ByGroup\Users::class)->postByGroup('my group', $body, false);
        self::assertInstanceOf(\Dmorenof\SdkRuntime\Response::class, $result);
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
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public int $sent = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->sent++;
                throw new \LogicException('Request should have been blocked');
            }
        };
        $client = \Example\MixedSdk\Client::connect('https://api.example.test/v1', transport: $transport);
        try {
            $client->resource(\Example\MixedSdk\Api\Groups\ByGroup\Users::class)->postByGroup(
                body: new \Example\MixedSdk\Requests\NewUser((object) ['name' => 'Ada']),
            );
            self::fail('Missing group was sent');
        } catch (\ArgumentCountError) {
            self::assertSame(0, $transport->sent);
        }
        self::assertSame(0, $transport->sent);
    }
}
