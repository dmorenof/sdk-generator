<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\ApiException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

final class SwaggerBinaryResponseGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/downloads-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\SwaggerDownloadSdk', 'example/downloads'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/DownloadAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); }
        }
    }
    public function testJsonYamlAndEquivalentOpenApiProduceIdenticalSdk(): void
    {
        $document = self::document();
        $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SwaggerDownloadSdk', 'example/downloads'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $oas = json_decode(file_get_contents(__DIR__ . '/fixtures/downloads-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($oas['paths']['/download']['get']['responses']['404']['content']);
        self::assertSame($expected, self::files($oas));
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        $response = $contract->operations[0]->responses[0];
        self::assertSame('application/octet-stream', $response->mediaType);
        self::assertSame('binary', $response->schema->format);
        self::assertSame('binary', $response->content[0]->schema->format);
    }
    public function testProducesPrecedenceResponseAliasesAndIndependentJsonOperation(): void
    {
        $document = self::document();
        $expected = self::files($document);
        $document['produces'] = ['application/json'];
        foreach (['get', 'post'] as $verb) { $document['paths']['/download'][$verb]['produces'] = ['application/octet-stream']; }
        $document['responses']['Alias'] = ['$ref' => '#/responses/File'];
        $document['paths']['/download']['get']['responses']['200'] = ['$ref' => '#/responses/Alias'];
        self::assertSame($expected, self::files($document));
        $document['paths']['/status'] = ['get' => ['operationId' => 'getStatus',
            'responses' => ['200' => ['description' => 'JSON', 'schema' => ['$ref' => '#/definitions/Error']]]]];
        $files = self::files($document);
        self::assertStringContainsString('ResponseInterface', $files['src/Resources/Download.php']);
        self::assertStringContainsString('DTOs\\Error', $files['src/Resources/Status.php']);
        self::assertStringNotContainsString('ResponseInterface', $files['src/Resources/Status.php']);
    }
    public function testGetPostParamsBytesHeadersReplayAndMutationValidation(): void
    {
        $http = new class implements ClientInterface {
            public array $requests = [];
            public ResponseInterface $response;
            public function __construct() { $this->response = new Response(200, ['X-Values' => ['one', 'two']], "\x00\xff\r\n"); }
            public function sendRequest(RequestInterface $request): ResponseInterface
            { $this->requests[] = $request; return $this->response; }
        };
        $config = new \Example\SwaggerDownloadSdk\Configuration();
        $config->httpClient = $http;
        \Example\SwaggerDownloadSdk\Client::configure($config);
        $get = \Example\SwaggerDownloadSdk\Download::get(new \Example\SwaggerDownloadSdk\GetParams\Download((object) ['body' => 'query body', 'X-Trace' => 'trace']));
        self::assertSame($http->response, $get);
        self::assertSame(['one', 'two'], $get->getHeader('X-Values'));
        self::assertSame("\x00\xff\r\n", $get->getBody()->getContents());
        $resource = \Example\SwaggerDownloadSdk\Client::connect($config->baseUrl, httpClient: $http)->resource(\Example\SwaggerDownloadSdk\Resources\Download::class);
        $params = new \Example\SwaggerDownloadSdk\PostParams\Download((object) [
            'queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['name' => 'export']]);
        foreach ([1, 2] as $_) {
            self::assertSame("\x00\xff\r\n", $resource->post($params)->getBody()->getContents());
        }
        foreach ($http->requests as $request) {
            self::assertSame('https://api.example.test/v1/download?body=query%20body', (string) $request->getUri());
            self::assertSame('trace', $request->getHeaderLine('X-Trace'));
            self::assertSame('application/octet-stream', $request->getHeaderLine('Accept'));
        }
        self::assertSame('', (string) $http->requests[0]->getBody());
        self::assertSame('{"name":"export"}', (string) $http->requests[1]->getBody());
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        $params->body->name = '';
        try { $resource->post($params); self::fail('Mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(3, $http->requests); }
    }
    public function testEmptySuccessStatusesAndRawErrorsRetainTheirContract(): void
    {
        foreach ([200, 204, 206, 404, 500] as $status) {
            $http = new class($status) implements ClientInterface {
                public function __construct(private int $status) {}
                public function sendRequest(RequestInterface $request): ResponseInterface
                { return new Response($this->status, ['X-Status' => (string) $this->status], $this->status >= 400 ? 'raw error' : ''); }
            };
            $resource = \Example\SwaggerDownloadSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\SwaggerDownloadSdk\Resources\Download::class);
            try {
                $response = $resource->get();
                self::assertLessThan(400, $status);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('', (string) $response->getBody());
            } catch (ApiException $e) {
                self::assertGreaterThanOrEqual(400, $status);
                self::assertSame($status, $e->response->status);
                self::assertSame('raw error', $e->response->body);
                self::assertNull($e->error);
            }
        }
    }
    public function testUnsupportedProducesFileSchemasAndErrorBodiesFailVisibly(): void
    {
        $variants = [];
        foreach ([['application/json'], ['image/png'], [], ['application/octet-stream', 'application/json']] as $produces) {
            $document = self::document(); $document['produces'] = $produces; $variants[] = $document;
        }
        foreach ([['enum' => []], ['minLength' => 1], ['nullable' => true], ['x-nullable' => true],
            ['format' => 'binary'], ['pattern' => '.'], ['items' => ['type' => 'file']], ['type' => ['file', 'null']],
            ['allOf' => [['type' => 'file']]]] as $extra) {
            $document = self::document(); $document['responses']['File']['schema'] = array_replace(['type' => 'file'], $extra); $variants[] = $document;
        }
        $document = self::document(); unset($document['produces']); $variants[] = $document;
        $document = self::document(); $document['paths']['/download']['post']['produces'] = ['application/json']; $variants[] = $document;
        $document = self::document(); $document['paths']['/download']['get']['responses']['404']['schema'] = ['$ref' => '#/definitions/Error']; $variants[] = $document;
        $document = self::document(); $document['paths']['/download']['get']['responses']['206']['schema'] = ['type' => 'string', 'format' => 'binary']; $variants[] = $document;
        foreach ($variants as $document) {
            try { self::files($document); self::fail('Unsupported contract accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
        $document = self::document();
        $document['produces'] = ['application/json'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertFalse($report['normalized']);
        self::assertContains('normalization_failed', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
        $document['produces'] = ['image/png'];
        $issues = array_values(array_filter((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['diagnostics'],
            static fn ($issue): bool => $issue->code === 'response_media_type'));
        self::assertNotEmpty($issues);
        self::assertSame('/produces', $issues[0]->pointer);
        self::assertSame('getDownload', $issues[0]->operationId);
    }
}
