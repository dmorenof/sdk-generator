<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Operations that declare the very same inline error body for the same status share one model, so a typed 401 is one
 * class and not one per operation. Anything that differs, even by a description, stays apart.
 */
final class SharedErrorResponseTest extends TestCase
{
    private const NS = 'Example\\SharedErrors';

    /** @param array<string, mixed> $body */
    private static function error(array $body): array
    {
        return ['description' => 'error', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $body, 'required' => array_keys($body)]]]];
    }

    /** @param array<string, array<string, array<string, mixed>>> $errors Path => status => properties. @return array<string, string> */
    private static function files(array $errors): array
    {
        $paths = [];
        foreach ($errors as $path => $statuses) {
            $responses = ['200' => ['description' => 'ok']];
            foreach ($statuses as $status => $body) {
                $responses[(string) $status] = self::error($body);
            }
            $paths[$path] = ['get' => ['operationId' => 'get' . ucfirst(trim($path, '/')), 'responses' => $responses]];
        }
        $document = ['openapi' => '3.0.3', 'info' => ['title' => 'Errors', 'version' => '1'], 'servers' => [['url' => 'https://api.example.test']], 'paths' => $paths];
        return (new PhpExecutableEmitter(self::NS, 'example/shared-errors', []))->emit((new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    /** @param array<string, string> $files @return list<string> */
    private static function errorClasses(array $files): array
    {
        $paths = array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'src/Responses/Errors/'));
        sort($paths);
        return array_values($paths);
    }

    public function testTheSameBodyForTheSameStatusIsOneClassUsedByEveryOperation(): void
    {
        $detail = ['detail' => ['type' => 'string']];
        $files = self::files(['/a' => [401 => $detail], '/b' => [401 => $detail], '/c' => [401 => $detail]]);

        self::assertSame(['src/Responses/Errors/Status401Response.php'], self::errorClasses($files));
        foreach (['A', 'B', 'C'] as $resource) {
            self::assertStringContainsString("'\\\\Example\\\\SharedErrors\\\\Responses\\\\Errors\\\\Status401Response'", $files["src/Resources/$resource.php"]);
        }
    }

    public function testABodyOnlyOneOperationDeclaresKeepsTheNameOfItsOperation(): void
    {
        $files = self::files(['/a' => [401 => ['detail' => ['type' => 'string']]], '/b' => [401 => ['detail' => ['type' => 'string']]], '/c' => [401 => ['code' => ['type' => 'integer']]]]);

        self::assertSame(['src/Responses/Errors/C/GetStatus401Response.php', 'src/Responses/Errors/Status401Response.php'], self::errorClasses($files));
        self::assertStringNotContainsString('Responses\\\\Errors\\\\Status401Response', $files['src/Resources/C.php']);
        self::assertStringContainsString('Responses\\\\Errors\\\\C\\\\GetStatus401Response', $files['src/Resources/C.php']);
    }

    public function testTheSameBodyUnderAnotherStatusIsAnotherClass(): void
    {
        $detail = ['detail' => ['type' => 'string']];
        $files = self::files(['/a' => [401 => $detail, 403 => $detail], '/b' => [401 => $detail, 403 => $detail]]);

        self::assertSame(['src/Responses/Errors/Status401Response.php', 'src/Responses/Errors/Status403Response.php'], self::errorClasses($files));
    }

    public function testTwoDifferentSharedBodiesOfOneStatusDoNotCollide(): void
    {
        $detail = ['detail' => ['type' => 'string']];
        $code = ['code' => ['type' => 'integer']];
        $files = self::files(['/a' => [400 => $detail], '/b' => [400 => $detail], '/c' => [400 => $code], '/d' => [400 => $code]]);

        self::assertSame(['src/Responses/Errors/Status400Response.php', 'src/Responses/Errors/Status400Variant2Response.php'], self::errorClasses($files));
        self::assertStringContainsString('public string $detail', $files['src/Responses/Errors/Status400Response.php']);
        self::assertStringContainsString('public int $code', $files['src/Responses/Errors/Status400Variant2Response.php']);
    }

    public function testBodiesThatDifferEvenByADescriptionStayApart(): void
    {
        $files = self::files([
            '/a' => [404 => ['message' => ['type' => 'string', 'description' => 'What was not found.']]],
            '/b' => [404 => ['message' => ['type' => 'string', 'description' => 'What is missing.']]],
        ]);

        self::assertSame(['src/Responses/Errors/A/GetStatus404Response.php', 'src/Responses/Errors/B/GetStatus404Response.php'], self::errorClasses($files));
    }

    public function testNoOperationPointsAtAClassThatWasNotGenerated(): void
    {
        $files = self::files(['/a' => [401 => ['detail' => ['type' => 'string']]], '/b' => [401 => ['detail' => ['type' => 'string']], 404 => ['m' => ['type' => 'string']]]]);
        $classes = array_map(static fn (string $path): string => 'Example\\SharedErrors\\' . str_replace('/', '\\', substr($path, strlen('src/'), -4)), array_keys($files));

        preg_match_all("/'\\\\\\\\(Example\\\\\\\\SharedErrors\\\\\\\\Responses\\\\\\\\Errors\\\\\\\\[A-Za-z0-9\\\\\\\\]+)'/", implode("\n", $files), $matches);
        foreach (array_unique($matches[1]) as $referenced) {
            self::assertContains(str_replace('\\\\', '\\', $referenced), $classes, "$referenced is referenced but not generated");
        }
        self::assertNotEmpty($matches[1]);
    }
}
