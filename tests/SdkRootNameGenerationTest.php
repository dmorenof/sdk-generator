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
        self::assertArrayHasKey('src/Api.php', $files);
        self::assertArrayHasKey('src/RevenueApi.php', $files);
        self::assertStringContainsString('abstract class FacturasGuaysSDK', $files['src/FacturasGuaysSDK.php']);
        self::assertStringContainsString('class Api extends FacturasGuaysSDK', $files['src/Api.php']);
        self::assertStringContainsString('class RevenueApi extends FacturasGuaysSDK', $files['src/RevenueApi.php']);
        self::assertStringContainsString('public static function postFacturas(', $files['src/Api.php']);
        self::assertStringContainsString('public static function get()', $files['src/RevenueApi.php']);
        self::assertStringContainsString('self::client()->resource(', $files['src/RevenueApi.php']);
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
