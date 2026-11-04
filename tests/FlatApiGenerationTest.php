<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class FlatApiGenerationTest extends TestCase
{
    /** @return array<string,string> */
    private static function generated(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        $contract = (new SpecificationNormalizer())->fromJson($json);
        return (new PhpExecutableEmitter('Example\\FlatSdk', 'example/flat-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generated();
        foreach ([
            'src/Enums/NewUser/RoleEnum.php', 'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php', 'src/DTOs/Profile.php',
            'src/DTOs/NewUser.php', 'src/DTOs/User.php',
            'src/Client.php', 'src/Configuration.php', 'src/Api/Users.php',
            'src/Api/Users/User.php', 'src/ExampleAccountsSDK.php', 'src/UsersApi.php',
        ] as $file) {
            self::assertArrayHasKey($file, $files);
            eval(substr($files[$file], strlen('<?php')));
        }
    }

    public function testOneApiPreservesOperationIdsTypesAndConnectionReplacement(): void
    {
        $get = new \ReflectionMethod(\Example\FlatSdk\UsersApi::class, 'getById');
        self::assertTrue(is_subclass_of(\Example\FlatSdk\UsersApi::class, \Example\FlatSdk\ExampleAccountsSDK::class));
        self::assertTrue($get->isStatic());
        self::assertSame('id', $get->getParameters()[0]->getName());
        self::assertSame('int', (string) $get->getParameters()[0]->getType());
        self::assertSame('Example\\FlatSdk\\DTOs\\User', (string) $get->getReturnType());

        try {
            \Example\FlatSdk\UsersApi::getById(88);
            self::fail('Calling before configure must fail');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Client::configure', $e->getMessage());
        }

        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                    $method === 'POST' ? 201 : 200,
                    '{"id":88,"name":"Grace","role":"viewer","profile":{"city":"Madrid","visits":1},"labels":[]}',
                );
            }
        };

        $config = new \Example\FlatSdk\Configuration();
        $config->baseUrl = 'https://first.test/v1';
        $config->headers = ['X-Account' => 'one'];
        $config->transport = $transport;
        \Example\FlatSdk\Client::configure($config);
        $current = \Example\FlatSdk\Client::current();

        $user = \Example\FlatSdk\UsersApi::getById(88, false);
        self::assertSame('Grace', $user->name);
        self::assertSame('https://first.test/v1/users/88?verbose=false', $transport->calls[0]['url']);

        $body = new \Example\FlatSdk\DTOs\NewUser();
        $body->name = 'Grace';
        self::assertSame('Grace', \Example\FlatSdk\UsersApi::post($body)->name);
        self::assertSame(['name' => 'Grace'], json_decode($transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR));

        $config->baseUrl = 'https://mutated.test';
        $config->headers = ['X-Account' => 'mutated'];
        \Example\FlatSdk\UsersApi::getById(88);
        self::assertSame('https://first.test/v1/users/88', $transport->calls[2]['url']);

        $nextTransport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->calls[] = $url;
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                    200, '{"id":88,"name":"Second","role":"viewer","profile":{"city":"Madrid","visits":1},"labels":[]}'
                );
            }
        };
        $next = new \Example\FlatSdk\Configuration();
        $next->baseUrl = 'https://second.test';
        $next->transport = $nextTransport;
        \Example\FlatSdk\Client::configure($next);
        self::assertNotSame($current, \Example\FlatSdk\Client::current());
        self::assertSame('Second', \Example\FlatSdk\UsersApi::getById(88)->name);
        self::assertSame(['https://second.test/users/88'], $nextTransport->calls);
        self::assertCount(3, $transport->calls);
    }

    public function testMissingOperationIdUsesLiteralParameterNameWithoutInventingId(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $get = $spec['paths']['/users/{id}']['get'];
        unset($get['operationId']);
        $get['parameters'][0]['name'] = 'num';
        unset($spec['paths']['/users/{id}']);
        $spec['paths']['/user/{num}'] = ['get' => $get];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\NoIdSdk', 'example/no-id-sdk'))->emit($contract);

        self::assertStringContainsString('public static function getByNum(int $num', $files['src/UserApi.php']);
        self::assertStringNotContainsString('getById', $files['src/UserApi.php']);
        self::assertSame('get.user.by.num', $contract->operations[0]->operationId);
    }
}
