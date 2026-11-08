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

final class SwaggerMultipartTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class SwaggerMultipartGenerationTest extends TestCase
{
    private static function document(string $fixture = 'multipart-swagger2.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter('Example\\SwaggerMultipart', 'example/swagger-multipart'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SwaggerMultipartSDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }

    public function testSwaggerAndOpenApiProduceByteIdenticalSdkIncludingYaml(): void
    {
        $expected = self::files(self::document('multipart-equivalent-openapi31.json'));
        self::assertSame($expected, self::files(self::document()));
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump(self::document(), 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SwaggerMultipart', 'example/swagger-multipart'))->emit($contract));
        self::assertStringContainsString('public string $file;', $expected['src/Requests/Items/Post.php']);
    }

    public function testGetPostParamsAndBinaryFilesUseExistingMultipartExecutor(): void
    {
        $transport = new SwaggerMultipartTransport();
        $configuration = new \Example\SwaggerMultipart\Configuration();
        $configuration->baseUrl = 'https://api.example.test/v1';
        $configuration->transport = $transport;
        \Example\SwaggerMultipart\Client::configure($configuration);
        \Example\SwaggerMultipart\Items::get(new \Example\SwaggerMultipart\GetParams\Items((object) ['name' => 'get +']));
        \Example\SwaggerMultipart\Items::post(new \Example\SwaggerMultipart\PostParams\Items((object) [
            'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
            'body' => (object) ['name' => 'body name', 'file' => "\x00\xff\r\n", 'active' => false,
                'body' => 'form body', 'city-name' => 'ñ +%~', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2], 'note' => null, 'state' => 'new'],
        ]));
        self::assertSame('https://api.example.test/v1/items?name=get%20%2B', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
        $call = $transport->calls[1];
        self::assertSame('https://api.example.test/v1/items?body=query-body&name=query%20name', $call['url']);
        self::assertSame('trace', $call['headers']['X-Trace']);
        self::assertStringContainsString('name="file"; filename="file.bin"', $call['body']);
        self::assertStringContainsString('Content-Type: application/octet-stream', $call['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n\r\n", $call['body']);
        self::assertStringContainsString("\r\n\r\nñ +%~\r\n", $call['body']);
        self::assertStringContainsString("\r\n\r\nfalse\r\n", $call['body']);
        self::assertSame(2, substr_count($call['body'], 'name="codes"'));
        self::assertSame(2, substr_count($call['body'], 'name="ids"'));
        self::assertStringNotContainsString('name="note"', $call['body']);
    }

    public function testOptionalNullableEmptyFilesAndEffectiveOverrides(): void
    {
        $transport = new SwaggerMultipartTransport();
        $client = \Example\SwaggerMultipart\Client::connect('https://api.example.test/v1', transport: $transport);
        $optional = $client->resource(\Example\SwaggerMultipart\Resources\Optional::class);
        $optional->post();
        $optional->post(new \Example\SwaggerMultipart\Requests\Optional\Post((object) ['file' => null, 'labels' => []]));
        $optional->post(new \Example\SwaggerMultipart\Requests\Optional\Post((object) ['file' => '']));
        self::assertNull($transport->calls[0]['body']);
        self::assertMatchesRegularExpression('/^--[a-f0-9]+--\r\n$/', $transport->calls[1]['body']);
        self::assertStringContainsString('filename="file.bin"', $transport->calls[2]['body']);
        $overrides = $client->resource(\Example\SwaggerMultipart\Resources\Overrides::class);
        $overrides->post();
        $overrides->post(new \Example\SwaggerMultipart\Requests\Overrides\Post((object) ['name' => 'A']));
        self::assertNull($transport->calls[3]['body']);
        self::assertStringNotContainsString('name="file"', $transport->calls[4]['body']);
        $client->resource(\Example\SwaggerMultipart\Resources\BodyOnly::class)->post(
            new \Example\SwaggerMultipart\Requests\BodyOnly\Post((object) ['name' => 'Ada', 'file' => '']));
        self::assertStringContainsString('filename="file.bin"', $transport->calls[5]['body']);
    }

    public function testRequiredFilesConstraintsAndMutationsFailBeforeHttp(): void
    {
        $transport = new SwaggerMultipartTransport();
        $items = \Example\SwaggerMultipart\Client::connect('https://api.example.test/v1', transport: $transport)->resource(\Example\SwaggerMultipart\Resources\Items::class);
        foreach ([(object) ['name' => 'Ada'], (object) ['name' => 'Ada', 'file' => null],
            (object) ['name' => '', 'file' => ''], (object) ['name' => 'Ada', 'file' => '', 'state' => 'invalid'],
            (object) ['name' => 'Ada', 'file' => '', 'ids' => [0]]] as $body) {
            try {
                $items->post(new \Example\SwaggerMultipart\PostParams\Items((object) ['body' => $body]));
                self::fail('Invalid multipart payload accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\SwaggerMultipart\PostParams\Items((object) ['body' => (object) ['name' => 'Ada', 'file' => 'bytes', 'ids' => [1]]]);
        unset($params->body->file);
        try {
            $items->post($params);
            self::fail('Required file removal accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
        $params->body->file = 'new bytes';
        try {
            $params->body->ids[] = 0;
            $items->post($params);
            self::fail('Invalid list mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testReusableFilesAreCheckedInEachOperationsMediaContext(): void
    {
        $document = self::document();
        $document['consumes'] = ['application/x-www-form-urlencoded'];
        foreach ($document['paths'] as &$path) {
            if (isset($path['post'])) {
                $path['post']['consumes'] = ['multipart/form-data'];
            }
        }
        unset($path);
        self::assertSame(self::files(self::document()), self::files($document));
        $document['paths']['/items']['post']['consumes'] = ['application/x-www-form-urlencoded'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('/parameters/File/type', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
    }

    public function testUnsupportedFileOptionsMultipartCsvAndMediaAreRejected(): void
    {
        foreach ([['minLength' => 1], ['enum' => ['a']], ['format' => 'binary'], ['collectionFormat' => 'multi'],
            ['default' => 'bytes'], ['required' => 'true'], ['x-nullable' => 'true'], ['name' => 'bad"name']] as $changes) {
            $document = self::document();
            $document['parameters']['File'] = array_replace($document['parameters']['File'], $changes);
            $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertFalse($report['strictlyAccepted']);
            self::assertContains('/parameters/File/type', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
        }
        foreach ([null, 'csv', 'ssv', 'tsv', 'pipes'] as $format) {
            $document = self::document();
            if ($format === null) {
                unset($document['parameters']['Ids']['collectionFormat']);
            } else {
                $document['parameters']['Ids']['collectionFormat'] = $format;
            }
            $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertFalse($report['strictlyAccepted']);
            self::assertContains('/parameters/Ids/type', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
        }
        foreach ([[], null, ['application/json'], ['multipart/form-data', 'application/x-www-form-urlencoded']] as $media) {
            $document = self::document();
            $document['consumes'] = $media;
            $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertFalse($report['strictlyAccepted']);
            self::assertContains('swagger_form_media', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
        }
        $document = self::document();
        $document['parameters']['Ids']['items'] = ['type' => 'file'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('/parameters/Ids/type', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
        $document = self::document();
        $document['paths']['/items']['post']['parameters'][] = ['name' => 'json', 'in' => 'body', 'schema' => ['type' => 'object']];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('swagger_form_body_conflict', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
    }
}
