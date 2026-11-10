<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class RequestBodiesGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/request-bodies-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function files(array $document): array { return (new PhpExecutableEmitter('Example\\BodySdk', 'example/body'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/RequestBodiesAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(204); }
        };
        return [\Example\BodySdk\Client::connect('https://example.test', httpClient: $http), $http];
    }
    public function testJsonYamlAndNamedArraysReuseExistingCollectionTypes(): void {
        self::assertSame(self::files(self::document()), (new PhpExecutableEmitter('Example\\BodySdk', 'example/body'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump(self::document(), 40), strict: true)));
        $files = self::files(self::document());
        foreach (['Users', 'UsersAlias', 'Text', 'TextAlias', 'Choice', 'Anything', 'Impossible', 'LooseList'] as $name) { self::assertArrayNotHasKey('src/DTOs/' . $name . '.php', $files); }
        self::assertStringContainsString('public function post(mixed $payload = null)', $files['src/Resources/NeverResource.php'] ?? '');
    }
    public function testPrimitiveBodiesAndOptionalPresenceAcrossInstanceAndFacade(): void {
        [$client, $http] = $this->setupClient();
        $optional = $client->resource(\Example\BodySdk\Resources\Optional::class);
        $optional->post(); $optional->post(null); $optional->post(0); $optional->post('text');
        self::assertSame(['', 'null', '0', '"text"'], array_map(static fn ($r) => (string) $r->getBody(), $http->calls));
        self::assertFalse($http->calls[0]->hasHeader('Content-Type'));
        self::assertSame('application/json', $http->calls[1]->getHeaderLine('Content-Type'));
        $configuration = new \Example\BodySdk\Configuration(); $configuration->baseUrl = 'https://example.test'; $configuration->httpClient = $http;
        \Example\BodySdk\Client::configure($configuration);
        \Example\BodySdk\Optional::post(); \Example\BodySdk\Optional::post(null);
        self::assertFalse($http->calls[4]->hasHeader('Content-Type')); self::assertSame('null', (string) $http->calls[5]->getBody());
        $client->resource(\Example\BodySdk\Resources\NullResource::class)->post(null);
        $client->resource(\Example\BodySdk\Resources\Scalar::class)->post('valid');
        foreach (['x', -1] as $invalid) {
            try { $optional->post($invalid); self::fail('Invalid scalar body reached HTTP.'); } catch (\UnexpectedValueException) { self::assertCount(8, $http->calls); }
        }
        $client->resource(\Example\BodySdk\Resources\NeverResource::class)->post();
        try { $client->resource(\Example\BodySdk\Resources\NeverResource::class)->post(null); self::fail('False body schema accepted null.'); }
        catch (\UnexpectedValueException) { self::assertCount(9, $http->calls); }
    }
    public function testSwaggerAndOpenApi30NativeBodiesUseTheSameRuntimeContract(): void {
        $operation = ['operationId' => 'postNumber', 'responses' => ['204' => ['description' => 'Accepted']]];
        $schema = ['type' => 'integer', 'minimum' => 0];
        foreach (['3.0.3', '2.0'] as $version) {
            $document = ['info' => ['title' => 'Legacy Body API', 'version' => '1'], 'paths' => ['/number' => ['post' => $operation]]];
            if ($version === '2.0') {
                $document['swagger'] = $version; $document['host'] = 'example.test'; $document['schemes'] = ['https']; $document['consumes'] = ['application/json'];
                $document['paths']['/number']['post']['parameters'] = [['name' => 'body', 'in' => 'body', 'required' => true, 'schema' => $schema]];
            } else {
                $document['openapi'] = $version;
                $document['paths']['/number']['post']['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
            }
            $files = self::files($document);
            self::assertStringContainsString('public function post(int $payload)', $files['src/Resources/Number.php']);
            self::assertStringContainsString("'minimum' => 0", $files['src/Resources/Number.php']);
            self::assertSame($files, (new PhpExecutableEmitter('Example\\BodySdk', 'example/body'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        }
    }
    public function testListMapQueryHeaderAndMutations(): void {
        [$client, $http] = $this->setupClient();
        $params = new \Example\BodySdk\PostParams\ListResource\ById((object) ['queryBody' => 'separate', 'X-Test' => 'header', 'body' => [(object) ['name' => 'Alice', 'readOnlyId' => 7]]]);
        $list = $client->resource(\Example\BodySdk\Resources\ListResource::class);
        $list->postById(7, $params);
        self::assertSame('https://example.test/list/7?body=separate', (string) $http->calls[0]->getUri());
        self::assertSame('header', $http->calls[0]->getHeaderLine('X-Test'));
        self::assertSame('[{"name":"Alice"}]', (string) $http->calls[0]->getBody());
        $params->body[0]->name = 'x';
        try { $list->postById(7, $params); self::fail('Nested body mutation reached HTTP.'); } catch (\UnexpectedValueException) { self::assertCount(1, $http->calls); }
        $client->resource(\Example\BodySdk\Resources\Loose::class)->post([1, 'x', null]);
        self::assertSame('[1,"x",null]', (string) $http->calls[1]->getBody());
        $scores = new \Example\BodySdk\DTOs\Scores((object) ['one' => 1]);
        $map = $client->resource(\Example\BodySdk\Resources\Map::class); $map->post($scores);
        self::assertSame('{"one":1}', (string) $http->calls[2]->getBody());
        try { $scores['one'] = -1; $map->post($scores); self::fail('Map body mutation reached HTTP.'); } catch (\UnexpectedValueException) { self::assertCount(3, $http->calls); }
    }
}
