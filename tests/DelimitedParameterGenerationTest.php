<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DelimitedParameterGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/delimited-parameters-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\DelimitedSdk', 'example/delimited'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public function testJsonYamlAliasesAndLocationMetadata(): void {
        $document = self::document(); $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\DelimitedSdk', 'example/delimited'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $resource = $expected['src/Resources/Items/BySpaces/ByTabs.php'];
        foreach (["'spaces' => 'ssv'", "'tabs' => 'tsv'", "'pipes' => 'pipes'", "'X-Spaces' => 'ssv'", "'X-Tabs' => 'tsv'", "'X-Pipes' => 'pipes'"] as $metadata) {
            self::assertStringContainsString($metadata, $resource);
        }
        $path = '/items/{spaces}/{tabs}/{pipes}';
        $document['parameters']['Spaces'] = $document['paths'][$path]['get']['parameters'][0];
        $document['parameters']['Alias'] = ['$ref' => '#/parameters/Spaces'];
        $document['paths'][$path]['get']['parameters'][0] = ['$ref' => '#/parameters/Alias'];
        self::assertSame($expected, self::files($document));
    }
    public function testUnsupportedFormatsAndOpenApiCollectionFormatAreRejected(): void {
        foreach (['path', 'header'] as $location) {
            foreach (['multi', 'invalid', false] as $format) {
                $d = self::document(); $index = $location === 'path' ? 0 : 3;
                $d['paths']['/items/{spaces}/{tabs}/{pipes}']['get']['parameters'][$index]['collectionFormat'] = $format;
                try { self::files($d); self::fail('Unsupported format accepted.'); }
                catch (\InvalidArgumentException) { self::assertTrue(true); }
            }
        }
        $d = json_decode(file_get_contents(__DIR__ . '/fixtures/simple-parameters-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $d['paths']['/items/{ids}']['get']['parameters'][0]['collectionFormat'] = 'ssv';
        $this->expectException(\InvalidArgumentException::class); self::files($d);
    }
    public function testGeneratedGetPostDtosPreserveLocationsAndRejectMutations(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SimpleAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $path = new \Example\DelimitedSdk\PathParams\Items\BySpaces\ByTabs\ByPipes((object) array_fill_keys(['spaces', 'tabs', 'pipes'], ['a/b', '%2F', 'ñ']));
        $headers = array_fill_keys(['X-Spaces', 'X-Tabs', 'X-Pipes'], ['alpha', 'beta']);
        $get = new \Example\DelimitedSdk\GetParams\Items\BySpaces\ByTabs\ByPipes((object) ($headers + ['body' => 'query body']));
        $post = new \Example\DelimitedSdk\PostParams\Items\BySpaces\ByTabs\ByPipes((object) ($headers + ['queryBody' => 'query body', 'body' => (object) ['name' => 'Ada']]));
        $resource = \Example\DelimitedSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\DelimitedSdk\Resources\Items\BySpaces\ByTabs::class);
        $resource->getByPipes($path, $get); $resource->postByPipes($path, $post);
        foreach ($http->calls as $call) {
            self::assertSame('https://example.test/items/a%2Fb%20%252F%20%C3%B1/a%2Fb%09%252F%09%C3%B1/a%2Fb%7C%252F%7C%C3%B1?body=query%20body', (string) $call->getUri());
            self::assertSame('alpha beta', $call->getHeaderLine('X-Spaces'));
            self::assertSame("alpha\tbeta", $call->getHeaderLine('X-Tabs'));
            self::assertSame('alpha|beta', $call->getHeaderLine('X-Pipes'));
        }
        self::assertSame('', (string) $http->calls[0]->getBody());
        self::assertSame('{"name":"Ada"}', (string) $http->calls[1]->getBody());
        foreach (['spaces' => 'a b', 'tabs' => "a\tb", 'pipes' => 'a|b'] as $field => $value) {
            $path->$field = [$value];
            try { $resource->postByPipes($path, $post); self::fail('Ambiguous path mutation accepted.'); }
            catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
            $path->$field = ['valid'];
        }
        foreach (['XSpaces' => 'a b', 'XTabs' => "a\tb", 'XPipes' => 'a|b'] as $field => $value) {
            $post->$field = [$value];
            try { $resource->postByPipes($path, $post); self::fail('Ambiguous header mutation accepted.'); }
            catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
            $post->$field = ['valid'];
        }
        $post->body->name = '';
        try { $resource->postByPipes($path, $post); self::fail('Invalid body mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
    }
}
