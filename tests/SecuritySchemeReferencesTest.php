<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\LocalObjectResolver;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SecuritySchemeReferencesTest extends TestCase
{
    private static function document(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/accounts-security-references-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function source(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    public function testAliasesEmitTheSameContractAndSdkAsInlineSecurityDefinitions(): void
    {
        $document = self::document();
        $inline = $document;
        foreach (['Bearer', 'HeaderKey', 'QueryKey', 'Basic', 'BearerHop'] as $name) {
            $inline['components']['securitySchemes'][$name] = $document['components']['securitySchemes'][$name === 'BearerHop' ? 'BearerBase' : $name . 'Base'];
        }
        $normalizer = new SpecificationNormalizer();
        foreach (['3.0.3', '3.1.0'] as $version) {
            $document['openapi'] = $inline['openapi'] = $version;
            $expected = $normalizer->fromJson(self::source($inline), strict: true);
            $actual = $normalizer->fromJson(self::source($document), strict: true);
            self::assertEquals($expected, $actual);
            $emitter = new PhpExecutableEmitter('Example\\AuthAliases', 'example/auth-aliases');
            self::assertSame($emitter->emit($expected), $emitter->emit($actual));
            self::assertEquals($actual, $normalizer->fromYaml(Yaml::dump($document, 30), strict: true));
        }
    }

    public function testCredentialNamesAndRootRequirementsKeepTheDeclaringAlias(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(self::source(self::document()), strict: true);
        $schemes = array_column($contract->securitySchemes, null, 'name');
        self::assertSame('Bearer', $schemes['Bearer']->name);
        self::assertSame('bearer', $schemes['Bearer']->scheme);
        self::assertSame('X-API-Key', $schemes['HeaderKey']->parameterName);
        self::assertSame([['Bearer' => [], 'HeaderKey' => []], ['QueryKey' => []], ['Basic' => []]], $contract->securityRequirements);
        self::assertSame($contract->securityRequirements, $contract->operations[1]->securityRequirements);
        $document = self::document();
        $document['paths']['/users/{id}']['get']['security'] = [];
        $contract = (new SpecificationNormalizer())->fromJson(self::source($document), strict: true);
        self::assertSame([], $contract->operations[1]->securityRequirements);
        self::assertNotEmpty($contract->operations[0]->securityRequirements);
    }

    public function testUnsupportedTargetsCannotBecomeSupportedThroughSiblingOverrides(): void
    {
        $document = self::document();
        $document['components']['securitySchemes']['BearerBase'] = ['type' => 'oauth2', 'flows' => []];
        $document['components']['securitySchemes']['Bearer']['type'] = 'http';
        $document['components']['securitySchemes']['Bearer']['scheme'] = 'bearer';
        $report = (new DocumentInspector())->inspect(self::source($document), sourceName: 'auth.json');
        self::assertFalse($report['strictlyAccepted']);
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool =>
            $issue->code === 'unsupported_security_scheme' && count($issue->referenceChain) === 2));
        self::assertSame('/components/securitySchemes/BearerBase/type', $issues[0]->pointer);
        self::assertSame('auth.json', $issues[0]->source);
        self::assertSame(['#/components/securitySchemes/BearerHop', '#/components/securitySchemes/BearerBase'], $issues[0]->referenceChain);
    }

    public function testMissingWrongKindExternalAndCyclicReferencesHaveStructuredDiagnostics(): void
    {
        foreach ([['#/components/securitySchemes/Missing~1~0', 'unresolved_reference'],
            ['#/components/schemas/User', 'unsupported_reference'],
            ['https://never-fetch.invalid/auth.json', 'external_reference']] as [$reference, $code]) {
            $document = self::document();
            $document['components']['securitySchemes']['Bearer']['$ref'] = $reference;
            $issues = (new DocumentInspector())->inspect(self::source($document))['diagnostics'];
            $matching = array_values(array_filter($issues, static fn ($issue): bool => $issue->code === $code));
            self::assertSame('/components/securitySchemes/Bearer/$ref', $matching[0]->pointer);
        }
        $document = self::document();
        $document['components']['securitySchemes']['BearerBase'] = ['$ref' => '#/components/securitySchemes/Bearer'];
        self::assertContains('reference_cycle', array_map(static fn ($issue): string => $issue->code,
            (new DocumentInspector())->inspect(self::source($document))['diagnostics']));
    }

    public function testDescriptionsFollowReferenceObjectRulesWithoutChangingTheAuthType(): void
    {
        $document = self::document();
        $node = ['$ref' => '#/components/securitySchemes/BearerBase', 'description' => 'Alias description', 'scheme' => 'basic'];
        $value = (new LocalObjectResolver($document))->resolve($node, 'securitySchemes', '/scheme')['value'];
        self::assertSame('Alias description', $value['description']);
        self::assertSame('bearer', $value['scheme']);
        $document['openapi'] = '3.0.3';
        self::assertArrayNotHasKey('description', (new LocalObjectResolver($document))->resolve($node, 'securitySchemes', '/scheme')['value']);
    }

    public function testAcceptanceAndInspectionKeepTheOriginalDocument(): void
    {
        $document = self::document();
        $source = self::source($document);
        self::assertTrue((new OpenApiAcceptanceAnalyzer())->analyze($source)['accepted']);
        $report = (new DocumentInspector())->inspect($source);
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame(hash('sha256', $source), $report['sourceSha256']);
        self::assertSame($document, json_decode($source, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSwaggerAndExternalFileSchemeReferencesRemainUnsupported(): void
    {
        $document = json_decode(file_get_contents(__DIR__ . '/fixtures/accounts-swagger2.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['securityDefinitions'] = ['Base' => ['type' => 'basic'], 'Alias' => ['$ref' => '#/securityDefinitions/Base']];
        self::assertFalse((new DocumentInspector())->inspect(self::source($document))['strictlyAccepted']);
        $document = self::document();
        $document['components']['securitySchemes']['Bearer']['$ref'] = './auth.json#/Bearer';
        self::assertContains('external_reference', array_map(static fn ($issue): string => $issue->code,
            (new DocumentInspector())->inspect(self::source($document))['diagnostics']));
    }
}
