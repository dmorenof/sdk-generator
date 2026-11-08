<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\ApiException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

final class BinaryResponseGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/downloads-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\DownloadSdk', 'example/downloads'))->emit(
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
    public function testVersionsYamlResponseAliasesAndNativePsrTypes(): void
    {
        $document = self::document();
        $files = self::files($document);
        $document['openapi'] = '3.0.3';
        self::assertSame($files, self::files($document));
        self::assertSame($files, (new PhpExecutableEmitter('Example\\DownloadSdk', 'example/downloads'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump(self::document(), 40), strict: true)));
        self::assertStringContainsString('): \\Psr\\Http\\Message\\ResponseInterface', $files['src/Resources/Download.php']);
        self::assertStringNotContainsString('Responses/', implode('\n', array_keys($files)));
    }
    public function testGetPostParamsPreserveQueryHeadersAndJsonBodyWithBinaryResponses(): void
    {
        $http = new class implements ClientInterface {
            public array $requests = [];
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                return new Response(206, ['Content-Disposition' => 'attachment; filename="file.bin"'], "\x00\xff\r\n");
            }
        };
        $config = new \Example\DownloadSdk\Configuration();
        $config->httpClient = $http;
        \Example\DownloadSdk\Client::configure($config);
        $response = \Example\DownloadSdk\Download::get(new \Example\DownloadSdk\GetParams\Download((object) ['body' => 'get body', 'X-Trace' => 'get trace']));
        self::assertSame(206, $response->getStatusCode());
        self::assertSame("\x00\xff\r\n", $response->getBody()->getContents());
        $params = new \Example\DownloadSdk\PostParams\Download((object) ['queryBody' => 'post query', 'X-Trace' => 'post trace', 'body' => (object) ['name' => 'export']]);
        // POST declares 200, so use an independent PSR client with that status.
        $postHttp = new class($http) implements ClientInterface {
            public function __construct(private ClientInterface $http) {}
            public function sendRequest(RequestInterface $request): ResponseInterface { return $this->http->sendRequest($request)->withStatus(200); }
        };
        $client = \Example\DownloadSdk\Client::connect($config->baseUrl, httpClient: $postHttp);
        $resource = $client->resource(\Example\DownloadSdk\Resources\Download::class);
        $post = $resource->post($params);
        self::assertSame('attachment; filename="file.bin"', $post->getHeaderLine('Content-Disposition'));
        self::assertSame('body=get%20body', $http->requests[0]->getUri()->getQuery());
        self::assertSame('', (string) $http->requests[0]->getBody());
        self::assertSame('body=post%20query', $http->requests[1]->getUri()->getQuery());
        self::assertSame('{"name":"export"}', (string) $http->requests[1]->getBody());
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        self::assertSame('post trace', $http->requests[1]->getHeaderLine('X-Trace'));
        self::assertSame('application/octet-stream', $http->requests[1]->getHeaderLine('Accept'));
        $params->body->name = '';
        try { $resource->post($params); self::fail('Invalid mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->requests); }
    }
    public function testEmptySuccessAndTypedErrorRemainDistinct(): void
    {
        foreach ([200, 204, 206, 404] as $status) {
            $http = new class($status) implements ClientInterface {
                public function __construct(private int $status) {}
                public function sendRequest(RequestInterface $request): ResponseInterface
                { return new Response($this->status, [], $this->status === 404 ? '{"message":"missing"}' : ''); }
            };
            $resource = \Example\DownloadSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\DownloadSdk\Resources\Download::class);
            try { self::assertSame($status, $resource->get()->getStatusCode()); self::assertNotSame(404, $status); }
            catch (ApiException $e) {
                self::assertSame(404, $status);
                self::assertInstanceOf(\Example\DownloadSdk\DTOs\Error::class, $e->error);
                self::assertSame('missing', $e->error->message);
            }
        }
    }
    public function testJsonRepresentationRetainsPriority(): void
    {
        $document = self::document();
        $json = ['schema' => ['$ref' => '#/components/schemas/Error']];
        unset($document['paths']['/download']['get']['responses']['206']);
        $document['components']['responses']['File']['content']['application/json'] = $json;
        $document['paths']['/download']['post']['responses']['200']['content']['application/json'] = $json;
        $files = self::files($document);
        self::assertStringNotContainsString('ResponseInterface', $files['src/Resources/Download.php']);
        self::assertStringContainsString('DTOs\\Error', $files['src/Resources/Download.php']);
    }
    public function testUnsupportedSchemasRepresentationsAndMixedSuccessFailVisibly(): void
    {
        $base = self::document();
        $variants = [];
        foreach ([['nullable' => true], ['enum' => []], ['minLength' => 1], ['pattern' => '.'], ['not' => ['type' => 'string']], ['type' => ['string', 'null']], ['$ref' => '#/components/schemas/Export']] as $extra) {
            $document = $base;
            $document['components']['responses']['File']['content']['application/octet-stream']['schema'] = array_replace(['type' => 'string', 'format' => 'binary'], $extra);
            $variants[] = $document;
        }
        $document = $base;
        $document['components']['responses']['File']['content'] = ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]];
        $variants[] = $document;
        $document = $base;
        $document['paths']['/download']['get']['responses']['206']['content'] = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];
        $variants[] = $document;
        $document = $base;
        $document['paths']['/download']['get']['responses']['404'] = $document['paths']['/download']['get']['responses']['206'];
        $variants[] = $document;
        $document = $base;
        $document['components']['responses']['File']['content']['application/octet-stream']['encoding'] = ['file' => ['contentType' => 'image/png']];
        $variants[] = $document;
        $document = $base;
        $document['paths']['/download']['get']['parameters'][] = ['in' => 'query', 'name' => 'raw',
            'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
        $variants[] = $document;
        $document = $base;
        $document['components']['responses']['File']['headers'] = ['X-Size' => ['schema' => ['type' => 'integer']]];
        $variants[] = $document;
        $variants[] = ['swagger' => '2.0', 'info' => ['title' => 'Download', 'version' => '1'],
            'produces' => ['application/octet-stream'], 'paths' => ['/download' => ['get' => ['operationId' => 'download',
                'responses' => ['200' => ['description' => 'File', 'schema' => ['type' => 'file']]]]]]];
        foreach ($variants as $document) {
            try { self::files($document); self::fail('Unsupported contract accepted.'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
}
