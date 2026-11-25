<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** Shapes of Trustpilot's published definitions: `openapi: 3.2.0`, `type: object` beside an `allOf`, and an extension that widens an inherited inline object. */
final class TrustpilotShapesTest extends TestCase
{
    private static function spec(string $version = '3.2.0'): string
    {
        $consumer = static fn (array $fields): array => ['type' => 'object', 'properties' => array_map(static fn (): array => ['type' => 'string'], array_flip($fields))];
        return json_encode([
            'openapi' => $version,
            'info' => ['title' => 'Reviews', 'version' => '1'],
            'servers' => [['url' => 'https://reviews.example.com']],
            'paths' => ['/reviews/{id}' => ['get' => ['operationId' => 'getReview',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PrivateReview']]]]]]]],
            'components' => ['schemas' => [
                'Review' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'consumer' => $consumer(['id', 'displayName'])]],
                'PrivateReview' => ['type' => 'object', 'allOf' => [['$ref' => '#/components/schemas/Review'],
                    ['type' => 'object', 'properties' => ['consumer' => $consumer(['id', 'email'])]]]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    public function testOpenApi32IsReadAsOpenApi31(): void
    {
        $report = (new DocumentInspector())->inspect(self::spec());
        self::assertTrue($report['generatable'], json_encode($report['diagnostics']));
        self::assertTrue($report['strictlyAccepted']);
    }

    public function testTheExtensionMergesTheInlineObjectItWidens(): void
    {
        $files = (new PhpExecutableEmitter('Example\\Trustpilot', 'example/trustpilot', []))->emit((new SpecificationNormalizer())->fromJson(self::spec()));
        $code = implode("\n", $files);
        foreach (['displayName', 'email'] as $field) {
            self::assertStringContainsString('$' . $field, $code);
        }
    }
}
