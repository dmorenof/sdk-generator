<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ResponseSelectionGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/response-selection-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\SelectionSdk', 'example/selection'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SelectionAPISDK.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); } }
    }
    public function testVersionsAliasesJsonYamlAndNativeUnions(): void {
        $document = self::document(); $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SelectionSdk', 'example/selection'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3'; self::assertSame($expected, self::files($document));
        self::assertStringContainsString('DTOs\\Exact|\\Psr\\Http\\Message\\ResponseInterface|\\Example\\SelectionSdk\\DTOs\\Range|null', $expected['src/Resources/Responses.php']);
        self::assertStringContainsString("400 => NULL", $expected['src/Resources/Responses.php']);
        self::assertStringContainsString("'2XX' =>", $expected['src/Resources/Responses.php']);
        $document['components']['responses']['Range'] = $document['paths']['/responses']['get']['responses']['2XX'];
        $document['paths']['/responses']['get']['responses']['2XX'] = ['$ref' => '#/components/responses/Range'];
        self::assertSame($expected, self::files($document));
    }
    public function testExactRangeDefaultAndEmptyPrecedenceWithRawErrors(): void {
        foreach ([200 => '{"name":"exact"}', 201 => "\x00\xffbinary", 204 => '', 299 => '{"name":"range"}', 400 => '', 404 => '{"name":"problem"}', 500 => '{"city":"Madrid"}'] as $status => $body) {
            $http = new class($status, $body) implements \Psr\Http\Client\ClientInterface {
                public function __construct(private int $status, private string $body) {}
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                    TestCase::assertSame('application/json, application/octet-stream', $request->getHeaderLine('Accept'));
                    return new \GuzzleHttp\Psr7\Response($this->status, ['X-Result' => 'preserved'], $this->body);
                }
            };
            $resource = \Example\SelectionSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\SelectionSdk\Resources\Responses::class);
            try {
                $result = $resource->get(new \Example\SelectionSdk\GetParams\Responses((object) ['status' => $status]));
                self::assertLessThan(400, $status);
                if ($status === 201) {
                    self::assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
                    self::assertSame(0, $result->getBody()->tell()); self::assertSame($body, (string) $result->getBody());
                } elseif ($status === 204) { self::assertNull($result); }
                else { self::assertInstanceOf($status === 200 ? \Example\SelectionSdk\DTOs\Exact::class : \Example\SelectionSdk\DTOs\Range::class, $result); }
            } catch (\Dmorenof\SdkRuntime\ApiException $error) {
                self::assertSame($status, $error->getCode()); self::assertSame($body, $error->response->body);
                if ($status === 400) { self::assertNull($error->error); self::assertNull($error->getPrevious()); }
                else { self::assertInstanceOf($status === 404 ? \Example\SelectionSdk\DTOs\Problem::class : \Example\SelectionSdk\DTOs\Fallback::class, $error->error); }
            }
        }
    }
    public function testInvalidSelectedSchemaDoesNotTryAnotherBranchAndPreservesErrorResponse(): void {
        foreach ([200, 299, 404, 500] as $status) {
            $http = new class($status) implements \Psr\Http\Client\ClientInterface {
                public function __construct(private int $status) {}
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \GuzzleHttp\Psr7\Response($this->status, [], '{}'); }
            };
            $resource = \Example\SelectionSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\SelectionSdk\Resources\Responses::class);
            try { $resource->get(new \Example\SelectionSdk\GetParams\Responses((object) ['status' => $status])); self::fail('Invalid selected schema accepted.'); }
            catch (\Dmorenof\SdkRuntime\ApiException $error) { self::assertGreaterThanOrEqual(400, $status); self::assertSame('{}', $error->response->body); self::assertNotNull($error->getPrevious()); }
            catch (\UnexpectedValueException) { self::assertLessThan(400, $status); }
        }
    }
    public function testOtherErrorRangesAndInlineDefaultOnlySchemas(): void {
        $d = self::document();
        foreach (['1XX', '3XX', '5XX'] as $range) {
            $d['paths']['/responses']['get']['responses'][$range] = $d['paths']['/responses']['get']['responses']['4XX'];
        }
        $files = self::files($d);
        foreach (['1XX', '3XX', '5XX'] as $range) { self::assertStringContainsString("'" . $range . "' =>", $files['src/Resources/Responses.php']); }
        $schema = $d['components']['schemas']['Fallback'];
        $d['paths']['/fallback']['get']['responses']['default']['content']['application/json']['schema'] = $schema;
        self::assertNotEmpty(self::files($d));
        $swagger = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'Test', 'version' => '1'], 'paths' => [
            '/items' => ['get' => ['responses' => ['default' => ['description' => 'Fallback', 'schema' => $schema]]]]]];
        self::assertNotEmpty(self::files($swagger));
    }
    public function testMalformedRangesAndSwaggerRangesAreRejectedAndBinaryErrorsAreDeclared(): void {
        foreach (['2xx', '20X', '6XX', '2000'] as $status) {
            $d = self::document(); $d['paths']['/responses']['get']['responses'][$status] = ['description' => 'Invalid'];
            try { self::files($d); self::fail('Invalid status accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $d = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'Test', 'version' => '1'], 'paths' => ['/items' => ['get' => ['responses' => ['2XX' => ['description' => 'Invalid']]]]]];
        try { self::files($d); self::fail('Swagger range accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        $d = self::document(); $d['paths']['/responses']['get']['responses']['4XX'] = $d['paths']['/responses']['get']['responses']['201'];
        $files = self::files($d); self::assertStringContainsString("'errorResponses' => [", $files['src/Resources/Responses.php']);
    }
}
