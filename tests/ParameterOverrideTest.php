<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ParameterOverrideTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/parameter-overrides-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function contract(array $document, bool $strict = true): \Dmorenof\SdkGenerator\Contract\ApiContract
    {
        return (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict);
    }
    private static function files(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\OverrideSdk', 'example/overrides'))->emit(self::contract($document));
    }
    private static function swagger(): array
    {
        $document = self::document();
        unset($document['openapi'], $document['servers']);
        $document['swagger'] = '2.0';
        $document['host'] = 'example.test';
        $document['schemes'] = ['https'];
        $document['definitions'] = $document['components']['schemas'];
        unset($document['components']);
        $convert = static function (array $parameters): array {
            $result = [];
            foreach ($parameters as $parameter) {
                if ($parameter['in'] === 'cookie') {
                    continue;
                }
                $schema = $parameter['schema'];
                unset($parameter['schema']);
                if ($schema['type'] === 'array') {
                    $parameter['collectionFormat'] = ($parameter['explode'] ?? true) ? 'multi' : 'csv';
                }
                unset($parameter['style'], $parameter['explode']);
                $result[] = $parameter + $schema;
            }
            return $result;
        };
        $item = &$document['paths']['/items/{id}'];
        $item['parameters'] = $convert($item['parameters']);
        foreach (['get', 'post', 'delete'] as $verb) {
            $item[$verb]['parameters'] = $convert($item[$verb]['parameters']);
        }
        unset($item['post']['requestBody']);
        $item['post']['parameters'][] = ['in' => 'body', 'name' => 'body', 'required' => true, 'schema' => ['$ref' => '#/definitions/Form']];
        return $document;
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php','src/Configuration.php','src/ParameterOverridesAPISDK.php'] as $path) {
            eval(substr($files[$path], 5));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, 5));
            }
        }
    }
    public function testReplacementAndInheritanceAcrossDialectsYamlAndReferences(): void
    {
        $document = self::document();
        $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\OverrideSdk', 'example/overrides'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.4';
        self::assertSame($expected, self::files($document));
        foreach (['parameters', 'get', 'post'] as $level) {
            $parameters = $level === 'parameters' ? $document['paths']['/items/{id}']['parameters'] : $document['paths']['/items/{id}'][$level]['parameters'];
            foreach ($parameters as $index => $parameter) {
                $name = $level . $index;
                $document['components']['parameters'][$name] = $parameter;
                if ($level === 'parameters') {
                    $document['paths']['/items/{id}']['parameters'][$index] = ['$ref' => '#/components/parameters/'.$name];
                } else {
                    $document['paths']['/items/{id}'][$level]['parameters'][$index] = ['$ref' => '#/components/parameters/'.$name];
                }
            }
        }
        self::assertSame($expected, self::files($document));
        foreach ([self::document(), $document, self::swagger()] as $input) {
            $operations = self::contract($input)->operations;
            foreach ($operations as $operation) {
                $parameters = [];
                foreach ($operation->parameters as $p) {
                    $parameters[$p->location.':'.$p->wireName] = $p;
                }
                self::assertArrayHasKey('query:body', $parameters);
                self::assertArrayHasKey('header:selector', $parameters);
                $query = $parameters['query:selector']->schema;
                self::assertSame($operation->method === 'POST' ? SchemaKind::String : SchemaKind::Array, $query->kind);
                if ($operation->method === 'GET') {
                    self::assertSame(SchemaKind::Integer, $query->items->kind);
                    self::assertSame(1, $query->validation['minItems']);
                }
                if ($operation->method === 'DELETE') {
                    self::assertSame(SchemaKind::String, $query->items->kind);
                    self::assertSame(2, $query->validation['minItems']);
                }
            }
        }
    }
    public function testBothParameterDtosUseOnlyEffectiveTypesStylesAndValidation(): void
    {
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public array $requests = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $configuration = new \Example\OverrideSdk\Configuration();
        $configuration->httpClient = $http;
        \Example\OverrideSdk\Client::configure($configuration);
        $get = new \Example\OverrideSdk\GetParams\Items\ById((object) ['querySelector' => [0], 'selector' => 'hdr', 'body' => 'query body', 'theme' => 'dark']);
        \Example\OverrideSdk\Items::getById('a', $get);
        $post = new \Example\OverrideSdk\PostParams\Items\ById((object) ['querySelector' => 'x/y', 'selector' => 'h', 'queryBody' => 'query body', 'theme' => 'dark', 'body' => (object) ['name' => 'body name']]);
        $resource = \Example\OverrideSdk\Client::connect($configuration->baseUrl, httpClient: $http)->resource(\Example\OverrideSdk\Resources\Items::class);
        $resource->postById('a', $post);
        self::assertSame('https://example.test/items/.a?body=query%20body&selector=0', (string) $http->requests[0]->getUri());
        self::assertSame('hdr', $http->requests[0]->getHeaderLine('selector'));
        self::assertSame('https://example.test/items/;id=a?body=query%20body&selector=x%2Fy', (string) $http->requests[1]->getUri());
        self::assertSame('h', $http->requests[1]->getHeaderLine('selector'));
        self::assertSame('{"name":"body name"}', (string) $http->requests[1]->getBody());
        foreach ($http->requests as $request) {
            self::assertSame('theme=dark', $request->getHeaderLine('Cookie'));
        }
        $get->querySelector = [];
        try {
            $resource->getById('a', $get);
            self::fail('Invalid mutation reached HTTP.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
        $post->querySelector = '';
        try {
            $resource->postById('a', $post);
            self::fail('Invalid mutation reached HTTP.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
    }
    public function testDuplicateListsRejectRatherThanOverrideEvenWhenOperationMasksParent(): void
    {
        foreach ([self::document(), self::swagger()] as $document) {
            foreach (['parameters', 'get', 'post'] as $level) {
                $bad = $document;
                $item = &$bad['paths']['/items/{id}'];
                if ($level === 'parameters') {
                    $item['parameters'][] = $item['parameters'][0];
                } else {
                    $item[$level]['parameters'][] = $item[$level]['parameters'][0];
                }
                unset($item);
                $report = (new DocumentInspector())->inspect(json_encode($bad, JSON_THROW_ON_ERROR), sourceName: 'duplicates.json');
                $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'duplicate_parameter'));
                self::assertCount(1, $issues);
                self::assertSame('duplicates.json', $issues[0]->source);
                $suffix = $level === 'parameters' ? '/parameters/'.(count($bad['paths']['/items/{id}']['parameters']) - 1) : '/'.$level.'/parameters/'.(count($bad['paths']['/items/{id}'][$level]['parameters']) - 1);
                self::assertSame('/paths/~1items~1{id}'.$suffix, $issues[0]->pointer);
                foreach ([true, false] as $strict) {
                    try {
                        self::contract($bad, $strict);
                        self::fail('Duplicate accepted.');
                    } catch (\InvalidArgumentException) {
                        self::assertTrue(true);
                    }
                }
            }
        }
    }
    public function testReferencedDuplicatesKeepUsagePointerAndAliasChain(): void
    {
        $document = self::document();
        $document['components']['parameters']['ID/~'] = $document['paths']['/items/{id}']['get']['parameters'][0];
        $document['components']['parameters']['Alias'] = ['$ref' => '#/components/parameters/ID~1~0'];
        $document['paths']['/items/{id}']['get']['parameters'][] = ['$ref' => '#/components/parameters/Alias'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'duplicate_parameter'));
        self::assertCount(1, $issues);
        self::assertSame('/paths/~1items~1{id}/get/parameters/3', $issues[0]->pointer);
        self::assertSame(['#/components/parameters/Alias', '#/components/parameters/ID~1~0'], $issues[0]->referenceChain);
        $item = $document['paths']['/items/{id}'];
        $item['parameters'][] = $item['parameters'][0];
        $document['components']['pathItems']['Shared/~'] = $item;
        $document['paths']['/items/{id}'] = ['$ref' => '#/components/pathItems/Shared~1~0'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'duplicate_parameter' && str_ends_with($issue->pointer, '/parameters/5')));
        self::assertNotEmpty($issues);
        self::assertSame('/components/pathItems/Shared~1~0/parameters/5', $issues[0]->pointer);
        self::assertNotEmpty(array_filter($issues, static fn ($issue): bool => in_array('#/components/pathItems/Shared~1~0', $issue->referenceChain, true)));
    }
    public function testDistinctLocationsAndReusableDeclarationsAreNotDuplicates(): void
    {
        $document = self::document();
        $parameter = $document['paths']['/items/{id}']['parameters'][4];
        $parameter['in'] = 'query';
        $document['paths']['/items/{id}']['parameters'][] = $parameter;
        $document['components']['parameters'] = ['One' => $parameter, 'Two' => $parameter];
        self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        foreach ([null, 'invalid-list'] as $invalid) {
            foreach (['parameters', 'get'] as $level) {
                $bad = $document;
                if ($level === 'parameters') {
                    $bad['paths']['/items/{id}']['parameters'] = $invalid;
                } else {
                    $bad['paths']['/items/{id}']['get']['parameters'] = $invalid;
                }
                $report = (new DocumentInspector())->inspect(json_encode($bad, JSON_THROW_ON_ERROR));
                self::assertContains('invalid_parameter_list', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
                try {
                    self::contract($bad, false);
                    self::fail('Invalid parameter list accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        $document['paths']['/items/{id}']['get']['parameters'] = ['bad-key' => $parameter];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('invalid_parameter_list', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
    }
}
