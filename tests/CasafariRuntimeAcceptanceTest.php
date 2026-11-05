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

final class CasafariRuntimeAcceptanceTest extends TestCase
{
    private const NAMESPACE = 'CasafariRuntimeSdk';
    private string $directory;
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/casafari-sdk-runtime-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);

        $source = file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json');
        self::assertIsString($source);
        $contract = (new SpecificationNormalizer())->fromJson($source);
        $files = (new PhpExecutableEmitter(self::NAMESPACE, 'example/casafari-runtime-sdk'))->emit($contract);
        foreach ($files as $relative => $contents) {
            $path = $this->directory . '/' . $relative;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            file_put_contents($path, $contents);
        }

        $this->autoload = function (string $class): void {
            $prefix = self::NAMESPACE . '\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $this->directory . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeDirectory($this->directory);
    }

    public static function platformOperations(): iterable
    {
        yield 'create search' => ['V1\\PropertySourcing\\Searches', 'post', [['location_ids' => [12345]]],
            'https://platform.casafari.com/v1/property-sourcing/searches'];
        yield 'describe search' => ['V1\\PropertySourcing\\Searches\\Describe', 'post', [['location_ids' => [12345]]],
            'https://platform.casafari.com/v1/property-sourcing/searches/describe'];
        yield 'next search page' => ['V1\\PropertySourcing\\Searches', 'getByCursor', ['cur/a b'],
            'https://platform.casafari.com/v1/property-sourcing/searches/cur%2Fa%20b'];
        yield 'property details' => ['V1\\PropertySourcing\\Properties', 'getByPropertyId', ['prop/a b', ['expand' => 'listings,history', 'operation' => 'sale']],
            'https://platform.casafari.com/v1/property-sourcing/properties/prop%2Fa%20b?expand=listings%2Chistory&operation=sale'];
        yield 'resolve property' => ['V1\\PropertySourcing\\Properties\\Resolve', 'post', [['url' => 'https://agency.example/listing/123', 'country' => 'ES']],
            'https://platform.casafari.com/v1/property-sourcing/properties/resolve'];
        yield 'list seller leads' => ['V1\\SellerLeads', 'get', [['lat' => 40.4168, 'lon' => -3.7038]],
            'https://platform.casafari.com/v1/seller-leads?lat=40.4168&lon=-3.7038'];
        yield 'seller lead details' => ['V1\\SellerLeads', 'getByLeadId', ['lead/a b'],
            'https://platform.casafari.com/v1/seller-leads/lead%2Fa%20b'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('platformOperations')]
    public function testEveryPlatformOperationSendsToItsDeclaredHost(string $route, string $method, array $values, string $expectedUrl): void
    {
        $history = [];
        // A mock error avoids inventing success DTO payloads while proving the
        // actual generated method, body validation, auth and transport URL.
        $stack = HandlerStack::create(new MockHandler([
            new Response(401, ['Content-Type' => 'application/problem+json'], '{"detail":"Mock response"}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = \CasafariRuntimeSdk\Client::connect('https://root.invalid', new Client(['handler' => $stack]),
            securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['PlatformApiKey' => 'routing-test-token']));
        $resource = $client->resource('CasafariRuntimeSdk\\Resources\\' . $route);
        $parameters = (new \ReflectionMethod($resource, $method))->getParameters();
        $arguments = [];
        foreach ($values as $index => $value) {
            $type = $parameters[$index]->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            $name = $type->getName();
            $arguments[] = $type->isBuiltin() ? $value : new $name((object) $value);
        }
        try {
            $resource->{$method}(...$arguments);
            self::fail('The mock 401 response should produce an API exception.');
        } catch (\Dmorenof\SdkRuntime\ApiException $exception) {
            self::assertSame(401, $exception->response->status);
        }
        self::assertCount(1, $history);
        self::assertSame($expectedUrl, (string) $history[0]['request']->getUri());
        self::assertSame('Bearer routing-test-token', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testGeneratedCasafariClientHandlesAuthenticatedListAndTypedError(): void
    {
        $history = [];
        $responses = new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                '[{"id":43,"name":"Lisbon homes","filter":{"operation":"sale","location_ids":[10,20]}}]',
            ),
            new Response(
                201,
                ['Content-Type' => 'application/json'],
                '{"id":44,"name":"Runtime feed","filter":{"operation":"sale","location_ids":[10]}}',
            ),
            new Response(
                401,
                ['Content-Type' => 'application/json'],
                '{"detail":"Authentication required"}',
            ),
        ]);
        $stack = HandlerStack::create($responses);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        $credentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials([
            'HTTPBearer' => 'runtime-test-token',
        ]);
        $client = \CasafariRuntimeSdk\Client::connect(
            'https://api.casafari.test',
            $http,
            [],
            $credentials,
        );
        $feedsResource = \CasafariRuntimeSdk\Resources\Api\V1\ListingAlerts\Feeds::class;

        $feeds = $client->resource($feedsResource)->get(new \CasafariRuntimeSdk\GetParams\Api\V1\ListingAlerts\Feeds((object) ['filter' => true, 'only_self' => false]));

        self::assertInstanceOf(\Dmorenof\SdkRuntime\TypedArray::class, $feeds);
        self::assertCount(1, $feeds);
        self::assertInstanceOf(\CasafariRuntimeSdk\DTOs\Api\AlertsFeedV1::class, $feeds[0]);
        self::assertSame(43, $feeds[0]->id);
        self::assertSame('Lisbon homes', $feeds[0]->name);
        self::assertSame('sale', $feeds[0]->filter->operation->value);
        self::assertSame([10, 20], $feeds[0]->filter->location_ids->getArrayCopy());

        $sent = $history[0]['request'];
        self::assertSame('GET', $sent->getMethod());
        self::assertSame(
            'https://api.casafari.test/api/v1/listing-alerts/feeds?filter=true&only_self=false',
            (string) $sent->getUri(),
        );
        self::assertSame('Bearer runtime-test-token', $sent->getHeaderLine('Authorization'));

        $created = $client->resource($feedsResource)->post(
            new \CasafariRuntimeSdk\DTOs\Api\AlertsFeedV1((object) [
                'name' => 'Runtime feed',
                'filter' => (object) ['operation' => 'sale', 'location_ids' => [10]],
            ]),
        );
        self::assertSame(44, $created->id);
        self::assertSame('sale', $created->filter->operation->value);
        self::assertSame('POST', $history[1]['request']->getMethod());
        self::assertSame(
            'https://api.casafari.test/api/v1/listing-alerts/feeds',
            (string) $history[1]['request']->getUri(),
        );
        self::assertSame('Bearer runtime-test-token', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertEquals(
            [
                'name' => 'Runtime feed',
                'filter' => ['operation' => 'sale', 'location_ids' => [10]],
            ],
            json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );

        try {
            $client->resource($feedsResource)->get(new \CasafariRuntimeSdk\GetParams\Api\V1\ListingAlerts\Feeds((object) ['filter' => false]));
            self::fail('The generated client did not throw for HTTP 401.');
        } catch (\Dmorenof\SdkRuntime\ApiException $exception) {
            self::assertSame(401, $exception->getCode());
            self::assertInstanceOf(\Dmorenof\SdkRuntime\DTO::class, $exception->error);
            self::assertSame('Authentication required', $exception->error->detail);
            self::assertSame('{"detail":"Authentication required"}', $exception->response->body);
        }
        self::assertCount(3, $history);
        self::assertSame('Bearer runtime-test-token', $history[2]['request']->getHeaderLine('Authorization'));

        $unauthenticatedHistory = [];
        $unauthenticatedStack = HandlerStack::create(new MockHandler());
        $unauthenticatedStack->push(Middleware::history($unauthenticatedHistory));
        $client = \CasafariRuntimeSdk\Client::connect(
            'https://api.casafari.test',
            new Client(['handler' => $unauthenticatedStack]),
        );
        try {
            $client->resource($feedsResource)->get(new \CasafariRuntimeSdk\GetParams\Api\V1\ListingAlerts\Feeds((object) ['filter' => true]));
            self::fail('The generated client sent a secured request without credentials.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('No configured credentials', $exception->getMessage());
        }
        self::assertSame([], $unauthenticatedHistory);
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
}
