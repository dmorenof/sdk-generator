<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Parsing\StreamingSupport;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A response that declares a streaming media type becomes a lazy \Generator instead of one decoded value. */
final class StreamingResponseGenerationTest extends TestCase
{
    private static int $loaded = 0;

    /** @return array<string, mixed> */
    private static function document(string $media, bool $withSchema = true): array
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $content = $withSchema ? ['schema' => ['$ref' => '#/components/schemas/User']] : [];
        $document['paths']['/users/{id}']['get']['responses']['200'] = ['description' => 'stream', 'content' => [$media => $content]];
        return $document;
    }

    /** @return array<string, string> */
    private static function emit(array $document): array
    {
        $number = ++self::$loaded;
        return (new PhpExecutableEmitter("Example\\StreamSdk$number", "example/stream-$number"))
            ->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    /** @return array{object, \ArrayObject<int, array<string, string>>} */
    private static function load(array $document, string $body, string $contentType): array
    {
        $files = self::emit($document);
        $namespace = 'Example\\StreamSdk' . self::$loaded;
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
        $sent = new \ArrayObject();
        $http = new class ($sent, $body, $contentType) implements ClientInterface {
            /** @param \ArrayObject<int, array<string, string>> $sent */
            public function __construct(private readonly \ArrayObject $sent, private readonly string $body, private readonly string $type) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = ['accept' => $request->getHeaderLine('Accept')];
                return new Response(200, ['Content-Type' => $this->type], $this->body);
            }
        };
        $client = $namespace . '\\Client';
        return [$client::connect('https://api.example.test/v1', $http)->resource($namespace . '\\Resources\\Users'), $sent];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function lineFormats(): iterable
    {
        yield 'jsonl' => ['application/jsonl', 'jsonl', "{\"id\":1,\"name\":\"Ada\"}\n{\"id\":2,\"name\":\"Grace\"}\n"];
        yield 'ndjson' => ['application/x-ndjson', 'jsonl', "{\"id\":1,\"name\":\"Ada\"}\n{\"id\":2,\"name\":\"Grace\"}\n"];
        yield 'json-seq' => ['application/json-seq', 'json-seq', "\x1e{\"id\":1,\"name\":\"Ada\"}\n\x1e{\"id\":2,\"name\":\"Grace\"}\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineFormats')]
    public function testItemStreamsYieldHydratedItems(string $media, string $encoding, string $body): void
    {
        $document = self::document($media);
        $source = self::emit($document)['src/Resources/Users.php'];
        self::assertStringContainsString("'responseEncoding' => '$encoding'", $source);
        self::assertStringContainsString("'responseMediaType' => '$media'", $source);
        self::assertMatchesRegularExpression('/public function \w+\([^)]*\): \\\\Generator/', $source);

        [$users, $sent] = self::load($document, $body, $media);
        $result = $users->getById(1);

        self::assertInstanceOf(\Generator::class, $result);
        self::assertSame(['Ada', 'Grace'], array_map(static fn (object $user): string => $user->name, iterator_to_array($result, false)));
        self::assertSame($media, $sent[0]['accept']);
    }

    public function testServerSentEventsNeedNoSchema(): void
    {
        $document = self::document('text/event-stream', withSchema: false);
        [$users] = self::load($document, "event: tick\ndata: hi\n\n", 'text/event-stream');

        $event = $users->getById(1)->current();

        self::assertSame('tick', $event->event);
        self::assertSame('hi', $event->data);
        self::assertStringContainsString('ServerSentEvent', self::emit($document)['src/Resources/Users.php']);
    }

    public function testServerSentEventsWithASchemaCarryTheHydratedItem(): void
    {
        [$users] = self::load(self::document('text/event-stream'), "data: {\"id\":3,\"name\":\"Linus\"}\n\n", 'text/event-stream');

        self::assertSame('Linus', $users->getById(1)->current()->item->name);
    }

    public function testJsonAlongsideAStreamKeepsTheBufferedValue(): void
    {
        $document = self::document('application/jsonl');
        $document['paths']['/users/{id}']['get']['responses']['200']['content']['application/json'] = ['schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']]];

        self::assertStringNotContainsString('Generator', self::emit($document)['src/Resources/Users.php']);
    }

    public function testErrorStatusesAreNeverStreamed(): void
    {
        $document = self::document('application/jsonl');
        $document['paths']['/users/{id}']['get']['responses']['400'] = ['description' => 'bad', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]];

        self::assertStringContainsString('Generator', self::emit($document)['src/Resources/Users.php']);
    }

    public function testEncodingLookupIgnoresCaseAndParameters(): void
    {
        self::assertSame('sse', StreamingSupport::encoding('Text/Event-Stream; charset=utf-8'));
        self::assertSame('jsonl', StreamingSupport::encoding('application/x-ndjson'));
        self::assertNull(StreamingSupport::encoding('application/json'));
        self::assertNull(StreamingSupport::encoding(null));
    }
}
