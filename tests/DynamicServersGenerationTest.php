<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\ServerSupport;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Selectable and templated servers reach the runtime, so one SDK serves regions and environments. */
final class DynamicServersGenerationTest extends TestCase
{
    private static int $loaded = 0;

    private const TEMPLATED = [
        ['url' => 'https://{region}.api.example.test/{version}', 'variables' => [
            'region' => ['default' => 'eu', 'enum' => ['eu', 'us']],
            'version' => ['default' => 'v1'],
        ]],
        ['url' => 'https://sandbox.example.test/v1'],
    ];

    /** @return array<string, mixed> */
    private static function document(?array $root = null, ?array $operation = null): array
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($root !== null) {
            $document['servers'] = $root;
        }
        if ($operation !== null) {
            $document['paths']['/users/{id}']['get']['servers'] = $operation;
        }
        return $document;
    }

    /** @return array<string, string> */
    private static function emit(array $document): array
    {
        $number = ++self::$loaded;
        return (new PhpExecutableEmitter("Example\\ServersSdk$number", "example/servers-$number"))
            ->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    /** @return array{object, \ArrayObject<int, string>, string} resource, requested URLs, client class */
    private static function load(array $document): array
    {
        $files = self::emit($document);
        $namespace = 'Example\\ServersSdk' . self::$loaded;
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
        $urls = new \ArrayObject();
        $http = new class ($urls) implements ClientInterface {
            /** @param \ArrayObject<int, string> $urls */
            public function __construct(private readonly \ArrayObject $urls) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->urls[] = (string) $request->getUri();
                return new Response(200, [], '{"id":1,"name":"Ada"}');
            }
        };
        $client = $namespace . '\\Client';
        return [$client::connect('https://api.example.test/v1', $http)->resource($namespace . '\\Resources\\Users'), $urls, $client];
    }

    public function testRootServersBecomeAClientServerUrlHelper(): void
    {
        [, , $client] = self::load(self::document(self::TEMPLATED));

        self::assertSame('https://eu.api.example.test/v1', $client::serverUrl());
        self::assertSame('https://us.api.example.test/v2', $client::serverUrl(0, ['region' => 'us', 'version' => 'v2']));
        self::assertSame('https://sandbox.example.test/v1', $client::serverUrl(1));
        $this->expectException(\InvalidArgumentException::class);
        $client::serverUrl(0, ['region' => 'asia']);
    }

    public function testOperationServersAreSelectableAndTemplated(): void
    {
        [$users, $urls] = self::load(self::document(operation: self::TEMPLATED));

        $users->getById(1);
        $users->withServerVariables(['region' => 'us'])->getById(1);
        $users->useServer(1)->getById(1);

        self::assertSame([
            'https://eu.api.example.test/v1/users/1',
            'https://us.api.example.test/v1/users/1',
            'https://sandbox.example.test/v1/users/1',
        ], $urls->getArrayCopy());
    }

    public function testVariablesAreCheckedAgainstTheirEnum(): void
    {
        [$users] = self::load(self::document(operation: self::TEMPLATED));

        $this->expectException(\InvalidArgumentException::class);
        $users->withServerVariables(['region' => 'asia'])->getById(1);
    }

    public function testPlainDeclarationsKeepTheirCurrentShape(): void
    {
        $plainRoot = self::emit(self::document());
        self::assertStringNotContainsString('SERVERS', $plainRoot['src/Client.php']);
        self::assertStringNotContainsString("'servers'", $plainRoot['src/Resources/Users.php']);

        $fixed = self::emit(self::document(operation: [['url' => 'https://other.example.test/v9']]))['src/Resources/Users.php'];
        self::assertStringContainsString("'serverUrl' => 'https://other.example.test/v9'", $fixed);
        self::assertStringNotContainsString("'servers'", $fixed);
    }

    public function testOperationsInheritingRootServersFollowTheConfiguredBaseUrl(): void
    {
        $users = self::emit(self::document(self::TEMPLATED))['src/Resources/Users.php'];

        self::assertStringNotContainsString("'servers'", $users);
        self::assertStringNotContainsString("'serverUrl' =>", $users);
    }

    public function testSpecsKeepTemplatesDefaultsAndEnums(): void
    {
        self::assertSame([
            ['url' => 'https://{region}.api.example.test/{version}', 'variables' => [
                'region' => ['default' => 'eu', 'enum' => ['eu', 'us']],
                'version' => ['default' => 'v1'],
            ]],
            ['url' => 'https://sandbox.example.test/v1'],
        ], ServerSupport::specs(self::TEMPLATED));
        self::assertTrue(ServerSupport::isDynamic(self::TEMPLATED));
        self::assertTrue(ServerSupport::isDynamic([['url' => 'https://a/{x}', 'variables' => ['x' => ['default' => 'y']]]]));
        self::assertFalse(ServerSupport::isDynamic([['url' => 'https://a.example.test']]));
        self::assertFalse(ServerSupport::isDynamic([]));
    }
}
