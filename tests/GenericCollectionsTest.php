<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * A generated collection or map says what it holds in the PHPDoc of its class (`@extends ...TypedArray<Item>`), so that static analysis
 * knows the type of every item it gives. Without it the runtime's generic base says `mixed` and `$item->name` is an error at PHPStan max.
 */
final class GenericCollectionsTest extends TestCase
{
    /** @return array<string, string> */
    private static function sdk(): array
    {
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Generic API', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => [],
            'components' => ['schemas' => [
                'Item' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
                'Box' => ['type' => 'object', 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                    'byName' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/Item']],
                    'anything' => ['type' => 'array', 'items' => []],
                ]],
            ]],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Generic\\Sdk', 'example/generic-sdk'))->emit($contract);
    }

    /** @return list<string> The `@extends` lines of the collections and maps. */
    private static function declared(): array
    {
        $lines = [];
        foreach (self::sdk() as $path => $code) {
            if (preg_match('#^src/(Collections|DTOs)/#', $path) === 1 && preg_match('#^/\*\* @extends .*$#m', $code, $match) === 1) {
                $lines[$path] = $match[0];
            }
        }
        ksort($lines);
        return array_values($lines);
    }

    public function testCollectionsAndMapsSayWhatTheyHold(): void
    {
        $declared = self::declared();

        self::assertContains('/** @extends \\Dmorenof\\SdkRuntime\\TypedArray<string> */', $declared, 'a list of strings');
        self::assertContains('/** @extends \\Dmorenof\\SdkRuntime\\TypedArray<\\Generic\\Sdk\\DTOs\\Item> */', $declared, 'a list of models');
        self::assertContains('/** @extends \\Dmorenof\\SdkRuntime\\Map<int> */', $declared, 'a map of integers');
        self::assertContains('/** @extends \\Dmorenof\\SdkRuntime\\Map<\\Generic\\Sdk\\DTOs\\Item> */', $declared, 'a map of models');
    }

    public function testAListOfAnythingIsLeftGenericBecauseItsItemTypeHasNoExactName(): void
    {
        foreach (self::sdk() as $path => $code) {
            if (str_contains($path, 'Anything') && str_contains($path, 'Collections/')) {
                self::fail("A list of anything became a collection: $path");
            }
        }
        $box = self::sdk()['src/DTOs/Box.php'];
        self::assertStringContainsString('public array $anything', $box);
    }
}
