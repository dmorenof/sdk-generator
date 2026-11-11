<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\ResponseHeaderSupport;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Declared response headers reach the runtime as typed metadata, so lastResponse() can convert and check them. */
final class ResponseHeadersGenerationTest extends TestCase
{
    private static int $loaded = 0;

    private const OPENAPI_HEADERS = [
        'X-Rate-Limit' => ['required' => true, 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000]],
        'X-Ratio' => ['schema' => ['type' => 'number']],
        'X-Cursor' => ['schema' => ['type' => 'string', 'maxLength' => 8]],
        'X-Admin' => ['schema' => ['type' => 'boolean']],
        'X-Tags' => ['schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'X-Level' => ['schema' => ['type' => 'string', 'enum' => ['low', 'high']]],
        'X-Alias' => ['$ref' => '#/components/headers/Remaining'],
        'Content-Type' => ['schema' => ['type' => 'string']],
        'X-Meta' => ['schema' => ['type' => 'object']],
    ];

    /** @return array<string, mixed> */
    private static function openapi(array $headers = self::OPENAPI_HEADERS): array
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['components']['headers']['Remaining'] = ['schema' => ['type' => 'integer']];
        $document['paths']['/users/{id}']['get']['responses']['200']['headers'] = $headers;
        return $document;
    }

    /** @return array<string, string> */
    private static function emit(array $document, bool $strict = false): array
    {
        $number = ++self::$loaded;
        return (new PhpExecutableEmitter("Example\\HeadersSdk$number", "example/headers-$number"))
            ->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: $strict));
    }

    /** Emits an SDK, loads its classes on demand and returns its Users resource over a client that answers with the given headers. */
    private static function users(array $document, array $answerHeaders): object
    {
        $files = self::emit($document);
        $namespace = 'Example\\HeadersSdk' . self::$loaded;
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
        $http = new class ($answerHeaders) implements ClientInterface {
            /** @param array<string, string> $headers */
            public function __construct(private readonly array $headers) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, $this->headers, '{"id":1,"name":"Ada"}');
            }
        };
        $client = ($namespace . '\\Client')::connect('https://api.example.test/v1', $http);
        return $client->resource(($namespace . '\\Resources\\Users'));
    }

    public function testDeclaredHeadersAreConvertedByTheirType(): void
    {
        $users = self::users(self::openapi(), [
            'X-Rate-Limit' => '42', 'X-Ratio' => '0.5', 'X-Cursor' => 'abc', 'X-Admin' => 'true',
            'X-Tags' => 'a, b,c', 'X-Level' => 'high', 'X-Alias' => '7', 'X-Undeclared' => 'raw',
        ]);

        $users->getById(1);
        $headers = $users->lastResponse()->headers;

        self::assertSame(42, $headers->get('x-rate-limit'));
        self::assertSame(0.5, $headers->get('X-Ratio'));
        self::assertSame('abc', $headers->get('X-Cursor'));
        self::assertTrue($headers->get('X-Admin'));
        self::assertSame(['a', 'b', 'c'], $headers->get('X-Tags'));
        self::assertSame('high', $headers->get('X-Level'));
        self::assertSame(7, $headers->get('X-Alias'), 'a header declared by reference is typed too');
        self::assertSame('raw', $headers->get('X-Undeclared'), 'an undeclared header stays readable as text');
    }

    public function testRequiredHeadersAndRulesAreEnforcedWhenRead(): void
    {
        $users = self::users(self::openapi(), ['X-Cursor' => 'far too long a cursor', 'X-Level' => 'medium', 'X-Rate-Limit' => '5000']);

        self::assertSame('Ada', $users->getById(1)->name, 'the call itself succeeds whatever the headers say');
        $headers = $users->lastResponse()->headers;
        foreach (['X-Cursor', 'X-Level', 'X-Rate-Limit'] as $name) {
            try {
                $headers->get($name);
                self::fail("$name accepted");
            } catch (\Throwable) {
                self::assertTrue(true);
            }
        }

        $missing = self::users(self::openapi(), []);
        $missing->getById(1);
        $this->expectException(\UnexpectedValueException::class);
        $missing->lastResponse()->headers->get('X-Rate-Limit');
    }

    public function testOnlyTypeableHeadersReachTheOperationMetadata(): void
    {
        $source = self::emit(self::openapi())['src/Resources/Users.php'];

        self::assertStringContainsString("'responseHeaders' =>", $source);
        foreach (['X-Rate-Limit', 'X-Ratio', 'X-Cursor', 'X-Admin', 'X-Tags', 'X-Level', 'X-Alias'] as $name) {
            self::assertStringContainsString("'$name'", $source);
        }
        self::assertStringNotContainsString("'Content-Type' =>", $source, 'the Content-Type response header is ignored by OpenAPI');
        self::assertStringNotContainsString("'X-Meta'", $source, 'an object schema cannot be typed');
    }

    public function testOperationsWithoutHeadersKeepTheirMetadataUnchanged(): void
    {
        $plain = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('responseHeaders', self::emit($plain)['src/Resources/Users.php']);
    }

    public function testStrictModeAcceptsTypedHeadersAndStillFlagsRawOnes(): void
    {
        $typed = self::openapi(['X-Rate-Limit' => self::OPENAPI_HEADERS['X-Rate-Limit'], 'X-Alias' => self::OPENAPI_HEADERS['X-Alias']]);
        self::assertArrayHasKey('src/Resources/Users.php', self::emit($typed, strict: true));

        $this->expectException(\InvalidArgumentException::class);
        self::emit(self::openapi(['X-Meta' => ['schema' => ['type' => 'object']]]), strict: true);
    }

    public function testSwaggerHeadersCarryTheirTypeOnTheHeaderObject(): void
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['paths']['/users/{id}']['get']['responses']['200']['headers'] = [
            'X-Rate-Limit' => ['type' => 'integer', 'minimum' => 0],
            'X-Tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'collectionFormat' => 'csv'],
            'X-Pipes' => ['type' => 'array', 'items' => ['type' => 'string'], 'collectionFormat' => 'pipes'],
        ];
        $users = self::users($document, ['X-Rate-Limit' => '9', 'X-Tags' => 'a,b', 'X-Pipes' => 'a|b']);

        $users->getById(1);
        $headers = $users->lastResponse()->headers;

        self::assertSame(9, $headers->get('X-Rate-Limit'));
        self::assertSame(['a', 'b'], $headers->get('X-Tags'));
        self::assertSame('a|b', $headers->get('X-Pipes'), 'a pipe-separated list is not representable, so it stays raw');
    }

    public function testStatusRangesAndDefaultKeepTheirOwnDeclarations(): void
    {
        $document = self::openapi([]);
        $document['paths']['/users/{id}']['get']['responses']['404'] = ['description' => 'missing', 'headers' => ['X-Request-Id' => ['required' => true, 'schema' => ['type' => 'string']]]];
        $document['paths']['/users/{id}']['get']['responses']['default'] = ['description' => 'error', 'headers' => ['X-Trace' => ['schema' => ['type' => 'integer']]]];

        $source = self::emit($document)['src/Resources/Users.php'];

        self::assertMatchesRegularExpression("/404 => \\[\\s*'X-Request-Id' => \\[\\s*'type' => 'string',\\s*'required' => true/", $source);
        self::assertMatchesRegularExpression("/'default' => \\[\\s*'X-Trace' => \\[\\s*'type' => 'integer'/", $source);
    }

    /** @return iterable<string, array{array<string, mixed>, ?array<string, mixed>}> */
    public static function headerSpecs(): iterable
    {
        yield 'integer' => [['schema' => ['type' => 'integer']], ['type' => 'integer']];
        yield 'required with rules' => [['required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9]], ['type' => 'integer', 'required' => true, 'rules' => ['minimum' => 1, 'maximum' => 9]]];
        yield 'string rules' => [['schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 4, 'pattern' => '^a']], ['type' => 'string', 'rules' => ['minLength' => 2, 'maxLength' => 4, 'pattern' => '^a']]];
        yield 'enum' => [['schema' => ['type' => 'string', 'enum' => ['a', 'b']]], ['type' => 'string', 'rules' => ['enum' => ['a', 'b']]]];
        yield 'boolean' => [['schema' => ['type' => 'boolean']], ['type' => 'boolean']];
        yield 'list of numbers' => [['schema' => ['type' => 'array', 'items' => ['type' => 'number']]], ['type' => 'array', 'items' => 'number']];
        yield 'swagger style' => [['type' => 'integer', 'minimum' => 0], ['type' => 'integer', 'rules' => ['minimum' => 0]]];
        yield 'object' => [['schema' => ['type' => 'object']], null];
        yield 'nested list' => [['schema' => ['type' => 'array', 'items' => ['type' => 'array']]], null];
        yield 'type union' => [['schema' => ['type' => ['string', 'null']]], null];
        yield 'content instead of schema' => [['content' => ['application/json' => ['schema' => ['type' => 'string']]]], null];
        yield 'pipes list' => [['type' => 'array', 'items' => ['type' => 'string'], 'collectionFormat' => 'pipes'], null];
        yield 'nothing declared' => [['description' => 'only text'], null];
        yield 'bad minimum ignored' => [['schema' => ['type' => 'integer', 'minimum' => 'x']], ['type' => 'integer']];
    }

    /**
     * @param array<string, mixed> $header
     * @param ?array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('headerSpecs')]
    public function testHeaderObjectsMapToRuntimeSpecifications(array $header, ?array $expected): void
    {
        self::assertSame($expected, ResponseHeaderSupport::spec($header));
    }
}
