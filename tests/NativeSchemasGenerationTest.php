<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class NativeSchemasGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/native-schemas-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\NativeSdk', 'example/native'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/NativeSchemasAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private static function record(): object {
        return (object) ['id' => 1.0, 'value' => 'valid', 'open' => (object) ['nested' => [true, null]], 'nullOnly' => null, 'items' => [1, 'text', null], 'empty' => []];
    }
    public function testNativeAliasesUnionsBooleanNodesAndJsonYaml(): void {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\NativeSdk', 'example/native'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        self::assertStringContainsString('public string|int|bool|null $value;', $files['src/DTOs/Record.php']);
        self::assertStringContainsString('public int $id;', $files['src/DTOs/Record.php']);
        self::assertStringContainsString('public mixed $open;', $files['src/DTOs/Record.php']);
        self::assertStringContainsString('public null $nullOnly;', $files['src/DTOs/Record.php']);
        foreach (['Choice', 'Identifier', 'IdentifierAlias', 'OpenValue', 'Impossible'] as $name) { self::assertArrayNotHasKey('src/DTOs/' . $name . '.php', $files); }
        self::assertStringContainsString('@return list<mixed>', $files['src/Resources/Items.php']);
    }
    public function testPrimitiveKindsBooleanSchemasAndArrayItemGuards(): void {
        foreach (['text', 7, true, null] as $value) {
            $data = self::record(); $data->value = $value;
            $record = new \Example\NativeSdk\DTOs\Record($data);
            self::assertSame($value, $record->value); self::assertSame(1, $record->id);
            self::assertSame([], $record->empty_);
        }
        foreach (['x', -1, 1.5, [], (object) []] as $invalid) {
            $data = self::record(); $data->value = $invalid;
            try { new \Example\NativeSdk\DTOs\Record($data); self::fail('Invalid native union value accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        foreach ([null, true, 0, []] as $invalid) {
            $data = self::record(); $data->rejected = $invalid;
            try { new \Example\NativeSdk\DTOs\Record($data); self::fail('False schema property accepted.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        }
        $data = self::record(); $data->empty = [null];
        try { new \Example\NativeSdk\DTOs\Record($data); self::fail('False item schema accepted a value.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
        $record = new \Example\NativeSdk\DTOs\Record(self::record()); $record->items[] = (object) [];
        $this->expectException(\UnexpectedValueException::class); $record->jsonSerializeForRequest();
    }
    public function testScalarAliasesAndUnionParametersRetainConstraintsAndLocations(): void {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { $this->calls[] = $request; return new \GuzzleHttp\Psr7\Response(200, [], '"result"'); }
        };
        $params = new \Example\NativeSdk\GetParams\Param\ById((object) ['value' => false, 'json' => 1.0, 'plain' => 'hello', 'X-Alias' => 'ab', 'session' => 'ab']);
        $resource = \Example\NativeSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\NativeSdk\Resources\Param::class);
        self::assertSame('result', $resource->getById(1, $params));
        parse_str($http->calls[0]->getUri()->getQuery(), $query);
        self::assertSame(['value' => 'false', 'json' => '1', 'plain' => 'hello'], array_replace(['value' => null, 'json' => null, 'plain' => null], $query));
        self::assertSame('ab', $http->calls[0]->getHeaderLine('X-Alias'));
        self::assertSame('session=ab', $http->calls[0]->getHeaderLine('Cookie'));
        $params->value = null; $resource->getById(1, $params);
        parse_str($http->calls[1]->getUri()->getQuery(), $query); self::assertArrayNotHasKey('value', $query);
        $params->XAlias = 'x';
        try { $resource->getById(1, $params); self::fail('Alias constraint mutation reached HTTP.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
    }
    public function testRootBooleanAndUnionResponsesRetainOriginalJsonKinds(): void {
        foreach ([['value', '"text"', 'text'], ['value', '7', 7], ['value', 'true', true], ['value', 'null', null], ['any', '{"nested":[1]}', (object) ['nested' => [1]]], ['items', '[1,"x",null]', [1, 'x', null]]] as [$route, $body, $expected]) {
            $http = new class($body) implements \Psr\Http\Client\ClientInterface {
                public function __construct(private string $body) {}
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \GuzzleHttp\Psr7\Response(200, [], $this->body); }
            };
            $client = \Example\NativeSdk\Client::connect('https://example.test', httpClient: $http);
            $class = 'Example\\NativeSdk\\Resources\\' . ucfirst($route);
            self::assertEquals($expected, $client->resource($class)->get());
        }
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \GuzzleHttp\Psr7\Response(200, [], 'null'); }
        };
        $this->expectException(\UnexpectedValueException::class);
        \Example\NativeSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\NativeSdk\Resources\NeverResource::class)->get();
    }
    public function testInvalidTypeListsAndOldDialectBooleanSchemasFail(): void {
        foreach ([[], ['string', 'string'], ['string', 'object'], ['integer', false]] as $types) {
            $document = self::document(); $document['components']['schemas']['Choice']['type'] = $types;
            try { self::files($document); self::fail('Invalid or unsupported union accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $document = self::document(); $document['openapi'] = '3.0.3';
        $this->expectException(\InvalidArgumentException::class); self::files($document);
    }
}
