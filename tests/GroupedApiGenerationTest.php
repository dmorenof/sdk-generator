<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpApiGroupingResolver;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class GroupedApiGenerationTest extends TestCase
{
    public function testCasafariGroupsAllOperationsByStructuralPaths(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(
            (string) file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json'),
        );
        $expected = [
            'AlertsApi' => 6,
            'ApiV1' => 22,
            'ApiV2' => 3,
            'LoginApi' => 1,
            'MarketAnalyticsApi' => 6,
            'PropertySourcingApiV1' => 5,
            'RefreshTokenApi' => 1,
            'SellerLeadsApiV1' => 2,
        ];
        $groups = PhpApiGroupingResolver::resolve($contract);
        self::assertSame($expected, array_map('count', $groups));
        self::assertSame(46, array_sum(array_map('count', $groups)));
        $files = (new PhpExecutableEmitter('Casafari\\GroupedSdk', 'preview/casafari-grouped'))->emit($contract);
        foreach (array_keys($expected) as $class) {
            self::assertArrayHasKey("src/$class.php", $files);
        }
        self::assertStringContainsString('public static function getReferencesTypes()', $files['src/ApiV1.php']);
        self::assertStringContainsString('public static function postPropertiesSearch(', $files['src/ApiV1.php']);
        self::assertStringContainsString('public static function deleteListingAlertsFeedsById(string $id)', $files['src/ApiV1.php']);
        self::assertStringContainsString('public static function getByLeadId(', $files['src/SellerLeadsApiV1.php']);
        self::assertStringContainsString('operationId: api__get__v1__references__types', $files['src/ApiV1.php']);
        self::assertArrayNotHasKey('src/Api.php', $files); // No flat facade.
        self::assertArrayHasKey('src/CasafariAPISDK.php', $files);
        self::assertStringContainsString('extends CasafariAPISDK', $files['src/ApiV1.php']);
    }

    public function testUserNumPlaceholderIsKeptWithoutGuessingItsMeaning(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $get = $spec['paths']['/users/{id}']['get'];
        unset($spec['paths']['/users/{id}']);
        $get['parameters'][0]['name'] = 'num';
        $spec['paths']['/user/{num}'] = ['get' => $get];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\NumSdk', 'example/num'))->emit($contract);
        self::assertStringContainsString('public static function getByNum(int $num', $files['src/UserApi.php']);
        self::assertStringNotContainsString('ById', $files['src/UserApi.php']);
    }

    public function testLongestPrefixOverridesSplitGroupsAndPreservePaths(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(
            (string) file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json'),
        );
        $overrides = ['/api/v1/references' => 'ReferencesApi'];
        $groups = PhpApiGroupingResolver::resolve($contract, $overrides);
        self::assertCount(10, $groups['ReferencesApi']);
        self::assertCount(12, $groups['ApiV1']);
        self::assertSame(46, array_sum(array_map('count', $groups)));
        $files = (new PhpExecutableEmitter('Example\\SplitSdk', 'example/split', $overrides))->emit($contract);
        self::assertStringContainsString('public static function getTypes()', $files['src/ReferencesApi.php']);
        self::assertStringContainsString('public static function getListingAlertsFeeds(', $files['src/ApiV1.php']);
        self::assertStringNotContainsString('getReferencesTypes', $files['src/ApiV1.php']);
        self::assertStringContainsString('/api/v1/references/types', $files['src/ReferencesApi.php']);
    }

    public function testGroupMethodsUseCurrentClientAndKeepTypedResponses(): void
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
            $user = \Example\GroupSdk\UsersApi::getById(88, false);
            self::assertSame('Grace', $user->name);
            self::assertSame('https://group.example.test/users/88?verbose=false', $transport->calls[0]['url']);
            $body = new \Example\GroupSdk\Requests\NewUser((object) ['name' => 'Grace']);
            self::assertSame('Grace', \Example\GroupSdk\UsersApi::post($body)->name);
            self::assertSame(['name' => 'Grace'], json_decode($transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR));
        } finally {
            spl_autoload_unregister($autoload);
        }
    }
}
