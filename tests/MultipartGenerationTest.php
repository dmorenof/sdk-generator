<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MultipartGenerationTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class MultipartGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/multipart-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter('Example\\Multipart', 'example/multipart'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/MultipartAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }

    public function testJsonYamlAndOpenApiVersionsProduceEquivalentSdks(): void
    {
        $document = self::document();
        $expected = self::files($document);
        $document['paths']['/items']['post']['requestBody']['content']['multipart/form-data']['encoding']['ids'] = ['style' => 'form', 'explode' => true, 'allowReserved' => false];
        self::assertSame($expected, self::files($document));
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\Multipart', 'example/multipart'))->emit($contract));
        $document['openapi'] = '3.0.3';
        foreach (['Form', 'OptionalForm'] as $name) {
            $document['components']['schemas'][$name]['properties']['note'] = ['type' => 'string', 'nullable' => true];
        }
        self::assertSame($expected, self::files($document));
        self::assertStringContainsString('public string|\\Psr\\Http\\Message\\UploadedFileInterface $file;', $expected['src/DTOs/Form.php']);
    }

    public function testGetPostParamsKeepQueryHeadersAndMultipartSeparate(): void
    {
        $transport = new MultipartGenerationTransport();
        $configuration = new \Example\Multipart\Configuration();
        $configuration->baseUrl = 'https://api.example.test/v1';
        $configuration->transport = $transport;
        \Example\Multipart\Client::configure($configuration);
        \Example\Multipart\Items::get(new \Example\Multipart\GetParams\Items((object) ['name' => 'get +']));
        \Example\Multipart\Items::post(new \Example\Multipart\PostParams\Items((object) [
            'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
            'body' => (object) ['name' => 'body name', 'body' => 'form-body', 'active' => false,
                'city-name' => 'ñ +%~', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2],
                'file' => "\x00\xff\r\n", 'note' => null, 'internal' => 'hidden', 'secret' => '', 'state' => 'open'],
        ]));
        self::assertSame('https://api.example.test/v1/items?name=get%20%2B', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
        $call = $transport->calls[1];
        self::assertSame('https://api.example.test/v1/items?body=query-body&name=query%20name', $call['url']);
        self::assertSame('trace', $call['headers']['X-Trace']);
        self::assertStringContainsString('name="file"; filename="file.bin"', $call['body']);
        self::assertStringContainsString('Content-Type: image/png', $call['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", $call['body']);
        self::assertStringContainsString("\r\n\r\nñ +%~\r\n", $call['body']);
        self::assertStringContainsString("\r\n\r\nfalse\r\n", $call['body']);
        self::assertSame(2, substr_count($call['body'], 'name="codes"'));
        self::assertSame(2, substr_count($call['body'], 'name="ids"'));
        foreach (['name', 'body', 'secret', 'state'] as $name) {
            self::assertStringContainsString('name="' . $name . '"', $call['body']);
        }
        foreach (['note', 'internal', 'queryBody', 'X-Trace'] as $name) {
            self::assertStringNotContainsString('name="' . $name . '"', $call['body']);
        }
    }

    public function testPsr18ReceivesMatchingBoundaryAndBinaryBytes(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\Multipart\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->resource(\Example\Multipart\Resources\Items::class)->post(new \Example\Multipart\PostParams\Items((object) [
            'body' => (object) ['name' => 'Ada', 'file' => "\x00\xff"],
        ]));
        $request = $history[0]['request'];
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=([a-f0-9]+)$/', $request->getHeaderLine('Content-Type'));
        $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
        self::assertStringStartsWith('--' . $boundary . "\r\n", (string) $request->getBody());
        self::assertStringEndsWith('--' . $boundary . "--\r\n", (string) $request->getBody());
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n", (string) $request->getBody());
    }

    public function testOptionalEmptyFieldsAndBodyOnlyDto(): void
    {
        $transport = new MultipartGenerationTransport();
        $client = \Example\Multipart\Client::connect('https://api.example.test/v1', transport: $transport);
        $optional = $client->resource(\Example\Multipart\Resources\Optional::class);
        $optional->post();
        $optional->post(new \Example\Multipart\DTOs\OptionalForm());
        $optional->post(new \Example\Multipart\DTOs\OptionalForm((object) ['note' => null]));
        $optional->post(new \Example\Multipart\DTOs\OptionalForm((object) ['note' => '']));
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
        foreach ([1, 2] as $index) {
            self::assertMatchesRegularExpression('/^--[a-f0-9]+--\r\n$/', $transport->calls[$index]['body']);
        }
        self::assertStringContainsString('name="note"', $transport->calls[3]['body']);
        $client->resource(\Example\Multipart\Resources\BodyOnly::class)->post(new \Example\Multipart\DTOs\Form((object) ['name' => 'Ada', 'file' => '']));
        self::assertStringContainsString('filename="file.bin"', $transport->calls[4]['body']);
    }

    public function testRequiredConstraintsEnumsAndMutationsFailBeforeSending(): void
    {
        $transport = new MultipartGenerationTransport();
        $items = \Example\Multipart\Client::connect('https://api.example.test/v1', transport: $transport)->resource(\Example\Multipart\Resources\Items::class);
        foreach ([(object) [], (object) ['name' => ''], (object) ['name' => 'Ada', 'ids' => [0]],
            (object) ['name' => 'Ada', 'state' => 'unknown'], (object) ['name' => 'Ada', 'extra' => 'x']] as $body) {
            try {
                $items->post(new \Example\Multipart\PostParams\Items((object) ['body' => $body]));
                self::fail('Invalid multipart payload accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\Multipart\PostParams\Items((object) ['body' => (object) ['name' => 'Ada', 'ids' => [1]]]);
        try {
            $params->body->ids[] = 0;
            $items->post($params);
            self::fail('Invalid list mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
        $params->body->name = '';
        $this->expectException(\UnexpectedValueException::class);
        $items->post($params);
    }

    public function testUnsupportedMultipartFeaturesAreDiagnosedAndRejected(): void
    {
        foreach ([['explode' => false], ['style' => 'deepObject'], ['allowReserved' => true], ['headers' => []],
            ['contentType' => "image/png\r\nX: y"], ['contentType' => 'image/png, image/jpeg']] as $encoding) {
            $document = self::document();
            $document['paths']['/items']['post']['requestBody']['content']['multipart/form-data']['encoding']['file'] = $encoding;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
            try {
                self::files($document);
                self::fail('Unsupported encoding accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['type' => 'object'], ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']]],
            ['type' => 'string', 'format' => 'binary', 'minLength' => 1],
            ['type' => 'array', 'items' => ['type' => ['string', 'null']]]] as $field) {
            $document = self::document();
            $document['components']['schemas']['Form']['properties']['file'] = $field;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
    }

    public function testJsonPriorityAndUrlencodedPriorityDoNotDependOnContentOrder(): void
    {
        $document = self::document();
        $content = &$document['paths']['/items']['post']['requestBody']['content'];
        $content['application/json'] = ['schema' => ['$ref' => '#/components/schemas/Form']];
        self::assertStringNotContainsString("'requestMediaType'", self::files($document)['src/Resources/Items.php']);
        unset($content['application/json']);
        $content['application/x-www-form-urlencoded'] = ['schema' => ['$ref' => '#/components/schemas/Form']];
        self::assertStringContainsString("'requestMediaType' => 'application/x-www-form-urlencoded'", self::files($document)['src/Resources/Items.php']);
    }

    public function testReusableBodiesAndInlineModelsKeepTheSamePartMetadata(): void
    {
        $document = self::document();
        $body = $document['paths']['/items']['post']['requestBody'];
        $document['components']['requestBodies']['Upload'] = $body;
        $document['paths']['/items']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/Upload'];
        self::assertSame(self::files(self::document()), self::files($document));
        $body['content']['multipart/form-data']['schema'] = $document['components']['schemas']['Form'];
        $document['paths']['/items']['post']['requestBody'] = $body;
        $files = self::files($document);
        self::assertStringContainsString('public string|\\Psr\\Http\\Message\\UploadedFileInterface $file;', $files['src/Requests/Items/Post.php']);
        self::assertStringContainsString("'contentType' => 'image/png'", $files['src/Resources/Items.php']);
        self::assertStringContainsString('\\Example\\Multipart\\Requests\\Items\\Post $body;', $files['src/PostParams/Items.php']);
    }

    public function testPsrUploadsPreserveMetadataBytesAndRepeatSendsThroughPsr18(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204), new \GuzzleHttp\Psr7\Response(204),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\Multipart\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $items = $client->resource(\Example\Multipart\Resources\Items::class);
        $upload = new \GuzzleHttp\Psr7\UploadedFile(\GuzzleHttp\Psr7\Utils::streamFor("\x00\xff\r\n"), 4, UPLOAD_ERR_OK, 'original.png', 'image/png');
        $params = new \Example\Multipart\PostParams\Items((object) [
            'queryBody' => 'query +', 'X-Trace' => 'trace', 'body' => (object) ['name' => 'Ada', 'file' => $upload],
        ]);
        self::assertSame($upload, $params->body->file);
        $items->post($params);
        $items->post($params);
        foreach ($history as $call) {
            self::assertInstanceOf(\GuzzleHttp\Psr7\MultipartStream::class, $call['request']->getBody());
            self::assertSame('https://api.example.test/v1/items?body=query%20%2B', (string) $call['request']->getUri());
            self::assertSame('trace', $call['request']->getHeaderLine('X-Trace'));
            self::assertStringContainsString('filename="original.png"', (string) $call['request']->getBody());
            self::assertStringContainsString('Content-Type: image/png', (string) $call['request']->getBody());
            self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", (string) $call['request']->getBody());
        }
        $params->body->file = new \GuzzleHttp\Psr7\UploadedFile(\GuzzleHttp\Psr7\Utils::streamFor('jpeg'), 4, UPLOAD_ERR_OK, 'next.jpg', 'image/jpeg');
        try {
            $items->post($params);
            self::fail('Conflicting upload MIME type accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $history);
        }
        $params->body->file = 'legacy bytes';
        $items->post($params);
        self::assertStringContainsString('filename="file.bin"', (string) $history[2]['request']->getBody());
    }

    public function testGeneratedBinaryDtosRejectUploadedObjectsInJson(): void
    {
        $body = new \Example\Multipart\DTOs\Form((object) ['name' => 'Ada', 'file' => new \GuzzleHttp\Psr7\UploadedFile(
            \GuzzleHttp\Psr7\Utils::streamFor('bytes'), 5, UPLOAD_ERR_OK, 'original.png', 'image/png')]);
        $this->expectException(\UnexpectedValueException::class);
        json_encode($body, JSON_THROW_ON_ERROR);
    }
}
