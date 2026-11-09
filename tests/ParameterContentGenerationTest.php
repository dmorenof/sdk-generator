<?php

declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ParameterContentGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/parameter-content-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document, bool $strict = true): array
    {
        return (new PhpExecutableEmitter('Example\\ContentSdk', 'example/content'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php','src/Configuration.php','src/ParameterContentAPISDK.php'] as $path) {
            eval(substr($files[$path], 5));
            unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) {
                eval(substr($source, 5));
            }
        }
    }
    public function testJsonYamlVersionsReferencesAndCaseInsensitiveMediaProduceSameSdk(): void
    {
        $document = self::document();
        $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\ContentSdk', 'example/content'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 50), strict: true)));
        $document['openapi'] = '3.0.4';
        self::assertSame($files, self::files($document));
        $document['components']['parameters']['BodyAlias'] = $document['components']['parameters']['Body'];
        $document['components']['parameters']['Body'] = ['$ref' => '#/components/parameters/BodyAlias'];
        self::assertSame($files, self::files($document));
        $document = self::document();
        $definition = $document['components']['parameters']['Plain']['content']['text/plain'];
        $document['components']['parameters']['Plain']['content'] = ['TEXT/PLAIN' => $definition];
        self::assertSame($files, self::files($document));
        self::assertStringContainsString("'parameterContent'", $files['src/Resources/Items/BySelector.php']);
        self::assertStringContainsString('getById(string $id)', $files['src/Resources/Scalar.php']);
    }
    public function testTypedContentPreservesDirectionsNativeContainersAndPayloadSeparation(): void
    {
        $http = new class () implements \Psr\Http\Client\ClientInterface {
            public array $requests = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->requests[] = $request;
                return new \GuzzleHttp\Psr7\Response(204);
            }
        };
        $config = new \Example\ContentSdk\Configuration();
        $config->httpClient = $http;
        $config->headers = ['Cookie' => 'default=base'];
        $config->securityCredentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => 'token+/==']);
        \Example\ContentSdk\Client::configure($config);
        $path = new \Example\ContentSdk\PathParams\Items\BySelector\ByToken((object) ['selector' => (object) ['code' => 'path/x', 'flag' => false], 'token' => 'a/b%']);
        $values = ['body' => (object) ['id' => 7, 'name' => 'query/x'], 'records' => [(object) ['id' => 8, 'name' => 'row/x']], 'empty' => [], 'scalar' => '', 'active' => false, 'values' => (object) ['0' => 'zero'], 'plain' => '100% / ñ', 'optional' => null, 'X-JSON' => (object) ['code' => 'header/x', 'flag' => false], 'X-Trace' => 'h,/=x', 'session' => 'a+/b==', 'theme' => 'a/b'];
        $get = new \Example\ContentSdk\GetParams\Items\BySelector\ByToken((object) $values);
        \Example\ContentSdk\Items\BySelector::getByToken($path, $get);
        $values['queryBody'] = $values['body'];
        $values['body'] = (object) ['id' => 9, 'name' => 'body/x'];
        $post = new \Example\ContentSdk\PostParams\Items\BySelector\ByToken((object) $values);
        $client = \Example\ContentSdk\Client::connect($config->baseUrl, httpClient: $http, headers: $config->headers, securityCredentials: $config->securityCredentials);
        $resource = $client->resource(\Example\ContentSdk\Resources\Items\BySelector::class);
        $resource->postByToken($path, $post);
        $expectedQuery = 'active=false&body='.rawurlencode('{"name":"query\/x"}').'&empty=%5B%5D&plain=100%25%20%2F%20%C3%B1&records='.rawurlencode('[{"name":"row\/x"}]').'&scalar=%22%22&values='.rawurlencode('{"0":"zero"}');
        foreach ($http->requests as $request) {
            self::assertSame('https://example.test/items/'.rawurlencode('{"code":"path\/x","flag":false}').'/a%2Fb%25?'.$expectedQuery, (string) $request->getUri());
            self::assertSame('{"code":"header\/x","flag":false}', $request->getHeaderLine('X-JSON'));
            self::assertSame('h,/=x', $request->getHeaderLine('X-Trace'));
            self::assertSame('default=base; session=a+/b==; theme=a%2Fb; auth=token+/==', $request->getHeaderLine('Cookie'));
        }
        self::assertFalse($http->requests[0]->hasHeader('Content-Type'));
        self::assertSame('{"name":"body\/x"}', (string) $http->requests[1]->getBody());
        self::assertSame('application/json', $http->requests[1]->getHeaderLine('Content-Type'));
        $client->resource(\Example\ContentSdk\Resources\Scalar::class)->getById('Cat');
        self::assertSame('https://example.test/scalar/%22Cat%22', (string) $http->requests[2]->getUri());
        $post->records[] = new \Example\ContentSdk\DTOs\Payload((object) ['id' => 10, 'name' => 'second']);
        unset($post->records[0]);
        $resource->postByToken($path, $post);
        parse_str($http->requests[3]->getUri()->getQuery(), $query);
        self::assertSame([['name' => 'second']], json_decode($query['records'], true, 512, JSON_THROW_ON_ERROR));
        foreach (['Cow','C'] as $id) {
            try {
                $client->resource(\Example\ContentSdk\Resources\Scalar::class)->getById($id);
                self::fail('Serialization bypassed logical constraints.');
            } catch (\UnexpectedValueException) {
                self::assertCount(4, $http->requests);
            }
        }
        $post->records[1]->name = '';
        try {
            $resource->postByToken($path, $post);
            self::fail('Nested collection mutation accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(4, $http->requests);
        }
        $get->session = 'bad;cookie';
        try {
            $resource->getByToken($path, $get);
            self::fail('Invalid raw cookie accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(4, $http->requests);
        }
        $get->session = 'a+/b==';
        $path->token = '';
        try {
            $resource->getByToken($path, $get);
            self::fail('Invalid plain path accepted.');
        } catch (\UnexpectedValueException) {
            self::assertCount(4, $http->requests);
        }
    }
    public function testSingleRepresentationAndSchemaStyleExclusivityAreEnforcedInBothModes(): void
    {
        $variants = [];
        foreach (['schema' => ['type' => 'string'], 'style' => 'form', 'explode' => false, 'allowReserved' => false, 'collectionFormat' => 'csv'] as $key => $value) {
            $p = self::document()['components']['parameters']['Plain'];
            $p[$key] = $value;
            $variants[] = $p;
        }
        $p = self::document()['components']['parameters']['Plain'];
        $p['content']['application/json'] = ['schema' => ['type' => 'string']];
        $variants[] = $p;
        $p['content'] = ['application/xml' => ['schema' => ['type' => 'string']]];
        $variants[] = $p;
        $p['content'] = ['text/plain' => ['schema' => ['type' => 'integer']]];
        $variants[] = $p;
        $p['content'] = ['application/json' => ['schema' => ['type' => 'string', 'nullable' => true]]];
        $variants[] = $p;
        $p['content'] = ['application/json' => ['schema' => ['type' => 'string']]];
        $p['in'] = 'cookie';
        $variants[] = $p;
        foreach ($variants as $parameter) {
            $document = self::document();
            $document['components']['parameters']['Plain'] = $parameter;
            foreach ([true,false] as $strict) {
                try {
                    self::files($document, $strict);
                    self::fail('Unsupported content accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
        $document = self::document();
        $document['components']['parameters']['Plain']['content'] = ['application/json' => ['schema' => ['type' => 'string']], 'text/plain' => ['schema' => ['type' => 'string']]];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR), sourceName: 'content.json');
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'parameter_content'));
        self::assertNotEmpty($issues);
        self::assertSame('/components/parameters/Plain/content', $issues[0]->pointer);
        self::assertSame('content.json', $issues[0]->source);
    }
}
