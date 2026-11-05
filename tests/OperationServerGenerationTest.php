<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;

final class OperationServerGenerationTest extends TestCase
{
    private static function source(): string
    {
        return file_get_contents(__DIR__ . '/fixtures/operation-servers-openapi30.json');
    }

    public static function setUpBeforeClass(): void
    {
        $contract = (new SpecificationNormalizer())->fromJson(self::source(), strict: true);
        $files = (new PhpExecutableEmitter('Example\\OperationServers', 'example/operation-servers'))->emit($contract);
        spl_autoload_register(static function (string $class) use ($files): void {
            $prefix = 'Example\\OperationServers\\';
            if (str_starts_with($class, $prefix)) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (isset($files[$path])) {
                    eval(substr($files[$path], strlen('<?php')));
                }
            }
        });
    }

    private static function transport(): TransportInterface
    {
        return new class implements TransportInterface {
            public array $urls = [];
            public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
            {
                $this->urls[] = $url;
                return new HttpResponse(204, '');
            }
        };
    }

    public function testOperationThenPathThenRootServersAreUsedOnTheWire(): void
    {
        $transport = self::transport();
        $client = \Example\OperationServers\Client::connect('https://configured.example.test/v9', transport: $transport);
        $items = $client->resource(\Example\OperationServers\Resources\Items::class);
        $items->getById('a/b', new \Example\OperationServers\GetParams\Items\ById((object) ['q' => 'a b']));
        $items->postById('a/b');
        $client->resource(\Example\OperationServers\Resources\Root::class)->get();
        $client->resource(\Example\OperationServers\Resources\Equivalent::class)->get();
        self::assertSame([
            'https://operation.example.test/v3/items/a%2Fb?q=a%20b',
            'https://path.example.test/v2/items/a%2Fb',
            'https://configured.example.test/v9/root',
            'https://configured.example.test/v9/equivalent',
        ], $transport->urls);
    }

    public function testStaticConfigurationSnapshotsServerOverridesAndSharesThemWithResources(): void
    {
        $transport = self::transport();
        $configuration = new \Example\OperationServers\Configuration();
        $configuration->baseUrl = 'https://configured.test/v9';
        $configuration->transport = $transport;
        $configuration->serverOverrides = [
            'https://path.example.test/v2/' => 'http://127.0.0.1:9090/path',
            'https://operation.example.test/v3' => 'https://staging.test/operation/',
        ];
        \Example\OperationServers\RoutingSDK::configure($configuration);
        $configuration->serverOverrides['https://operation.example.test/v3'] = 'https://changed.test';
        \Example\OperationServers\Items::getById('id');
        \Example\OperationServers\Items::postById('id');
        self::assertSame(['https://staging.test/operation/items/id', 'http://127.0.0.1:9090/path/items/id'], $transport->urls);
    }

    public function testBothLiteralOverridesAndTheWholeCasafariContractPassStrictAcceptance(): void
    {
        $analyzer = new OpenApiAcceptanceAnalyzer();
        self::assertTrue($analyzer->analyze(self::source())['accepted']);
        $source = file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json');
        $report = $analyzer->analyze($source);
        self::assertTrue($report['accepted'], json_encode($report['full']));
        self::assertSame(46, $report['summary']['generated']);
        self::assertSame(0, $report['summary']['compatibility_failed']);
    }

    public function testRelativeAndTemplateOperationServersRemainExplicitlyRejected(): void
    {
        $source = json_decode(self::source(), true, 512, JSON_THROW_ON_ERROR);
        $source['paths']['/items/{id}']['get']['servers'] = [['url' => '/relative']];
        $this->expectException(\Dmorenof\SdkGenerator\Diagnostics\CompatibilityException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($source, JSON_THROW_ON_ERROR), strict: true);
    }
}
