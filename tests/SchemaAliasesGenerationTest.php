<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SchemaAliasesGenerationTest extends TestCase {
    private static function document(): array { return json_decode(file_get_contents(__DIR__ . '/fixtures/schema-aliases-openapi31.json'), true, 512, JSON_THROW_ON_ERROR); }
    private static function files(array $document, string $namespace = 'Example\\AliasSdk'): array { return (new PhpExecutableEmitter($namespace, 'example/aliases'))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true)); }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SchemaAliasAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    public function testAliasChainsReuseCanonicalTypesAndPhpInheritance(): void {
        $files = self::files(self::document());
        self::assertSame($files, (new PhpExecutableEmitter('Example\\AliasSdk', 'example/aliases'))->emit((new SpecificationNormalizer())->fromYaml(Yaml::dump(self::document(), 40), strict: true)));
        foreach (array_keys($files) as $path) { if (str_starts_with($path, 'src/DTOs/') || str_starts_with($path, 'src/Enums/')) { self::assertStringNotContainsString('Alias', basename($path)); } }
        $child = new \Example\AliasSdk\DTOs\ChildRecord((object) ['id' => 1, 'name' => 'Alice', 'status' => 'open']);
        self::assertInstanceOf(\Example\AliasSdk\DTOs\BaseRecord::class, $child);
        self::assertSame(\Example\AliasSdk\Enums\StatusCode::Open, $child->status);
        self::assertFalse((new \ReflectionClass(\Example\AliasSdk\DTOs\BaseRecord::class))->isFinal());
        $node = new \Example\AliasSdk\DTOs\NodeRecord((object) ['id' => 1, 'next' => (object) ['id' => 2]]);
        self::assertInstanceOf($node::class, $node->next); self::assertSame(2, $node->next->id);
        $choice = \Example\AliasSdk\DTOs\OneOfRecordChoice::fromValue((object) ['id' => 1, 'name' => 'Alice']);
        self::assertInstanceOf($child::class, $choice->matchedVariants()[0]);
        $choice->matchedVariants()[0]->name = 'x'; $this->expectException(\UnexpectedValueException::class); $choice->jsonSerialize();
    }
    public function testAliasesWorkAcrossHttpAndFlatQueryEnumFields(): void {
        $http = new class implements \Psr\Http\Client\ClientInterface {
            public array $calls = [];
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $this->calls[] = $request;
                return new \GuzzleHttp\Psr7\Response(200, [], $request->getMethod() === 'GET' ? '"open"' : (string) $request->getBody());
            }
        };
        $client = \Example\AliasSdk\Client::connect('https://example.test', httpClient: $http);
        $child = new \Example\AliasSdk\DTOs\ChildRecord((object) ['id' => 1, 'name' => 'Alice', 'status' => 'open']);
        $result = $client->resource(\Example\AliasSdk\Resources\Record::class)->post($child); self::assertInstanceOf($child::class, $result); self::assertSame($child->status, $result->status);
        self::assertSame(\Example\AliasSdk\Enums\StatusCode::Open, $client->resource(\Example\AliasSdk\Resources\Status::class)->post($child->status));
        $params = new \Example\AliasSdk\GetParams\Filter((object) ['filter' => (object) ['state' => 'closed']]);
        self::assertSame(\Example\AliasSdk\Enums\StatusCode::Open, $client->resource(\Example\AliasSdk\Resources\Filter::class)->get($params));
        parse_str($http->calls[2]->getUri()->getQuery(), $query); self::assertSame(['filter' => ['state' => 'closed']], $query);
    }
    public function testDiscriminatorAliasesSelectCanonicalChildrenAndKeepExclusiveValidation(): void {
        $document = json_decode(file_get_contents(__DIR__ . '/fixtures/pets-discriminator-openapi30.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['Pet', 'Dog', 'Cat'] as $name) { $document['components']['schemas'][$name . 'Alias'] = ['$ref' => '#/components/schemas/' . $name]; }
        foreach (['Dog', 'Cat'] as $name) { $document['components']['schemas'][$name]['allOf'][0]['$ref'] .= 'Alias'; }
        $document['components']['schemas']['Pet']['discriminator']['mapping']['canine'] .= 'Alias';
        $document['components']['schemas']['PetChoice']['oneOf'][0]['$ref'] .= 'Alias';
        $files = self::files($document, 'Example\\AliasPets');
        $loader = static function (string $class) use ($files): void {
            $prefix = 'Example\\AliasPets\\'; if (!str_starts_with($class, $prefix)) { return; }
            $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (isset($files[$path])) { eval(substr($files[$path], 5)); }
        };
        spl_autoload_register($loader);
        try {
            $pet = \Example\AliasPets\DTOs\Pet::fromValue((object) ['pet_type' => 'canine', 'bark' => true]);
            self::assertInstanceOf(\Example\AliasPets\DTOs\Dog::class, $pet);
            $choice = \Example\AliasPets\DTOs\OneOfPetChoice::fromValue((object) ['pet_type' => 'canine', 'bark' => true]);
            self::assertInstanceOf($pet::class, $choice->matchedVariants()[0]);
            self::assertSame('canine', $choice->jsonSerialize()->pet_type);
        } finally { spl_autoload_unregister($loader); }
    }
    public function testSchemaPointersReuseUriAndTildeDecoding(): void {
        $document = self::document();
        $document['components']['schemas']['Status-Code'] = $document['components']['schemas']['StatusCode'];
        unset($document['components']['schemas']['StatusCode']);
        $document['components']['schemas']['StatusAlias']['$ref'] = '#%2Fcomponents%2Fschemas%2FStatus%2DCode';
        $files = self::files($document);
        self::assertStringContainsString('Enums\\StatusCode $status', $files['src/DTOs/BaseRecord.php']);
        $swagger = ['swagger' => '2.0', 'host' => 'example.test', 'schemes' => ['https'], 'info' => ['title' => 'Pointer API', 'version' => '1'],
            'definitions' => ['User~One/Two' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], 'PointerAlias' => ['$ref' => '#/definitions/User~0One~1Two']],
            'paths' => ['/pointer' => ['get' => ['operationId' => 'getPointer', 'responses' => ['200' => ['description' => 'Result', 'schema' => ['$ref' => '#/definitions/PointerAlias']]]]]]];
        $files = self::files($swagger);
        self::assertArrayHasKey('src/DTOs/UserOneTwo.php', $files);
        self::assertArrayNotHasKey('src/DTOs/PointerAlias.php', $files);
        self::assertStringContainsString('DTOs\\UserOneTwo', $files['src/Resources/Pointer.php']);
    }
    public function testEquivalentAliasBranchesStillViolateOneOfExclusivity(): void {
        $document = self::document();
        $document['components']['schemas']['RecordChoice']['oneOf'] = [['$ref' => '#/components/schemas/ChildRecord'], ['$ref' => '#/components/schemas/ChildAlias']];
        $files = self::files($document, 'Example\\AmbiguousAliasSdk');
        $loader = static function (string $class) use ($files): void {
            $prefix = 'Example\\AmbiguousAliasSdk\\'; if (!str_starts_with($class, $prefix)) { return; }
            $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (isset($files[$path])) { eval(substr($files[$path], 5)); }
        };
        spl_autoload_register($loader);
        try {
            try { \Example\AmbiguousAliasSdk\DTOs\OneOfRecordChoice::fromValue((object) ['id' => 1, 'name' => 'Alice']); self::fail('Equivalent aliases hid oneOf ambiguity.'); }
            catch (\UnexpectedValueException) { self::assertTrue(true); }
        } finally { spl_autoload_unregister($loader); }
    }
    public function testCyclicAndMissingAliasTargetsFailBeforeGeneration(): void {
        foreach ([['$ref' => '#/components/schemas/BaseAlias2'], ['$ref' => '#/components/schemas/Missing']] as $schema) {
            $document = self::document(); $document['components']['schemas']['BaseRecord'] = $schema;
            try { self::files($document); self::fail('Invalid alias target accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
