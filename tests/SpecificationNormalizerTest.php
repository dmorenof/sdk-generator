<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SpecificationNormalizerTest extends TestCase
{
    private SpecificationNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SpecificationNormalizer();
    }

    private function fixture(string $filename): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $filename);
    }

    public function testThreeDialectsProduceTheSameTypedContract(): void
    {
        $swagger2 = $this->normalizer->fromJson($this->fixture('accounts-swagger2.json'));
        $openapi30 = $this->normalizer->fromJson($this->fixture('accounts-openapi30.json'));
        $openapi31 = $this->normalizer->fromJson($this->fixture('accounts-openapi31.json'));

        self::assertEquals($swagger2, $openapi30);
        self::assertEquals($swagger2, $openapi31);
        self::assertSame(['https://api.example.test/v1'], $swagger2->servers);
        self::assertCount(3, $swagger2->models);
        self::assertCount(2, $swagger2->operations);
        self::assertSame('createUser', $swagger2->operations[0]->operationId);
        self::assertSame('getUser', $swagger2->operations[1]->operationId);
    }

    public function testCanonicalContractKeepsWireTypesRequiredAndNullable(): void
    {
        $contract = $this->normalizer->fromJson($this->fixture('accounts-swagger2.json'));
        $user = $contract->models[2]->schema;
        self::assertSame(SchemaKind::Object, $user->kind);

        $props = [];
        foreach ($user->properties as $property) {
            $props[$property->wireName] = $property;
        }
        self::assertTrue($props['id']->required);
        self::assertSame(SchemaKind::Integer, $props['id']->schema->kind);
        self::assertSame('int64', $props['id']->schema->format);
        self::assertFalse($props['nickname']->required);
        self::assertTrue($props['nickname']->schema->nullable);
        self::assertSame(SchemaKind::Array, $props['labels']->schema->kind);
        self::assertSame(SchemaKind::String, $props['labels']->schema->items->kind);
        self::assertSame(SchemaKind::Reference, $props['profile']->schema->kind);
        self::assertSame('Profile', $props['profile']->schema->reference);
        self::assertSame(['admin', 'viewer'], $props['role']->schema->enumValues);

        $get = $contract->operations[1];
        self::assertSame('GET', $get->method);
        self::assertSame('/users/{id}', $get->path);
        self::assertCount(2, $get->parameters);
        self::assertSame('id', $get->parameters[0]->wireName);
        self::assertTrue($get->parameters[0]->required);
        self::assertSame('200', $get->responses[0]->status);
        self::assertSame('User', $get->responses[0]->schema->reference);

        $post = $contract->operations[0];
        self::assertSame('POST', $post->method);
        self::assertSame('NewUser', $post->requestBody->schema->reference);
        self::assertSame('application/json', $post->requestBody->mediaType);
        self::assertTrue($post->requestBody->required);
        self::assertSame('201', $post->responses[0]->status);
    }

    public function testUnknownSpecVersionFailsExplicitly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported specification version');
        $this->normalizer->fromJson('{"openapi":"4.0.0","info":{"title":"future"},"paths":{}}');
    }

    public function testUnresolvedModelReferenceFailsWithContext(): void
    {
        $raw = json_decode($this->fixture('accounts-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
        $raw['paths']['/users/{id}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] = '#/components/schemas/UnknownModel';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unresolved schema reference 'UnknownModel' at getUser.responses.200");
        $this->normalizer->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
    }

    public function testUnsupportedComposedSchemaIsNotSilentlyDropped(): void
    {
        $raw = json_decode($this->fixture('accounts-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
        $raw['components']['schemas']['User']['allOf'] = [['type' => 'object']];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported schema keyword 'allOf' at models.User");
        $this->normalizer->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
    }

    public function testUnsupportedApiSecurityCannotBeIgnored(): void
    {
        $raw = json_decode($this->fixture('accounts-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
        $raw['security'] = [['bearerAuth' => []]];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported security requirements');
        $this->normalizer->fromJson(json_encode($raw, JSON_THROW_ON_ERROR));
    }

    public function testInvalidJsonFailsWithCause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid specification JSON');
        $this->normalizer->fromJson('{"invalid":');
    }
}
