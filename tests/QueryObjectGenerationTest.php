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

final class DeepQueryTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class QueryObjectGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/query-objects-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter('Example\\DeepQuery', 'example/deep-query'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        foreach (self::files(self::document()) as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }

    public function testTypedObjectsWorkAcrossJsonYamlAndOpenApiVersions(): void
    {
        $document = self::document();
        $expected = self::files($document);
        self::assertStringContainsString('public ?\\Example\\DeepQuery\\DTOs\\Filter $filter;', $expected['src/GetParams/Items.php']);
        self::assertStringContainsString('public const bool OPERATION_BODY = true;', $expected['src/PostParams/Items.php']);
        self::assertStringNotContainsString('OPERATION_BODY', $expected['src/GetParams/Items.php']);
        self::assertStringContainsString('$queryBody;', $expected['src/PostParams/Items.php']);
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\DeepQuery', 'example/deep-query'))->emit($contract));
        $document['openapi'] = '3.0.3';
        self::assertSame($expected, self::files($document));
    }

    public function testRequiredReferenceToNullableObjectPreservesNullability(): void
    {
        $document = self::document();
        $document['components']['schemas']['Filter']['type'] = ['object', 'null'];
        $document['paths']['/items']['get']['parameters'][0]['required'] = true;
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        foreach ((new PhpExecutableEmitter('Example\\NullableDeepQuery', 'example/nullable-query'))->emit($contract) as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, strlen('<?php')));
            }
        }
        $transport = new DeepQueryTransport();
        $client = \Example\NullableDeepQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\NullableDeepQuery\Resources\Items::class)->get(new \Example\NullableDeepQuery\GetParams\Items((object) ['filter' => null]));
        self::assertSame('https://api.example.test/v1/items', $transport->calls[0]['url']);
    }

    public function testGetQueryBodyNameIsNotSentAsAJsonRequestBody(): void
    {
        $transport = new DeepQueryTransport();
        $client = \Example\DeepQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $params = new \Example\DeepQuery\GetParams\Items((object) [
            'body' => (object) ['city-name' => 'Madrid'],
            'filter' => (object) ['city-name' => 'ñ &+', 'active' => false, 'state' => 'open', 'internal' => 'hidden', 'secret' => 'yes'],
            'options' => (object) ['sort' => 'a,b|c', '0' => 'zero'],
        ]);
        $client->resource(\Example\DeepQuery\Resources\Items::class)->get($params);
        self::assertSame('https://api.example.test/v1/items?body%5Bcity-name%5D=Madrid&filter%5Bactive%5D=false&filter%5Bcity-name%5D=%C3%B1%20%26%2B&filter%5Bsecret%5D=yes&filter%5Bstate%5D=open&options%5Bsort%5D=a%2Cb%7Cc&options%5B0%5D=zero', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
    }

    public function testPostParamsKeepQueryBodyAndJsonBodySeparateThroughStaticFacade(): void
    {
        $transport = new DeepQueryTransport();
        $configuration = new \Example\DeepQuery\Configuration();
        $configuration->baseUrl = 'https://api.example.test/v1';
        $configuration->transport = $transport;
        \Example\DeepQuery\Client::configure($configuration);
        $params = new \Example\DeepQuery\PostParams\Items((object) [
            'filter' => (object) ['city-name' => 'Madrid'],
            'queryBody' => (object) ['city-name' => 'Barcelona'],
            'body' => (object) ['filter' => 'json-body'], 'X-Trace' => 'trace-123',
        ]);
        \Example\DeepQuery\Items::post($params);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertSame('https://api.example.test/v1/items?body%5Bcity-name%5D=Barcelona&filter%5Bcity-name%5D=Madrid', $transport->calls[0]['url']);
        self::assertSame('{"filter":"json-body"}', $transport->calls[0]['body']);
        self::assertSame('application/json', $transport->calls[0]['headers']['Content-Type']);
        self::assertSame('trace-123', $transport->calls[0]['headers']['X-Trace']);
    }

    public function testPsr18PreservesDeepBracketsAndPostJsonBytes(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\DeepQuery\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $params = new \Example\DeepQuery\PostParams\Items((object) [
            'filter' => (object) ['city-name' => 'Madrid'], 'body' => (object) ['filter' => 'json'],
        ]);
        $client->resource(\Example\DeepQuery\Resources\Items::class)->post($params);
        self::assertSame('https://api.example.test/v1/items?filter%5Bcity-name%5D=Madrid', (string) $history[0]['request']->getUri());
        self::assertSame('{"filter":"json"}', (string) $history[0]['request']->getBody());
    }

    public function testAbsentNullAndEmptyMapOmitQueryButEmptyStringValueIsExplicit(): void
    {
        $transport = new DeepQueryTransport();
        $client = \Example\DeepQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $resource = $client->resource(\Example\DeepQuery\Resources\Items::class);
        $resource->get(new \Example\DeepQuery\GetParams\Items((object) ['filter' => null, 'options' => (object) []]));
        self::assertSame('https://api.example.test/v1/items', $transport->calls[0]['url']);
        $params = new \Example\DeepQuery\GetParams\Items((object) ['filter' => (object) ['city-name' => 'Madrid', 'secret' => '']]);
        $resource->get($params);
        self::assertStringEndsWith('&filter%5Bsecret%5D=', $transport->calls[1]['url']);
    }

    public function testInvalidEnumsRulesUnknownFieldsAndMapMutationsFailBeforeHttp(): void
    {
        $transport = new DeepQueryTransport();
        $client = \Example\DeepQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        foreach ([['filter' => (object) []], ['filter' => (object) ['city-name' => '']],
            ['filter' => (object) ['city-name' => 'Madrid', 'state' => 'unknown']],
            ['filter' => (object) ['city-name' => 'Madrid', 'unknown' => 'x']],
            ['filter' => (object) ['city-name' => 'Madrid', 'active' => null]]] as $data) {
            try {
                $client->resource(\Example\DeepQuery\Resources\Items::class)->get(new \Example\DeepQuery\GetParams\Items((object) $data));
                self::fail('Invalid filter accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\DeepQuery\GetParams\Items((object) ['filter' => (object) ['city-name' => 'Madrid']]);
        $params->filter->limit = 0;
        try {
            $client->resource(\Example\DeepQuery\Resources\Items::class)->get($params);
            self::fail('Invalid object mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
        foreach ([(object) ['sort' => ''], (object) ['a[b]' => 'x']] as $options) {
            try {
                $params = new \Example\DeepQuery\GetParams\Items((object) ['options' => $options]);
                $client->resource(\Example\DeepQuery\Resources\Items::class)->get($params);
                self::fail('Invalid query map accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->calls);
            }
        }
    }

    public function testUndefinedDeepObjectSchemasStylesAndNamesAreRejected(): void
    {
        foreach ([['explode' => false], ['explode' => null], ['in' => 'header'], ['style' => 'form'], ['name' => 'filter[x]'],
            ['schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'object']], 'additionalProperties' => false]],
            ['schema' => ['type' => 'object', 'additionalProperties' => true]],
            ['schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]]] as $changes) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][0] = array_replace($document['paths']['/items']['get']['parameters'][0], $changes);
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
    }

    public function testReusableParameterAndInlineObjectRetainTypedSerialization(): void
    {
        $document = self::document();
        $document['components']['parameters']['Filter'] = $document['paths']['/items']['get']['parameters'][0];
        $document['paths']['/items']['get']['parameters'][0] = ['$ref' => '#/components/parameters/Filter'];
        self::assertSame(self::files(self::document()), self::files($document));
        $document = self::document();
        $document['paths']['/items']['get']['parameters'][0]['schema'] = $document['components']['schemas']['Filter'];
        $files = self::files($document);
        self::assertStringContainsString("'deepObject'", $files['src/Resources/Items.php']);
        self::assertGreaterThan(0, count(array_filter(array_keys($files), static fn ($path): bool => str_contains($path, '/Query/'))));
    }
}
