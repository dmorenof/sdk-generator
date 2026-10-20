<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use PHPUnit\Framework\TestCase;

final class OpenApiAcceptanceAnalyzerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(): array
    {
        $source = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        self::assertIsString($source);
        return json_decode($source, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testKnownWorkingFixtureIsFullyAccepted(): void
    {
        $raw = json_encode(self::spec(), JSON_THROW_ON_ERROR);
        $report = (new OpenApiAcceptanceAnalyzer())->analyze($raw);
        self::assertTrue($report['accepted']);
        self::assertSame(2, $report['operationCount']);
        self::assertSame('generated', $report['full']['status']);
        self::assertSame(2, $report['summary']['generated']);
        self::assertSame(hash('sha256', $raw), $report['sourceSha256']);
    }

    public function testOneUnsupportedOperationDoesNotHideOtherWorkingOperations(): void
    {
        $raw = self::spec();
        $raw['paths']['/users']['post']['operationId'] = 'operation-with-hyphens';
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($raw, JSON_THROW_ON_ERROR));
        self::assertFalse($report['accepted']);
        self::assertSame(2, $report['operationCount']);
        self::assertSame(1, $report['summary']['generated']);
        self::assertSame(1, $report['summary']['generation_failed']);
        self::assertSame('generation_failed', $report['full']['status']);
        $bad = array_values(array_filter($report['operations'],
            static fn (array $operation): bool => $operation['status'] === 'generation_failed',
        ));
        self::assertCount(1, $bad);
        self::assertStringContainsString('operation-with-hyphens', $bad[0]['error']['message']);
    }

    public function testUnusedUnsupportedSchemaDoesNotHideIndividualOperationSuccess(): void
    {
        $raw = self::spec();
        $raw['components']['schemas']['UnusedComposed'] = [
            'allOf' => [['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($raw, JSON_THROW_ON_ERROR));
        self::assertFalse($report['accepted']);
        self::assertSame('normalization_failed', $report['full']['status']);
        self::assertSame(2, $report['summary']['generated']);
        self::assertSame(0, $report['summary']['normalization_failed']);
        self::assertStringContainsString('allOf', $report['full']['error']['message']);
    }

    public function testDeclaredSecurityFailuresRemainVisible(): void
    {
        $raw = self::spec();
        $raw['paths']['/users/{id}']['get']['security'] = [['HTTPBearer' => []]];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($raw, JSON_THROW_ON_ERROR));
        self::assertFalse($report['accepted']);
        self::assertSame(1, $report['summary']['normalization_failed']);
        self::assertSame(1, $report['summary']['generated']);
        $failures = array_values(array_filter($report['operations'],
            static fn (array $operation): bool => $operation['status'] === 'normalization_failed',
        ));
        self::assertCount(1, $failures);
        self::assertStringContainsString('security', $failures[0]['error']['message']);
    }
}
