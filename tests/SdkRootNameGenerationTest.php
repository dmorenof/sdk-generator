<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpApiNameResolver;
use Dmorenof\SdkGenerator\Generation\PhpNameTransliterator;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class SdkRootNameGenerationTest extends TestCase
{
    public function testNamesKeepAllWordsAndAcronyms(): void
    {
        self::assertSame('FacturasGuaysSDK', PhpApiNameResolver::sdkClassFromTitle('Facturas Guays'));
        self::assertSame('CasafariAPISDK', PhpApiNameResolver::sdkClassFromTitle('Casafari API'));
        self::assertSame('StripeAPISDK', PhpApiNameResolver::sdkClassFromTitle('Stripe API'));
        self::assertSame('ExampleServiceSDK', PhpApiNameResolver::sdkClassFromTitle('Example Service'));
        self::assertSame('ExampleSDK', PhpApiNameResolver::sdkClassFromTitle('Example SDK'));
        self::assertSame('CafeNinoSDK', PhpApiNameResolver::sdkClassFromTitle('Café Niño'));
        self::assertSame('ZhongWen', PhpNameTransliterator::pascal('中文'));
    }

    public function testUnsupportedUnicodeDoesNotDisappear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhpApiNameResolver::sdkClassFromTitle('🐱');
    }

    public function testLiteralApiAndRevenueRoutesProduceDistinctChildClasses(): void
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $raw['info']['title'] = 'Facturas Guays';
        $post = $raw['paths']['/users']['post'];
        $get = $raw['paths']['/users/{id}']['get'];
        unset($get['parameters']);
        $raw['paths'] = [
            '/api/facturas' => ['post' => $post],
            '/revenue/' => ['get' => $get],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\Facturas', 'example/facturas'))->emit($contract);

        self::assertArrayHasKey('src/FacturasGuaysSDK.php', $files);
        self::assertArrayHasKey('src/Api/Facturas.php', $files);
        self::assertArrayHasKey('src/Revenue.php', $files);
        self::assertStringContainsString('abstract class FacturasGuaysSDK', $files['src/FacturasGuaysSDK.php']);
        self::assertStringContainsString('class Facturas extends \\Example\\Facturas\\FacturasGuaysSDK', $files['src/Api/Facturas.php']);
        self::assertStringContainsString('class Revenue extends \\Example\\Facturas\\FacturasGuaysSDK', $files['src/Revenue.php']);
        self::assertStringContainsString('public static function post(', $files['src/Api/Facturas.php']);
        self::assertStringContainsString('public static function get()', $files['src/Revenue.php']);
        self::assertStringContainsString('self::client()->resource(', $files['src/Revenue.php']);
    }

    public function testGeneratedApiClassAndNestedApiNamespaceCanCoexistAtRuntime(): void
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $raw['info']['title'] = 'Facturas Guays';
        $post = $raw['paths']['/users']['post'];
        $get = $raw['paths']['/users/{id}']['get'];
        unset($get['parameters']);
        $raw['paths'] = [
            '/api/facturas' => ['post' => $post],
            '/revenue/' => ['get' => $get],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\FacturasRuntime', 'example/facturas-runtime'))->emit($contract);
        $loader = static function (string $class) use ($files): void {
            $prefix = 'Example\\FacturasRuntime\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (isset($files[$path])) {
                eval(substr($files[$path], strlen('<?php')));
            }
        };
        spl_autoload_register($loader);
        try {
            $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
                public array $calls = [];
                public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
                {
                    $this->calls[] = [$method, $url, $body];
                    return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                        $method === 'POST' ? 201 : 200,
                        '{"id":88,"name":"Grace","role":"viewer","profile":{"city":"Madrid","visits":1},"labels":[]}',
                    );
                }
            };
            $config = new \Example\FacturasRuntime\Configuration();
            $config->baseUrl = 'https://facturas.test';
            $config->transport = $transport;
            \Example\FacturasRuntime\FacturasGuaysSDK::configure($config);
            $input = new \Example\FacturasRuntime\DTOs\NewUser((object) ['name' => 'Grace']);
            self::assertSame('Grace', \Example\FacturasRuntime\Api\Facturas::post($input)->name);
            self::assertSame('Grace', \Example\FacturasRuntime\Revenue::get()->name);
            self::assertSame([
                ['POST', 'https://facturas.test/api/facturas', '{"name":"Grace"}'],
                ['GET', 'https://facturas.test/revenue/', null],
            ], $transport->calls);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testTransliterationOfQueryParametersKeepsWireNames(): void
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $raw['paths']['/users/{id}']['get']['parameters'][] = [
            'name' => 'código',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\UnicodeQuery', 'example/unicode-query'))->emit($contract);
        self::assertStringContainsString('public ?string $codigo;', $files['src/GetParams/Users/ById.php']);
        // Executable SDKs inline parameter handling into the resource method;
        // they do not emit the contract-only Api/Requests operation wrapper.
        self::assertStringContainsString("'código' => 'codigo'", $files['src/GetParams/Users/ById.php']);
    }

    public function testTransliterationOfSchemaPropertiesAndRoutesKeepsWireNames(): void
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $raw['info']['title'] = 'Facturación 中文';
        $raw['components']['schemas']['Café'] = [
            'type' => 'object',
            'properties' => ['año' => ['type' => 'integer'], '中文' => ['type' => 'string']],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\Unicode', 'example/unicode'))->emit($contract);
        self::assertArrayHasKey('src/FacturacionZhongWenSDK.php', $files);
        self::assertArrayHasKey('src/DTOs/Cafe.php', $files);
        self::assertStringContainsString('public int $ano;', $files['src/DTOs/Cafe.php']);
        self::assertStringContainsString('public string $zhongWen;', $files['src/DTOs/Cafe.php']);
        self::assertStringContainsString("'año' => 'ano'", $files['src/DTOs/Cafe.php']);
        self::assertStringContainsString("'中文' => 'zhongWen'", $files['src/DTOs/Cafe.php']);
    }
}
