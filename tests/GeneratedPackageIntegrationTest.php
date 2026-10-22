<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use PHPUnit\Framework\TestCase;

final class GeneratedPackageIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sdk-generated-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $child) {
            if ($child->isDir()) {
                $this->removeDirectory($child->getPathname());
            } else {
                unlink($child->getPathname());
            }
        }
        rmdir($path);
    }

    public function testCliCreatesARealLoadableSDKAndConsumerRunsInSeparatePhpProcess(): void
    {
        $generator = dirname(__DIR__) . '/bin/generate-sdk.php';
        $fixture = __DIR__ . '/fixtures/accounts-openapi31.json';
        $dest = $this->directory . '/client';
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $generator, $fixture, $dest, 'Acme\\PublicSdk', 'acme/public-sdk',
        ]));
        exec($cmd . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        self::assertFileExists($dest . '/src/CasafariSDK.php');
        self::assertFileExists($dest . '/src/Api/Users.php');
        self::assertFileExists($dest . '/src/Api/Users/User.php');
        self::assertFileExists($dest . '/src/Responses/User.php');
        self::assertFileExists($dest . '/composer.json');

        $composer = json_decode(file_get_contents($dest . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('acme/public-sdk', $composer['name']);
        self::assertSame('src/', $composer['autoload']['psr-4']['Acme\\PublicSdk\\']);

        $consumer = <<<'PHP'
<?php
declare(strict_types=1);

require $argv[2]; // Only the installed runtime dependency, never SDK generator.
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'Acme\\PublicSdk\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $argv[1] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$fake = new class implements \Acme\PublicSdk\Transport\TransportInterface {
    public function send(string $method, string $url, array $headers, ?string $body): \Acme\PublicSdk\Transport\HttpResponse
    {
        if ($method !== 'GET' || $url !== 'https://api.example.test/v1/users/88?verbose=true') {
            throw new RuntimeException('Unexpected wire request: ' . $method . ' ' . $url);
        }
        return new \Acme\PublicSdk\Transport\HttpResponse(
            200,
            '{"id":88,"name":"Demo","role":"viewer","profile":{"city":"Bilbao","visits":2},"labels":["php"]}',
        );
    }
};
\Acme\PublicSdk\CasafariSDK::connect('https://api.example.test/v1', transport: $fake);
$user = \Acme\PublicSdk\Api\Users\User::getById(88, true);
if (!$user instanceof \Acme\PublicSdk\Responses\User
    || $user->role !== \Acme\PublicSdk\Enums\User\RoleEnum::Viewer
    || $user->profile->city !== 'Bilbao'
    || $user->labels[0] !== 'php') {
    throw new RuntimeException('Generated DTO hydration failed.');
}
echo "GENERATED_CONSUMER_OK\n";
PHP;
        file_put_contents($this->directory . '/consumer.php', $consumer);

        $runtimeAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        $external = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $this->directory . '/consumer.php', $dest, $runtimeAutoload,
        ]));
        $lines = [];
        $result = 0;
        exec($external . ' 2>&1', $lines, $result);
        self::assertSame(0, $result, implode("\n", $lines));
        self::assertContains('GENERATED_CONSUMER_OK', $lines);
    }

    public function testCliDerivesSdkNamespaceFromOpenApiTitleWhenNotSpecified(): void
    {
        $generator = dirname(__DIR__) . '/bin/generate-sdk.php';
        $fixture = __DIR__ . '/fixtures/accounts-openapi31.json';
        $dest = $this->directory . '/automatic-root';
        // The shorter CLI form supplies only vendor/package; no PHP namespace.
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $generator, $fixture, $dest, 'example/automatic-accounts-sdk',
        ]));
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(0, $exit, implode("\n", $lines));
        $manifest = json_decode(
            (string) file_get_contents($dest . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        // "Example Accounts" comes from the OpenAPI info.title, not a model name.
        self::assertSame('src/', $manifest['autoload']['psr-4']['ExampleAccounts\\']);
        self::assertFileExists($dest . '/src/Responses/User.php');
        self::assertFileExists($dest . '/src/Api/Users.php');
    }

    public function testCliGeneratesSdkFromYamlInput(): void
    {
        $generator = dirname(__DIR__) . '/bin/generate-sdk.php';
        $fixture = $this->directory . '/input.yml';
        file_put_contents($fixture, <<<'YAML'
openapi: 3.1.0
info:
  title: YAML API
  version: '1.0'
servers:
  - url: https://api.example.test
paths:
  /health:
    get:
      operationId: getHealth
      responses:
        '204':
          description: Healthy
YAML);
        $dest = $this->directory . '/yaml-client';
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $generator, $fixture, $dest, 'Acme\\YamlSdk', 'acme/yaml-sdk',
        ]));
        $lines = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $lines, $exit);

        self::assertSame(0, $exit, implode("\n", $lines));
        self::assertFileExists($dest . '/src/CasafariSDK.php');
        self::assertFileExists($dest . '/src/Api/Health.php');
        self::assertFileExists($dest . '/composer.json');
    }

    public function testCliRefusesToOverwriteNonemptyOutputDirectory(): void
    {
        $generator = dirname(__DIR__) . '/bin/generate-sdk.php';
        $fixture = __DIR__ . '/fixtures/accounts-swagger2.json';
        $dest = $this->directory;
        file_put_contents($dest . '/keep.txt', 'do-not-overwrite');
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $generator, $fixture, $dest, 'Acme\\PublicSdk', 'acme/public-sdk',
        ]));
        exec($cmd . ' 2>&1', $lines, $exit);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Destination must be empty', implode("\n", $lines));
        self::assertSame('do-not-overwrite', file_get_contents($dest . '/keep.txt'));
    }
}
