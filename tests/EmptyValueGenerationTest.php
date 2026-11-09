<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class EmptyValueGenerationTest extends TestCase
{
    private static function document(string $name = 'empty-values-openapi30.json'): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document, string $namespace = 'Example\\EmptySdk', bool $strict = true): array
    {
        return (new PhpExecutableEmitter($namespace, 'example/empty'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict));
    }
    public static function setUpBeforeClass(): void
    {
        foreach (['empty-values-openapi30.json' => 'Example\\EmptySdk', 'empty-values-swagger2.json' => 'Example\\EmptySwaggerSdk'] as $fixture => $namespace) {
            $files = self::files(self::document($fixture), $namespace);
            foreach (['src/Client.php','src/Configuration.php','src/EmptyValuesAPISDK.php'] as $path) {
                eval(substr($files[$path], 5));
                unset($files[$path]);
            }
            foreach ($files as $path => $source) {
                if (str_ends_with($path, '.php')) {
                    eval(substr($source, 5));
                }
            }
        }
    }
    public function testSchemaAuthoritativePolicyDoesNotAddRuntimeMetadataOrChangeWireTypes(): void
    {
        foreach (['empty-values-openapi30.json','empty-values-swagger2.json'] as $fixture) {
            $document = self::document($fixture);
            $expected = self::files($document);
            self::assertSame($expected, (new PhpExecutableEmitter('Example\\EmptySdk', 'example/empty'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
            $without = $document;
            $parameters = isset($document['swagger']) ? $without['parameters'] : $without['components']['parameters'];
            foreach ($parameters as &$parameter) {
                unset($parameter['allowEmptyValue']);
            }
            unset($parameter);
            if (isset($document['swagger'])) {
                $without['parameters'] = $parameters;
                foreach ($without['paths']['/items']['post']['parameters'] as &$field) {
                    unset($field['allowEmptyValue']);
                }
                unset($field);
            } else {
                $without['components']['parameters'] = $parameters;
            }
            self::assertSame($expected, self::files($without));
            foreach ($parameters as &$parameter) {
                if ($parameter['in'] === 'query') {
                    $parameter['allowEmptyValue'] = false;
                }
            }
            unset($parameter);
            if (isset($document['swagger'])) {
                $without['parameters'] = $parameters;
            } else {
                $without['components']['parameters'] = $parameters;
            }
            self::assertSame($expected, self::files($without));
        }
        $document = self::document();
        $document['openapi'] = '3.1.0';
        self::assertSame(self::files(self::document()), self::files($document));
        foreach (['query-objects-openapi31.json', 'object-parameters-openapi31.json', 'multipart-swagger2.json'] as $fixture) {
            $document = self::document($fixture);
            $expected = self::files($document);
            foreach ($document['paths'] as &$item) {
                foreach (['get','post'] as $verb) {
                    if (!isset($item[$verb]['parameters'])) {
                        continue;
                    } foreach ($item[$verb]['parameters'] as &$parameter) {
                        if (in_array($parameter['in'] ?? null, ['query','formData'], true)) {
                            $parameter['allowEmptyValue'] = true;
                        }
                    } unset($parameter);
                }
            } unset($item);
            self::assertSame($expected, self::files($document));
        }
    }
    public function testGetPostAndFormsDistinguishOmittedNullEmptyAndFalseZero(): void
    {
        foreach (['Example\\EmptySdk','Example\\EmptySwaggerSdk'] as $namespace) {
            $http = new class () implements \Psr\Http\Client\ClientInterface {
                public array $requests = [];
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $this->requests[] = $request;
                    return new \GuzzleHttp\Psr7\Response(204);
                }
            };
            $clientType = $namespace.'\\Client';
            $configurationType = $namespace.'\\Configuration';
            $getType = $namespace.'\\GetParams\\Items';
            $postType = $namespace.'\\PostParams\\Items';
            $jsonType = $namespace.'\\PostParams\\Json';
            $configuration = new $configurationType();
            $configuration->httpClient = $http;
            $clientType::configure($configuration);
            $values = ['requiredEmpty' => '', 'q' => '', 'body' => '', 'nullable' => null, 'count' => 0, 'enabled' => false, 'labels' => [''], 'csv' => [''], 'strict' => 'x', 'X-Trace' => 'trace'];
            $get = new $getType((object) $values);
            $facade = $namespace.'\\Items';
            $facade::get($get);
            $client = $clientType::connect($configuration->baseUrl, httpClient: $http);
            $resource = $client->resource($namespace.'\\Resources\\Items');
            $get->q = null;
            $get->labels = [];
            $get->csv = [];
            $resource->get($get);
            $values['queryBody'] = $values['body'];
            $values['body'] = (object) ['name' => '', 'nullable' => null, 'strict' => 'x', 'count' => 0, 'enabled' => false, 'labels' => ['']];
            $post = new $postType((object) $values);
            $resource->post($post);
            $client->resource($namespace.'\\Resources\\Json')->post(new $jsonType((object) $values));
            $query = 'body=&count=0&csv=&enabled=false&labels=&q=&requiredEmpty=&strict=x';
            self::assertSame('https://example.test/items?'.$query, (string) $http->requests[0]->getUri());
            self::assertSame('https://example.test/items?body=&count=0&enabled=false&requiredEmpty=&strict=x', (string) $http->requests[1]->getUri());
            self::assertSame('https://example.test/items?'.$query, (string) $http->requests[2]->getUri());
            self::assertSame('https://example.test/json?'.$query, (string) $http->requests[3]->getUri());
            self::assertSame('application/x-www-form-urlencoded', $http->requests[2]->getHeaderLine('Content-Type'));
            self::assertSame('count=0&enabled=false&labels=&name=&strict=x', (string) $http->requests[2]->getBody());
            self::assertSame(['count' => 0, 'enabled' => false, 'labels' => [''], 'name' => '', 'nullable' => null, 'strict' => 'x'], json_decode((string) $http->requests[3]->getBody(), true, 512, JSON_THROW_ON_ERROR));
            foreach ($http->requests as $request) {
                self::assertSame('trace', $request->getHeaderLine('X-Trace'));
                self::assertStringNotContainsString('omitted', (string) $request->getUri());
                self::assertStringNotContainsString('nullable', (string) $request->getUri());
            }
            $get->strict = '';
            try {
                $resource->get($get);
                self::fail('allowEmptyValue bypassed minLength.');
            } catch (\UnexpectedValueException) {
                self::assertCount(4, $http->requests);
            }
            $post->body->strict = '';
            try {
                $resource->post($post);
                self::fail('allowEmptyValue bypassed form constraints.');
            } catch (\UnexpectedValueException) {
                self::assertCount(4, $http->requests);
            }
        }
    }
    public function testRequiredAndNativeTypesRemainAuthoritative(): void
    {
        foreach (['Example\\EmptySdk','Example\\EmptySwaggerSdk'] as $namespace) {
            $getType = $namespace.'\\GetParams\\Items';
            foreach ([(object) [], (object) ['requiredEmpty' => null], (object) ['requiredEmpty' => '', 'count' => ''], (object) ['requiredEmpty' => '', 'labels' => '']] as $value) {
                try {
                    new $getType($value);
                    self::fail('Flag bypassed required or native type.');
                } catch (\UnexpectedValueException) {
                    self::assertTrue(true);
                }
            }
        }
    }
    public function testInvalidContextsAndNonBooleanFlagsRetainDiagnostics(): void
    {
        foreach (['header','cookie','path'] as $location) {
            $document = self::document();
            $document['components']['parameters']['q']['in'] = $location;
            foreach ([true,false] as $strict) {
                try {
                    self::files($document, strict: $strict);
                    self::fail('Invalid location accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        foreach (['empty-values-openapi30.json','empty-values-swagger2.json'] as $fixture) {
            $document = self::document($fixture);
            if (isset($document['swagger'])) {
                $document['parameters']['q']['allowEmptyValue'] = 'true';
            } else {
                $document['components']['parameters']['q']['allowEmptyValue'] = 'true';
            }
            self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
        }
    }
}
