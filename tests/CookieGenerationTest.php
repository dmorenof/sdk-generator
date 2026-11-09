<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CookieGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/cookies-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document, bool $strict = true): array
    {
        return (new PhpExecutableEmitter('Example\\CookieSdk', 'example/cookies'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict)
        );
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/CookiesAPISDK.php'] as $path) {
            eval(substr($files[$path], 5));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, 5));
            }
        }
    }
    public function testDialectsYamlReferencesAndScalarExplodeDefaults(): void
    {
        $document = self::document();
        $expected = self::files($document);
        self::assertSame($expected, (new PhpExecutableEmitter('Example\\CookieSdk', 'example/cookies'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)
        ));
        $document['openapi'] = '3.0.4';
        self::assertSame($expected, self::files($document));
        foreach ([false, true] as $explode) {
            foreach (['get', 'post'] as $verb) {
                foreach ($document['paths']['/items'][$verb]['parameters'] as &$parameter) {
                    if ($parameter['in'] === 'cookie') {
                        $parameter['style'] = 'form';
                        $parameter['explode'] = $explode;
                    }
                } unset($parameter);
            }
            self::assertSame($expected, self::files($document));
        }
        $document['components']['parameters']['Theme'] = $document['paths']['/items']['get']['parameters'][2];
        foreach (['get', 'post'] as $verb) {
            $document['paths']['/items'][$verb]['parameters'][2] = ['$ref' => '#/components/parameters/Theme'];
        }
        $document['components']['securitySchemes']['SessionAlias'] = $document['components']['securitySchemes']['Session'];
        $document['components']['securitySchemes']['Session'] = ['$ref' => '#/components/securitySchemes/SessionAlias'];
        $files = self::files($document);
        self::assertSame($expected['src/GetParams/Items.php'], $files['src/GetParams/Items.php']);
        self::assertStringContainsString("'location' => 'cookie'", $files['src/Client.php']);
    }
    public function testBothParameterDtosKeepQueryBodyAndCookiesIndependent(): void
    {
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public array $requests = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $config = new \Example\CookieSdk\Configuration();
        $config->httpClient = $http;
        $config->headers = ['COOKIE' => 'default=base'];
        $config->securityCredentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Session' => 'a+/b==']);
        \Example\CookieSdk\Client::configure($config);
        $values = ['theme' => 'a/b,ñ', 'enabled' => false, 'count' => 0, 'empty' => '', 'cookie' => 'caller=ok', 'body' => 'query body'];
        $get = new \Example\CookieSdk\GetParams\Items((object) $values);
        \Example\CookieSdk\Items::get($get);
        $values['queryBody'] = $values['body'];
        $values['body'] = (object) ['name' => 'body name'];
        $post = new \Example\CookieSdk\PostParams\Items((object) $values);
        $resource = \Example\CookieSdk\Client::connect($config->baseUrl, httpClient: $http, headers: $config->headers, securityCredentials: $config->securityCredentials)->resource(\Example\CookieSdk\Resources\Items::class);
        $resource->post($post);
        foreach ($http->requests as $request) {
            self::assertSame('https://example.test/items?body=query%20body', (string) $request->getUri());
            self::assertSame(['default=base; caller=ok; count=0; empty=; enabled=false; theme=a%2Fb%2C%C3%B1; session=a+/b=='], $request->getHeader('Cookie'));
        }
        self::assertSame('{"name":"body name"}', (string) $http->requests[1]->getBody());
        $post->theme = '';
        try {
            $resource->post($post);
            self::fail('Mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
        $post->theme = 'valid';
        $post->cookie = 'session=different';
        try {
            $resource->post($post);
            self::fail('Conflicting credential accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(2, $http->requests);
        }
    }
    public function testUnsupportedShapesFlagsAndSwaggerFailAlsoOutsideStrictMode(): void
    {
        foreach ([['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'object', 'additionalProperties' => false], ['type' => 'string', 'nullable' => true]] as $schema) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][2]['schema'] = $schema;
            foreach ([true, false] as $strict) {
                try {
                    self::files($document, $strict);
                    self::fail('Unsupported schema accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        foreach (['style' => 'simple', 'allowReserved' => true, 'allowEmptyValue' => true, 'collectionFormat' => 'csv'] as $key => $value) {
            $document = self::document();
            $document['paths']['/items']['get']['parameters'][2][$key] = $value;
            foreach ([true, false] as $strict) {
                try {
                    self::files($document, $strict);
                    self::fail('Unsupported flag accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        $document = ['swagger' => '2.0', 'info' => ['title' => 'Legacy', 'version' => '1'], 'host' => 'example.test', 'schemes' => ['https'],
            'securityDefinitions' => ['Session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'session']], 'paths' => []];
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }
}
