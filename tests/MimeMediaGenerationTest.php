<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MimeMediaGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/mime-media-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function files(array $document): array { return (new PhpExecutableEmitter('Example\\MimeMediaSdk', 'example/mime-media'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/MIMEMediaAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = []; public int $status = 200; public string $body = 'reply';
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response($this->status, [], $this->body); }
        };
        return [\Example\MimeMediaSdk\Client::connect('https://example.test', httpClient: $http), $http];
    }
    public function testJsonYamlAndOpenApi30RetainTheSameMediaContract(): void {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\MimeMediaSdk', 'example/mime-media'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3'; self::assertSame($files, self::files($document));
    }
    public function testCsvXmlAndVendorJsonUseTheirOwnBytesAndHeaders(): void {
        [$client, $http] = $this->setupClient();
        $http->body = "a,b\n3,4\n"; self::assertSame($http->body, $client->resource(\Example\MimeMediaSdk\Resources\Csv::class)->post("a,b\n1,2\n"));
        self::assertSame("a,b\n1,2\n", (string) $http->calls[0]->getBody()); self::assertSame('text/csv; charset=utf-8', $http->calls[0]->getHeaderLine('Content-Type')); self::assertSame('text/csv; charset=utf-8', $http->calls[0]->getHeaderLine('Accept'));
        $http->body = '<reply/>'; self::assertSame('<reply/>', $client->resource(\Example\MimeMediaSdk\Resources\Xml::class)->post('<input>ñ</input>'));
        self::assertSame('<input>ñ</input>', (string) $http->calls[1]->getBody()); self::assertSame('application/xml', $http->calls[1]->getHeaderLine('Content-Type'));
        $http->body = '{"ok":true}'; self::assertTrue($client->resource(\Example\MimeMediaSdk\Resources\Problem::class)->post(new \Example\MimeMediaSdk\DTOs\InputModel((object) ['value' => 7]))->ok);
        self::assertSame('application/vnd.example+json', $http->calls[2]->getHeaderLine('Content-Type')); self::assertSame('application/problem+json', $http->calls[2]->getHeaderLine('Accept')); self::assertSame('{"value":7}', (string) $http->calls[2]->getBody());
        try { $client->resource(\Example\MimeMediaSdk\Resources\Xml::class)->post('not XML'); self::fail('Raw string pattern was ignored.'); } catch (\UnexpectedValueException) { self::assertCount(3, $http->calls); }
    }
    public function testBinaryAliasesPreservePsrStreamsAndDifferentResponseMimes(): void {
        [$client, $http] = $this->setupClient(); $http->status = 201; $http->body = "\0\xffpng";
        $stream = \GuzzleHttp\Psr7\Utils::streamFor("\0\xffupload"); $stream->seek(3);
        $params = new \Example\MimeMediaSdk\PostParams\Image\ById((object) ['body' => $stream, 'queryBody' => 'separate', 'X-Test' => 'header']);
        $result = $client->resource(\Example\MimeMediaSdk\Resources\Image::class)->postById(7, $params);
        self::assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result); self::assertSame($http->body, (string) $result->getBody());
        self::assertSame($stream, $http->calls[0]->getBody()); self::assertSame("\0\xffupload", (string) $http->calls[0]->getBody()); self::assertSame('body=separate', $http->calls[0]->getUri()->getQuery());
        self::assertSame('image/png', $http->calls[0]->getHeaderLine('Content-Type')); self::assertSame('application/problem+json, image/png', $http->calls[0]->getHeaderLine('Accept'));
        $pdf = $client->resource(\Example\MimeMediaSdk\Resources\Pdf::class);
        foreach ([[200, '%PDF bytes'], [201, '<svg/>']] as [$status, $bytes]) { $http->status = $status; $http->body = $bytes; self::assertSame($bytes, (string) $pdf->get()->getBody()); }
        self::assertSame('application/pdf, image/svg+xml, application/xml', $http->calls[1]->getHeaderLine('Accept'));
    }
    public function testMixedStatusCodecsAndRawErrorsRetainValidation(): void {
        [$client, $http] = $this->setupClient(); $mixed = $client->resource(\Example\MimeMediaSdk\Resources\MixedResource::class);
        foreach ([[200, '<xml/>', '<xml/>'], [201, '7', 7], [204, '', null]] as [$status, $body, $expected]) { $http->status = $status; $http->body = $body; self::assertSame($expected, $mixed->get()); }
        $http->status = 202; $http->body = '%PDF bytes'; self::assertSame($http->body, (string) $mixed->get()->getBody());
        self::assertSame('application/problem+json, application/xml, application/pdf, text/csv, image/png', $http->calls[0]->getHeaderLine('Accept'));
        foreach ([[406, "error,csv\n"], [409, "\0\xfferror"]] as [$status, $body]) {
            $http->status = $status; $http->body = $body;
            try { $mixed->get(); self::fail('HTTP failure became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { self::assertSame($body, $error->payload); self::assertNull($error->getPrevious()); }
        }
    }
    public function testSwaggerFileMimeAndUnsupportedStructuredRawContracts(): void {
        $document = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'MIME API', 'version' => '1'], 'produces' => ['image/png'], 'paths' => ['/image' => ['get' => ['responses' => ['200' => ['description' => 'Image', 'schema' => ['type' => 'file']]]]]]];
        self::assertStringContainsString("'responseMediaType' => 'image/png'", self::files($document)['src/Resources/Image.php']);
        foreach (['wildcard', 'object', 'ambiguous'] as $case) {
            $document = self::document();
            if ($case === 'wildcard') { $document['paths']['/csv']['post']['requestBody']['content'] = ['text/*' => ['schema' => ['type' => 'string']]]; }
            elseif ($case === 'object') { $document['paths']['/xml']['post']['requestBody']['content']['application/xml']['schema'] = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]; }
            else { $document['paths']['/xml']['post']['requestBody']['content']['text/csv'] = ['schema' => ['type' => 'string']]; }
            try { self::files($document); self::fail('Unsupported raw contract accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
