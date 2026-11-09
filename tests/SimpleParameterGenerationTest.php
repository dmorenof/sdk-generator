<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

final class SimpleParameterGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/simple-parameters-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\SimpleSdk', 'example/simple'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SimpleAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
    }
    public function testDefaultsExplicitSimpleVersionsAndNativeArrayPathDto(): void {
        $document = self::document(); $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SimpleSdk', 'example/simple'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3';
        foreach (['get', 'post'] as $verb) {
            foreach ([0, 1] as $index) { $document['paths']['/items/{ids}'][$verb]['parameters'][$index]['style'] = 'simple'; }
        }
        foreach ([true, false] as $explode) {
            foreach (['get', 'post'] as $verb) {
                foreach ([0, 1] as $index) { $document['paths']['/items/{ids}'][$verb]['parameters'][$index]['explode'] = $explode; }
            }
            self::assertSame($expected, self::files($document));
        }
        self::assertStringContainsString('public array $ids;', $expected['src/PathParams/Items/ByIds.php']);
        self::assertStringContainsString('@var list<string>', $expected['src/PathParams/Items/ByIds.php']);
        self::assertStringContainsString('\\PathParams\\Items\\ByIds $identifier', $expected['src/Resources/Items.php']);
        self::assertStringContainsString("'headerSerialization' =>", $expected['src/Resources/Items.php']);
    }
    public function testSwaggerCsvAndOpenApiAliasesUseTheSameMetadata(): void {
        $expected = self::files(self::document());
        $swagger = json_decode(file_get_contents(__DIR__ . '/fixtures/simple-parameters-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, self::files($swagger));
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SimpleSdk', 'example/simple'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($swagger, 40), strict: true)));
        foreach (['get', 'post'] as $verb) {
            foreach ([0, 1] as $index) { $swagger['paths']['/items/{ids}'][$verb]['parameters'][$index]['collectionFormat'] = 'csv'; }
        }
        self::assertSame($expected, self::files($swagger));
        $document = self::document();
        $document['components']['parameters'] = ['Ids' => $document['paths']['/items/{ids}']['get']['parameters'][0], 'Alias' => ['$ref' => '#/components/parameters/Ids']];
        foreach (['get', 'post'] as $verb) { $document['paths']['/items/{ids}'][$verb]['parameters'][0] = ['$ref' => '#/components/parameters/Alias']; }
        self::assertSame($expected, self::files($document));
        foreach (['multi', 'invalid'] as $format) {
            $unsupported = $swagger; $unsupported['paths']['/items/{ids}']['get']['parameters'][1]['collectionFormat'] = $format;
            try { self::files($unsupported); self::fail('Unsupported header format accepted.'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testNumericParameterNamesProduceValidMetadataAndPathFields(): void {
        $document = self::document(); $item = $document['paths']['/items/{ids}'];
        foreach (['get', 'post'] as $verb) {
            $item[$verb]['parameters'][0]['name'] = '0'; $item[$verb]['parameters'][1]['name'] = '0';
        }
        $document['paths'] = ['/items/{0}' => $item];
        $files = self::files($document);
        self::assertStringContainsString('ByResource0 $identifier', $files['src/Resources/Items.php']);
        self::assertStringContainsString("'pathSerialization' =>", $files['src/Resources/Items.php']);
        $numericFiles = (new PhpExecutableEmitter('Example\\NumericSimpleSdk', 'example/numeric-simple'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SimpleAPISDK.php'] as $path) {
            eval(substr($numericFiles[$path], strlen('<?php'))); unset($numericFiles[$path]);
        }
        foreach ($numericFiles as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse {
                $this->calls[] = compact('url', 'headers'); return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
            }
        };
        $resource = \Example\NumericSimpleSdk\Client::connect('https://example.test', transport: $transport)->resource(\Example\NumericSimpleSdk\Resources\Items::class);
        $resource->getByResource0(new \Example\NumericSimpleSdk\PathParams\Items\ByResource0((object) ['0' => ['a,b', 'c/d']]),
            new \Example\NumericSimpleSdk\GetParams\Items\ByResource0((object) ['0' => ['alpha', 'beta']]));
        self::assertSame('https://example.test/items/a%2Cb,c%2Fd', $transport->calls[0]['url']);
        self::assertSame('alpha,beta', $transport->calls[0]['headers'][0]);
    }
    public function testCompositePathRetainsTheTwoArgumentDtoContract(): void {
        $document = self::document();
        $item = $document['paths']['/items/{ids}'];
        foreach (['get', 'post'] as $verb) {
            $item[$verb]['parameters'][] = ['name' => 'tenant', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        $document['paths'] = ['/tenants/{tenant}/items/{ids}' => $item];
        $files = self::files($document);
        $paths = array_filter(array_keys($files), static fn ($path): bool => str_starts_with($path, 'src/PathParams/'));
        self::assertCount(1, $paths);
        $source = $files[array_values($paths)[0]];
        self::assertStringContainsString('public array $ids;', $source);
        self::assertStringContainsString('public string $tenant;', $source);
    }
    public function testGetPostValuesLocationsAndMutationsAreExecutedByTheInheritedRuntime(): void {
        $http = new class implements ClientInterface {
            public array $calls = [];
            public function sendRequest(RequestInterface $request): ResponseInterface { $this->calls[] = $request; return new Response(204); }
        };
        $config = new \Example\SimpleSdk\Configuration(); $config->httpClient = $http;
        \Example\SimpleSdk\Client::configure($config);
        $path = new \Example\SimpleSdk\PathParams\Items\ByIds((object) ['ids' => ['a,b', 'c/d', '%2F', 'ñ']]);
        \Example\SimpleSdk\Items::getByIds($path, new \Example\SimpleSdk\GetParams\Items\ByIds((object) ['body' => 'query body', 'ids' => [2, 3], 'X-Codes' => ['alpha', 'x y']]));
        $params = new \Example\SimpleSdk\PostParams\Items\ByIds((object) ['queryBody' => 'query body', 'ids' => [2, 3], 'X-Codes' => ['alpha', 'x y'], 'body' => (object) ['name' => 'body name']]);
        $resource = \Example\SimpleSdk\Client::connect($config->baseUrl, httpClient: $http)->resource(\Example\SimpleSdk\Resources\Items::class);
        $resource->postByIds($path, $params);
        foreach ($http->calls as $call) {
            self::assertSame('https://api.example.test/v1/items/a%2Cb,c%2Fd,%252F,%C3%B1?body=query%20body&ids=2&ids=3', (string) $call->getUri());
            self::assertSame('alpha,x y', $call->getHeaderLine('X-Codes'));
        }
        self::assertSame('{"name":"body name"}', (string) $http->calls[1]->getBody());
        self::assertSame('', (string) $http->calls[0]->getBody());
        foreach ([['ids', []], ['ids', ['']], ['ids', ['x' => 'a']]] as [$field, $value]) {
            $path->$field = $value;
            try { $resource->postByIds($path, $params); self::fail('Path mutation accepted.'); }
            catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
        }
        $path->ids = ['valid'];
        foreach ([['a,b'], [1], [], ['']] as $codes) {
            $params->XCodes = $codes;
            try { $resource->postByIds($path, $params); self::fail('Invalid header accepted.'); }
            catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
        }
        $params->XCodes = ['valid']; $params->body->name = '';
        try { $resource->postByIds($path, $params); self::fail('Body mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
    }
    public function testMissingAndInvalidPathDeclarationsAndUnsupportedSchemasAreDiagnosed(): void {
        $variants = [];
        foreach (['deepObject', 'spaceDelimited', 'form'] as $style) {
            $d = self::document(); $d['paths']['/items/{ids}']['get']['parameters'][0]['style'] = $style; $variants[] = $d;
        }
        foreach ([['type' => 'object'], ['nullable' => true], ['enum' => []], ['items' => ['type' => 'object']],
            ['items' => ['type' => 'string', 'nullable' => true]], ['items' => ['type' => 'string', 'enum' => []]]] as $schema) {
            $d = self::document(); $d['paths']['/items/{ids}']['get']['parameters'][0]['schema'] = array_replace($d['paths']['/items/{ids}']['get']['parameters'][0]['schema'], $schema); $variants[] = $d;
        }
        $d = self::document(); $d['paths']['/items/{ids}']['get']['parameters'][0]['required'] = false; $variants[] = $d;
        $d = self::document(); unset($d['paths']['/items/{ids}']['get']['parameters'][0]); $variants[] = $d;
        $d = self::document(); $d['paths']['/items/{ids}']['get']['parameters'][0]['name'] = 'other'; $variants[] = $d;
        $d = self::document(); $d['paths']['/items/{ids}']['get']['parameters'][1]['style'] = 'form'; $variants[] = $d;
        foreach ($variants as $d) {
            try { self::files($d); self::fail('Unsupported contract accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
}
