<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

/** The public API has at most two parameters: (identifier, payload). */
final class TwoArgumentApiTest extends TestCase
{
    public function testAllOperationShapesAndCompositeRouteIdentifierExecuteCorrectly(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $get = $spec['paths']['/users/{id}']['get'];
        $post = $spec['paths']['/users']['post'];
        $get['operationId'] = 'readCompositeUser';
        $get['parameters'] = [
            ['name' => 'company_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'user_id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ['name' => 'verbose', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'boolean']],
        ];
        $spec['info']['title'] = 'Two Argument Test';
        $spec['paths'] = [
            '/companies/{company_id}/users/{user_id}' => ['get' => $get],
            '/invoices' => ['post' => $post],
            '/health' => ['get' => [
                'operationId' => 'getHealth',
                'responses' => ['204' => ['description' => 'Healthy']],
            ]],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\TwoArgSdk', 'example/two-arg-sdk'))->emit($contract);

        $identifierPath = 'src/PathParams/Companies/ByCompanyId/Users/ByUserId.php';
        $payloadPath = 'src/GetParams/Companies/ByCompanyId/Users/ByUserId.php';
        self::assertArrayHasKey($identifierPath, $files);
        self::assertArrayHasKey($payloadPath, $files);
        self::assertArrayNotHasKey('src/PostParams/Invoices.php', $files);
        self::assertStringContainsString("'company_id' => 'company_id'", $files[$identifierPath]);
        self::assertStringContainsString("'verbose' => 'verbose'", $files[$payloadPath]);

        foreach (['src/Companies/ByCompanyId/Users.php', 'src/Invoices.php', 'src/Health.php'] as $path) {
            self::assertArrayHasKey($path, $files);
            preg_match_all('/public static function \w+\((.*?)\):/s', $files[$path], $matches);
            foreach ($matches[1] as $params) {
                self::assertLessThanOrEqual(2, substr_count($params, '$'), $path);
            }
        }

        $loader = static function (string $class) use ($files): void {
            $prefix = 'Example\\TwoArgSdk\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (isset($files[$path])) {
                eval(substr($files[$path], strlen('<?php')));
            }
        };
        spl_autoload_register($loader);
        try {
            $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
                public array $calls = [];
                public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
                {
                    $this->calls[] = compact('method', 'url', 'body');
                    return new \Dmorenof\SdkRuntime\Transport\HttpResponse(
                        $url === 'https://sdk.test/health' ? 204 : ($method === 'POST' ? 201 : 200),
                        $url === 'https://sdk.test/health'
                            ? '' : '{"id":13,"name":"Ada","role":"viewer","profile":{"city":"Madrid","visits":1},"labels":[]}',
                    );
                }
            };
            $config = new \Example\TwoArgSdk\Configuration();
            $config->baseUrl = 'https://sdk.test';
            $config->transport = $transport;
            \Example\TwoArgSdk\TwoArgumentTestSDK::configure($config);

            $ids = new \Example\TwoArgSdk\PathParams\Companies\ByCompanyId\Users\ByUserId((object) [
                'company_id' => 9, 'user_id' => 13,
            ]);
            $payload = new \Example\TwoArgSdk\GetParams\Companies\ByCompanyId\Users\ByUserId((object) [
                'verbose' => false,
            ]);
            $person = \Example\TwoArgSdk\Companies\ByCompanyId\Users::getByUserId($ids, $payload);
            self::assertSame('Ada', $person->name);
            self::assertSame('https://sdk.test/companies/9/users/13?verbose=false', $transport->calls[0]['url']);

            $invoice = \Example\TwoArgSdk\Invoices::post(
                new \Example\TwoArgSdk\DTOs\NewUser((object) ['name' => 'Ada']),
            );
            self::assertSame('Ada', $invoice->name);
            self::assertSame(['name' => 'Ada'], json_decode($transport->calls[1]['body'], true, 512, JSON_THROW_ON_ERROR));
            \Example\TwoArgSdk\Health::get();
            self::assertSame('https://sdk.test/health', $transport->calls[2]['url']);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
