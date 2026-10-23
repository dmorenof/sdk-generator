<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use PHPUnit\Framework\TestCase;

final class GeneratedSdkUsabilityTest extends TestCase
{
    private static array $files;
    private static \Closure $autoload;

    public static function setUpBeforeClass(): void
    {
        $source = json_decode((string) file_get_contents(__DIR__ . '/fixtures/casafari-openapi31.json'), true, 512, JSON_THROW_ON_ERROR);
        $source['components']['schemas']['UnionBatch'] = [
            'type' => 'object',
            'properties' => ['values' => ['type' => 'array', 'items' => [
                'anyOf' => [['type' => 'integer'], ['type' => 'string']],
            ]]],
        ];
        $source['components']['schemas']['ReusableLists'] = [
            'type' => 'object',
            'properties' => [
                'names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                'codes' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 2]],
            ],
        ];
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($source, JSON_THROW_ON_ERROR));
        self::$files = (new PhpExecutableEmitter('UsableSdk', 'example/usable-sdk'))->emit($contract);
        self::$autoload = static function (string $class): void {
            if (str_starts_with($class, 'UsableSdk\\')) {
                $path = 'src/' . str_replace('\\', '/', substr($class, strlen('UsableSdk\\'))) . '.php';
                if (isset(self::$files[$path])) {
                    eval(substr(self::$files[$path], strlen('<?php')));
                }
            }
        };
        spl_autoload_register(self::$autoload);
    }

    public static function tearDownAfterClass(): void
    {
        spl_autoload_unregister(self::$autoload);
    }

    public function testInstanceClientsKeepAccountsAndCredentialsIsolated(): void
    {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->calls[] = [$url, $headers['Authorization']];
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(200, '[]');
            }
        };
        $a = new \UsableSdk\Api\V1\ListingAlerts\Feeds(
            'https://account-a.test', securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['HTTPBearer' => 'a']), transport: $transport,
        );
        $b = \UsableSdk\Api\V1\ListingAlerts\Feeds::connect(
            'https://account-b.test', securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['HTTPBearer' => 'b']), transport: $transport,
        );
        $a->get();
        $b->get();
        $a->get();
        self::assertSame([
            ['https://account-a.test/api/v1/listing-alerts/feeds', 'Bearer a'],
            ['https://account-b.test/api/v1/listing-alerts/feeds', 'Bearer b'],
            ['https://account-a.test/api/v1/listing-alerts/feeds', 'Bearer a'],
        ], $transport->calls);
        self::assertFalse((new \ReflectionMethod($a, 'get'))->isStatic());
        self::assertSame(\Dmorenof\SdkRuntime\ApiClient::class, (new \ReflectionMethod($a, 'executeOperation'))->getDeclaringClass()->getName());
        self::assertSame(\Dmorenof\SdkRuntime\ApiClient::class, (new \ReflectionClass($a))->getConstructor()->getDeclaringClass()->getName());
        self::assertArrayNotHasKey('src/CasafariSDK.php', self::$files);
    }

    public function testFiniteEnumUnionIsNaturalToConstructAndRoundTrips(): void
    {
        $filters = new \UsableSdk\DTOs\AlertsApiAlertFeedFiltersSchema((object) [
            'business_type' => 'sale', 'statuses' => ['active', 'sold', 'rented'],
        ]);
        self::assertInstanceOf(\BackedEnum::class, $filters->statuses[0]);
        self::assertSame('active', $filters->statuses[0]->value);
        self::assertSame(['active', 'sold', 'rented'], json_decode((string) $filters, true, 512, JSON_THROW_ON_ERROR)['statuses']);
        self::assertArrayNotHasKey('src/DTOs/AlertsApiAlertFeedFiltersSchema/StatusesDTO.php', self::$files);
        $this->expectException(\ValueError::class);
        new \UsableSdk\DTOs\AlertsApiAlertFeedFiltersSchema((object) ['business_type' => 'sale', 'statuses' => ['invented']]);
    }

    public function testGeneralUnionCollectionUsesTheSameConversionAsRootResponses(): void
    {
        $batch = new \UsableSdk\DTOs\UnionBatch((object) ['values' => [4, 'four']]);
        self::assertInstanceOf(\Dmorenof\SdkRuntime\AnyOf::class, $batch->values[0]);
        self::assertSame([4], $batch->values[0]->matchedVariants());
        self::assertSame(['four'], $batch->values[1]->matchedVariants());
        self::assertSame(['values' => [4, 'four']], json_decode((string) $batch, true, 512, JSON_THROW_ON_ERROR));
        $type = $batch->values::class;
        $response = \Dmorenof\SdkRuntime\JsonHydrator::decode('[4,"four"]', $type);
        self::assertSame([4], $response[0]->matchedVariants());
    }

    public function testEquivalentCollectionsShareATypeButRetainDifferentRules(): void
    {
        $lists = new \ReflectionClass(\UsableSdk\DTOs\ReusableLists::class);
        self::assertSame($lists->getProperty('names')->getType()->getName(), $lists->getProperty('labels')->getType()->getName());
        self::assertNotSame($lists->getProperty('names')->getType()->getName(), $lists->getProperty('codes')->getType()->getName());
        $this->expectException(\UnexpectedValueException::class);
        new \UsableSdk\DTOs\ReusableLists((object) ['codes' => ['x']]);
    }

    public function testGeneratedRangeAndErrorNamesAreReadable(): void
    {
        self::assertArrayHasKey('src/Responses/Errors/V1/Properties/Search/PostStatus401Response.php', self::$files);
        self::assertArrayHasKey('src/DTOs/AlertsApi/Ranges/IntRangeSchema1.php', self::$files);
        foreach (array_keys(self::$files) as $path) {
            self::assertStringNotContainsString('__', $path);
        }
    }

    public function testReconstructedRangeReferencesKeepTypesConstraintsAndJsonFieldNames(): void
    {
        $filters = new \UsableSdk\DTOs\AlertsApiAlertFeedFiltersSchema((object) [
            'business_type' => 'sale',
            'price_range' => (object) ['min' => 100, 'max' => 500],
            'bedrooms_range' => (object) ['min' => 1, 'max' => 4],
        ]);
        self::assertInstanceOf(\UsableSdk\DTOs\AlertsApi\Ranges\IntRangeSchema1::class, $filters->price_range);
        self::assertInstanceOf(\UsableSdk\DTOs\AlertsApi\Ranges\IntRangeSchema4::class, $filters->bedrooms_range);
        $wire = json_decode((string) $filters, true, 512, JSON_THROW_ON_ERROR)['price_range'];
        self::assertSame(100, $wire['min']);
        self::assertSame(500, $wire['max']);
        $filters->bedrooms_range->max = 15001;
        $this->expectException(\UnexpectedValueException::class);
        json_encode($filters, JSON_THROW_ON_ERROR);
    }

    public function testSharedBodyModelsExcludeReadOnlyFieldsWhenSent(): void
    {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public ?string $body = null;
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                $this->body = $body;
                return new \Dmorenof\SdkRuntime\Transport\HttpResponse(201, '{"name":"Feed","filter":{"operation":"sale"},"id":9,"user":"owner"}');
            }
        };
        $client = new \UsableSdk\Api\V1\ListingAlerts\Feeds('https://api.test',
            securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['HTTPBearer' => 'token']), transport: $transport,
        );
        $body = new \UsableSdk\DTOs\ApiAlertsFeedV1((object) ['name' => 'Feed', 'filter' => (object) ['operation' => 'sale'], 'id' => 9, 'user' => 'owner']);
        $client->post($body);
        self::assertSame(['filter' => ['operation' => 'sale'], 'name' => 'Feed'], json_decode($transport->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSerializationValidatesEachNodeOnceAndStillRejectsMutations(): void
    {
        $leaf = new class extends \Dmorenof\SdkRuntime\DTO {
            public static int $visits = 0;
            public const array REQUIRED_FIELDS = ['name'];
            protected const array VALIDATION = ['name' => ['minLength' => 2]];
            public string $name = 'ok';
            public function validate(): void { self::$visits++; parent::validate(); }
        };
        $node = $leaf;
        for ($i = 0; $i < 8; $i++) {
            $parent = new class extends \Dmorenof\SdkRuntime\DTO { public \Dmorenof\SdkRuntime\DTO $child; };
            $parent->child = $node;
            $node = $parent;
        }
        json_encode($node, JSON_THROW_ON_ERROR);
        self::assertSame(1, $leaf::$visits);
        $leaf->name = 'x';
        $this->expectException(\UnexpectedValueException::class);
        json_encode($node, JSON_THROW_ON_ERROR);
    }

    public function testDtoInsideMixedArrayCannotBypassValidationWhenSerializing(): void
    {
        $value = new class extends \Dmorenof\SdkRuntime\DTO {
            public const array REQUIRED_FIELDS = ['name'];
            public string $name;
        };
        $parent = new class extends \Dmorenof\SdkRuntime\DTO { public mixed $extra; };
        $parent->extra = ['nested' => [$value]];
        $this->expectException(\UnexpectedValueException::class);
        json_encode($parent, JSON_THROW_ON_ERROR);
    }
}
