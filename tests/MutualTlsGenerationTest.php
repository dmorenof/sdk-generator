<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Diagnostics\ContractAuditor;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** A `mutualTLS` scheme (OpenAPI 3.1): the client presents a certificate in the TLS handshake. */
final class MutualTlsGenerationTest extends TestCase
{
    private static function spec(string $version = '3.1.0'): string
    {
        $document = json_decode((string) file_get_contents(__DIR__ . '/fixtures/mutual-tls-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $document['openapi'] = $version;
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    public function testTheSchemeIsEmittedAsAMutualTlsScheme(): void
    {
        $files = (new PhpExecutableEmitter('Example\\MtlsEmit', 'example/mtls', []))->emit((new SpecificationNormalizer())->fromJson(self::spec()));
        self::assertStringContainsString("'type' => 'mutualTLS'", $files['src/Client.php']);
        self::assertStringContainsString("'security' => [\n", implode("\n", $files)); // the operation that requires it lists it
    }

    public function testOnlyOpenApi31HasMutualTls(): void
    {
        foreach (['3.0.3', '3.0.0'] as $version) {
            try {
                (new SpecificationNormalizer())->fromJson(self::spec($version));
                self::fail("mutualTLS accepted in OpenAPI $version.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Unsupported security scheme type', $exception->getMessage());
            }
        }
    }

    public function testARequirementOfAMutualTlsSchemeCannotListScopes(): void
    {
        $document = json_decode(self::spec(), true, 512, JSON_THROW_ON_ERROR);
        $document['paths']['/secure']['get']['security'] = [['mtls' => ['read']]];
        $this->expectException(\InvalidArgumentException::class);
        (new SpecificationNormalizer())->fromJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function testTheAuditorDoesNotFlagTheScheme(): void
    {
        $document = json_decode(self::spec(), true, 512, JSON_THROW_ON_ERROR);
        $codes = array_map(static fn ($issue): string => $issue->code, (new ContractAuditor())->audit($document, 'mutual-tls.json'));
        self::assertNotContains('unsupported_security_scheme', $codes);

        $document['openapi'] = '3.0.3'; // not a scheme OpenAPI 3.0 has
        $codes = array_map(static fn ($issue): string => $issue->code, (new ContractAuditor())->audit($document, 'mutual-tls.json'));
        self::assertContains('unsupported_security_scheme', $codes);
    }

    public function testAGeneratedClientPresentsTheCertificateOnlyWhereTheOperationRequiresIt(): void
    {
        $files = (new PhpExecutableEmitter('Example\\MtlsRun', 'example/mtls', []))->emit((new SpecificationNormalizer())->fromJson(self::spec()));
        $directory = sys_get_temp_dir() . '/mtls-sdk-' . bin2hex(random_bytes(6));
        foreach ($files as $path => $content) {
            @mkdir(dirname("$directory/$path"), 0777, true);
            file_put_contents("$directory/$path", $content);
        }
        spl_autoload_register($loader = static function (string $class) use ($directory): void {
            $prefix = 'Example\\MtlsRun\\';
            if (str_starts_with($class, $prefix) && is_file($file = "$directory/src/" . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php')) {
                require $file;
            }
        });
        try {
            $seen = [];
            $handler = static function (\Psr\Http\Message\RequestInterface $request, array $options) use (&$seen) {
                $seen[$request->getUri()->getPath()] = $options;
                return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"pong":"ok"}'));
            };
            $config = new \Example\MtlsRun\Configuration();
            $config->httpClient = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($handler)]);
            $config->securityCredentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials([
                'mtls' => \Dmorenof\SdkRuntime\Security\ClientCertificate::fromFiles('/etc/client.pem', '/etc/client.key', 'pw'),
            ]);
            \Example\MtlsRun\Client::configure($config);
            $client = \Example\MtlsRun\Client::current();
            $client->resource(\Example\MtlsRun\Resources\Secure::class)->get();
            $client->resource(\Example\MtlsRun\Resources\PublicResource::class)->get();
            self::assertSame('/etc/client.pem', $seen['/secure']['cert']);
            self::assertSame(['/etc/client.key', 'pw'], $seen['/secure']['ssl_key']);
            self::assertArrayNotHasKey('cert', $seen['/public']);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
