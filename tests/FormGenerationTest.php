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

final class FormGenerationTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class FormGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/forms-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document, string $namespace = 'Example\\Forms'): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter($namespace, 'example/forms'))->emit($contract);
    }

    private static function load(array $files): void
    {
        $first = ['src/Client.php', 'src/Configuration.php', 'src/FormAPISDK.php'];
        foreach ($first as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php') && !in_array($path, $first, true)) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::load(self::files(self::document()));
    }

    public function testOpenApiJsonYamlAndVersionsProduceEquivalentFormSdks(): void
    {
        $document = self::document();
        $expected = self::files($document);
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\Forms', 'example/forms'))->emit($contract));
        $document['openapi'] = '3.0.3';
        foreach (['Form', 'OptionalForm'] as $name) {
            $document['components']['schemas'][$name]['properties']['note'] = ['type' => 'string', 'nullable' => true];
        }
        self::assertSame($expected, self::files($document));
        self::assertArrayNotHasKey('src/PostParams/BodyOnly.php', $expected);
        self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testGetParamsRemainQueryAndDoNotBecomeFormFields(): void
    {
        $transport = new FormGenerationTransport();
        $client = \Example\Forms\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\Forms\Resources\Items::class)->get(new \Example\Forms\GetParams\Items((object) ['name' => 'get +~']));
        self::assertSame('https://api.example.test/v1/items?name=get%20%2B~', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
    }

    public function testPostParamsStaticFacadeKeepsQueryHeadersAndFormFieldsSeparate(): void
    {
        $transport = new FormGenerationTransport();
        $configuration = new \Example\Forms\Configuration();
        $configuration->baseUrl = 'https://api.example.test/v1';
        $configuration->transport = $transport;
        \Example\Forms\Client::configure($configuration);
        $params = new \Example\Forms\PostParams\Items((object) [
            'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
            'body' => (object) ['name' => 'body name', 'active' => false, 'body' => 'form-body',
                'city-name' => 'ñ +~%20', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2],
                'note' => null, 'internal' => 'hidden', 'secret' => '', 'state' => 'open'],
        ]);
        \Example\Forms\Items::post($params);
        self::assertSame('POST', $transport->calls[0]['method']);
        self::assertSame('https://api.example.test/v1/items?body=query-body&name=query%20name', $transport->calls[0]['url']);
        self::assertSame('active=false&body=form-body&city-name=%C3%B1+%2B%7E%2520&codes=a%2Cb,x+y&ids=1&ids=2&name=body+name&secret=&state=open', $transport->calls[0]['body']);
        self::assertSame('application/x-www-form-urlencoded', $transport->calls[0]['headers']['Content-Type']);
        self::assertSame('trace', $transport->calls[0]['headers']['X-Trace']);
    }

    public function testPsr18ReceivesExactFormBytesAndMediaType(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\Forms\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->resource(\Example\Forms\Resources\Items::class)->post(new \Example\Forms\PostParams\Items((object) [
            'name' => 'query', 'body' => (object) ['name' => 'Ada +', 'ids' => [1, 2]],
        ]));
        self::assertSame('https://api.example.test/v1/items?name=query', (string) $history[0]['request']->getUri());
        self::assertSame('ids=1&ids=2&name=Ada+%2B', (string) $history[0]['request']->getBody());
        self::assertSame('application/x-www-form-urlencoded', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function testOptionalAbsentEmptyNullAndEmptyStringFormsRemainDistinct(): void
    {
        $transport = new FormGenerationTransport();
        $client = \Example\Forms\Client::connect('https://api.example.test/v1', transport: $transport);
        $resource = $client->resource(\Example\Forms\Resources\Optional::class);
        $resource->post();
        $resource->post(new \Example\Forms\DTOs\OptionalForm());
        $resource->post(new \Example\Forms\DTOs\OptionalForm((object) ['note' => null]));
        $resource->post(new \Example\Forms\DTOs\OptionalForm((object) ['note' => '']));
        self::assertSame([null, '', '', 'note='], array_column($transport->calls, 'body'));
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
        self::assertSame('application/x-www-form-urlencoded', $transport->calls[1]['headers']['Content-Type']);
    }

    public function testBodyOnlyOperationsReuseTheirNamedFormDto(): void
    {
        $transport = new FormGenerationTransport();
        $client = \Example\Forms\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\Forms\Resources\BodyOnly::class)->post(new \Example\Forms\DTOs\Form((object) ['name' => 'Ada']));
        self::assertSame('name=Ada', $transport->calls[0]['body']);
        self::assertSame('https://api.example.test/v1/body-only', $transport->calls[0]['url']);
    }

    public function testRequiredFieldsEnumsAndListMutationsFailBeforeHttp(): void
    {
        $transport = new FormGenerationTransport();
        $client = \Example\Forms\Client::connect('https://api.example.test/v1', transport: $transport);
        foreach ([(object) [], (object) ['name' => ''], (object) ['name' => 'Ada', 'ids' => [0]],
            (object) ['name' => 'Ada', 'state' => 'unknown'], (object) ['name' => 'Ada', 'extra' => 1]] as $body) {
            try {
                $client->resource(\Example\Forms\Resources\Items::class)->post(new \Example\Forms\PostParams\Items((object) ['body' => $body]));
                self::fail('Invalid form accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\Forms\PostParams\Items((object) ['body' => (object) ['name' => 'Ada', 'ids' => [1]]]);
        try {
            $params->body->ids[] = 0;
            $client->resource(\Example\Forms\Resources\Items::class)->post($params);
            self::fail('Invalid form mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
        $params->body->name = '';
        try {
            $client->resource(\Example\Forms\Resources\Items::class)->post($params);
            self::fail('Invalid scalar form mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testUnsupportedFormSchemasAndEncodingAreStrictFailures(): void
    {
        foreach ([['style' => 'simple'], ['style' => 'deepObject'], ['explode' => 'true'], ['allowReserved' => true],
            ['contentType' => 'application/json'], ['headers' => ['X-Field' => ['schema' => ['type' => 'string']]]]] as $encoding) {
            $document = self::document();
            $document['paths']['/items']['post']['requestBody']['content']['application/x-www-form-urlencoded']['encoding']['codes'] = $encoding;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        foreach ([['type' => 'object', 'additionalProperties' => true], ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => 'object']]],
            ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => 'array', 'items' => ['type' => 'string', 'nullable' => true]]]]] as $schema) {
            $document = self::document();
            $document['components']['schemas']['Form'] = $schema;
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
        $document = self::document();
        $document['paths']['/items']['post']['requestBody']['content'] = ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/Form']]];
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testReusableBodiesInlineSchemasAndJsonPreferenceRemainExplicit(): void
    {
        $document = self::document();
        $document['components']['requestBodies']['FormBody'] = $document['paths']['/items']['post']['requestBody'];
        $document['paths']['/items']['post']['requestBody'] = ['$ref' => '#/components/requestBodies/FormBody'];
        self::assertSame(self::files(self::document()), self::files($document));
        $document = self::document();
        $document['paths']['/items']['post']['requestBody']['content']['application/x-www-form-urlencoded']['schema'] = $document['components']['schemas']['Form'];
        self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        $document = self::document();
        $document['paths']['/items']['post']['requestBody']['content']['application/json'] = ['schema' => ['$ref' => '#/components/schemas/Form']];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        $post = array_values(array_filter($contract->operations, static fn ($operation): bool => $operation->operationId === 'postItems'))[0];
        self::assertSame('application/json', $post->requestBody->mediaType);
        $document['paths']['/items']['post']['requestBody']['content']['application/vnd.example+json'] = $document['paths']['/items']['post']['requestBody']['content']['application/json'];
        unset($document['paths']['/items']['post']['requestBody']['content']['application/json']);
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testOmittedArrayEncodingDefaultsToRepeatedFields(): void
    {
        $document = self::document();
        unset($document['paths']['/items']['post']['requestBody']['content']['application/x-www-form-urlencoded']['encoding']['codes']);
        self::load(self::files($document, 'Example\\DefaultForms'));
        $transport = new FormGenerationTransport();
        $client = \Example\DefaultForms\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\DefaultForms\Resources\Items::class)->post(new \Example\DefaultForms\PostParams\Items((object) [
            'body' => (object) ['name' => 'Ada', 'codes' => ['a,b', 'x y']],
        ]));
        self::assertSame('codes=a%2Cb&codes=x+y&name=Ada', $transport->calls[0]['body']);
    }
}
