<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\LocalObjectResolver;
use Dmorenof\SdkGenerator\Parsing\LocalReferenceException;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PathItemHeaderReferencesTest extends TestCase
{
    private static function document(string $fixture = 'accounts-path-items-openapi31.json'): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function source(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    private static function issues(array $document, string $code): array
    {
        return array_values(array_filter((new DocumentInspector())->inspect(self::source($document), sourceName: 'paths.json')['diagnostics'],
            static fn ($issue): bool => $issue->code === $code));
    }

    public function testPathItemsProduceTheSameContractAndSdkAsInlineRoutes(): void
    {
        $normalizer = new SpecificationNormalizer();
        $inline = $normalizer->fromJson(self::source(self::document('accounts-openapi31.json')), strict: true);
        $references = $normalizer->fromJson(self::source(self::document()), strict: true);
        self::assertEquals($inline, $references);
        $emitter = new PhpExecutableEmitter('Example\\PathReferences', 'example/path-references');
        self::assertSame($emitter->emit($inline), $emitter->emit($references));
        self::assertEquals($references, $normalizer->fromYaml(Yaml::dump(self::document(), 40), strict: true));
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source(self::document()));
        self::assertTrue($report['accepted']);
        self::assertSame(2, $report['operationCount']);
        self::assertSame(2, $report['summary']['generated']);
    }

    public function testReferencedServersAndParametersRetainTheirExecutionSemantics(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['servers'] = [['url' => 'https://path.example.test/prefix']];
        $document['components']['pathItems']['ReadUser']['get']['servers'] = [['url' => 'https://operation.example.test/other']];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document), strict: true);
        self::assertSame('operation', $contract->operations[1]->serverSource);
        self::assertSame(['https://operation.example.test/other'], $contract->operations[1]->servers);
        self::assertSame('id', $contract->operations[1]->parameters[0]->wireName);
        $normalizer = new SpecificationNormalizer();
        $inline = $normalizer->fromJson(self::source(self::document('operation-servers-openapi30.json')), strict: true);
        $referenced = $normalizer->fromJson(self::source(self::document('operation-servers-path-items-openapi31.json')), strict: true);
        self::assertEquals($inline, $referenced);
        $emitter = new PhpExecutableEmitter('Example\\PathServers', 'example/path-servers');
        self::assertSame($emitter->emit($inline), $emitter->emit($referenced));
    }

    public function testIndependentSiblingsMergeButOverlappingFieldsAreRejected(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['delete'] = ['operationId' => 'deleteUser', 'responses' => ['204' => ['description' => 'Deleted']]];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document), strict: true);
        self::assertCount(3, $contract->operations);
        $document['paths']['/users/{id}']['parameters'] = [];
        $issues = self::issues($document, 'conflicting_path_item');
        self::assertSame('/paths/~1users~1{id}/parameters', $issues[0]->pointer);
        self::assertSame(['#/components/pathItems/ReadAlias', '#/components/pathItems/ReadUser'], $issues[0]->referenceChain);
    }

    public function testPathItemDescriptionsAreSiblingsRatherThanReferenceObjectOverrides(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['summary'] = 'Base route';
        self::assertSame('/paths/~1users~1{id}/summary', self::issues($document, 'conflicting_path_item')[0]->pointer);
    }

    public function testLocalAndInheritedOperationsHaveRealPointersAndIndependentProvenance(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['get']['unknownTransport'] = true;
        $document['paths']['/users/{id}']['delete'] = ['operationId' => 'deleteUser', 'unknownTransport' => true,
            'responses' => ['204' => ['description' => 'Deleted']]];
        $issues = self::issues($document, 'unsupported_keyword');
        $local = array_values(array_filter($issues, static fn ($issue): bool => $issue->operationId === 'deleteUser'))[0];
        self::assertSame('/paths/~1users~1{id}/delete/unknownTransport', $local->pointer);
        self::assertSame([], $local->referenceChain);
        $inherited = array_values(array_filter($issues, static fn ($issue): bool => $issue->operationId === 'getUser' && count($issue->referenceChain) === 2))[0];
        self::assertSame('/components/pathItems/ReadUser/get/unknownTransport', $inherited->pointer);
        self::assertSame(['#/components/pathItems/ReadAlias', '#/components/pathItems/ReadUser'], $inherited->referenceChain);
    }

    public function testIsolatedOperationDiagnosticsKeepOriginalPathItemSource(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['get']['unknownTransport'] = true;
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source($document), sourceName: 'original.json');
        $get = array_values(array_filter($report['operations'], static fn ($operation): bool => $operation['operationId'] === 'getUser'))[0];
        $issues = array_values(array_filter($get['diagnostics'], static fn ($issue): bool => count($issue->referenceChain) === 2));
        self::assertSame('/components/pathItems/ReadUser/get/unknownTransport', $issues[0]->pointer);
        self::assertSame('original.json', $issues[0]->source);
    }

    public function testLocalPathReuseWorksInOpenApi30AndSwaggerWithoutRenamingOperations(): void
    {
        foreach (['accounts-openapi30.json', 'accounts-swagger2.json'] as $fixture) {
            $document = self::document($fixture);
            unset($document['paths']['/users']['post']['operationId']);
            $document['paths']['/other-users'] = ['$ref' => '#/paths/~1users'];
            $contract = (new SpecificationNormalizer())->fromJson(self::source($document), strict: true);
            self::assertCount(3, $contract->operations);
            $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source($document));
            self::assertTrue($report['accepted']);
            self::assertSame(3, $report['operationCount']);
        }
        $document = self::document();
        $document['paths']['/other-users'] = ['$ref' => '#/components/pathItems/CreateUser'];
        $this->expectExceptionMessage('duplicate operationId');
        (new SpecificationNormalizer())->fromJson(self::source($document));
    }

    public function testMissingExternalCyclicAndWrongKindPathReferencesAreDiagnosed(): void
    {
        foreach ([['#/components/pathItems/Missing', 'unresolved_reference'],
            ['https://never-fetch.invalid/paths.json', 'external_reference'],
            ['#/components/responses/Created', 'unsupported_reference']] as [$reference, $code]) {
            $document = self::document();
            $document['paths']['/users/{id}']['$ref'] = $reference;
            self::assertSame('/paths/~1users~1{id}/$ref', self::issues($document, $code)[0]->pointer);
        }
        $document = self::document();
        $document['components']['pathItems']['ReadUser'] = ['$ref' => '#/components/pathItems/ReadAlias'];
        self::assertNotEmpty(self::issues($document, 'reference_cycle'));
    }

    public function testHeaderAliasesResolveToTheSameMetadataButRemainUnexposedAtRuntime(): void
    {
        $document = self::document();
        $header = ['description' => 'Page', 'schema' => ['type' => 'integer', 'minimum' => 1]];
        $document['components']['headers'] = ['Page' => $header, 'PageAlias' => ['$ref' => '#/components/headers/Page']];
        $document['components']['responses']['User/~']['headers'] = ['X-Page' => ['$ref' => '#/components/headers/PageAlias']];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document));
        self::assertSame($header, $contract->operations[1]->responses[0]->headers['X-Page']);
        $issues = self::issues($document, 'response_headers');
        self::assertNotEmpty($issues);
        self::assertSame([], self::issues($document, 'unsupported_reference'));
    }

    public function testHeadersInsideEncodingResolveAndRetainLiteralExamples(): void
    {
        $document = self::document();
        $header = ['schema' => ['type' => 'string'], 'example' => ['$ref' => 'https://never-fetch.invalid/example']];
        $document['components']['headers']['File'] = $header;
        $document['components']['requestBodies']['NewUser']['content']['application/json']['encoding'] = [
            'name' => ['headers' => ['X-File' => ['$ref' => '#/components/headers/File']]],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document));
        self::assertSame($header, $contract->operations[0]->requestBody->content[0]->encoding['name']['headers']['X-File']);
        self::assertNotEmpty(self::issues($document, 'media_encoding'));
        self::assertSame([], self::issues($document, 'external_reference'));
    }

    public function testHeaderSchemaDiagnosticsIncludeTheWholeHttpReferenceChain(): void
    {
        $document = self::document();
        $document['components']['headers']['Page'] = ['schema' => ['type' => 'integer', 'multipleOf' => 2]];
        $document['components']['responses']['User/~']['headers'] = ['X-Page' => ['$ref' => '#/components/headers/Page']];
        $issues = self::issues($document, 'unsupported_schema_keyword');
        $issue = array_values(array_filter($issues, static fn ($issue): bool => count($issue->referenceChain) === 5))[0];
        self::assertSame('/components/headers/Page/schema/multipleOf', $issue->pointer);
        self::assertSame('getUser', $issue->operationId);
        self::assertSame(['#/components/pathItems/ReadAlias', '#/components/pathItems/ReadUser',
            '#/components/responses/ReadAlias', '#/components/responses/User~1~0', '#/components/headers/Page'], $issue->referenceChain);
    }

    public function testRecursiveMetadataGraphsAreBoundedDuringInspection(): void
    {
        $document = self::document();
        $document['components']['headers']['Loop'] = ['content' => ['application/json' => ['encoding' => [
            'field' => ['headers' => ['X-Again' => ['$ref' => '#/components/headers/Loop']]],
        ]]]];
        self::assertNotEmpty(self::issues($document, 'recursive_http_reference'));
    }

    public function testInvalidPathReferencesCannotBeAcceptedAsAnEmptySdk(): void
    {
        $document = self::document();
        $document['paths'] = ['/missing' => ['$ref' => '#/components/pathItems/Missing']];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source($document));
        self::assertFalse($report['accepted']);
        self::assertSame(1, $report['unresolvedPathCount']);
        self::assertSame(0, $report['operationCount']);
        self::assertSame('normalization_failed', $report['full']['status']);
    }

    public function testSelectionKeepsReusableDeclarationsThatReferenceOtherActualPaths(): void
    {
        $document = self::document();
        $document['components']['pathItems']['FromPath'] = ['$ref' => '#/paths/~1users'];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(self::source($document));
        self::assertTrue($report['accepted']);
        self::assertSame(2, $report['operationCount']);
    }

    public function testInheritedPathMetadataKeepsItsOriginWhenUsedByALocalOperation(): void
    {
        $document = self::document();
        $document['paths']['/users/{id}']['delete'] = ['operationId' => 'deleteUser', 'responses' => ['204' => ['description' => 'Deleted']]];
        $document['components']['parameters']['ID/~']['allowReserved'] = true;
        $issues = self::issues($document, 'parameter_allowReserved');
        $issue = array_values(array_filter($issues, static fn ($issue): bool => $issue->operationId === 'deleteUser'))[0];
        self::assertSame('/components/parameters/ID~1~0/allowReserved', $issue->pointer);
        self::assertSame(['#/components/pathItems/ReadAlias', '#/components/pathItems/ReadUser',
            '#/components/parameters/Identifier', '#/components/parameters/ID~1~0'], $issue->referenceChain);
    }

    public function testHeaderReferenceSiblingsRespectDialectWithoutChangingTheTargetSchema(): void
    {
        $document = self::document();
        $document['components']['headers']['Page'] = ['description' => 'Target', 'schema' => ['type' => 'integer']];
        $node = ['$ref' => '#/components/headers/Page', 'description' => 'Override', 'schema' => ['type' => 'string']];
        $value = (new LocalObjectResolver($document))->resolve($node, 'headers', '/header')['value'];
        self::assertSame('Override', $value['description']);
        self::assertSame('integer', $value['schema']['type']);
        $document['openapi'] = '3.0.3';
        self::assertSame('Target', (new LocalObjectResolver($document))->resolve($node, 'headers', '/header')['value']['description']);
    }

    public function testInspectionPreservesSourceHashAndDeterministicAliasProvenance(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['get']['unknownTransport'] = true;
        $source = self::source($document);
        $inspector = new DocumentInspector();
        $first = $inspector->inspect($source);
        self::assertSame(hash('sha256', $source), $first['sourceSha256']);
        self::assertSame($document, json_decode($source, true, 512, JSON_THROW_ON_ERROR));
        $document['components']['pathItems'] = array_reverse($document['components']['pathItems'], true);
        self::assertEquals($first['diagnostics'], $inspector->inspect(self::source($document))['diagnostics']);
    }

    public function testCyclicPathDeclarationsWithSiblingsReportTheCycleBeforeOverlap(): void
    {
        $document = self::document();
        $document['components']['pathItems']['ReadUser']['$ref'] = '#/components/pathItems/ReadAlias';
        $issues = self::issues($document, 'reference_cycle');
        self::assertNotEmpty($issues);
        self::assertSame([], self::issues($document, 'conflicting_path_item'));
    }
}
