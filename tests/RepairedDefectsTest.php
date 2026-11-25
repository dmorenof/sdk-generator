<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Real contracts break rules the generator can mend without guessing: uDA leaves 24 path placeholders undeclared, Sendbird
 * declares a path parameter its path does not have and writes `type: string` beside a `oneOf` of objects. The generator mends
 * them and says so; `--strict` (and a normalizer asked for nothing) still refuses.
 */
final class RepairedDefectsTest extends TestCase
{
    private static function spec(): string
    {
        return json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Defects', 'version' => '1'],
            'servers' => [['url' => 'https://defects.example.com']],
            'paths' => [
                '/things/{id}/parts/{part}' => ['get' => ['operationId' => 'getPart',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => false, 'schema' => ['type' => 'integer']],
                        ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Template']]]]]]],
            ],
            'components' => ['schemas' => [
                'Template' => ['type' => 'string', 'oneOf' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]]]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    public function testWithoutRepairTheDefectsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(self::spec());
    }

    public function testRepairMendsEachDefectAndRecordsIt(): void
    {
        $normalizer = new SpecificationNormalizer(repair: true);
        $contract = $normalizer->fromJson(self::spec());
        $messages = implode("\n", array_column($normalizer->repairs(), 'message'));
        self::assertStringContainsString("'key' has no placeholder", $messages);
        self::assertStringContainsString("'id' is not required", $messages);
        self::assertStringContainsString("'{part}' has no path parameter", $messages);
        self::assertStringContainsString('beside oneOf (type)', $messages);
        $names = array_map(static fn ($p): string => $p->wireName . ($p->required ? '!' : ''), $contract->operations[0]->parameters);
        sort($names);
        self::assertSame(['id!', 'part!'], $names);
        self::assertNotSame([], (new PhpExecutableEmitter('Example\\Repaired', 'example/repaired', []))->emit($contract));
    }

    public function testTheInspectionGeneratesButIsNotStrictlyAccepted(): void
    {
        $report = (new DocumentInspector())->inspect(self::spec());
        self::assertTrue($report['generatable']);
        self::assertFalse($report['strictlyAccepted']);
        self::assertContains('repaired_defect', array_map(static fn ($d): string => $d->code, $report['diagnostics']));
    }
}
