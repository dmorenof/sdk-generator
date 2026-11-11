<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Declared formats are annotations unless the SDK is generated with format validation. */
final class FormatValidationGenerationTest extends TestCase
{
    private static int $loaded = 0;

    /** @return array<string, mixed> */
    private static function document(): array
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $user = &$document['components']['schemas']['User']['properties'];
        $user['email'] = ['type' => 'string', 'format' => 'email'];
        $user['token'] = ['type' => 'string', 'format' => 'uuid'];
        $user['avatar'] = ['type' => 'string', 'format' => 'binary'];
        $user['score'] = ['type' => 'number', 'format' => 'double'];
        return $document;
    }

    /** @return array<string, string> */
    private static function emit(bool $validate): array
    {
        $number = ++self::$loaded;
        return (new PhpExecutableEmitter("Example\\FormatSdk$number", "example/format-$number"))
            ->emit((new SpecificationNormalizer($validate))->fromJson(json_encode(self::document(), JSON_THROW_ON_ERROR)));
    }

    private static function users(bool $validate, string $body): object
    {
        $files = self::emit($validate);
        $namespace = 'Example\\FormatSdk' . self::$loaded;
        spl_autoload_register(static function (string $class) use ($files, $namespace): void {
            if (str_starts_with($class, $namespace . '\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($namespace) + 1)) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
        $http = new class ($body) implements ClientInterface {
            public function __construct(private readonly string $body) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->body);
            }
        };
        $client = $namespace . '\\Client';
        return $client::connect('https://api.example.test/v1', $http)->resource($namespace . '\\Resources\\Users');
    }

    public function testFormatsAreAssertedWhenRequested(): void
    {
        $source = implode("\n", self::emit(true));

        self::assertStringContainsString("'format' => 'email'", $source);
        self::assertStringContainsString("'format' => 'uuid'", $source);
        self::assertStringContainsString("'format' => 'int64'", $source, 'integers carry their format too');
        self::assertStringNotContainsString("'format' => 'binary'", $source);
        self::assertStringNotContainsString("'format' => 'double'", $source);
    }

    public function testNothingIsAssertedByDefault(): void
    {
        self::assertStringNotContainsString("'format' =>", implode("\n", self::emit(false)));
    }

    public function testInvalidValuesAreRejectedOnlyWithTheOption(): void
    {
        $body = '{"id":1,"name":"Ada","email":"not-an-email","token":"nope"}';

        self::assertSame('Ada', self::users(false, $body)->getById(1)->name);

        $this->expectException(\Throwable::class);
        self::users(true, $body)->getById(1);
    }

    public function testValidValuesPassWithTheOption(): void
    {
        $body = '{"id":1,"name":"Ada","email":"ada@example.test","token":"123e4567-e89b-42d3-a456-426614174000"}';

        self::assertSame('ada@example.test', self::users(true, $body)->getById(1)->email);
    }
}
