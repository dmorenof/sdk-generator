<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReservedQueryGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/reserved-query-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document, bool $strict = true): array
    {
        return (new PhpExecutableEmitter('Example\\ReservedSdk', 'example/reserved'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php','src/Configuration.php','src/ReservedQueryAPISDK.php'] as $path) {
            eval(substr($files[$path], 5));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, 5));
            }
        }
    }
    public function testJsonYamlVersionsReferencesAndFalseDefaultsPreserveMetadata(): void
    {
        $document = self::document();
        $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\ReservedSdk', 'example/reserved'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.4';
        self::assertSame($files, self::files($document));
        $parameter = $document['paths']['/items']['get']['parameters'][0];
        $document['components']['parameters']['Q'] = $parameter;
        $document['paths']['/items']['get']['parameters'][0] = ['$ref' => '#/components/parameters/Q'];
        self::assertSame($files, self::files($document));
        $document = self::document();
        foreach (['get','post'] as $verb) {
            foreach ($document['paths']['/items'][$verb]['parameters'] as &$parameter) {
                unset($parameter['allowReserved']);
            } unset($parameter);
        }
        $ordinary = self::files($document);
        foreach (['get','post'] as $verb) {
            foreach ($document['paths']['/items'][$verb]['parameters'] as &$parameter) {
                if ($parameter['in'] === 'query') {
                    $parameter['allowReserved'] = false;
                }
            } unset($parameter);
        }
        self::assertSame($ordinary, self::files($document));
        self::assertStringNotContainsString('queryAllowReserved', $ordinary['src/Resources/Items.php']);
        foreach (['form', 'deepObject'] as $style) {
            foreach ([true, false] as $explode) {
                if ($style === 'deepObject' && !$explode) {
                    continue;
                }
                $document = self::document();
                $document['paths']['/items']['get']['parameters'][4]['style'] = $style;
                $document['paths']['/items']['get']['parameters'][4]['explode'] = $explode;
                self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
            }
        }
    }
    public function testBothParameterDtosPreserveOrdinaryValuesAndCredentialEncoding(): void
    {
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public array $requests = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $configuration = new \Example\ReservedSdk\Configuration();
        $configuration->httpClient = $http;
        $configuration->securityCredentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => '%2F']);
        \Example\ReservedSdk\Client::configure($configuration);
        $values = ['q' => 'a/b%26c?x', 'body' => 'query/body', 'labels' => ['a/b', 'c?d'], 'filter' => (object) ['code' => 'x/y'], 'plain' => 'a/b', 'k' => 'ignored/value', 'X-Trace' => 'h/x', 'theme' => 'a/b'];
        $get = new \Example\ReservedSdk\GetParams\Items((object) $values);
        \Example\ReservedSdk\Items::get($get);
        $values['queryBody'] = $values['body'];
        $values['body'] = (object) ['name' => 'body/x'];
        $post = new \Example\ReservedSdk\PostParams\Items((object) $values);
        $resource = \Example\ReservedSdk\Client::connect($configuration->baseUrl, httpClient: $http, securityCredentials: $configuration->securityCredentials)->resource(\Example\ReservedSdk\Resources\Items::class);
        $resource->post($post);
        foreach ($http->requests as $request) {
            self::assertSame('https://example.test/items?body=query/body&filter%5Bcode%5D=x/y&k=%252F&labels=a/b&labels=c?d&plain=a%2Fb&q=a/b%26c?x', (string) $request->getUri());
            self::assertSame('h/x', $request->getHeaderLine('X-Trace'));
            self::assertSame('theme=a%2Fb', $request->getHeaderLine('Cookie'));
        }
        self::assertSame('{"name":"body\/x"}', (string) $http->requests[1]->getBody());
        $get->q = 'bad&extra=value';
        try {
            $resource->get($get);
            self::fail('Query structure changed.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
        $post->filter->code = 'bad#fragment';
        try {
            $resource->post($post);
            self::fail('Fragment reached HTTP.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
    }
    public function testUnsupportedLocationsDialectsAndFlagsStillFail(): void
    {
        foreach (['header','cookie','path'] as $location) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][0]['in'] = $location;
            foreach ([true, false] as $strict) {
                try {
                    self::files($document, $strict);
                    self::fail('Invalid reserved expansion location.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        $document = self::document();
        $document['paths']['/items']['get']['parameters'][0]['name'] = '';
        foreach ([true, false] as $strict) {
            try {
                self::files($document, $strict);
                self::fail('Empty reserved parameter name accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $document = self::document();
        $document['paths']['/items']['get']['parameters'][0]['allowReserved'] = 'true';
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        $swagger = ['swagger' => '2.0', 'info' => ['title' => 'Legacy', 'version' => '1'], 'host' => 'example.test', 'schemes' => ['https'], 'paths' => ['/items' => ['get' => ['parameters' => [['name' => 'q', 'in' => 'query', 'type' => 'string', 'allowReserved' => true]], 'responses' => ['204' => ['description' => 'OK']]]]]];
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($swagger, JSON_THROW_ON_ERROR));
    }
}
