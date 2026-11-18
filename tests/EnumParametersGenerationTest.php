<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** A path, query or header parameter whose schema lists its values is an enum; the request carries the backing value. */
final class EnumParametersGenerationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(): array
    {
        return ['openapi' => '3.0.3', 'info' => ['title' => 'Param Enums', 'version' => '1'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => ['/items/{state}' => ['get' => ['operationId' => 'getItem', 'parameters' => [
                ['name' => 'state', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['open', 'closed']]],
                ['name' => 'level', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]],
                ['name' => 'sort', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
                ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']]]],
                ['name' => 'X-Mode', 'in' => 'header', 'schema' => ['type' => 'string', 'enum' => ['fast', 'slow'], 'nullable' => true]],
            ], 'responses' => ['204' => ['description' => 'none']]]]]];
    }

    /** @return array<string, string> */
    private static function files(): array
    {
        return (new PhpExecutableEmitter('Example\\ParamEnums', 'example/param-enums'))->emit(
            (new SpecificationNormalizer())->fromJson(json_encode(self::document(), JSON_THROW_ON_ERROR)),
        );
    }

    public function testScalarEnumParametersAreEnumsAndTheirListIsNotRepeated(): void
    {
        $files = self::files();
        $params = $files['src/GetParams/Items/ByState.php'];
        self::assertStringContainsString('public ?\\Example\\ParamEnums\\Enums\\Items\\ByState\\Get\\Query\\QueryLevelEnum $level;', $params);
        self::assertStringContainsString('public \\Example\\ParamEnums\\Enums\\Items\\ByState\\Get\\Query\\QuerySortEnum $sort;', $params);
        self::assertStringContainsString('public ?\\Example\\ParamEnums\\Enums\\Items\\ByState\\Get\\Header\\XModeEnum $XMode;', $params);
        self::assertStringNotContainsString("'asc'", $params);
        self::assertStringNotContainsString("'fast'", $params);
        // A list of enum values is still a list of strings that the rule checks.
        self::assertStringContainsString('@var list<string>|null', $params);
        self::assertStringContainsString("'x'", $params);
        self::assertStringContainsString('PathStateEnum $state', $files['src/Resources/Items.php']);
    }

    public function testTheRequestCarriesTheBackingValues(): void
    {
        $directory = sys_get_temp_dir() . '/param-enum-sdk-' . bin2hex(random_bytes(6));
        foreach (self::files() as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\ParamEnums\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $history = [];
            $stack = HandlerStack::create(new MockHandler([new Response(204)]));
            $stack->push(Middleware::history($history));
            $configuration = new \Dmorenof\SdkRuntime\Configuration();
            $configuration->baseUrl = 'https://api.example.test';
            $configuration->httpClient = new Client(['handler' => $stack]);
            $client = \Example\ParamEnums\Client::connect($configuration)->resource(\Example\ParamEnums\Resources\Items::class);

            $params = new \Example\ParamEnums\GetParams\Items\ByState();
            $params->sort = \Example\ParamEnums\Enums\Items\ByState\Get\Query\QuerySortEnum::Desc;
            $params->level = \Example\ParamEnums\Enums\Items\ByState\Get\Query\QueryLevelEnum::Value2;
            $params->XMode = \Example\ParamEnums\Enums\Items\ByState\Get\Header\XModeEnum::Slow;
            $params->tags = ['x', 'y'];
            $client->getByState(\Example\ParamEnums\Enums\Items\ByState\Get\Path\PathStateEnum::Closed, $params);

            $request = $history[0]['request'];
            self::assertSame('https://api.example.test/items/closed?level=2&sort=desc&tags=x&tags=y', (string) $request->getUri());
            self::assertSame('slow', $request->getHeaderLine('X-Mode'));
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
