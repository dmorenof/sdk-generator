<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class RawMediaGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/raw-media-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function files(array $document): array { return (new PhpExecutableEmitter('Example\\RawSdk', 'example/raw'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/RawMediaAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private function setupClient(): array {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = []; public int $status = 200; public string $body = 'reply';
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response($this->status, [], $this->body); }
        };
        return [\Example\RawSdk\Client::connect('https://example.test', httpClient: $http), $http];
    }
    public function testJsonYamlAndOpenApi30GenerateTheSameRawContract(): void {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\RawSdk', 'example/raw'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3'; self::assertSame($files, self::files($document));
        self::assertStringContainsString('public string|\\Psr\\Http\\Message\\StreamInterface $body;', $files['src/PostParams/Binary/ById.php']);
    }
    public function testSwaggerTextBodyAndResponseUseRawCodec(): void {
        $document = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'Raw Swagger', 'version' => '1'], 'consumes' => ['text/plain'], 'produces' => ['text/plain'],
            'paths' => ['/text' => ['post' => ['operationId' => 'postText', 'parameters' => [['in' => 'body', 'name' => 'body', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 2]]],
                'responses' => ['200' => ['description' => 'Text', 'schema' => ['type' => 'string', 'minLength' => 2]]]]]]];
        $files = self::files($document);
        self::assertStringContainsString("'encoding' => 'text'", $files['src/Resources/Text.php']);
        self::assertStringContainsString("'responseMediaType' => 'text/plain'", $files['src/Resources/Text.php']);
        $document['paths']['/text']['post']['parameters'][0]['schema']['enum'] = ['open', 'closed'];
        $files = self::files($document);
        self::assertStringContainsString('public function post(string $payload)', $files['src/Resources/Text.php']);
        self::assertStringContainsString("'enum' => [", $files['src/Resources/Text.php']);
    }
    public function testRawBytesStreamingParameterLocationsAndMutations(): void {
        [$client, $http] = $this->setupClient();
        self::assertSame('reply', $client->resource(\Example\RawSdk\Resources\Text::class)->post("ñ text\n"));
        self::assertSame("ñ text\n", (string) $http->calls[0]->getBody()); self::assertSame('text/plain', $http->calls[0]->getHeaderLine('Accept'));
        $stream = \GuzzleHttp\Psr7\Utils::streamFor("\0\xffbytes"); $stream->seek(3);
        $params = new \Example\RawSdk\PostParams\Binary\ById((object) ['body' => $stream, 'queryBody' => 'separate', 'X-Test' => 'header']);
        $http->status = 204; $http->body = ''; $binary = $client->resource(\Example\RawSdk\Resources\Binary::class); $binary->postById(7, $params);
        self::assertSame($stream, $http->calls[1]->getBody()); self::assertSame("\0\xffbytes", (string) $http->calls[1]->getBody());
        self::assertSame('https://example.test/binary/7?body=separate', (string) $http->calls[1]->getUri()); self::assertSame('header', $http->calls[1]->getHeaderLine('X-Test'));
        $params->body = new \GuzzleHttp\Psr7\NoSeekStream(\GuzzleHttp\Psr7\Utils::streamFor('invalid retry'));
        try { $binary->postById(7, $params); self::fail('Non-seekable body reached HTTP.'); } catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
        $optional = $client->resource(\Example\RawSdk\Resources\Optional::class); $optional->post(); $optional->post('');
        self::assertFalse($http->calls[2]->hasHeader('Content-Type')); self::assertSame('text/plain', $http->calls[3]->getHeaderLine('Content-Type'));
        try { $optional->post(null); self::fail('Null was omitted.'); } catch (\UnexpectedValueException) { self::assertCount(4, $http->calls); }
    }
    public function testResponseCodecsAndConstraintsFollowTheSelectedStatus(): void {
        [$client, $http] = $this->setupClient(); $mixed = $client->resource(\Example\RawSdk\Resources\MixedResource::class);
        foreach ([[200, 'not JSON', 'not JSON'], [200, '', ''], [201, '7', 7], [204, '', null]] as [$status, $body, $expected]) {
            $http->status = $status; $http->body = $body; self::assertSame($expected, $mixed->get());
            self::assertSame('application/json, text/plain, application/octet-stream', $http->calls[array_key_last($http->calls)]->getHeaderLine('Accept'));
        }
        $http->status = 202; $http->body = "\0\xff"; self::assertSame("\0\xff", (string) $mixed->get()->getBody());
        $http->status = 200; $http->body = 'x'; $this->expectException(\UnexpectedValueException::class);
        $client->resource(\Example\RawSdk\Resources\Text::class)->post('valid');
    }
    public function testUnsupportedRawContractsStayExplicit(): void {
        foreach (['binaryRules', 'nullableText', 'ambiguous'] as $case) {
            $document = self::document();
            if ($case === 'binaryRules') { $document['components']['schemas']['BinaryData']['minLength'] = 1; }
            elseif ($case === 'nullableText') { $document['components']['schemas']['Text']['nullable'] = true; }
            else { $document['paths']['/optional']['post']['requestBody']['content']['application/octet-stream'] = ['schema' => ['type' => 'string', 'format' => 'binary']]; }
            try { self::files($document); self::fail('Unsupported raw contract accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
