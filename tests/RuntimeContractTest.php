<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpResourceEmitter;
use Dmorenof\SdkRuntime\Operation;
use PHPUnit\Framework\TestCase;

final class RuntimeContractTest extends TestCase
{
    public function testEmitterOmitsExactlyTheDefaultsTheRuntimeInherits(): void
    {
        self::assertSame(Operation::DEFAULTS, PhpResourceEmitter::OPERATION_DEFAULTS);
    }

    public function testGeneratedSdkInheritsItsExecutorAndConnectionOptions(): void
    {
        $source = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        $contract = (new \Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer())->fromJson($source);
        $files = (new \Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter('Example\\InheritedRuntime', 'example/inherited-runtime'))->emit($contract);
        foreach (['src/Configuration.php', 'src/Client.php'] as $path) {
            eval(substr($files[$path], strlen('<?php')));
        }
        $method = new \ReflectionMethod(\Example\InheritedRuntime\Client::class, 'executeArguments');
        self::assertSame(\Dmorenof\SdkRuntime\ApiClient::class, $method->getDeclaringClass()->getName());
        $configuration = new \Example\InheritedRuntime\Configuration();
        self::assertInstanceOf(\Dmorenof\SdkRuntime\Configuration::class, $configuration);
        self::assertSame('https://api.example.test/v1', $configuration->baseUrl);
    }

}
