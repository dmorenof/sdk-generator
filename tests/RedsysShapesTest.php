<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use PHPUnit\Framework\TestCase;

/**
 * Redsys's contract (reconstructed from its public documents) carries a base64 JSON string described with the content vocabulary and
 * declares webhooks. Both are annotations for a client SDK: the document generates, and the report says what was not executed.
 */
final class RedsysShapesTest extends TestCase
{
    private static function spec(): string
    {
        return json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Pay', 'version' => '1'],
            'servers' => [['url' => 'https://pay.example.com']],
            'paths' => ['/pay' => ['post' => ['operationId' => 'pay',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']], 'text/plain' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]]]]]],
            'webhooks' => ['notification' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]],
                'responses' => ['200' => ['description' => 'ok']]]]],
            'components' => ['schemas' => ['Envelope' => ['type' => 'object', 'properties' => [
                'params' => ['type' => 'string', 'contentEncoding' => 'base64', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'object']],
            ]]]],
        ], JSON_THROW_ON_ERROR);
    }

    public function testContentVocabularyAndWebhooksAreReportedNotRefused(): void
    {
        $report = (new DocumentInspector())->inspect(self::spec());
        self::assertTrue($report['generatable'], json_encode($report['diagnostics']));
        self::assertTrue($report['strictlyAccepted']);
        $codes = array_map(static fn ($d): string => $d->code, $report['diagnostics']);
        self::assertContains('content_not_executed', $codes);
        self::assertContains('webhooks_not_executed', $codes);
    }
}
