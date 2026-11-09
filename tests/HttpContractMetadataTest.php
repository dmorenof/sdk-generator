<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class HttpContractMetadataTest extends TestCase
{
    private static function document(string $fixture = 'accounts-openapi31.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function normalize(array $document): \Dmorenof\SdkGenerator\Contract\ApiContract
    {
        return (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function testParameterSerializationRetainsExplicitFalseValuesAndAnnotations(): void
    {
        $document = self::document();
        $parameter = &$document['paths']['/users/{id}']['get']['parameters'][1];
        $parameter += ['style' => 'form', 'explode' => false, 'allowReserved' => false,
            'allowEmptyValue' => true, 'description' => 'Filter', 'deprecated' => true];
        $parameter['schema'] = ['type' => 'array', 'items' => ['type' => 'string']];
        $contract = self::normalize($document);
        unset($parameter); // Stop referencing the original source node before reading the IR.
        $parameter = $contract->operations[1]->parameters[1];
        self::assertSame('form', $parameter->serialization->style);
        self::assertFalse($parameter->serialization->explode);
        self::assertFalse($parameter->serialization->allowReserved);
        self::assertTrue($parameter->serialization->allowEmptyValue);
        self::assertSame('Filter', $parameter->annotations['description']);
        self::assertTrue($parameter->annotations['deprecated']);
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['strictlyAccepted']);
        self::assertTrue($report['normalized']);
        self::assertSame([], $report['diagnostics']);
    }

    public function testContentParametersArePreservedButRemainStrictlyUnsupported(): void
    {
        $document = self::document();
        $parameter = &$document['paths']['/users/{id}']['get']['parameters'][1];
        unset($parameter['schema']);
        $parameter['content'] = ['application/json' => ['schema' => ['type' => 'boolean'], 'example' => true]];
        $contract = self::normalize($document);
        $content = $contract->operations[1]->parameters[1]->content;
        self::assertSame('application/json', $content[0]->mediaType);
        self::assertTrue($content[0]->annotations['example']);
        self::assertFalse((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testSwaggerCollectionFormatIsNotMisparsedAsASchemaKeyword(): void
    {
        $document = self::document('accounts-swagger2.json');
        $parameter = &$document['paths']['/users/{id}']['get']['parameters'][1];
        $parameter['type'] = 'array';
        $parameter['items'] = ['type' => 'string'];
        $parameter['collectionFormat'] = 'multi';
        $parameter['x-note'] = 'retained annotation';
        $contract = self::normalize($document);
        self::assertSame('multi', $contract->operations[1]->parameters[1]->serialization->collectionFormat);
        self::assertSame('retained annotation', $contract->operations[1]->parameters[1]->annotations['x-note']);
        self::assertSame('application/json', $contract->operations[0]->requestBody->content[0]->mediaType);
    }

    public function testEffectiveServerPrecedenceAndPreferenceOrderArePreserved(): void
    {
        $document = self::document();
        $document['servers'] = [['url' => 'https://z.example.test'], ['url' => 'https://a.example.test']];
        $document['paths']['/users/{id}']['servers'] = [['url' => 'https://path.example.test', 'description' => 'path host']];
        $contract = self::normalize($document);
        self::assertSame(['https://z.example.test', 'https://a.example.test'], $contract->servers);
        self::assertSame(['https://path.example.test'], $contract->operations[1]->servers);
        self::assertSame('path', $contract->operations[1]->serverSource);
        self::assertSame('path host', $contract->operations[1]->serverDefinitions[0]['description']);
        $document['paths']['/users/{id}']['get']['servers'] = [['url' => 'https://operation.example.test']];
        $contract = self::normalize($document);
        self::assertSame('operation', $contract->operations[1]->serverSource);
        self::assertSame(['https://operation.example.test'], $contract->operations[1]->servers);
        $files = (new PhpExecutableEmitter('Example\\ServerPreference', 'example/server-preference'))->emit($contract);
        self::assertStringContainsString('https://z.example.test', $files['src/Configuration.php']);
    }

    public function testAllMediaHeadersLinksAndEncodingSurviveNormalization(): void
    {
        $document = self::document();
        $body = &$document['paths']['/users']['post']['requestBody'];
        $body['content']['application/json']['encoding'] = ['name' => ['style' => 'form']];
        $body['description'] = 'Create';
        $response = &$document['paths']['/users/{id}']['get']['responses']['200'];
        $response['content']['application/xml'] = ['schema' => ['type' => 'string'], 'example' => '<user/>'];
        $response['content']['application/x-other'] = ['schema' => ['not' => ['type' => 'null']]];
        $response['headers'] = ['Set-Cookie' => ['schema' => ['type' => 'string'], 'description' => 'Session']];
        $response['links'] = ['next' => ['operationId' => 'getUser']];
        $contract = self::normalize($document);
        self::assertSame(['name' => ['style' => 'form']], $contract->operations[0]->requestBody->content[0]->encoding);
        self::assertSame('Create', $contract->operations[0]->requestBody->annotations['description']);
        unset($response);
        $response = $contract->operations[1]->responses[0];
        self::assertCount(3, $response->content);
        self::assertSame('application/json', $response->mediaType);
        self::assertSame('<user/>', $response->content[1]->annotations['example']);
        self::assertSame(['not' => ['type' => 'null']], $response->content[2]->unparsedSchema);
        self::assertSame('Session', $response->headers['Set-Cookie']['description']);
        self::assertSame('getUser', $response->links['next']['operationId']);
    }

    public function testVendorJsonMediaTypesAreRetainedAndFlaggedUntilHeadersAreExecuted(): void
    {
        $document = self::document('accounts-swagger2.json');
        $document['consumes'] = ['application/vnd.example+json'];
        $document['produces'] = ['application/problem+json'];
        $contract = self::normalize($document);
        self::assertSame('application/vnd.example+json', $contract->operations[0]->requestBody->mediaType);
        self::assertSame('application/problem+json', $contract->operations[1]->responses[0]->mediaType);
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('request_media_type', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
        self::assertContains('response_media_type', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
    }

    public function testProblemJsonErrorsAndSuccessCharsetDoNotCauseFalseNegotiationErrors(): void
    {
        $document = self::document();
        $responses = &$document['paths']['/users/{id}']['get']['responses'];
        $schema = $responses['200']['content']['application/json']['schema'];
        $responses['200']['content'] = ['application/json; charset=utf-8' => ['schema' => $schema]];
        $responses['400'] = ['description' => 'Problem', 'content' => [
            'application/problem+json' => ['schema' => ['type' => 'object', 'properties' => ['detail' => ['type' => 'string']]]],
        ]];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame([], $report['diagnostics']);
    }

    public function testProgrammaticStrictNormalizationProvidesStructuredErrors(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['get']['parameters'][1]['style'] = 'deepObject';
        try {
            (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR), strict: true, sourceName: 'source.json');
            self::fail('Strict normalization accepted an unimplemented HTTP option.');
        } catch (\Dmorenof\SdkGenerator\Diagnostics\CompatibilityException $exception) {
            self::assertSame('source.json', $exception->diagnostics[0]->source);
            self::assertSame('/paths/~1users~1{id}/get/parameters/1/style', $exception->diagnostics[0]->pointer);
            self::assertSame('getUser', $exception->diagnostics[0]->operationId);
        }
    }

    public function testProgrammaticYamlStrictModeUsesTheSameGate(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['get']['parameters'][1]['style'] = 'deepObject';
        $this->expectException(\Dmorenof\SdkGenerator\Diagnostics\CompatibilityException::class);
        $this->expectExceptionMessage('source.yaml#/paths/~1users~1{id}/get/parameters/1/style');
        (new SpecificationNormalizer())->fromYaml(\Symfony\Component\Yaml\Yaml::dump($document, 20, 2), strict: true, sourceName: 'source.yaml');
    }

    public function testExamplesContainingLiteralRefDataAreNotResolved(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['get']['responses']['200']['content']['application/json']['example'] = ['$ref' => 'ordinary payload data'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame([], $report['diagnostics']);
    }

    public function testOverriddenPathParameterOptionsDoNotProduceFalseCompatibilityErrors(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['parameters'] = [[
            'name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'style' => 'deepObject',
        ]];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame([], $report['diagnostics']);
        self::assertNull(self::normalize($document)->operations[1]->parameters[1]->serialization->style);
    }

    public function testUnsupportedSchemaKeywordHasItsExactPointerAndOperation(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['get']['parameters'][1]['schema']['not'] = ['type' => 'null'];
        $report = (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR));
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'unsupported_schema_keyword'));
        self::assertSame('/paths/~1users~1{id}/get/parameters/1/schema/not', $issues[0]->pointer);
        self::assertSame('getUser', $issues[0]->operationId);
    }
}
