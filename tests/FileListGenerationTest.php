<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FileListGenerationTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class FileListGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/multipart-file-lists-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\FileLists', 'example/file-lists'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/FileListsAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }
    private static function upload(string $bytes = "\x00\xff", string $name = 'first.png', string $mime = 'image/png'): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, $mime);
    }

    public function testVersionsJsonYamlAndNativeListDeclarations(): void
    {
        $document = self::document();
        $expected = self::files($document);
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\FileLists', 'example/file-lists'))->emit($contract));
        $document['openapi'] = '3.0.3';
        foreach (['Form', 'OptionalForm'] as $name) {
            $document['components']['schemas'][$name]['properties']['note'] = ['type' => 'string', 'nullable' => true];
        }
        $document['components']['schemas']['OptionalForm']['properties']['attachments']['type'] = 'array';
        $document['components']['schemas']['OptionalForm']['properties']['attachments']['nullable'] = true;
        self::assertSame($expected, self::files($document));
        self::assertStringContainsString('public array $attachments;', $expected['src/DTOs/Form.php']);
        self::assertStringContainsString('@var list<string|\\Psr\\Http\\Message\\UploadedFileInterface>', $expected['src/DTOs/Form.php']);
        self::assertStringContainsString('public ?array $attachments;', $expected['src/DTOs/OptionalForm.php']);
    }

    public function testGetPostQueryHeadersAndRepeatedFilePartsKeepIndependentValues(): void
    {
        $transport = new FileListGenerationTransport();
        $config = new \Example\FileLists\Configuration();
        $config->baseUrl = 'https://api.example.test/v1';
        $config->transport = $transport;
        \Example\FileLists\Client::configure($config);
        \Example\FileLists\Items::get(new \Example\FileLists\GetParams\Items((object) ['name' => 'query +']));
        $params = new \Example\FileLists\PostParams\Items((object) [
            'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
            'body' => (object) ['name' => 'body name', 'attachments' => [self::upload(), self::upload("pdf\x00", 'second.pdf', 'application/pdf')]],
        ]);
        \Example\FileLists\Items::post($params);
        self::assertNull($transport->calls[0]['body']);
        self::assertSame('https://api.example.test/v1/items?name=query%20%2B', $transport->calls[0]['url']);
        $call = $transport->calls[1];
        self::assertSame('https://api.example.test/v1/items?body=query-body&name=query%20name', $call['url']);
        self::assertSame('trace', $call['headers']['X-Trace']);
        self::assertSame(2, substr_count($call['body'], 'name="attachments"'));
        self::assertStringNotContainsString('attachments[', $call['body']);
        self::assertStringContainsString('Content-Type: image/png', $call['body']);
        self::assertStringContainsString('Content-Type: application/pdf', $call['body']);
        self::assertStringContainsString("\r\n\r\n\x00\xff\r\n", $call['body']);
        self::assertStringContainsString("\r\n\r\npdf\x00\r\n", $call['body']);
        self::assertLessThan(strpos($call['body'], 'filename="second.pdf"'), strpos($call['body'], 'filename="first.png"'));
        $params->body->attachments[1] = "raw\x00\xff";
        \Example\FileLists\Items::post($params);
        self::assertStringContainsString('filename="attachments.bin"', $transport->calls[2]['body']);
        self::assertStringContainsString("\r\n\r\nraw\x00\xff\r\n", $transport->calls[2]['body']);
    }

    public function testOptionalAbsentNullEmptyListsAndEmptyFiles(): void
    {
        $transport = new FileListGenerationTransport();
        $client = \Example\FileLists\Client::connect('https://api.example.test/v1', transport: $transport);
        $optional = $client->resource(\Example\FileLists\Resources\Optional::class);
        $optional->post();
        foreach ([null, [], ['']] as $value) {
            $optional->post(new \Example\FileLists\DTOs\OptionalForm((object) ['attachments' => $value]));
        }
        self::assertNull($transport->calls[0]['body']);
        foreach ([1, 2] as $index) {
            self::assertMatchesRegularExpression('/^--[a-f0-9]+--\r\n$/', $transport->calls[$index]['body']);
        }
        self::assertSame(1, substr_count($transport->calls[3]['body'], 'name="attachments"'));
        $client->resource(\Example\FileLists\Resources\BodyOnly::class)->post(
            new \Example\FileLists\DTOs\Form((object) ['name' => 'Ada', 'attachments' => ['']]));
        self::assertStringContainsString('filename="attachments.bin"', $transport->calls[4]['body']);
    }

    public function testRequiredItemsBoundsAndMutationsAreValidatedBeforeHttp(): void
    {
        $transport = new FileListGenerationTransport();
        $items = \Example\FileLists\Client::connect('https://api.example.test/v1', transport: $transport)->resource(\Example\FileLists\Resources\Items::class);
        foreach ([[], [null], [1], ['key' => 'bytes'], [[1]], ['a', 'b', 'c']] as $value) {
            try {
                $items->post(new \Example\FileLists\PostParams\Items((object) ['body' => (object) ['name' => 'Ada', 'attachments' => $value]]));
                self::fail('Invalid file list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\FileLists\PostParams\Items((object) ['body' => (object) ['name' => 'Ada', 'attachments' => [self::upload()]]]);
        $params->body->attachments[] = null;
        try {
            $items->post($params);
            self::fail('Invalid file list mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
        unset($params->body->attachments);
        $this->expectException(\UnexpectedValueException::class);
        $items->post($params);
    }

    public function testUnsupportedItemsEncodingAndSwaggerFileArraysStayDiagnosed(): void
    {
        foreach ([['type' => ['string', 'null'], 'format' => 'binary'], ['type' => 'string', 'format' => 'binary', 'minLength' => 1],
            ['type' => 'string', 'format' => 'binary', 'enum' => ['x']], ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']],
            ['type' => 'integer', 'format' => 'binary']] as $item) {
            $document = self::document();
            $document['components']['schemas']['Form']['properties']['attachments']['items'] = $item;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        foreach ([['explode' => false], ['headers' => []], ['style' => 'deepObject']] as $encoding) {
            $document = self::document();
            $document['paths']['/items']['post']['requestBody']['content']['multipart/form-data']['encoding']['attachments'] = $encoding;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        foreach ([['type' => 'file'], ['type' => 'string', 'format' => 'binary']] as $item) {
            $swagger = json_decode(file_get_contents(__DIR__ . '/fixtures/multipart-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
            $swagger['parameters']['Ids']['items'] = $item;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($swagger, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
    }

    public function testJsonOnlyCollectionsRemainUnchangedAndInlineMultipartIsNative(): void
    {
        $document = self::document();
        foreach ($document['paths'] as &$path) {
            if (isset($path['post']['requestBody'])) {
                $content = &$path['post']['requestBody']['content'];
                $content = ['application/json' => $content['multipart/form-data']];
                unset($content['application/json']['encoding']);
            }
        }
        unset($path, $content);
        $jsonFiles = self::files($document);
        self::assertStringContainsString('StringCollection $attachments;', $jsonFiles['src/DTOs/Form.php']);
        self::assertStringNotContainsString('public array $attachments;', $jsonFiles['src/DTOs/Form.php']);
        $document = self::document();
        $document['paths']['/items']['post']['requestBody']['content']['multipart/form-data']['schema'] = $document['components']['schemas']['Form'];
        self::assertStringContainsString('public array $attachments;', self::files($document)['src/Requests/Items/Post.php']);
    }

    public function testInheritedNativeListsRetainItemValidationWithoutRedeclaringFields(): void
    {
        self::assertSame(\Example\FileLists\DTOs\Form::class,
            (new \ReflectionProperty(\Example\FileLists\DTOs\ZChildForm::class, 'attachments'))->getDeclaringClass()->getName());
        $body = new \Example\FileLists\DTOs\ZChildForm((object) ['name' => 'Ada', 'attachments' => ['bytes']]);
        self::assertSame(['bytes'], $body->attachments);
        $body->attachments[] = 1;
        $this->expectException(\UnexpectedValueException::class);
        $body->validateForRequest();
    }
}
