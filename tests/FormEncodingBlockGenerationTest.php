<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FormEncodingBlockGenerationTest extends TestCase {
    private static function document(bool $swagger = false): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/form-block-' . ($swagger ? 'swagger2' : 'openapi31') . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document, bool $swagger = false): array {
        return (new PhpExecutableEmitter($swagger ? 'Example\\SwaggerFormBlockSdk' : 'Example\\FormBlockSdk', 'example/form-block'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        foreach ([false, true] as $swagger) {
            $files = self::files(self::document($swagger), $swagger);
            foreach (['src/Client.php', 'src/Configuration.php', $swagger ? 'src/SwaggerFormBlockAPISDK.php' : 'src/FormBlockAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
            foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
        }
    }
    public function testJsonYamlDialectsAndMetadataReuse(): void {
        foreach ([false, true] as $swagger) {
            $document = self::document($swagger); $expected = self::files($document, $swagger);
            self::assertSame($expected, (new PhpExecutableEmitter($swagger ? 'Example\\SwaggerFormBlockSdk' : 'Example\\FormBlockSdk', 'example/form-block'))->emit(
                (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
            if (!$swagger) { $document['openapi'] = '3.0.3'; self::assertSame($expected, self::files($document)); }
        }
        $files = self::files(self::document());
        foreach (["'deep' => 'deepObject'", "'expanded' => 'form-object-explode'", "'filter' => 'form-object'", "'space' => 'ssv'", "'pipe' => 'pipes'"] as $metadata) {
            self::assertStringContainsString($metadata, $files['src/Resources/Form.php']);
        }
        self::assertSame(3, substr_count($files['src/Resources/Multipart.php'], "'json' => true"));
        $swagger = self::files(self::document(true), true);
        foreach (['csv', 'multi', 'ssv', 'tsv', 'pipes'] as $format) { self::assertStringContainsString("'" . $format . "' => '" . $format . "'", $swagger['src/Resources/Swagger.php']); }
    }
    public function testReusableBodiesAndSwaggerParametersRetainFormats(): void {
        $document = self::document(); $expected = self::files($document);
        $document['components']['requestBodies']['Form'] = $document['paths']['/form']['post']['requestBody'];
        $document['paths']['/form']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/Form'];
        self::assertSame($expected, self::files($document));
        $document = self::document(true); $expected = self::files($document, true);
        $document['parameters']['Ssv'] = $document['paths']['/swagger']['post']['parameters'][4];
        $document['paths']['/swagger']['post']['parameters'][4] = ['$ref' => '#/parameters/Ssv'];
        self::assertSame($expected, self::files($document, true));
    }
    public function testFormObjectsDelimiterRulesAndQueryBodySeparation(): void {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(204); }
        };
        $params = new \Example\FormBlockSdk\PostParams\Form((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) [
            'body' => 'form body', 'space' => ['one', 'two'], 'pipe' => ['a/b', '%2F'], 'filter' => (object) ['code' => 'a/b', 'flag' => false],
            'expanded' => (object) ['city' => 'Madrid', 'zip' => 0], 'deep' => (object) ['name' => 'niño']]]);
        $resource = \Example\FormBlockSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\FormBlockSdk\Resources\Form::class);
        $resource->post($params);
        self::assertSame('body=query%20body', $http->calls[0]->getUri()->getQuery());
        self::assertSame('trace', $http->calls[0]->getHeaderLine('X-Trace'));
        self::assertSame('application/x-www-form-urlencoded', $http->calls[0]->getHeaderLine('Content-Type'));
        $actual = explode('&', (string) $http->calls[0]->getBody()); sort($actual);
        $expected = ['body=form+body', 'city=Madrid', 'deep%5Bname%5D=ni%C3%B1o', 'filter=code,a%2Fb,flag,false', 'pipe=a%2Fb%7C%252F', 'space=one+two', 'zip=0']; sort($expected);
        self::assertSame($expected, $actual);
        $params->body->filter->code = '';
        try { $resource->post($params); self::fail('Invalid nested mutation accepted.'); } catch (\UnexpectedValueException) { self::assertCount(1, $http->calls); }
        $params->body->filter->code = 'valid'; $params->body->space[] = 'a b';
        try { $resource->post($params); self::fail('Ambiguous list accepted.'); } catch (\UnexpectedValueException) { self::assertCount(1, $http->calls); }
    }
    public function testMultipartNestedJsonCollectionsReadOnlyAndMutations(): void {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(204); }
        };
        $profile = (object) ['id' => 7, 'code' => 'a/b', 'nested' => (object) ['flag' => false]];
        $params = new \Example\FormBlockSdk\PostParams\Multipart((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) [
            'profile' => $profile, 'batch' => [$profile, $profile], 'asJson' => 'quoted', 'document' => "\x00\xfffile", 'count' => 0]]);
        $resource = \Example\FormBlockSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\FormBlockSdk\Resources\Multipart::class);
        $resource->post($params); $mime = (string) $http->calls[0]->getBody();
        self::assertSame(3, substr_count($mime, '{"code":"a\/b","nested":{"flag":false}}'));
        self::assertStringNotContainsString('"id"', $mime);
        self::assertStringContainsString("\r\n\r\n\"quoted\"\r\n", $mime);
        self::assertStringContainsString("\r\n\r\n\x00\xfffile\r\n", $mime);
        self::assertSame(2, substr_count($mime, 'name="batch"'));
        $params->body->batch[0]->code = '';
        try { $resource->post($params); self::fail('Invalid JSON part mutation accepted.'); } catch (\UnexpectedValueException) { self::assertCount(1, $http->calls); }
    }
    public function testUnsupportedEncodingAndNestingFailVisibly(): void {
        foreach ([['space' => ['style' => 'spaceDelimited', 'explode' => true]], ['space' => ['style' => 'deepObject', 'explode' => true]], ['filter' => ['style' => 'spaceDelimited']], ['body' => ['style' => 'pipeDelimited']], ['filter' => ['headers' => ['X-Test' => []]]]] as $encoding) {
            $document = self::document(); $document['paths']['/form']['post']['requestBody']['content']['application/x-www-form-urlencoded']['encoding'] = $encoding;
            try { self::files($document); self::fail('Unsupported form encoding accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach (['text/plain', 'application/xml'] as $media) {
            $document = self::document(); $document['paths']['/multipart']['post']['requestBody']['content']['multipart/form-data']['encoding']['profile']['contentType'] = $media;
            try { self::files($document); self::fail('Complex non-JSON part accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $document = self::document(); $document['components']['schemas']['Flat']['properties']['nested'] = ['type' => 'object', 'additionalProperties' => false];
        $this->expectException(\InvalidArgumentException::class); self::files($document);
    }
}
