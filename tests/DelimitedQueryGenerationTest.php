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

final class DelimitedQueryTransport implements TransportInterface
{
    public array $urls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->urls[] = $url;
        return new HttpResponse(204, '');
    }
}

final class DelimitedQueryGenerationTest extends TestCase
{
    private static function document(string $dialect = 'openapi31'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/query-delimited-' . $dialect . '.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document, string $namespace = 'Example\\DelimitedQuery'): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter($namespace, 'example/delimited-query'))->emit($contract);
    }

    private static function load(array $files): void
    {
        foreach (['src/Client.php', 'src/GetParams/Items.php', 'src/Resources/Items.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::load(self::files(self::document()));
    }

    public function testDialectsDefaultsExplicitFalseAndYamlProduceEquivalentSdks(): void
    {
        $document = self::document();
        $expected = self::files($document);
        self::assertSame($expected, self::files(self::document('swagger2')));
        foreach ($document['paths']['/items']['get']['parameters'] as &$parameter) {
            if (isset($parameter['style'])) {
                $parameter['explode'] = false;
            }
        }
        unset($parameter);
        self::assertSame($expected, self::files($document));
        $document['openapi'] = '3.0.3';
        $document['paths']['/items']['get']['parameters'][4]['schema'] = ['type' => 'string', 'nullable' => true];
        self::assertSame($expected, self::files($document));
        foreach (['openapi31', 'swagger2'] as $dialect) {
            $report = (new DocumentInspector())->inspect(Yaml::dump(self::document($dialect), 30), 'yaml');
            self::assertTrue($report['strictlyAccepted']);
        }
    }

    public function testGeneratedOperationAndPsr18SendLegalEncodedSeparators(): void
    {
        $params = new \Example\DelimitedQuery\GetParams\Items((object) [
            'ids' => [1, 2], 'codes' => ['a,b', 'ñ+&', '%7C'], 'flags' => [true, false], 'weights' => [0.5, 2], 'q' => 'a b|c',
        ]);
        $expected = 'https://api.example.test/v1/items?codes=a%2Cb%7C%C3%B1%2B%26%7C%257C&flags=true%20false&ids=1%202&q=a%20b%7Cc&weights=0.5%7C2';
        $transport = new DelimitedQueryTransport();
        $client = \Example\DelimitedQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\DelimitedQuery\Resources\Items::class)->get($params);
        self::assertSame([$expected], $transport->urls);
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\DelimitedQuery\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->resource(\Example\DelimitedQuery\Resources\Items::class)->get($params);
        self::assertSame($expected, (string) $history[0]['request']->getUri());
    }

    public function testEmptyListsNullAndEmptyStringItemsKeepTheirExpansionRules(): void
    {
        $transport = new DelimitedQueryTransport();
        $client = \Example\DelimitedQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $resource = $client->resource(\Example\DelimitedQuery\Resources\Items::class);
        $resource->get(new \Example\DelimitedQuery\GetParams\Items((object) ['ids' => [1], 'codes' => [], 'q' => null]));
        $resource->get(new \Example\DelimitedQuery\GetParams\Items((object) ['ids' => [1], 'codes' => ['', 'x'], 'flags' => null]));
        self::assertSame(['https://api.example.test/v1/items?ids=1', 'https://api.example.test/v1/items?codes=%7Cx&ids=1'], $transport->urls);
    }

    public function testAmbiguousValuesAndMutationsAreRejectedBeforeTransport(): void
    {
        $transport = new DelimitedQueryTransport();
        $client = \Example\DelimitedQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $resource = $client->resource(\Example\DelimitedQuery\Resources\Items::class);
        $params = new \Example\DelimitedQuery\GetParams\Items((object) ['ids' => [1], 'codes' => ['a']]);
        foreach (['a|b', 'x|'] as $value) {
            $params->codes[0] = $value;
            try {
                $resource->get($params);
                self::fail('Ambiguous pipe value accepted.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString('codes', $exception->getMessage());
                self::assertSame([], $transport->urls);
            }
        }
        $params->codes = ['a'];
        $params->ids[] = 0;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ids[1]');
        $resource->get($params);
    }

    public function testUndefinedExplodeAndUnsupportedShapesRemainDiagnosed(): void
    {
        foreach (['spaceDelimited', 'pipeDelimited'] as $style) {
            foreach ([['explode' => true], ['in' => 'header'], ['schema' => ['type' => 'object']],
                ['schema' => ['type' => 'array', 'items' => ['type' => 'string', 'nullable' => true]]]] as $changes) {
                $document = self::document();
                $parameter = &$document['paths']['/items']['get']['parameters'][0];
                $parameter = array_replace($parameter, ['style' => $style], $changes);
                unset($parameter);
                $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
                self::assertFalse($report['strictlyAccepted']);
                self::assertContains('parameter_style', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
            }
        }
    }

    public function testSwaggerTsvExecutesAndRejectsAmbiguousTabValues(): void
    {
        $document = self::document('swagger2');
        $document['paths']['/items']['get']['parameters'][1]['collectionFormat'] = 'tsv';
        self::load(self::files($document, 'Example\\TabQuery'));
        $transport = new DelimitedQueryTransport();
        $client = \Example\TabQuery\Client::connect('https://api.example.test/v1', transport: $transport);
        $resource = $client->resource(\Example\TabQuery\Resources\Items::class);
        $params = new \Example\TabQuery\GetParams\Items((object) ['ids' => [1], 'codes' => ['a b|c', 'ñ']]);
        $resource->get($params);
        self::assertSame(['https://api.example.test/v1/items?codes=a%20b%7Cc%09%C3%B1&ids=1'], $transport->urls);
        $params->codes[] = "a\tb";
        try {
            $resource->get($params);
            self::fail('Ambiguous tab value accepted.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('codes', $exception->getMessage());
            self::assertCount(1, $transport->urls);
        }
    }

    public function testReferencedDelimitedParameterPreservesSerialization(): void
    {
        $document = self::document();
        $document['components']['parameters']['Ids'] = $document['paths']['/items']['get']['parameters'][0];
        $document['paths']['/items']['get']['parameters'][0] = ['$ref' => '#/components/parameters/Ids'];
        self::assertSame(self::files(self::document()), self::files($document));
    }
}
