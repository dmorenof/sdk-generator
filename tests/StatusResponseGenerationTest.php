<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\ApiException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

final class StatusResponseGenerationTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/status-responses-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array
    {
        return (new PhpExecutableEmitter('Example\\StatusSdk', 'example/status'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void
    {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/StatusAPISDK.php', 'src/DTOs/User.php'] as $path) {
            eval(substr($files[$path], strlen('<?php'))); unset($files[$path]);
        }
        foreach ($files as $path => $source) {
            if (str_ends_with($path, '.php')) { eval(substr($source, strlen('<?php'))); }
        }
    }
    private function resource(int $status, string $body): \Example\StatusSdk\Resources\Users
    {
        $http = new class($status, $body) implements ClientInterface {
            public function __construct(private int $status, private string $body) {}
            public function sendRequest(RequestInterface $request): ResponseInterface { return new Response($this->status, [], $this->body); }
        };
        return \Example\StatusSdk\Client::connect('https://api.example.test/v1', httpClient: $http)->resource(\Example\StatusSdk\Resources\Users::class);
    }
    public function testVersionsJsonYamlNativeSignaturesAndDistinctInlineStatusNames(): void
    {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\StatusSdk', 'example/status'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        $document['openapi'] = '3.0.3';
        foreach (['get', 'post'] as $verb) {
            $schema = &$document['paths']['/users'][$verb]['responses']['202']['content']['application/json']['schema'];
            $schema['type'] = 'object'; $schema['nullable'] = true; unset($schema);
        }
        self::assertSame($files, self::files($document));
        self::assertStringContainsString('DTOs\\User|\\Example\\StatusSdk\\DTOs\\CreatedUser|\\Example\\StatusSdk\\Responses\\Users\\GetStatus202|null', $files['src/Resources/Users.php']);
        self::assertArrayHasKey('src/Responses/Users/GetStatus202.php', $files);
        self::assertArrayHasKey('src/Responses/Users/PostStatus202.php', $files);
        self::assertStringContainsString("'responseTypes' =>", $files['src/Resources/Users.php']);
        self::assertStringNotContainsString("'responseType' =>", $files['src/Resources/Users.php']);
    }
    public function testSwaggerProducesTheSameStatusMapsAndNativeReturnTypes(): void
    {
        $document = self::document();
        $swagger = ['swagger' => '2.0', 'info' => $document['info'], 'host' => 'api.example.test',
            'basePath' => '/v1', 'schemes' => ['https'], 'consumes' => ['application/json'],
            'produces' => ['application/json'], 'definitions' => $document['components']['schemas'], 'paths' => []];
        foreach ($document['paths']['/users'] as $verb => $operation) {
            $parameters = [];
            foreach ($operation['parameters'] as $parameter) {
                $parameter['type'] = $parameter['schema']['type']; unset($parameter['schema']); $parameters[] = $parameter;
            }
            if (isset($operation['requestBody'])) {
                $parameters[] = ['in' => 'body', 'name' => 'body', 'required' => true,
                    'schema' => ['$ref' => '#/definitions/Export']];
            }
            $responses = [];
            foreach ($operation['responses'] as $status => $response) {
                $responseSchema = $response['content']['application/json']['schema'] ?? null;
                if (is_array($responseSchema['type'] ?? null)) {
                    $responseSchema['type'] = 'object'; $responseSchema['nullable'] = true;
                }
                $responses[$status] = ['description' => $response['description']]
                    + ($responseSchema === null ? [] : ['schema' => $responseSchema]);
            }
            $swagger['paths']['/users'][$verb] = ['operationId' => $operation['operationId'],
                'parameters' => $parameters, 'responses' => $responses];
        }
        $swagger = json_decode(str_replace('#/components/schemas/', '#/definitions/', json_encode($swagger, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::files($document), self::files($swagger));
        self::assertSame(self::files($document), (new PhpExecutableEmitter('Example\\StatusSdk', 'example/status'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($swagger, 40), strict: true)));
    }
    public function testResponseSerializationAndMutationsRetainInheritedRules(): void
    {
        $created = $this->resource(201, '{"id":7,"name":"Ada","ticket":"created"}')->get();
        self::assertEquals((object) ['id' => 7, 'name' => 'Ada', 'ticket' => 'created'], $created->jsonSerializeForResponse());
        self::assertFalse(property_exists($created->jsonSerializeForRequest(), 'id'));
        $created->ticket = '';
        try { $created->jsonSerializeForResponse(); self::fail('Invalid response mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
    }
    public function testActualStatusSelectsExactClassIncludingInheritanceAndNullableResponse(): void
    {
        self::assertSame(\Example\StatusSdk\DTOs\User::class, $this->resource(200, '{"id":7,"name":"Ada"}')->get()::class);
        $created = $this->resource(201, '{"id":7,"name":"Ada","ticket":"created"}')->get();
        self::assertSame(\Example\StatusSdk\DTOs\CreatedUser::class, $created::class);
        self::assertInstanceOf(\Example\StatusSdk\DTOs\User::class, $created);
        self::assertSame('created', $created->ticket);
        self::assertSame(7, $created->id); // readOnly response fields remain valid.
        self::assertSame(\Example\StatusSdk\Responses\Users\GetStatus202::class, $this->resource(202, '{"message":"queued"}')->get()::class);
        self::assertNull($this->resource(202, 'null')->get());
        self::assertNull($this->resource(204, '')->get());
    }
    public function testSchemaValidationIsBoundToItsStatusWithoutAlternativeFallback(): void
    {
        foreach ([[200, ''], [200, 'null'], [201, '{"name":"Ada"}'], [201, '{"name":"Ada","ticket":""}'],
            [202, '{}'], [202, ''], [200, '{"name":""}'], [200, '{"name":42}']] as [$status, $body]) {
            try { $this->resource($status, $body)->get(); self::fail('Invalid status-specific body accepted.'); }
            catch (\UnexpectedValueException|\InvalidArgumentException|\JsonException|\TypeError) { self::assertTrue(true); }
        }
        foreach ([400, 500] as $status) {
            try { $this->resource($status, '{"message":"problem"}')->get(); self::fail('Unexpected status accepted.'); }
            catch (ApiException $e) {
                self::assertSame($status, $e->response->status);
                if ($status === 400) { self::assertInstanceOf(\Example\StatusSdk\DTOs\Problem::class, $e->error); }
                else { self::assertNull($e->error); }
            }
        }
    }
    public function testGetPostParamsQueriesJsonBodyAndMutationRemainIndependentOfResponseType(): void
    {
        $http = new class implements ClientInterface {
            public array $calls = [];
            public function sendRequest(RequestInterface $request): ResponseInterface
            { $this->calls[] = $request; return new Response(201, [], '{"name":"Ada","ticket":"created"}'); }
        };
        $config = new \Example\StatusSdk\Configuration(); $config->httpClient = $http;
        \Example\StatusSdk\Client::configure($config);
        $get = \Example\StatusSdk\Users::get(new \Example\StatusSdk\GetParams\Users((object) ['body' => 'query body', 'X-Trace' => 'trace']));
        self::assertSame(\Example\StatusSdk\DTOs\CreatedUser::class, $get::class);
        $params = new \Example\StatusSdk\PostParams\Users((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['name' => 'export']]);
        $resource = \Example\StatusSdk\Client::connect($config->baseUrl, httpClient: $http)->resource(\Example\StatusSdk\Resources\Users::class);
        self::assertSame(\Example\StatusSdk\DTOs\CreatedUser::class, $resource->post($params)::class);
        self::assertSame('body=query%20body', $http->calls[0]->getUri()->getQuery());
        self::assertSame('body=query%20body', $http->calls[1]->getUri()->getQuery());
        self::assertSame('application/json', $http->calls[1]->getHeaderLine('Accept'));
        self::assertSame('trace', $http->calls[1]->getHeaderLine('X-Trace'));
        self::assertSame('{"name":"export"}', (string) $http->calls[1]->getBody());
        $params->body->name = '';
        try { $resource->post($params); self::fail('Mutation accepted.'); }
        catch (\UnexpectedValueException) { self::assertCount(2, $http->calls); }
    }
    public function testSingleSchemaPlusNoContentUsesStatusMapAndKeepsItsExistingTypeName(): void
    {
        $document = self::document();
        foreach (['get', 'post'] as $verb) { unset($document['paths']['/users'][$verb]['responses']['201'], $document['paths']['/users'][$verb]['responses']['202']); }
        $files = self::files($document);
        self::assertStringContainsString('): ?\\Example\\StatusSdk\\DTOs\\User', $files['src/Resources/Users.php']);
        self::assertStringContainsString("'responseTypes' =>", $files['src/Resources/Users.php']);
        self::assertStringNotContainsString("'noContentAllowed' => true", $files['src/Resources/Users.php']);
        $document['paths']['/users']['get']['responses']['201'] = $document['paths']['/users']['get']['responses']['200'];
        self::assertStringNotContainsString('User|\\Example\\StatusSdk\\DTOs\\User', self::files($document)['src/Resources/Users.php']);
    }
}
