<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DocumentInspectorTest extends TestCase
{
    private static function source(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function inspect(array $document): array
    {
        return (new DocumentInspector())->inspect(json_encode($document, JSON_THROW_ON_ERROR), sourceName: 'api.json');
    }

    public function testSupportedJsonAndYamlAreInspectedWithoutMutatingSource(): void
    {
        $document = self::source();
        $source = json_encode($document, JSON_THROW_ON_ERROR);
        $report = (new DocumentInspector())->inspect($source);
        self::assertSame('supported', $report['status']);
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame(hash('sha256', $source), $report['sourceSha256']);
        self::assertSame([], $report['diagnostics']);
        $yaml = (new DocumentInspector())->inspect(Yaml::dump($document, 20, 2), 'yaml');
        self::assertSame('supported', $yaml['status']);
        self::assertSame($document, json_decode($source, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPreviouslyIgnoredParameterOptionsHaveSourceOperationAndEscapedPointer(): void
    {
        $document = self::source();
        $document['paths']['/users/{id}']['get']['parameters'][1]['allowEmptyValue'] = true;
        $report = self::inspect($document);
        self::assertSame('partial', $report['status']);
        self::assertFalse($report['strictlyAccepted']);
        $issue = $report['diagnostics'][0];
        self::assertSame('/paths/~1users~1{id}/get/parameters/1/allowEmptyValue', $issue->pointer);
        self::assertSame('getUser', $issue->operationId);
        self::assertSame('api.json', $issue->source);
    }

    public function testLiteralServerOverridesAreExecutableAndAnnotationsDoNotChangeSelection(): void
    {
        $document = self::source();
        $document['paths']['/users/{id}']['servers'] = [['url' => 'https://elsewhere.test']];
        $report = self::inspect($document);
        self::assertTrue($report['strictlyAccepted']);
        self::assertSame([], $report['diagnostics']);
        $document['paths']['/users/{id}']['get']['servers'] = $document['servers'];
        $document['paths']['/users/{id}']['get']['servers'][0]['description'] = 'same effective endpoint';
        self::assertTrue(self::inspect($document)['strictlyAccepted']);
    }

    public function testUnknownHttpOptionsAreErrorsButAnnotationsAreNot(): void
    {
        $document = self::source();
        $document['paths']['/users/{id}']['get']['summary'] = 'Read a user';
        $document['paths']['/users/{id}']['get']['imaginaryTransportOption'] = true;
        $report = self::inspect($document);
        self::assertCount(1, $report['diagnostics']);
        self::assertSame('unsupported_keyword', $report['diagnostics'][0]->code);
    }

    public function testReferencesIncludeTheWholeLocalChainAndNeverFetchExternalDocuments(): void
    {
        $document = self::source();
        $document['components']['schemas']['User']['properties']['other'] = ['$ref' => '#/components/schemas/Alias'];
        $document['components']['schemas']['Alias'] = ['$ref' => 'https://unreachable.invalid/model.json'];
        $report = self::inspect($document);
        $issues = array_values(array_filter($report['diagnostics'], static fn ($issue): bool => $issue->code === 'external_reference'));
        self::assertNotEmpty($issues);
        $chains = array_map(static fn ($issue): array => $issue->referenceChain, $issues);
        self::assertContains(['#/components/schemas/Alias', 'https://unreachable.invalid/model.json'], $chains);
    }

    public function testUnresolvedPointerEscapesAndInvalidInputHaveHonestDiagnostics(): void
    {
        $document = self::source();
        $document['paths']['/users/{id}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] = '#/components/schemas/Missing~1Thing';
        $issues = array_values(array_filter(self::inspect($document)['diagnostics'], static fn ($issue): bool => $issue->code === 'unresolved_reference'));
        self::assertSame('/paths/~1users~1{id}/get/responses/200/content/application~1json/schema/$ref', $issues[0]->pointer);
        self::assertSame(['#/components/schemas/Missing~1Thing'], $issues[0]->referenceChain);
        $invalid = (new DocumentInspector())->inspect('{');
        self::assertSame('unsupported', $invalid['status']);
        self::assertSame('', $invalid['diagnostics'][0]->pointer);
    }

    public function testDiagnosticOrderIsIndependentOfObjectKeyOrder(): void
    {
        $document = self::source();
        $document['paths']['/users/{id}']['get']['bOption'] = 1;
        $document['paths']['/users/{id}']['get']['aOption'] = 2;
        $first = self::inspect($document)['diagnostics'];
        $document['paths'] = array_reverse($document['paths'], true);
        self::assertEquals($first, self::inspect($document)['diagnostics']);
    }

    public function testStrictCliRejectsBeforeCreatingOutputAndInspectNeedsNoDestination(): void
    {
        $directory = sys_get_temp_dir() . '/sdk-inspection-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $document = self::source();
        $document['paths']['/users/{id}']['get']['parameters'][1]['allowEmptyValue'] = true;
        file_put_contents("$directory/input.json", json_encode($document, JSON_THROW_ON_ERROR));
        try {
            $args = [PHP_BINARY, dirname(__DIR__) . '/bin/generate-sdk.php', '--strict', "$directory/input.json", "$directory/output", 'example/strict'];
            exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $exit);
            self::assertSame(1, $exit);
            self::assertDirectoryDoesNotExist("$directory/output");
            self::assertStringContainsString('/paths/~1users~1{id}/get/parameters/1/allowEmptyValue', implode("\n", $lines));
            $args = [PHP_BINARY, dirname(__DIR__) . '/bin/generate-sdk.php', '--inspect', "$directory/input.json"];
            exec(implode(' ', array_map('escapeshellarg', $args)), $json, $status);
            self::assertSame(1, $status);
            self::assertSame('partial', json_decode(implode("\n", $json), true, 512, JSON_THROW_ON_ERROR)['status']);
            self::assertSame(['input.json'], array_values(array_diff(scandir($directory), ['.', '..'])));
        } finally {
            unlink("$directory/input.json");
            rmdir($directory);
        }
    }
}
