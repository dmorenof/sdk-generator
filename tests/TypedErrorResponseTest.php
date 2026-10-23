<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class TypedErrorResponseTest extends TestCase
{
    /** @return array<string,string> */
    private static function files(): array
    {
        $input = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        self::assertIsString($input);
        $spec = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        $spec['components']['schemas']['ApiError'] = [
            'type' => 'object',
            'required' => ['message', 'code'],
            'properties' => [
                'message' => ['type' => 'string', 'minLength' => 3],
                'code' => ['type' => 'integer'],
            ],
        ];
        $error = [
            'description' => 'Error',
            'content' => ['application/json' => ['schema' => [
                '$ref' => '#/components/schemas/ApiError',
            ]]],
        ];
        $spec['paths']['/users/{id}']['get']['responses']['404'] = $error;
        $spec['paths']['/users/{id}']['get']['responses']['default'] = $error;
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\ErrorSdk', 'example/error-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::files();
        foreach ([
            'src/Enums/NewUser/RoleEnum.php', 'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php',
            'src/Responses/ApiError.php', 'src/DTOs/Profile.php',
            'src/Requests/NewUser.php', 'src/Responses/User.php',
            'src/Client.php', 'src/Api/Users/User.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    private static function connect(int $status, string $body): \Example\ErrorSdk\Client
    {
        $transport = new class($status, $body) implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public function __construct(private int $status, private string $body) {}
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                    $this->status,
                    $this->body,
                    ['X-Correlation-ID' => 'test-123'],
                );
            }
        };
        return \Example\ErrorSdk\Client::connect('https://api.example.test/v1', transport: $transport);
    }

    public function testDeclaredErrorResponseHydratesTypedDTOAndRetainsRawHTTP(): void
    {
        $payload = '{"message":"Missing user","code":4041}';
        try {
            $client = self::connect(404, $payload);
            $client->resource(\Example\ErrorSdk\Api\Users\User::class)->getById(42);
            self::fail('Expected typed HTTP exception');
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertSame(404, $e->getCode());
            self::assertSame(404, $e->response->status);
            self::assertSame($payload, $e->response->body);
            self::assertSame('test-123', $e->response->headers['X-Correlation-ID']);
            self::assertInstanceOf(\Example\ErrorSdk\Responses\ApiError::class, $e->error);
            self::assertSame('Missing user', $e->error->message);
            self::assertSame(4041, $e->error->code);
            self::assertNull($e->getPrevious());
        }
    }

    public function testDefaultErrorSchemaAppliesToOtherwiseUnknownStatus(): void
    {
        try {
            $client = self::connect(503, '{"message":"Service unavailable","code":503}');
            $client->resource(\Example\ErrorSdk\Api\Users\User::class)->getById(42);
            self::fail('Expected HTTP exception');
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            self::assertSame(503, $e->response->status);
            self::assertInstanceOf(\Example\ErrorSdk\Responses\ApiError::class, $e->error);
            self::assertSame(503, $e->error->code);
        }
    }

    public function testInvalidErrorDTOStillPreservesResponseAndOriginalHydrationError(): void
    {
        try {
            $client = self::connect(404, '{"message":"No"}');
            $client->resource(\Example\ErrorSdk\Api\Users\User::class)->getById(42);
            self::fail('Expected HTTP exception');
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            self::assertSame(404, $e->response->status);
            self::assertSame('{"message":"No"}', $e->response->body);
            self::assertNull($e->error);
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    public function testErrorDecoderDoesNotLoseRawBodyOnInvalidJson(): void
    {
        try {
            $client = self::connect(404, '{broken');
            $client->resource(\Example\ErrorSdk\Api\Users\User::class)->getById(42);
            self::fail('Expected HTTP exception');
        } catch (\Dmorenof\SdkRuntime\ApiException $e) {
            self::assertSame('{broken', $e->response->body);
            self::assertNull($e->error);
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}
