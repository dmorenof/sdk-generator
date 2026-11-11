<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\AlternativesSupport;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** One status that offers JSON, text and binary is decoded by the media type the server answers with. */
final class RepresentationAlternativesGenerationTest extends TestCase
{
    private static int $loaded = 0;

    /** @return array<string, mixed> */
    private static function document(?array $content = null): array
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['paths']['/users/{id}']['get']['responses']['200'] = ['description' => 'any', 'content' => $content ?? [
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/User']],
            'text/csv' => ['schema' => ['type' => 'string']],
            'image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']],
        ]];
        return $document;
    }

    /** @return array<string, string> */
    private static function emit(array $document): array
    {
        $number = ++self::$loaded;
        return (new PhpExecutableEmitter("Example\\AltSdk$number", "example/alt-$number"))
            ->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    /** @return array{object, \ArrayObject<int, string>} */
    private static function load(array $document, string $body, string $contentType): array
    {
        $files = self::emit($document);
        $namespace = 'Example\\AltSdk' . self::$loaded;
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
        $accept = new \ArrayObject();
        $http = new class ($accept, $body, $contentType) implements ClientInterface {
            /** @param \ArrayObject<int, string> $accept */
            public function __construct(private readonly \ArrayObject $accept, private readonly string $body, private readonly string $type) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->accept[] = $request->getHeaderLine('Accept');
                return new Response(200, ['Content-Type' => $this->type], $this->body);
            }
        };
        $client = $namespace . '\\Client';
        return [$client::connect('https://api.example.test/v1', $http)->resource($namespace . '\\Resources\\Users'), $accept];
    }

    public function testTheContentTypePicksTheCodec(): void
    {
        [$users, $accept] = self::load(self::document(), '{"id":1,"name":"Ada"}', 'application/json');
        self::assertSame('Ada', $users->getById(1)->name);
        self::assertSame('application/json, text/csv, image/*', $accept[0]);

        [$users] = self::load(self::document(), "id,name\n1,Ada\n", 'text/csv; charset=utf-8');
        self::assertSame("id,name\n1,Ada\n", $users->getById(1));

        [$users] = self::load(self::document(), 'PNG', 'image/png');
        $response = $users->getById(1);
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('PNG', (string) $response->getBody());
    }

    public function testAnUndeclaredMediaTypeIsRejected(): void
    {
        [$users] = self::load(self::document(), '<a/>', 'application/xml');

        $this->expectException(\UnexpectedValueException::class);
        $users->getById(1);
    }

    public function testTheReturnTypeIsTheUnionOfTheRepresentations(): void
    {
        $source = self::emit(self::document())['src/Resources/Users.php'];

        self::assertMatchesRegularExpression('/function getById\([^)]*\): [^\n{]*string[^\n{]*/', $source);
        self::assertStringContainsString("'alternatives' =>", $source);
    }

    public function testASingleRepresentationKeepsItsShape(): void
    {
        $source = self::emit(self::document(['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]))['src/Resources/Users.php'];

        self::assertStringNotContainsString('alternatives', $source);
    }

    public function testAnUndecodableMediaTypeKeepsTheLegacyChoice(): void
    {
        $source = self::emit(self::document([
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/User']],
            'application/xml' => ['schema' => ['$ref' => '#/components/schemas/User']],
        ]))['src/Resources/Users.php'];

        self::assertStringNotContainsString('alternatives', $source);
    }

    public function testSelectionRules(): void
    {
        $string = ['schema' => ['type' => 'string']];
        self::assertNull(AlternativesSupport::select(['text/csv' => $string], []));
        self::assertNull(AlternativesSupport::select(['text/csv' => $string, 'application/jsonl' => $string], []), 'streams are not alternatives');
        self::assertNull(AlternativesSupport::select(['text/csv' => $string, 'text/x' => ['schema' => ['type' => 'object']]], []));
        self::assertCount(2, AlternativesSupport::select(['text/csv' => $string, 'text/plain' => $string], []));
    }
}
