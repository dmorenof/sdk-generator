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

final class ObjectParameterGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/object-parameters-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\ObjectSdk', 'example/object-parameters'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/ObjectParametersAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
    }
    public function testVersionsYamlReferencesDefaultsAndExplicitFormats(): void {
        $document = self::document(); $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\ObjectSdk', 'example/object-parameters'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3';
        foreach (['get', 'post'] as $verb) {
            $document['paths']['/items/{selector}'][$verb]['parameters'][0]['style'] = 'simple';
            $document['paths']['/items/{selector}'][$verb]['parameters'][0]['explode'] = false;
            $document['paths']['/items/{selector}'][$verb]['parameters'][3]['style'] = 'form';
            $document['paths']['/items/{selector}'][$verb]['parameters'][3]['explode'] = true;
        }
        self::assertSame($expected, self::files($document));
        $document['components']['parameters']['Selector'] = $document['paths']['/items/{selector}']['get']['parameters'][0];
        $document['components']['parameters']['Alias'] = ['$ref' => '#/components/parameters/Selector'];
        foreach (['get', 'post'] as $verb) { $document['paths']['/items/{selector}'][$verb]['parameters'][0] = ['$ref' => '#/components/parameters/Alias']; }
        self::assertSame($expected, self::files($document));
        self::assertStringContainsString('public \\Example\\ObjectSdk\\DTOs\\Selector $selector;', $expected['src/PathParams/Items/BySelector.php']);
        self::assertStringContainsString("'form-object-explode'", $expected['src/Resources/Items.php']);
    }
    public function testGetPostLocationsRequiredDirectionsAndBodyRemainIndependent(): void {
        $http = new class implements ClientInterface {
            public array $calls = [];
            public function sendRequest(RequestInterface $request): ResponseInterface { $this->calls[] = $request; return new Response(204); }
        };
        $config = new \Example\ObjectSdk\Configuration(); $config->httpClient = $http;
        \Example\ObjectSdk\Client::configure($config);
        $path = new \Example\ObjectSdk\PathParams\Items\BySelector((object) ['selector' => (object) ['code' => 'a,b/ñ', 'active' => false, 'count' => 0, 'server-id' => 'x,y']]);
        $filter = (object) ['code' => 'safe', 'active' => false, 'count' => 0, 'server-id' => 'x,y'];
        $get = new \Example\ObjectSdk\GetParams\Items\BySelector((object) ['X-Filter' => $filter, 'filter' => $filter, 'labels' => (object) ['tag' => 'x y'], 'body' => 'query body']);
        \Example\ObjectSdk\Items::getBySelector($path, $get);
        $post = new \Example\ObjectSdk\PostParams\Items\BySelector((object) ['X-Filter' => $filter, 'filter' => $filter, 'labels' => (object) ['tag' => 'x y'], 'queryBody' => 'query body', 'body' => (object) ['name' => 'body name']]);
        $resource = \Example\ObjectSdk\Client::connect($config->baseUrl, httpClient: $http)->resource(\Example\ObjectSdk\Resources\Items::class);
        $resource->postBySelector($path, $post);
        foreach ($http->calls as $request) {
            self::assertSame('https://api.example.test/v1/items/active,false,code,a%2Cb%2F%C3%B1,count,0?body=query%20body&filter=active,false,code,safe,count,0&tag=x%20y', (string) $request->getUri());
            self::assertSame('active=false,code=safe,count=0', $request->getHeaderLine('X-Filter'));
        }
        self::assertSame('', (string) $http->calls[0]->getBody());
        self::assertSame('{"name":"body name"}', (string) $http->calls[1]->getBody());
        $post->XFilter->code = 'a,b';
        try { $resource->postBySelector($path, $post); self::fail('Ambiguous header accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
        $post->XFilter->code = 'safe'; $path->selector->code = '';
        try { $resource->postBySelector($path, $post); self::fail('Nested path mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
        $path->selector->code = 'valid'; $post->labels['body'] = 'collision';
        try { $resource->postBySelector($path, $post); self::fail('Expanded query collision accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
    }
    public function testExplodedPathAndQueryWithCompactHeaderUseTheOtherSimpleForms(): void {
        $document = self::document();
        foreach (['get', 'post'] as $verb) {
            $document['paths']['/items/{selector}'][$verb]['parameters'][0]['explode'] = true;
            $document['paths']['/items/{selector}'][$verb]['parameters'][1]['explode'] = false;
            $document['paths']['/items/{selector}'][$verb]['parameters'][2]['explode'] = true;
        }
        $files = (new PhpExecutableEmitter('Example\\ExplodedObjectSdk', 'example/exploded-objects'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
        foreach (['src/Client.php', 'src/Configuration.php', 'src/ObjectParametersAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse {
                $this->calls[] = compact('url', 'headers'); return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
            }
        };
        $resource = \Example\ExplodedObjectSdk\Client::connect('https://example.test', transport: $transport)->resource(\Example\ExplodedObjectSdk\Resources\Items::class);
        $resource->getBySelector(new \Example\ExplodedObjectSdk\PathParams\Items\BySelector((object) ['selector' => (object) ['code' => 'a,b', 'active' => false, 'count' => 0]]),
            new \Example\ExplodedObjectSdk\GetParams\Items\BySelector((object) ['X-Filter' => (object) ['code' => 'header=value', 'active' => false, 'count' => 0], 'filter' => (object) ['code' => 'safe', 'active' => false, 'count' => 0]]));
        self::assertSame('https://example.test/items/active=false,code=a%2Cb,count=0?active=false&code=safe&count=0', $transport->calls[0]['url']);
        self::assertSame('active,false,code,header=value,count,0', $transport->calls[0]['headers']['X-Filter']);
    }
    public function testOmittedAndNullOptionalObjectsAreNotSerialized(): void {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse {
                $this->calls[] = compact('url', 'headers'); return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
            }
        };
        $resource = \Example\ObjectSdk\Client::connect('https://example.test', transport: $transport)->resource(\Example\ObjectSdk\Resources\Items::class);
        $path = new \Example\ObjectSdk\PathParams\Items\BySelector((object) ['selector' => (object) ['code' => 'safe', 'active' => false]]);
        $resource->getBySelector($path);
        $resource->getBySelector($path, new \Example\ObjectSdk\GetParams\Items\BySelector((object) ['X-Filter' => null, 'filter' => null, 'labels' => (object) []]));
        foreach ($transport->calls as $call) {
            self::assertSame('https://example.test/items/active,false,code,safe', $call['url']);
            self::assertArrayNotHasKey('X-Filter', $call['headers']);
        }
    }
    public function testObjectTypeLookupKeepsIdenticalNamesInDifferentLocationsSeparate(): void {
        $document = self::document();
        $document['components']['schemas']['HeaderSelector'] = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['header' => ['type' => 'string']]];
        $document['components']['schemas']['QuerySelector'] = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['query' => ['type' => 'integer']]];
        foreach (['get', 'post'] as $verb) {
            $parameters = &$document['paths']['/items/{selector}'][$verb]['parameters'];
            $parameters[1]['name'] = 'selector'; $parameters[1]['schema'] = ['$ref' => '#/components/schemas/HeaderSelector'];
            $parameters[2]['name'] = 'selector'; $parameters[2]['schema'] = ['$ref' => '#/components/schemas/QuerySelector']; unset($parameters);
        }
        $files = self::files($document);
        self::assertStringContainsString('DTOs\\Selector $selector', $files['src/PathParams/Items/BySelector.php']);
        self::assertStringContainsString('DTOs\\HeaderSelector $selector', $files['src/GetParams/Items/BySelector.php']);
        self::assertStringContainsString('DTOs\\QuerySelector $querySelector', $files['src/GetParams/Items/BySelector.php']);
    }
    public function testUnsupportedNestingNullablePathsOpenObjectsAndStylesStayDiagnosed(): void {
        $variants = [];
        foreach ([['style' => 'label'], ['style' => 'matrix'], ['schema' => ['type' => 'object', 'additionalProperties' => true]],
            ['schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
            ['schema' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => false]],
            ['schema' => ['type' => 'object', 'additionalProperties' => false, 'enum' => []]],
            ['schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => 'string', 'enum' => []]]]]] as $change) {
            $document = self::document(); $document['paths']['/items/{selector}']['get']['parameters'][0] = array_replace($document['paths']['/items/{selector}']['get']['parameters'][0], $change); $variants[] = $document;
        }
        foreach ($variants as $document) {
            try { self::files($document); self::fail('Unsupported contract accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
        $swagger = ['swagger' => '2.0', 'info' => ['title' => 'Invalid', 'version' => '1'], 'paths' => ['/items' => ['get' => ['parameters' => [['name' => 'filter', 'in' => 'query', 'type' => 'object', 'additionalProperties' => false]], 'responses' => ['204' => ['description' => 'Done']]]]]];
        try { self::files($swagger); self::fail('Swagger object parameter accepted.'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
    }
}
