<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\ApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ErrorPayloadsGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/error-payloads-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function files(array $document): array { return (new PhpExecutableEmitter('Example\\ErrorPayloadSdk', 'example/errors'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/ErrorPayloadAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private function error(int $status, string $body): ApiException {
        $http = new class($status, $body) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private int $status, private string $body) {}
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                TestCase::assertSame('application/json, text/plain, application/octet-stream', $request->getHeaderLine('Accept'));
                TestCase::assertSame('/errors/' . $this->status, $request->getUri()->getPath());
                return new \GuzzleHttp\Psr7\Response($this->status, ['X-Trace' => 'trace'], $this->body);
            }
        };
        try { \Example\ErrorPayloadSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\ErrorPayloadSdk\Resources\Errors::class)->getById($status); self::fail('HTTP error became success.'); }
        catch (ApiException $error) { self::assertSame($status, $error->getCode()); self::assertSame($body, $error->response->body); return $error; }
    }
    public function testJsonYamlAndOpenApi30PreserveGeneralErrorDescriptors(): void {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\ErrorPayloadSdk', 'example/errors'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3'; $document['paths']['/errors/{id}']['get']['responses']['403']['content']['application/json']['schema'] = ['type' => 'boolean', 'nullable' => true];
        self::assertSame($files, self::files($document));
        self::assertStringContainsString("'errorResponses' => [", $files['src/Resources/Errors.php']);
    }
    public function testScalarNullableEnumTextAndBinaryErrorsAreDecodedWithoutLosingHttp(): void {
        foreach ([[400, '"issue"', 'issue'], [403, 'false', false], [403, 'null', null], [405, '"denied"', \Example\ErrorPayloadSdk\Enums\Error\Code::Denied], [406, 'plain error', 'plain error'], [407, "\0\xffbin", "\0\xffbin"], [422, '7', 7]] as [$status, $body, $expected]) {
            $error = $this->error($status, $body); self::assertSame($expected, $error->payload); self::assertNull($error->error); self::assertNull($error->getPrevious());
        }
    }
    public function testCollectionsMapsCompositionsAndLegacyDtoErrorsReuseRuntime(): void {
        $list = $this->error(401, '[1,2]'); self::assertSame([1, 2], iterator_to_array($list->payload)); self::assertNull($list->error);
        $map = $this->error(402, '{"one":1}'); self::assertInstanceOf(\Dmorenof\SdkRuntime\Map::class, $map->payload); self::assertSame(1, $map->payload['one']);
        $composition = $this->error(409, '"many"'); self::assertInstanceOf(\Dmorenof\SdkRuntime\OneOf::class, $composition->payload); self::assertSame(['many'], $composition->payload->matchedVariants());
        $dto = $this->error(500, '{"code":500,"message":"failed"}'); self::assertInstanceOf(\Dmorenof\SdkRuntime\DTO::class, $dto->error); self::assertSame($dto->error, $dto->payload); self::assertSame(500, $dto->error->code); self::assertNull($dto->getPrevious());
        $bodyless = $this->error(404, 'not JSON'); self::assertNull($bodyless->payload); self::assertNull($bodyless->getPrevious());
    }
    public function testInvalidDeclaredPayloadRetainsRawFailureAndDecodingCause(): void {
        foreach ([[400, '"x"', \UnexpectedValueException::class], [401, '[1,-1]', \UnexpectedValueException::class], [400, 'bad JSON', \JsonException::class], [406, 'x', \UnexpectedValueException::class], [409, '""', \UnexpectedValueException::class]] as [$status, $body, $cause]) {
            $error = $this->error($status, $body); self::assertNull($error->payload); self::assertNull($error->error); self::assertInstanceOf($cause, $error->getPrevious());
        }
    }
    public function testSwaggerBinaryErrorsUseBytesAndRetainBodylessSuccess(): void {
        $document = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'Error API', 'version' => '1'], 'produces' => ['application/octet-stream'],
            'paths' => ['/errors' => ['get' => ['operationId' => 'getError', 'responses' => ['204' => ['description' => 'Empty'], '400' => ['description' => 'Binary', 'schema' => ['type' => 'file']]]]]]];
        $files = self::files($document); self::assertStringContainsString("'mediaType' => 'application/octet-stream'", $files['src/Resources/Errors.php']);
        self::assertStringContainsString("'type' => 'string'", $files['src/Resources/Errors.php']);
    }
}
