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

final class SwaggerFormTransport implements TransportInterface
{
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new HttpResponse(204, '');
    }
}

final class SwaggerFormGenerationTest extends TestCase
{
    private static function document(string $fixture = 'forms-swagger2.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function files(array $document): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true);
        return (new PhpExecutableEmitter('Example\\SwaggerForms', 'example/swagger-forms'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        $first = ['src/Client.php', 'src/Configuration.php', 'src/SwaggerFormsSDK.php'];
        foreach ($first as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php') && !in_array($path, $first, true)) {
                eval(substr($source, strlen('<?php')));
            }
        }
    }

    public function testSwaggerAndOpenApiFormsEmitTheSameSdkIncludingYaml(): void
    {
        $expected = self::files(self::document('forms-equivalent-openapi31.json'));
        self::assertSame($expected, self::files(self::document()));
        $contract = (new SpecificationNormalizer())->fromYaml(Yaml::dump(self::document(), 40), strict: true);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\SwaggerForms', 'example/swagger-forms'))->emit($contract));
        self::assertArrayNotHasKey('src/PostParams/BodyOnly.php', $expected);
    }

    public function testGetParamsIgnoreConsumesWithoutFormFieldsAndRemainQuery(): void
    {
        $transport = new SwaggerFormTransport();
        $client = \Example\SwaggerForms\Client::connect('https://api.example.test/v1', transport: $transport);
        $client->resource(\Example\SwaggerForms\Resources\Items::class)->get(new \Example\SwaggerForms\GetParams\Items((object) ['name' => 'get +']));
        self::assertSame('https://api.example.test/v1/items?name=get%20%2B', $transport->calls[0]['url']);
        self::assertNull($transport->calls[0]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
    }

    public function testPostParamsUseSwaggerCsvDefaultAndExplicitMultiWithIndependentQuery(): void
    {
        $transport = new SwaggerFormTransport();
        $configuration = new \Example\SwaggerForms\Configuration();
        $configuration->baseUrl = 'https://api.example.test/v1';
        $configuration->transport = $transport;
        \Example\SwaggerForms\Client::configure($configuration);
        $params = new \Example\SwaggerForms\PostParams\Items((object) [
            'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
            'body' => (object) ['name' => 'body name', 'body' => 'form-body', 'active' => false,
                'city-name' => 'ñ +~%20', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2], 'note' => null, 'state' => 'new'],
        ]);
        \Example\SwaggerForms\Items::post($params);
        self::assertSame('https://api.example.test/v1/items?body=query-body&name=query%20name', $transport->calls[0]['url']);
        self::assertSame('active=false&body=form-body&city-name=%C3%B1+%2B%7E%2520&codes=a%2Cb,x+y&ids=1&ids=2&name=body+name&state=new', $transport->calls[0]['body']);
        self::assertSame('application/x-www-form-urlencoded', $transport->calls[0]['headers']['Content-Type']);
        self::assertSame('trace', $transport->calls[0]['headers']['X-Trace']);
    }

    public function testPsr18GetsExactSwaggerFormBodyBytes(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(204)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = \Example\SwaggerForms\Client::connect('https://api.example.test/v1', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->resource(\Example\SwaggerForms\Resources\Items::class)->post(new \Example\SwaggerForms\PostParams\Items((object) [
            'name' => 'query', 'body' => (object) ['name' => 'Ada +', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2]],
        ]));
        self::assertSame('https://api.example.test/v1/items?name=query', (string) $history[0]['request']->getUri());
        self::assertSame('codes=a%2Cb,x+y&ids=1&ids=2&name=Ada+%2B', (string) $history[0]['request']->getBody());
        self::assertSame('application/x-www-form-urlencoded', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function testOptionalFormsAndOperationOverridesPreserveRequiredAndEmptyValues(): void
    {
        $transport = new SwaggerFormTransport();
        $client = \Example\SwaggerForms\Client::connect('https://api.example.test/v1', transport: $transport);
        $optional = $client->resource(\Example\SwaggerForms\Resources\Optional::class);
        $optional->post();
        $optional->post(new \Example\SwaggerForms\Requests\Optional\Post((object) ['note' => null, 'labels' => []]));
        $optional->post(new \Example\SwaggerForms\Requests\Optional\Post((object) ['note' => '']));
        $client->resource(\Example\SwaggerForms\Resources\Overrides::class)->post();
        $client->resource(\Example\SwaggerForms\Resources\Overrides::class)->post(new \Example\SwaggerForms\Requests\Overrides\Post((object) ['name' => 'A']));
        self::assertSame([null, '', 'note=', null, 'name=A'], array_column($transport->calls, 'body'));
        self::assertArrayNotHasKey('Content-Type', $transport->calls[0]['headers']);
        self::assertSame('application/x-www-form-urlencoded', $transport->calls[1]['headers']['Content-Type']);
    }

    public function testConstraintsEnumsRequiredAndMutationsFailBeforeTransport(): void
    {
        $transport = new SwaggerFormTransport();
        $client = \Example\SwaggerForms\Client::connect('https://api.example.test/v1', transport: $transport);
        foreach ([(object) [], (object) ['name' => ''], (object) ['name' => 'Ada', 'state' => 'invalid'],
            (object) ['name' => 'Ada', 'ids' => [0]]] as $body) {
            try {
                $client->resource(\Example\SwaggerForms\Resources\Items::class)->post(new \Example\SwaggerForms\PostParams\Items((object) ['body' => $body]));
                self::fail('Invalid Swagger form accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException|\ValueError) {
                self::assertSame([], $transport->calls);
            }
        }
        $params = new \Example\SwaggerForms\PostParams\Items((object) ['body' => (object) ['name' => 'Ada']]);
        $params->body->name = '';
        try {
            $client->resource(\Example\SwaggerForms\Resources\Items::class)->post($params);
            self::fail('Invalid mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $transport->calls);
        }
    }

    public function testConsumesOverrideAndReusableAliasesRemainExecutable(): void
    {
        $document = self::document();
        $document['consumes'] = ['application/json'];
        foreach ($document['paths'] as &$path) {
            if (isset($path['post'])) {
                $path['post']['consumes'] = ['application/x-www-form-urlencoded'];
            }
        }
        unset($path);
        self::assertSame(self::files(self::document()), self::files($document));
        $document['parameters']['NameAlias']['required'] = false; // Swagger Reference Object siblings do not replace the target.
        self::assertSame(self::files(self::document()), self::files($document));
    }

    public function testUnsupportedFilesFormatsAndMediaKeepPreciseDiagnostics(): void
    {
        foreach ([['type' => 'file'], ['type' => 'object'], ['type' => 'array', 'items' => ['type' => 'string'], 'collectionFormat' => 'unknown'],
            ['type' => 'array', 'items' => ['type' => 'string'], 'collectionFormat' => null],
            ['type' => 'array', 'items' => ['type' => 'string', 'x-nullable' => true]], ['type' => 'string', 'collectionFormat' => 'csv'],
            ['type' => 'string', 'required' => 'false'], ['type' => 'string', 'allowEmptyValue' => 'true']] as $changes) {
            $document = self::document();
            $document['parameters']['Name'] = array_replace($document['parameters']['Name'], $changes);
            $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertFalse($report['strictlyAccepted']);
            self::assertContains('/parameters/Name/type', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
        }
        foreach ([[], ['application/json'], ['application/x-www-form-urlencoded', 'application/json']] as $media) {
            $document = self::document();
            $document['consumes'] = $media;
            $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
            self::assertFalse($report['strictlyAccepted']);
            self::assertContains('swagger_form_media', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
        }
        $document = self::document();
        $document['paths']['/items']['post']['consumes'] = null;
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('/paths/~1items/post/consumes', array_map(static fn ($issue): string => $issue->pointer, $report['diagnostics']));
    }

    public function testBodyAndFormDataConflictCannotGenerateAnSdk(): void
    {
        $document = self::document();
        $document['paths']['/items']['post']['parameters'][] = ['name' => 'json', 'in' => 'body', 'schema' => ['type' => 'object']];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('swagger_form_body_conflict', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
    }
}
