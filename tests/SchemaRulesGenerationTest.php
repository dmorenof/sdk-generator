<?php
declare(strict_types=1);
namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SchemaRulesGenerationTest extends TestCase {
    private static function document(): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/schema-rules-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    private static function files(array $document): array {
        return (new PhpExecutableEmitter('Example\\SchemaRulesSdk', 'example/schema-rules'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true));
    }
    public static function setUpBeforeClass(): void {
        $files = self::files(self::document());
        foreach (['src/Client.php', 'src/Configuration.php', 'src/SchemaRulesAPISDK.php'] as $path) { eval(substr($files[$path], 5)); unset($files[$path]); }
        foreach ($files as $path => $source) { if (str_ends_with($path, '.php')) { eval(substr($source, 5)); } }
    }
    private static function oldBounds(array $value): array {
        foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = self::oldBounds($item); } }
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $inclusive) {
            if (isset($value[$exclusive]) && is_numeric($value[$exclusive])) { $value[$inclusive] = $value[$exclusive]; $value[$exclusive] = true; }
        }
        return $value;
    }
    public function testJsonYamlRulesMetadataAndConstrainedEnums(): void {
        $document = self::document(); $files = self::files($document);
        self::assertSame($files, (new PhpExecutableEmitter('Example\\SchemaRulesSdk', 'example/schema-rules'))->emit(
            (new SpecificationNormalizer())->fromYaml(Yaml::dump($document, 40), strict: true)));
        self::assertStringContainsString('MODEL_VALIDATION', $files['src/DTOs/Record.php']);
        self::assertStringContainsString('MAP_VALIDATION', $files['src/DTOs/Profile.php']);
        self::assertStringContainsString('responseValidation', $files['src/Resources/Scalar.php']);
        self::assertStringContainsString('COLLECTION_VALIDATION', implode('', $files));
        self::assertStringContainsString('case Value1 = 1;', $files['src/Enums/NumericStatus.php']);
        self::assertStringContainsString('case Value2 = 2;', $files['src/Enums/NumericStatus.php']);
        self::assertStringNotContainsString('case Value0', $files['src/Enums/NumericStatus.php']);
        self::assertStringNotContainsString('case Value3', $files['src/Enums/NumericStatus.php']);
    }
    public function testOpenApi30AndSwaggerBooleanExclusiveBoundsNormalizeIdentically(): void {
        $document = self::document();
        $document['components']['schemas']['Record']['properties']['tag'] = ['type' => 'integer', 'enum' => [7]];
        $expected = self::files($document); $old = self::oldBounds($document); $old['openapi'] = '3.0.3';
        self::assertSame($expected, self::files($old));
        $swagger = ['swagger' => '2.0', 'info' => $old['info'], 'host' => 'api.example.test', 'schemes' => ['https'], 'produces' => ['application/json'], 'consumes' => ['application/json'], 'definitions' => $old['components']['schemas'], 'paths' => []];
        foreach ($old['paths'] as $path => $item) {
            foreach ($item as $verb => $operation) {
                $parameters = [];
                foreach ($operation['parameters'] ?? [] as $parameter) { $parameters[] = array_diff_key($parameter, ['schema' => true]) + $parameter['schema']; }
                if (isset($operation['requestBody'])) { $parameters[] = ['name' => 'body', 'in' => 'body', 'required' => true, 'schema' => $operation['requestBody']['content']['application/json']['schema']]; }
                unset($operation['requestBody']); $operation['parameters'] = $parameters;
                foreach ($operation['responses'] as &$response) { $response['schema'] = $response['content']['application/json']['schema']; unset($response['content']); } unset($response);
                $swagger['paths'][$path][$verb] = $operation;
            }
        }
        $swagger = json_decode(str_replace('#/components/schemas/', '#/definitions/', json_encode($swagger, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, self::files($swagger));
    }
    public function testConstructorAndMutationValidationIncludesModelsMapsAndCollections(): void {
        $valid = (object) ['amount' => 0.3, 'rows' => [1, 2], 'tag' => 7, 'profile' => (object) ['only' => 1], 'label' => 'ñ'];
        $record = new \Example\SchemaRulesSdk\DTOs\Record($valid);
        self::assertSame(7, $record->tag); self::assertCount(2, $record->rows);
        foreach (['amount' => 0.31, 'tag' => 8, 'rows' => [1, 1], 'profile' => (object) [], 'label' => 'xx'] as $field => $invalid) {
            $data = clone $valid; $data->$field = $invalid;
            try { new \Example\SchemaRulesSdk\DTOs\Record($data); self::fail('Invalid schema constraint accepted.'); }
            catch (\UnexpectedValueException|\ValueError) { self::assertTrue(true); }
        }
        $record->rows[] = 1;
        try { $record->jsonSerializeForRequest(); self::fail('Duplicate mutation accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
        $record = new \Example\SchemaRulesSdk\DTOs\Record($valid); unset($record->profile['only']);
        try { $record->jsonSerializeForResponse(); self::fail('Map property-count mutation accepted.'); } catch (\UnexpectedValueException) { self::assertTrue(true); }
    }
    public function testScalarResponseRulesRejectInvalidDataAndValidateStatusMaps(): void {
        foreach ([0.3, 0.31, 0, 1] as $number) {
            $http = new class($number) implements \Psr\Http\Client\ClientInterface {
                public function __construct(private float|int $number) {}
                public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \GuzzleHttp\Psr7\Response(200, [], json_encode($this->number, JSON_THROW_ON_ERROR)); }
            };
            $resource = \Example\SchemaRulesSdk\Client::connect('https://example.test', httpClient: $http)->resource(\Example\SchemaRulesSdk\Resources\Scalar::class);
            try { self::assertSame(0.3, $resource->get()); self::assertSame(0.3, $number); }
            catch (\UnexpectedValueException) { self::assertNotSame(0.3, $number); }
        }
        $document = self::document(); $document['paths']['/scalar']['get']['responses']['201'] = $document['paths']['/scalar']['get']['responses']['200'];
        self::assertStringContainsString("'rules' =>", self::files($document)['src/Resources/Scalar.php']);
    }
    public function testInvalidRulesAndDialectMismatchesAreRejected(): void {
        foreach ([['multipleOf' => 0], ['multipleOf' => -1], ['exclusiveMinimum' => true], ['exclusiveMaximum' => false], ['uniqueItems' => 1]] as $rules) { // a complex const such as [] is a supported guard now
            $document = self::document(); $document['components']['schemas']['Record']['properties']['amount'] = ['type' => 'number'] + $rules;
            try { self::files($document); self::fail('Invalid rule accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $document = self::document(); $document['openapi'] = '3.0.3';
        $this->expectException(\InvalidArgumentException::class); self::files($document);
    }
}
