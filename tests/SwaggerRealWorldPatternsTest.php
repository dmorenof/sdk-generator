<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\ContractAuditor;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Patterns the Swagger Petstore (Swagger 2.0) uses and real Swagger documents share. */
final class SwaggerRealWorldPatternsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(): array
    {
        return [
            'swagger' => '2.0',
            'info' => ['title' => 'Pets', 'version' => '1'],
            'host' => 'pets.example.com',
            'schemes' => ['https'],
            'paths' => [
                '/pet' => ['post' => [
                    'operationId' => 'addPet',
                    // JSON first, an XML twin of the same schema after it.
                    'consumes' => ['application/json', 'application/xml'],
                    'produces' => ['application/json', 'application/xml'],
                    'parameters' => [['in' => 'body', 'name' => 'body', 'required' => true, 'schema' => ['$ref' => '#/definitions/Pet']]],
                    // Only the failure is described: the success is not.
                    'responses' => ['405' => ['description' => 'Invalid input']],
                ]],
                '/pets' => ['post' => [
                    'operationId' => 'addPets',
                    'consumes' => ['application/json'],
                    'parameters' => [['in' => 'body', 'name' => 'body', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/Pet']]]],
                    'responses' => ['default' => ['description' => 'ok']],
                ]],
                '/pet/{id}' => ['get' => [
                    'operationId' => 'getPet',
                    'produces' => ['application/json', 'application/xml'],
                    'parameters' => [['in' => 'path', 'name' => 'id', 'required' => true, 'type' => 'integer', 'format' => 'int64']],
                    'responses' => ['200' => ['description' => 'ok', 'schema' => ['$ref' => '#/definitions/Pet']]],
                ]],
            ],
            'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
        ];
    }

    /** @return array<string, string> */
    private static function files(): array
    {
        return (new PhpExecutableEmitter('Example\\SwaggerReal', 'example/swagger-real', []))
            ->emit((new SpecificationNormalizer())->fromJson(json_encode(self::document(), JSON_THROW_ON_ERROR)));
    }

    public function testAnXmlTwinAfterJsonIsNotEmittedAndDoesNotRefuseTheOperation(): void
    {
        $files = self::files();
        self::assertStringContainsString('function post(\\Example\\SwaggerReal\\DTOs\\Pet $payload)', $files['src/Resources/Pet.php']);
        self::assertStringContainsString('function getById(int $id)', $files['src/Resources/Pet.php']);
    }

    public function testAnOperationThatListsOnlyFailuresSucceedsWithAnyTwoHundredAndReturnsNothing(): void
    {
        $resource = self::files()['src/Resources/Pet.php'];
        self::assertMatchesRegularExpression("/'success' => \\[\\s+'2XX',\\s+\\]/", $resource);
        self::assertStringContainsString('function post(\\Example\\SwaggerReal\\DTOs\\Pet $payload): void', $resource);
    }

    public function testASwaggerBodyThatIsAListOfModelsIsATypedCollectionNotAnUnsupportedParameter(): void
    {
        $document = self::document();
        $errors = array_filter(
            (new ContractAuditor())->audit($document, 'pets.json'),
            static fn ($issue): bool => $issue->severity === 'error',
        );
        self::assertSame([], array_values($errors));
        $all = implode("\n", self::files());
        self::assertStringContainsString('extends \\Dmorenof\\SdkRuntime\\TypedArray', $all);
        self::assertMatchesRegularExpression('/function post\(\\\\Example\\\\SwaggerReal\\\\Collections\\\\\w+ \$payload\): void/', $all);
    }

    public function testAnXmlOnlyBodyIsStillRefused(): void
    {
        $document = self::document();
        $document['paths']['/pet']['post']['consumes'] = ['application/xml'];
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function testAnOperationWithoutAnyResponseIsStillRefused(): void
    {
        $document = self::document();
        $document['paths']['/pet']['post']['responses'] = [];
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }
}
