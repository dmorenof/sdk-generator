<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\DocumentInspector;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Stripe writes its nested data as `style: deepObject, explode: true`: objects inside objects, lists of objects, unions and
 * lists of strings, in urlencoded bodies and in the query (`collection_options[fields]`, `custom_fields[0][key]`, `created[gt]`).
 * They are sent with the runtime's `nested` format.
 */
final class NestedFormSupportTest extends TestCase
{
    private static int $count = 0;

    private static function spec(): string
    {
        $deep = ['style' => 'deepObject', 'explode' => true];
        return json_encode([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Nested', 'version' => '1'],
            'servers' => [['url' => 'https://nested.example.com']],
            'paths' => [
                '/account_links' => ['post' => ['operationId' => 'createAccountLink',
                    'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => [
                        'schema' => ['type' => 'object', 'required' => ['account'], 'properties' => [
                            'account' => ['type' => 'string'],
                            'collection_options' => ['type' => 'object', 'properties' => [
                                'fields' => ['type' => 'string', 'enum' => ['currently_due', 'eventually_due']],
                                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string']]]],
                            ]],
                            'expand' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'range' => ['anyOf' => [['type' => 'object', 'properties' => ['gt' => ['type' => 'integer']]], ['type' => 'integer']]],
                        ]],
                        'encoding' => ['collection_options' => $deep, 'expand' => $deep, 'range' => $deep],
                    ]]],
                    'responses' => ['200' => ['description' => 'ok']]]],
                '/files' => ['post' => ['operationId' => 'createFile',
                    'requestBody' => ['content' => ['multipart/form-data' => [
                        'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                            'file' => ['type' => 'string', 'format' => 'binary'],
                            'file_link_data' => ['type' => 'object', 'properties' => ['create' => ['type' => 'boolean'], 'metadata' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]],
                        ]],
                        'encoding' => ['file_link_data' => $deep],
                    ]]],
                    'responses' => ['200' => ['description' => 'ok']]]],
                '/charges' => ['get' => ['operationId' => 'listCharges',
                    'parameters' => [
                        ['name' => 'created', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'object', 'properties' => [
                            'gt' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ]]] + $deep,
                        ['name' => 'expand', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]] + $deep,
                    ],
                    'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function testNestedDeepObjectsAreExecutable(): void
    {
        $report = (new DocumentInspector())->inspect(self::spec());
        self::assertTrue($report['generatable'], json_encode($report['diagnostics'] ?? [], JSON_PRETTY_PRINT));
    }

    public function testTheOperationsDeclareTheNestedFormat(): void
    {
        $files = (new PhpExecutableEmitter('Example\\Nested' . ++self::$count, 'example/nested', []))->emit((new SpecificationNormalizer())->fromJson(self::spec()));
        $code = implode("\n", $files);
        self::assertStringContainsString("'collection_options' => 'nested'", $code);
        self::assertStringContainsString("'created' => 'nested'", $code);
        self::assertStringContainsString("'expand' => 'nested'", $code);
        self::assertStringContainsString("'nested' => true", $code);
    }
}
