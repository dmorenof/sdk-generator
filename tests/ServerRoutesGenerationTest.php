<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ServerRoutesGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/server-routes-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function contract(array $document): \Dmorenof\SdkGenerator\Contract\ApiContract { return (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true); }
    private static function files(array $document): array { return (new PhpExecutableEmitter('Example\\ServerRoutesSdk', 'example/server-routes'))->emit(self::contract($document)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/ServerRoutesAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(204); }
        };
        return [\Example\ServerRoutesSdk\Client::connect('https://configured.test/v9', httpClient: $http), $http];
    }
    public function testDefaultExpansionScopeAndJsonYamlDialects(): void {
        $document = self::document(); $contract = self::contract($document); $files = self::files($document);
        self::assertSame(['https://api.eu.example.test:443/v1', 'https://backup.example.test/v2'], $contract->servers);
        self::assertSame($document['servers'], $contract->serverDefinitions);
        self::assertSame('https://api.eu.example.test:443/v1', (new \Example\ServerRoutesSdk\Configuration())->baseUrl);
        $byId = []; foreach ($contract->operations as $operation) { $byId[$operation->operationId] = $operation; }
        self::assertSame(['https://audit.us.example.test/v2'], $byId['getItem']->servers);
        self::assertSame(['https://exports.eu.example.test/v3'], $byId['postItem']->servers);
        self::assertSame('us', $byId['getItem']->serverDefinitions[0]['variables']['region']['default']);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\ServerRoutesSdk', 'example/server-routes'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3'; self::assertSame($files, self::files($document));
        self::assertStringNotContainsString("'serverUrl'", $files['src/Resources/Equivalent.php']);
    }
    public function testRootPathOperationAndEquivalentServersKeepParameterLocations(): void {
        [$client, $http] = $this->setupClient();
        $client->resource(\Example\ServerRoutesSdk\Resources\Root::class)->getById('a/b');
        $items = $client->resource(\Example\ServerRoutesSdk\Resources\Items::class);
        $items->getById('a/b', new \Example\ServerRoutesSdk\GetParams\Items\ById((object) ['body' => 'query body', 'X-Trace' => 'trace']));
        $params = new \Example\ServerRoutesSdk\PostParams\Items\ById((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['value' => 'valid']]);
        $items->postById('a/b', $params);
        $client->resource(\Example\ServerRoutesSdk\Resources\Equivalent::class)->getById('a/b');
        $client->resource(\Example\ServerRoutesSdk\Resources\Mirror\ById::class)->getById('a/b');
        $client->resource(\Example\ServerRoutesSdk\Resources\Assets\StemExt::class)->get(new \Example\ServerRoutesSdk\PathParams\Assets\StemExt((object) ['stem' => 'report', 'ext' => 'csv']));
        self::assertSame(['https://configured.test/v9/root/a%2Fb', 'https://audit.us.example.test/v2/items/a%2Fb?body=query%20body',
            'https://exports.eu.example.test/v3/items/a%2Fb?body=query%20body', 'https://configured.test/v9/equivalent/a%2Fb',
            'https://configured.test/v9/mirror/a%2Fb/a%2Fb', 'https://configured.test/v9/assets/report.csv'], array_map(static fn ($r) => (string) $r->getUri(), $http->calls));
        self::assertSame('trace', $http->calls[2]->getHeaderLine('X-Trace')); self::assertSame('{"value":"valid"}', (string) $http->calls[2]->getBody());
        self::assertSame('', (string) $http->calls[1]->getBody());
        $params->body->value = 'x';
        try { $items->postById('a/b', $params); self::fail('Nested mutation reached HTTP.'); } catch (\UnexpectedValueException) { self::assertCount(6, $http->calls); }
    }
    public function testExistingConfigurationOverridesSnapshotExpandedDefaults(): void {
        [, $http] = $this->setupClient(); $configuration = new \Example\ServerRoutesSdk\Configuration(); $configuration->httpClient = $http;
        $configuration->serverOverrides = ['https://audit.us.example.test/v2' => 'https://stage.test/audit', 'https://exports.eu.example.test/v3' => 'https://stage.test/export'];
        \Example\ServerRoutesSdk\Client::configure($configuration);
        $configuration->serverOverrides['https://audit.us.example.test/v2'] = 'https://changed.test';
        \Example\ServerRoutesSdk\Items::getById('id');
        \Example\ServerRoutesSdk\Items::postById('id', new \Example\ServerRoutesSdk\PostParams\Items\ById((object) ['body' => (object) ['value' => 'valid']]));
        self::assertSame(['https://stage.test/audit/items/id', 'https://stage.test/export/items/id'], array_map(static fn ($r) => (string) $r->getUri(), $http->calls));
    }
    public function testPathExtensionsRemainDataInInspectionAndLocalFileLoading(): void {
        $document = self::document(); $contract = self::contract($document);
        self::assertSame($document['paths']['x-route-notes'], $contract->annotations['paths']['x-route-notes']);
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['accepted']); self::assertSame(5, $report['pathCount']); self::assertSame(6, $report['operationCount']);
        $fromFile = (new SpecificationNormalizer())->fromFile(__DIR__ . '/fixtures/server-routes-openapi31.json', strict: true);
        self::assertEquals($contract, $fromFile);
    }
    public function testComponentPathItemsMayHaveExtensionLikeNames(): void {
        $document = self::document();
        $document['components']['pathItems']['x-shared'] = ['parameters' => [['$ref' => '#/components/parameters/Id']],
            'servers' => [['url' => 'https://shared.example.test/{version}', 'variables' => ['version' => ['default' => 'v4']]]],
            'get' => ['operationId' => 'getReused', 'responses' => ['204' => ['description' => 'Empty']]]];
        $document['paths']['/reused/{id}'] = ['$ref' => '#/components/pathItems/x-shared'];
        $contract = self::contract($document); $operation = array_values(array_filter($contract->operations, static fn ($op) => $op->operationId === 'getReused'))[0];
        self::assertSame(['https://shared.example.test/v4'], $operation->servers); self::assertCount(7, $contract->operations);
    }
    public function testInvalidServerVariablesAndShapesAreRejected(): void {
        foreach ([['default' => 'other', 'enum' => ['eu']], ['default' => 443], ['enum' => ['eu']], ['default' => 'eu', 'enum' => []], ['default' => 'eu', 'enum' => [false]], ['default' => '{region}']] as $variable) {
            $document = self::document(); $document['servers'][0]['variables']['region'] = $variable;
            try { self::files($document); self::fail('Invalid server variable accepted.'); } catch (\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
        foreach ([null, 'server', ['url' => 'https://example.test']] as $servers) {
            $document = self::document(); $document['paths']['/items/{id}']['post']['servers'] = $servers;
            try { self::files($document); self::fail('Invalid server list accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $document = self::document(); unset($document['servers'][0]['variables']['region']);
        try { self::files($document); self::fail('Missing binding accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
    }
    public function testInvalidTemplatesRequiredFlagsAndEquivalentRoutesFailBeforeOutput(): void {
        foreach (['missing', 'extra', 'optional', 'flag', 'equivalent', 'braces', 'empty', 'query', 'fragment'] as $case) {
            $document = self::document();
            if ($case === 'missing') { $document['paths']['/root/{id}']['parameters'] = []; }
            elseif ($case === 'extra') { $document['components']['parameters']['Id']['name'] = 'unused'; }
            elseif ($case === 'optional') { $document['components']['parameters']['Id']['required'] = false; }
            elseif ($case === 'flag') { $document['components']['parameters']['Id']['required'] = 'true'; }
            elseif ($case === 'equivalent') { $document['paths']['/root/{other}'] = ['parameters' => [['name' => 'other', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'get' => ['operationId' => 'getOther', 'responses' => ['204' => ['description' => 'Empty']]]]; }
            else { $path = match ($case) { 'braces' => '/bad/{id', 'empty' => '/bad/{}', 'query' => '/bad?query=1', default => '/bad#fragment' }; $document['paths'][$path] = []; }
            try { self::files($document); self::fail('Invalid route accepted: ' . $case); } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage()); if ($case === 'equivalent') { self::assertStringContainsString('Equivalent templated paths', $error->getMessage()); }
            }
        }
    }
}
