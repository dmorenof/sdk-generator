<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\ContractAuditor;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Diagnostics\CompatibilityException;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class NestedContractAuditTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function issues(array $document, string $code): array
    {
        return array_values(array_filter((new ContractAuditor())->audit($document, 'nested.json'),
            static fn ($issue): bool => $issue->code === $code));
    }

    public function testReusableParametersAndBodiesCannotHideUnsupportedSemantics(): void
    {
        $document = self::document();
        $document['components']['parameters']['filter/~'] = [
            'name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'string'],
        ];
        $document['components']['requestBodies']['Upload'] = ['content' => ['application/json' => [
            'schema' => ['type' => 'object'], 'encoding' => ['file' => ['unknownEncoding' => true]],
        ]]];
        $issues = self::issues($document, 'parameter_style');
        self::assertSame('/components/parameters/filter~1~0/style', $issues[0]->pointer);
        self::assertSame('nested.json', $issues[0]->source);
        self::assertNull($issues[0]->operationId);
        self::assertSame('/components/requestBodies/Upload/content/application~1json/encoding/file/unknownEncoding',
            self::issues($document, 'unsupported_keyword')[0]->pointer);
        $source = json_encode($document, JSON_THROW_ON_ERROR);
        self::assertFalse((new DocumentInspector())->inspect($source)['strictlyAccepted']);
        $this->expectException(CompatibilityException::class);
        (new SpecificationNormalizer())->fromJson($source, strict: true);
    }

    public function testReusableResponsesAndHeadersAreAuditedAtTheirDeclaration(): void
    {
        $document = self::document();
        $document['components']['headers']['Page/~'] = ['schema' => ['type' => 'integer', 'not' => ['type' => 'null']]];
        $document['components']['responses']['Paged'] = ['description' => 'Page', 'headers' => [
            'Next' => ['content' => ['application/json' => ['schema' => ['not' => ['type' => 'null']]]]],
        ]];
        self::assertSame([
            '/components/headers/Page~1~0/schema/not',
            '/components/responses/Paged/headers/Next/content/application~1json/schema/not',
        ], array_map(static fn ($issue): string => $issue->pointer, self::issues($document, 'unsupported_schema_keyword')));
        self::assertSame('/components/responses/Paged/headers', self::issues($document, 'response_headers')[0]->pointer);
    }

    public function testNestedEncodingHeaderReferencesHaveAnEscapedPointerAndFullChain(): void
    {
        $document = self::document();
        $document['components']['headers']['Alias'] = ['$ref' => '#/components/headers/Missing'];
        $document['paths']['/users']['post']['requestBody']['content']['application/json']['encoding'] = [
            'file/~' => ['headers' => ['X/~' => ['$ref' => '#/components/headers/Alias']]],
        ];
        $issues = self::issues($document, 'unresolved_reference');
        $issue = array_values(array_filter($issues, static fn ($issue): bool => $issue->operationId === 'createUser'))[0];
        self::assertSame('/paths/~1users/post/requestBody/content/application~1json/encoding/file~1~0/headers/X~1~0/$ref', $issue->pointer);
        self::assertSame(['#/components/headers/Alias', '#/components/headers/Missing'], $issue->referenceChain);
    }

    public function testReusablePathItemsAreAuditedWithReferenceProvenance(): void
    {
        $document = self::document();
        $document['components']['pathItems']['Shared/~'] = ['get' => [
            'operationId' => 'sharedRead', 'imaginaryTransportOption' => true,
            'responses' => ['200' => ['description' => 'OK']],
        ]];
        $document['paths']['/shared'] = ['$ref' => '#/components/pathItems/Shared~1~0'];
        $issues = self::issues($document, 'unsupported_keyword');
        self::assertSame('/components/pathItems/Shared~1~0/get/imaginaryTransportOption', $issues[0]->pointer);
        self::assertSame('sharedRead', $issues[0]->operationId);
        self::assertSame(['#/components/pathItems/Shared~1~0'], $issues[1]->referenceChain);
        self::assertSame([], self::issues($document, 'unsupported_reference'));
    }

    public function testSecurityFailuresAreLocatedAtTheSchemeAndRequirement(): void
    {
        $document = self::document();
        $document['components']['securitySchemes']['OAuth/~'] = ['type' => 'oauth2', 'flows' => []];
        $document['paths']['/users/{id}']['get']['security'] = [['OAuth/~' => ['read:users']]];
        self::assertSame('/components/securitySchemes/OAuth~1~0/type', self::issues($document, 'unsupported_security_scheme')[0]->pointer);
        $issue = self::issues($document, 'unsupported_security_scopes')[0];
        self::assertSame('/paths/~1users~1{id}/get/security/0/OAuth~1~0', $issue->pointer);
        self::assertSame('getUser', $issue->operationId);
    }

    public function testAnnotationsAndLiteralExampleOrLinkPayloadsAreNeverWalkedAsSchemas(): void
    {
        $document = self::document();
        $literal = ['$ref' => 'https://never-fetch.invalid/example.json', 'not' => ['unknown' => true]];
        $document['components']['examples']['Example'] = ['value' => $literal, 'externalValue' => 'https://never-fetch.invalid/data.json'];
        $document['components']['links']['Next'] = [
            'operationId' => 'getUser', 'parameters' => ['id' => $literal], 'requestBody' => $literal,
        ];
        $document['components']['parameters']['Optional'] = [
            'name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'],
            'examples' => ['value' => ['value' => $literal]],
        ];
        self::assertSame([], (new ContractAuditor())->audit($document));
        self::assertTrue((new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR))['strictlyAccepted']);
    }

    public function testUnknownNestedKeysAndExtensionsAreClassifiedDeterministically(): void
    {
        $document = self::document();
        $document['components']['headers']['H'] = ['schema' => ['type' => 'string'], 'x-provider' => true, 'unknown' => true];
        $document['components']['inventedComponents'] = [];
        $first = (new ContractAuditor())->audit($document);
        self::assertSame('warning', self::issues($document, 'uninterpreted_extension')[0]->severity);
        self::assertCount(2, self::issues($document, 'unsupported_keyword'));
        $document['components'] = array_reverse($document['components'], true);
        self::assertEquals($first, (new ContractAuditor())->audit($document));
    }

    public function testSwaggerReusableParametersResponsesAndHeadersAreAudited(): void
    {
        $document = json_decode(file_get_contents(__DIR__ . '/fixtures/accounts-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['parameters']['IDs'] = ['name' => 'ids', 'in' => 'query', 'type' => 'array',
            'items' => ['type' => 'integer'], 'collectionFormat' => 'multi'];
        $document['responses']['Paged'] = ['description' => 'Page', 'headers' => [
            'Pages' => ['type' => 'integer', 'not' => ['type' => 'null']],
        ]];
        self::assertSame([], self::issues($document, 'parameter_collectionFormat'));
        self::assertSame('/responses/Paged/headers/Pages/not', self::issues($document, 'unsupported_schema_keyword')[0]->pointer);
    }

    public function testInlineHeadersAndLinksUseTheSameNestedAuditAndRealServerPointer(): void
    {
        $document = self::document();
        $response = &$document['paths']['/users/{id}']['get']['responses']['200'];
        $response['headers']['X-Page'] = ['schema' => ['type' => 'integer'], 'unknownHeader' => true];
        $response['links']['Next/~'] = ['operationId' => 'getUser', 'server' => ['url' => '/relative']];
        self::assertSame('/paths/~1users~1{id}/get/responses/200/headers/X-Page/unknownHeader',
            self::issues($document, 'unsupported_keyword')[0]->pointer);
        $issue = self::issues($document, 'unsupported_server_url')[0];
        self::assertSame('/paths/~1users~1{id}/get/responses/200/links/Next~1~0/server/url', $issue->pointer);
        self::assertSame('getUser', $issue->operationId);
    }

    public function testCallbacksAreExplicitlyUnsupportedAndTheirOperationsAreStillAudited(): void
    {
        $document = self::document();
        $document['paths']['/users']['post']['callbacks']['Changed'] = [
            '{$request.body#/callback}' => ['post' => [
                'operationId' => 'onChange', 'unknownTransport' => true,
                'responses' => ['200' => ['description' => 'OK']],
            ]],
        ];
        $issue = self::issues($document, 'unsupported_callback')[0];
        self::assertSame('/paths/~1users/post/callbacks/Changed', $issue->pointer);
        self::assertSame('createUser', $issue->operationId);
        self::assertSame('/paths/~1users/post/callbacks/Changed/{$request.body#~1callback}/post/unknownTransport',
            self::issues($document, 'unsupported_keyword')[0]->pointer);
    }

    public function testReferenceCyclesAreBoundedAndAuditorStateIsResetBetweenDocuments(): void
    {
        $document = self::document();
        $document['components']['headers']['A'] = ['$ref' => '#/components/headers/B'];
        $document['components']['headers']['B'] = ['$ref' => '#/components/headers/A'];
        $issues = self::issues($document, 'reference_cycle');
        self::assertCount(2, $issues);
        self::assertSame(['#/components/headers/B', '#/components/headers/A', '#/components/headers/B'], $issues[0]->referenceChain);
        $auditor = new ContractAuditor();
        self::assertNotEmpty($auditor->audit($document, 'first.json'));
        self::assertSame([], $auditor->audit(self::document(), 'second.json'));
    }

    public function testUndefinedSecuritySchemesAndInvalidKeyLocationsHavePreciseDiagnostics(): void
    {
        $document = self::document();
        $document['security'] = [['Missing/~' => []]];
        $document['components']['securitySchemes']['Session'] = ['type' => 'apiKey', 'name' => 'session', 'in' => 'body'];
        self::assertSame('/security/0/Missing~1~0', self::issues($document, 'unknown_security_scheme')[0]->pointer);
        self::assertSame('/components/securitySchemes/Session/in', self::issues($document, 'unsupported_security_scheme')[0]->pointer);
    }

    public function testReusableDeclarationsDoNotMakeSwaggerRootFieldsValidInOpenApi(): void
    {
        $document = self::document();
        $document['parameters'] = ['Ignored' => ['name' => 'q', 'in' => 'query', 'type' => 'string']];
        self::assertSame('/parameters', self::issues($document, 'unsupported_keyword')[0]->pointer);
    }
}
