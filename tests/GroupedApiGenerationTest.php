<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Generation\PhpRouteNameResolver;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class GroupedApiGenerationTest extends TestCase
{
    public function testCasafariOperationsBecomeExactPathFacadesWithoutHashSuffixes(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(
            (string) file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json'),
        );
        $routes = PhpRouteNameResolver::resolve($contract);
        self::assertCount(46, $routes['operationNodes']);
        $files = (new PhpExecutableEmitter('Casafari\\RouteSdk', 'preview/casafari-route'))->emit($contract);

        self::assertArrayHasKey('src/CasafariAPISDK.php', $files);
        self::assertArrayHasKey('src/Api/V1/References/Types.php', $files);
        self::assertArrayHasKey('src/Api/V1/Properties/Search.php', $files);
        self::assertArrayHasKey('src/Api/V1/ListingAlerts/Feeds.php', $files);
        self::assertArrayHasKey('src/Api/V1/ListingAlerts/Feeds/ById/Update.php', $files);
        self::assertArrayHasKey('src/V1/SellerLeads.php', $files);
        self::assertArrayHasKey('src/MarketAnalyticsApi/Analysis.php', $files);
        self::assertArrayHasKey('src/GetParams/Api/V1/ListingAlerts/Feeds.php', $files);
        self::assertArrayHasKey('src/GetParams/Api/V1/ListingAlerts/Feeds/ById.php', $files);
        self::assertArrayHasKey('src/PostParams/Api/V1/Properties/Search.php', $files);

        self::assertArrayNotHasKey('src/ApiV1.php', $files);
        self::assertArrayNotHasKey('src/StaticApi/V1/ListingAlerts/Feeds.php', $files);
        self::assertArrayNotHasKey('src/StaticClient.php', $files);
        self::assertStringContainsString('public static function get()', $files['src/Api/V1/References/Types.php']);
        self::assertStringContainsString('public static function deleteById(string $id)', $files['src/Api/V1/ListingAlerts/Feeds.php']);
        self::assertStringContainsString('extends \\Casafari\\RouteSdk\\CasafariAPISDK', $files['src/Api/V1/ListingAlerts/Feeds.php']);
        self::assertStringContainsString('operationId: api__get__v1__references__types', $files['src/Api/V1/References/Types.php']);

        $publicMethods = 0;
        foreach ($files as $path => $source) {
            if (str_starts_with($path, 'src/Resources/')
                || !str_starts_with($path, 'src/')
                || str_starts_with($path, 'src/GetParams/')
                || str_starts_with($path, 'src/PostParams/')) {
                continue;
            }
            if (str_contains($source, 'extends \\Casafari\\RouteSdk\\CasafariAPISDK')) {
                $publicMethods += preg_match_all('/public static function (?:get|post|put|patch|delete)\w*\(/', $source);
            }
        }
        self::assertSame(46, $publicMethods);
    }

    public function testUnknownPlaceholderIsNeverRenamedToId(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $get = $spec['paths']['/users/{id}']['get'];
        unset($spec['paths']['/users/{id}']);
        $get['parameters'][0]['name'] = 'num';
        $spec['paths']['/user/{num}'] = ['get' => $get];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\NumSdk', 'example/num'))->emit($contract);
        self::assertStringContainsString('public static function getByNum(int $num', $files['src/User.php']);
        self::assertStringNotContainsString('getById', $files['src/User.php']);
    }

    public function testSameRouteIsDeterministicRegardlessOfOperationIdAndHasOneResourceClass(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $spec['paths']['/users']['post']['operationId'] = 'strange-post-operation';
        $spec['paths']['/users/{id}']['get']['operationId'] = 'different-id';
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\DeterministicSdk', 'example/deterministic'))->emit($contract);

        self::assertArrayHasKey('src/Users.php', $files);
        self::assertArrayHasKey('src/Resources/Users.php', $files);
        self::assertArrayHasKey('src/GetParams/Users/ById.php', $files);
        self::assertArrayNotHasKey('src/UsersApi.php', $files);
        self::assertStringContainsString('public static function post(', $files['src/Users.php']);
        self::assertStringContainsString('public static function getById(', $files['src/Users.php']);
        self::assertStringContainsString('public function post(', $files['src/Resources/Users.php']);
        self::assertStringContainsString('public function getById(', $files['src/Resources/Users.php']);
    }

    public function testRouteFacadeSharesConfiguredConnectionAndTypedResponses(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(
            (string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
        );
        $files = (new PhpExecutableEmitter('Example\\GroupSdk', 'example/group'))->emit($contract);
        $autoload = static function (string $class) use ($files): void {
            if (str_starts_with($class, 'Example\\GroupSdk\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen('Example\\GroupSdk\\'))) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        };
        spl_autoload_register($autoload);
        try {
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
            $config = new \Example\GroupSdk\Configuration();
            $config->baseUrl = 'https://group.example.test';
            $config->transport = $transport;
            \Example\GroupSdk\Client::configure($config);
            $params = new \Example\GroupSdk\GetParams\Users\ById((object) ['verbose' => false]);
            $user = \Example\GroupSdk\Users::getById(88, $params);
            self::assertSame('Grace', $user->name);
            self::assertSame('https://group.example.test/users/88?verbose=false', $transport->calls[0]['url']);
            $body = new \Example\GroupSdk\DTOs\NewUser((object) ['name' => 'Grace']);
            self::assertSame('Grace', \Example\GroupSdk\Users::post($body)->name);
            self::assertSame(['name' => 'Grace'], json_decode($transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR));
        } finally {
            spl_autoload_unregister($autoload);
        }
    }
}
