<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Two operations sharing one URL must not collide with its reusable User DTO. */
final class OperationEnvelopeTest extends TestCase
{
    /** @return array<string,string> */
    private static function generate(): array
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Envelope Example', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => [
                '/api/v1/user' => [
                    'get' => [
                        'operationId' => 'getUser',
                        'responses' => ['200' => [
                            'description' => 'The user status',
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['result'],
                                'properties' => [
                                    'result' => ['type' => 'boolean'],
                                ],
                            ]]],
                        ]],
                    ],
                    'post' => [
                        'operationId' => 'createUser',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                '$ref' => '#/components/schemas/Api.V1.User',
                            ]]],
                        ],
                        'responses' => ['200' => [
                            'description' => 'User created',
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['result', 'msg', 'user'],
                                'properties' => [
                                    'result' => ['type' => 'boolean'],
                                    'msg' => ['type' => 'string'],
                                    'user' => ['$ref' => '#/components/schemas/Api.V1.User'],
                                ],
                            ]]],
                        ]],
                    ],
                    'patch' => [
                        'operationId' => 'partialUser',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['name'],
                                'properties' => ['name' => ['type' => 'string']],
                            ]]],
                        ],
                        'responses' => ['200' => [
                            'description' => 'Updated',
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['msg'],
                                'properties' => ['msg' => ['type' => 'string']],
                            ]]],
                        ]],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                'Api.V1.User' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'readOnly' => true],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ]],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\EnvelopeSDK', 'example/envelope-sdk'))->emit($contract);
    }

    public function testReusableSchemaAndOperationEnvelopesHaveDistinctNamespacesAndSingleBase(): void
    {
        $files = self::generate();
        self::assertArrayHasKey('src/DTOs/Api/V1/User.php', $files);
        self::assertArrayHasKey('src/Responses/Api/V1/User/Get.php', $files);
        self::assertArrayHasKey('src/Responses/Api/V1/User/Post.php', $files);
        self::assertArrayHasKey('src/Responses/Api/V1/User/Patch.php', $files);
        self::assertArrayHasKey('src/Requests/Api/V1/User/Patch.php', $files);
        self::assertArrayHasKey('src/Api/V1/User.php', $files);

        foreach ([
            'src/DTOs/Api/V1/User.php',
            'src/Responses/Api/V1/User/Get.php',
            'src/Responses/Api/V1/User/Post.php',
            'src/Requests/Api/V1/User/Patch.php',
        ] as $path) {
            self::assertStringContainsString('extends \\Dmorenof\\SdkRuntime\\DTO', $files[$path]);
        }
        self::assertStringContainsString(
            'public \\Example\\EnvelopeSDK\\DTOs\\Api\\V1\\User $user;',
            $files['src/Responses/Api/V1/User/Post.php'],
        );
        self::assertStringContainsString(
            'public static function post(\\Example\\EnvelopeSDK\\DTOs\\Api\\V1\\User $payload): \\Example\\EnvelopeSDK\\Responses\\Api\\V1\\User\\Post',
            $files['src/Api/V1/User.php'],
        );
        self::assertStringContainsString(
            'public static function patch(\\Example\\EnvelopeSDK\\Requests\\Api\\V1\\User\\Patch $payload): \\Example\\EnvelopeSDK\\Responses\\Api\\V1\\User\\Patch',
            $files['src/Api/V1/User.php'],
        );
        foreach ($files as $sourcePath => $source) {
            if (!str_ends_with($sourcePath, '.php')) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'envelope-lint');
            try {
                file_put_contents($tmp, $source);
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
                self::assertSame(0, $code, $sourcePath . ': ' . implode("\n", $out));
                $out = [];
                $code = 0;
            } finally {
                unlink($tmp);
            }
        }
    }
    public function testInlineRequestAndResponseEnvelopesExecuteAndHydrateNestedUser(): void
    {
        $files = self::generate();
        $loader = static function (string $class) use ($files): void {
            $prefix = 'Example\\EnvelopeSDK\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (isset($files[$path])) {
                eval(substr($files[$path], strlen('<?php')));
            }
        };
        spl_autoload_register($loader);
        try {
            $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
                public array $calls = [];
                public function send(
                    string $method,
                    string $url,
                    array $headers,
                    ?string $body,
                ): \Dmorenof\SdkRuntime\Transport\HttpResponse {
                    $this->calls[] = compact('method', 'url', 'headers', 'body');
                    return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                        200,
                        match ($method) {
                            'GET' => '{"result":true}',
                            'POST' => '{"result":true,"msg":"Todo ok","user":{"id":9,"name":"Ada"}}',
                            'PATCH' => '{"msg":"Updated"}',
                            default => throw new \LogicException('Unexpected method ' . $method),
                        },
                    );
                }
            };
            $client = \Example\EnvelopeSDK\Client::connect(
                'https://envelope.test',
                transport: $transport,
            );
            $resource = $client->resource(\Example\EnvelopeSDK\Resources\Api\V1\User::class);

            $user = new \Example\EnvelopeSDK\DTOs\Api\V1\User((object) ['name' => 'Ada']);
            $created = $resource->post($user);
            self::assertInstanceOf(\Example\EnvelopeSDK\Responses\Api\V1\User\Post::class, $created);
            self::assertSame('Todo ok', $created->msg);
            self::assertTrue($created->result);
            self::assertInstanceOf(\Example\EnvelopeSDK\DTOs\Api\V1\User::class, $created->user);
            self::assertSame(9, $created->user->id);
            self::assertSame('{"name":"Ada"}', $transport->calls[0]['body']);

            $patched = $resource->patch(
                new \Example\EnvelopeSDK\Requests\Api\V1\User\Patch((object) ['name' => 'Updated']),
            );
            self::assertInstanceOf(\Example\EnvelopeSDK\Responses\Api\V1\User\Patch::class, $patched);
            self::assertSame('Updated', $patched->msg);
            self::assertSame('{"name":"Updated"}', $transport->calls[1]['body']);

            $read = $resource->get();
            self::assertInstanceOf(\Example\EnvelopeSDK\Responses\Api\V1\User\Get::class, $read);
            self::assertTrue($read->result);
            self::assertSame('https://envelope.test/api/v1/user', $transport->calls[2]['url']);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

}
