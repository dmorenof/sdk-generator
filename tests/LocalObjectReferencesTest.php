<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Diagnostics\CompatibilityException;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\LocalObjectResolver;
use Dmorenof\SdkGenerator\Parsing\LocalReferenceException;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LocalObjectReferencesTest extends TestCase
{
    private static function document(string $name = 'accounts-references-openapi31.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function source(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    public function testReferencedAndInlineDocumentsProduceTheSameContractAndSdk(): void
    {
        $normalizer = new SpecificationNormalizer();
        $inline = $normalizer->fromJson(self::source(self::document('accounts-openapi31.json')), strict: true);
        $references = $normalizer->fromJson(self::source(self::document()), strict: true);
        self::assertEquals($inline, $references);
        $emitter = new PhpExecutableEmitter('Example\\ReferencedAccounts', 'example/referenced-accounts');
        self::assertSame($emitter->emit($inline), $emitter->emit($references));
        self::assertEquals($references, $normalizer->fromYaml(Yaml::dump(self::document(), 30), strict: true));
    }

    public function testOperationIsolationIncludesSchemasHiddenBehindObjectReferences(): void
    {
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source(self::document()));
        self::assertTrue($report['accepted']);
        self::assertSame(2, $report['summary']['generated']);
        self::assertSame('generated', $report['full']['status']);
    }

    public function testParameterOverridesUseTheResolvedLocationAndWireName(): void
    {
        $document = self::document();
        $document['components']['parameters']['Override'] = ['name' => 'id', 'in' => 'path',
            'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 10]];
        $document['paths']['/users/{id}']['get']['parameters'][] = ['$ref' => '#/components/parameters/Override'];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document), strict: true);
        self::assertCount(2, $contract->operations[1]->parameters);
        self::assertSame(10, $contract->operations[1]->parameters[0]->schema->validation['minimum']);
    }

    public function testReferenceSiblingRulesRespectOpenApiVersions(): void
    {
        $document = self::document();
        $body = &$document['paths']['/users']['post']['requestBody'];
        $body['description'] = 'outer description';
        $body['required'] = false; // Not an allowed Reference Object override.
        $document['components']['requestBodies']['CreateAlias']['description'] = 'inner description';
        $document['components']['requestBodies']['NewUser']['description'] = 'target description';
        $normalizer = new SpecificationNormalizer();
        $contract = $normalizer->fromJson(self::source($document), strict: true);
        self::assertSame('outer description', $contract->operations[0]->requestBody->annotations['description']);
        self::assertTrue($contract->operations[0]->requestBody->required);
        $document['openapi'] = '3.0.3';
        $contract = $normalizer->fromJson(self::source($document), strict: true);
        self::assertSame('target description', $contract->operations[0]->requestBody->annotations['description']);
        self::assertTrue($contract->operations[0]->requestBody->required);
    }

    public function testSwaggerReusableParametersBodyAndResponsesMatchInlineNormalization(): void
    {
        $document = self::document('accounts-swagger2.json');
        $normalizer = new SpecificationNormalizer();
        $inline = $normalizer->fromJson(self::source($document), strict: true);
        $document['parameters'] = [];
        $document['responses'] = [];
        foreach ($document['paths'] as $path => &$item) {
            foreach ($item as $method => &$operation) {
                foreach ($operation['parameters'] ?? [] as $index => $parameter) {
                    $name = $operation['operationId'] . $index;
                    $document['parameters'][$name] = $parameter;
                    $operation['parameters'][$index] = ['$ref' => '#/parameters/' . $name];
                }
                foreach ($operation['responses'] as $status => $response) {
                    $name = $operation['operationId'] . $status;
                    $document['responses'][$name] = $response;
                    $operation['responses'][$status] = ['$ref' => '#/responses/' . $name, 'description' => 'ignored'];
                }
            }
        }
        unset($item, $operation);
        self::assertEquals($inline, $normalizer->fromJson(self::source($document), strict: true));
        self::assertTrue((new OpenApiAcceptanceAnalyzer())->analyze(self::source($document))['accepted']);
    }

    public function testResolvedDiagnosticsKeepDeclarationPointerOperationAndAliasChain(): void
    {
        $document = self::document();
        $document['components']['parameters']['ID/~']['allowEmptyValue'] = true;
        $report = (new DocumentInspector())->inspect(self::source($document), sourceName: 'refs.json');
        self::assertFalse($report['strictlyAccepted']);
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool =>
            $issue->code === 'parameter_allowEmptyValue' && $issue->operationId === 'getUser'));
        self::assertSame('/components/parameters/ID~1~0/allowEmptyValue', $issues[0]->pointer);
        self::assertSame('refs.json', $issues[0]->source);
        self::assertSame(['#/components/parameters/Identifier', '#/components/parameters/ID~1~0'], $issues[0]->referenceChain);
    }

    public function testMissingReferencesAreStructuredBeforeStrictNormalization(): void
    {
        $document = self::document();
        $document['components']['requestBodies']['CreateAlias']['$ref'] = '#/components/requestBodies/Missing';
        try {
            (new SpecificationNormalizer())->fromJson(self::source($document), strict: true, sourceName: 'broken.json');
            self::fail('Missing reference accepted.');
        } catch (CompatibilityException $exception) {
            $issues = array_values(array_filter($exception->diagnostics, static fn ($issue): bool => $issue->operationId === 'createUser'));
            self::assertSame('unresolved_reference', $issues[0]->code);
            self::assertSame('/paths/~1users/post/requestBody/$ref', $issues[0]->pointer);
            self::assertSame(['#/components/requestBodies/CreateAlias', '#/components/requestBodies/Missing'], $issues[0]->referenceChain);
        }
    }

    public function testAliasCyclesAreRejectedButRecursiveSchemasKeepTheirIdentity(): void
    {
        $document = self::document();
        $document['components']['schemas']['User']['properties']['friend'] = ['$ref' => '#/components/schemas/User'];
        self::assertTrue((new DocumentInspector())->inspect(self::source($document))['strictlyAccepted']);
        $document['components']['responses']['User/~'] = ['$ref' => '#/components/responses/ReadAlias'];
        $report = (new DocumentInspector())->inspect(self::source($document));
        self::assertFalse($report['strictlyAccepted']);
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool =>
            $issue->code === 'reference_cycle' && $issue->operationId === 'getUser'));
        self::assertSame(['#/components/responses/ReadAlias', '#/components/responses/User~1~0', '#/components/responses/ReadAlias'], $issues[0]->referenceChain);
    }

    public function testInvalidExternalAndWrongKindTargetsAreRejectedWithoutFetching(): void
    {
        $document = self::document();
        $cases = [
            ['https://never-fetch.invalid/body.json', 'external_reference'],
            ['#/components/schemas/User', 'unsupported_reference'],
            ['#/components/requestBodies/Bad~2Escape', 'invalid_reference'],
            ['#/components/requestBodies/Bad%zz', 'invalid_reference'],
            [false, 'invalid_reference'],
        ];
        foreach ($cases as [$reference, $code]) {
            try {
                (new LocalObjectResolver($document))->resolve(['$ref' => $reference], 'requestBodies', '/body');
                self::fail('Invalid reference accepted.');
            } catch (LocalReferenceException $exception) {
                self::assertSame($code, $exception->diagnosticCode);
            }
        }
        $document['components']['requestBodies']['NotObject'] = 'body';
        $this->expectException(LocalReferenceException::class);
        (new LocalObjectResolver($document))->resolve(['$ref' => '#/components/requestBodies/NotObject'], 'requestBodies', '/body');
    }

    public function testInspectionKeepsOriginalHashSourceAndLiteralExamplePayloads(): void
    {
        $document = self::document();
        $document['components']['parameters']['Détail']['example'] = ['$ref' => 'https://never-fetch.invalid/data'];
        $source = self::source($document);
        $report = (new DocumentInspector())->inspect($source);
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame(hash('sha256', $source), $report['sourceSha256']);
        self::assertSame($document, json_decode($source, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testAliasDiagnosticProvenanceIsIndependentOfDeclarationOrder(): void
    {
        $document = self::document();
        $document['components']['parameters']['ID/~']['allowEmptyValue'] = true;
        $inspector = new DocumentInspector();
        $first = $inspector->inspect(self::source($document))['diagnostics'];
        $document['components']['parameters'] = array_reverse($document['components']['parameters'], true);
        self::assertEquals($first, $inspector->inspect(self::source($document))['diagnostics']);
    }

    public function testAliasDepthIsBoundedWithoutRecursingIntoSchemas(): void
    {
        $document = self::document();
        for ($index = 0; $index < 130; ++$index) {
            $document['components']['requestBodies']['Alias' . $index] = ['$ref' => '#/components/requestBodies/Alias' . ($index + 1)];
        }
        $document['components']['requestBodies']['Alias130'] = $document['components']['requestBodies']['NewUser'];
        try {
            (new LocalObjectResolver($document))->resolve(['$ref' => '#/components/requestBodies/Alias0'], 'requestBodies', '/body');
            self::fail('Unbounded alias chain accepted.');
        } catch (LocalReferenceException $exception) {
            self::assertSame('reference_depth', $exception->diagnosticCode);
            self::assertCount(129, $exception->referenceChain);
        }
    }

    public function testResolvedContentParametersRemainDiagnosedUntilTheirSerializerExists(): void
    {
        $document = self::document();
        $parameter = &$document['components']['parameters']['Détail'];
        unset($parameter['schema']);
        $parameter['content'] = ['application/json' => ['schema' => ['type' => 'boolean']]];
        $report = (new DocumentInspector())->inspect(self::source($document));
        self::assertTrue($report['generatable']);
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('parameter_content', array_map(static fn ($issue): string => $issue->code, $report['diagnostics']));
    }

    public function testIsolationDoesNotInterpretExampleOrLinkPayloadsAsSchemaReferences(): void
    {
        $document = self::document();
        $document['components']['schemas']['UnusedUnsupported'] = ['not' => ['type' => 'null']];
        $literal = ['$ref' => '#/components/schemas/UnusedUnsupported'];
        $document['components']['responses']['Unused'] = ['description' => 'Example', 'examples' => ['application/json' => $literal]];
        $document['components']['links']['Unused'] = ['operationId' => 'getUser', 'parameters' => ['id' => $literal], 'requestBody' => $literal];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source($document));
        self::assertFalse($report['accepted']); // The full document still reports its unsupported model.
        self::assertSame(2, $report['summary']['generated']);
    }

    public function testDeclarationAndPropertyNamesThatResembleAnnotationsKeepTheirDependencies(): void
    {
        $document = self::document();
        $document['components']['parameters']['default'] = ['name' => 'unused', 'in' => 'query',
            'schema' => ['$ref' => '#/components/schemas/Profile']];
        $document['components']['schemas']['User']['properties']['examples'] = ['$ref' => '#/components/schemas/Profile'];
        self::assertTrue((new OpenApiAcceptanceAnalyzer())->analyze(self::source($document))['accepted']);
    }
}
