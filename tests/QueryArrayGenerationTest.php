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

class QueryProbeTransport implements TransportInterface
{
    public array $urls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->urls[] = $url;
        return new HttpResponse(204, '');
    }
}

final class QueryArrayGenerationTest extends TestCase
{
    private static function document(string $fixture = 'query-arrays-openapi31.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter('Example\\QueryLists', 'example/query-lists'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/GetParams/Items.php', 'src/Resources/Items.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public function testOpenApiAndSwaggerEmitEquivalentNativeListSdks(): void
    {
        $openapi = self::files(self::document());
        self::assertSame($openapi, self::files(self::document('query-arrays-swagger2.json')));
        self::assertStringContainsString('public array $ids;', $openapi['src/GetParams/Items.php']);
        self::assertStringContainsString('@var list<int>', $openapi['src/GetParams/Items.php']);
        self::assertSame([], array_values(array_filter(array_keys($openapi), static fn ($path): bool => str_starts_with($path, 'src/Collections/'))));
        $source = json_encode(self::document(), JSON_THROW_ON_ERROR);
        self::assertTrue((new DocumentInspector())->inspect($source)['strictlyAccepted']);
        self::assertTrue((new DocumentInspector())->inspect(Yaml::dump(self::document(), 30), 'yaml')['strictlyAccepted']);
    }

    public function testActualGeneratedMethodBuildsRepeatedAndCsvQueryWithEncodedItems(): void
    {
        $transport = new QueryProbeTransport();
        $client = \Example\QueryLists\Client::connect('https://api.example.test/v1', transport: $transport);
        $params = new \Example\QueryLists\GetParams\Items((object) [
            'ids' => [1, 2], 'codes' => ['a,b', 'x y'], 'flags' => [true, false], 'weights' => [0.5, 2], 'q' => 'ñ &+',
        ]);
        $client->resource(\Example\QueryLists\Resources\Items::class)->get($params);
        self::assertSame('https://api.example.test/v1/items?codes=a%2Cb,x%20y&flags=true&flags=false&ids=1&ids=2&q=%C3%B1%20%26%2B&weights=0.5&weights=2', $transport->urls[0]);
    }

    public function testOptionalEmptyListsAndNullOmitButScalarEmptyStringIsSent(): void
    {
        $transport = new QueryProbeTransport();
        $client = \Example\QueryLists\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\QueryLists\Resources\Items::class)->get(new \Example\QueryLists\GetParams\Items((object) [
            'ids' => [1], 'codes' => [], 'flags' => null, 'q' => '',
        ]));
        self::assertSame('https://api.example.test/v1/items?ids=1&q=', $transport->urls[0]);
    }

    public function testDefaultPsr18TransportPreservesEncodedItemsAndRepeatedNames(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\QueryLists\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->resource(\Example\QueryLists\Resources\Items::class)->get(new \Example\QueryLists\GetParams\Items((object) ['ids' => [1, 2], 'codes' => ['a,b', 'x y']]));
        self::assertSame('https://api.example.test/v1/items?codes=a%2Cb,x%20y&ids=1&ids=2', (string) $history[0]['request']->getUri());
    }

    public function testArrayBoundsItemTypesEnumsAndMutationFailBeforeHttp(): void
    {
        $transport = new QueryProbeTransport();
        $client = \Example\QueryLists\Client::connect('https://api.example.test', transport: $transport);
        foreach ([['ids' => []], ['ids' => [1, 2, 3, 4]], ['ids' => [0]], ['ids' => ['1']],
            ['ids' => [1], 'codes' => ['invalid']], ['ids' => [1], 'flags' => ['false']],
            ['ids' => [1], 'weights' => [-1]]] as $data) {
            try {
                $client->resource(\Example\QueryLists\Resources\Items::class)->get(new \Example\QueryLists\GetParams\Items((object) $data));
                self::fail('Invalid query list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->urls);
            }
        }
        $params = new \Example\QueryLists\GetParams\Items((object) ['ids' => [1]]);
        $params->ids[] = 'wrong';
        try {
            $client->resource(\Example\QueryLists\Resources\Items::class)->get($params);
            self::fail('Invalid mutation accepted.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('ids[1]', $exception->getMessage());
            self::assertSame([], $transport->urls);
        }
    }

    public function testUnsupportedLocationsShapesAndStylesRemainStrictFailures(): void
    {
        foreach ([['in', 'path'], ['style', 'deepObject'], ['allowReserved', true], ['allowEmptyValue', true]] as [$key, $value]) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][0][$key] = $value;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        $document = self::document();
        $document['paths']['/items']['get']['parameters'][0]['in'] = 'header';
        self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        $document['paths']['/items']['get']['parameters'][0]['style'] = 'form';
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        foreach ([['type' => 'object'], ['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'string', 'nullable' => true], ['type' => 'string', 'enum' => []]] as $items) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][0]['schema']['items'] = $items;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        $document = self::document('query-arrays-swagger2.json');
        $document['paths']['/items']['get']['parameters'][0]['collectionFormat'] = 'invalid';
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testNullableArrayBranchesKeepTheirItemAndSizeConstraints(): void
    {
        $document = self::document();
        $schema = $document['paths']['/items']['get']['parameters'][0]['schema'];
        $document['paths']['/items']['get']['parameters'][0]['schema'] = ['anyOf' => [$schema, ['type' => 'null']]];
        $files = self::files($document);
        self::assertStringContainsString('public ?array $ids;', $files['src/GetParams/Items.php']);
        self::assertStringContainsString("'maxItems' => 3", $files['src/GetParams/Items.php']);
        self::assertStringContainsString("'minimum' => 1", $files['src/GetParams/Items.php']);
    }
}
