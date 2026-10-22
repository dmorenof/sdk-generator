<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testYamlInputUsesTheSameAcceptancePipeline(): void
    {
        $json = json_encode(self::spec(), JSON_THROW_ON_ERROR);
        $yaml = Yaml::dump(self::spec(), 20, 2);
        $analyzer = new OpenApiAcceptanceAnalyzer();
        $jsonReport = $analyzer->analyze($json);
        $yamlReport = $analyzer->analyze($yaml, 'yaml');

        self::assertTrue($yamlReport['accepted']);
        self::assertSame($jsonReport['summary'], $yamlReport['summary']);
        self::assertSame(hash('sha256', $yaml), $yamlReport['sourceSha256']);
    }

    public function testAcceptanceCliDetectsYamlExtension(): void
    {
        $directory = sys_get_temp_dir() . '/sdk-acceptance-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700, true));
        $specification = $directory . '/input.yml';
        file_put_contents($specification, Yaml::dump(self::spec(), 20, 2));

        try {
            $command = implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                dirname(__DIR__) . '/bin/check-openapi-acceptance.php',
                $specification,
            ]));
            $lines = [];
            $exit = 0;
            exec($command . ' 2>&1', $lines, $exit);

            self::assertSame(0, $exit, implode("\n", $lines));
            self::assertStringContainsString('"accepted": true', implode("\n", $lines));
        } finally {
            if (is_file($specification)) {
                unlink($specification);
            }
            rmdir($directory);
        }
    }

    public function testOneUnsupportedOperationDoesNotHideOtherWorkingOperations(): void
    {
        $raw = self::spec();
        $raw['paths']['/users']['post']['operationId'] = 'operation-with-hyphens';
        $raw['paths']['/users']['post']['requestBody']['content']['application/json']['schema'] = [
            'type' => 'string',
        ];
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
        self::assertStringContainsString('Unsupported non-reference request body', $bad[0]['error']['message']);
    }

    public function testUnusedUnsupportedSchemaDoesNotHideIndividualOperationSuccess(): void
    {
        $raw = self::spec();
        $raw['components']['schemas']['UnusedComposed'] = [
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ],
        ];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($raw, JSON_THROW_ON_ERROR));
        self::assertFalse($report['accepted']);
        self::assertSame('generation_failed', $report['full']['status']);
        self::assertSame(2, $report['summary']['generated']);
        self::assertSame(0, $report['summary']['normalization_failed']);
        self::assertStringContainsString('Conflicting allOf property', $report['full']['error']['message']);
    }

    public function testSupportedSecurityRequirementsAreAccepted(): void
    {
        $raw = self::spec();
        $raw['components']['securitySchemes'] = [
            'HTTPBearer' => ['type' => 'http', 'scheme' => 'bearer'],
        ];
        $raw['paths']['/users/{id}']['get']['security'] = [['HTTPBearer' => []]];
        $report = (new OpenApiAcceptanceAnalyzer())->analyze(json_encode($raw, JSON_THROW_ON_ERROR));
        self::assertTrue($report['accepted']);
        self::assertSame(0, $report['summary']['normalization_failed']);
        self::assertSame(2, $report['summary']['generated']);
    }
}
