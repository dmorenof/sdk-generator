<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpApiNameResolver;
use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Generation\PhpModelNameResolver;
use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HierarchicalNamesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function specification(): array
    {
        $agency = 'Casafari.Crm.Api.Dtos.Agency.AgencyDto';
        $user = 'Casafari.Crm.Api.Dtos.User.UserDto';
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Casafari CRM API', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://api.example.test']],
            'paths' => [
                '/agencies/{id}' => [
                    'get' => [
                        'operationId' => 'getAgency',
                        'parameters' => [[
                            'name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer'],
                        ]],
                        'responses' => [
                            '200' => ['description' => 'Agency', 'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $agency]],
                            ]],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'updateAgency',
                        'parameters' => [[
                            'name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer'],
                        ]],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                '$ref' => '#/components/schemas/' . $agency,
                            ]]],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Agency', 'content' => [
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $agency]],
                            ]],
                        ],
                    ],
                ],
            ],
            'components' => ['schemas' => [
                $agency => [
                    'type' => 'object',
                    'required' => ['name', 'owner'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'owner' => ['$ref' => '#/components/schemas/' . $user],
                        'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                    ],
                ],
                $user => [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string']],
                ],
            ]],
        ];
    }

    /** @return array<string,string> */
    private function generate(): array
    {
        $source = json_encode($this->specification(), JSON_THROW_ON_ERROR);
        $contract = (new SpecificationNormalizer())->fromJson($source);
        self::assertSame('Casafari CRM API', $contract->title);
        return (new PhpExecutableEmitter('CasafariCrmApi', 'example/casafari-sdk'))->emit($contract);
    }

    public function testApiNameComesFromMetadataNotRepeatedModelPrefix(): void
    {
        self::assertSame('CasafariCrmApi', PhpApiNameResolver::fromTitle('Casafari CRM API'));
        self::assertSame('ExternalApiVersion', PhpApiNameResolver::fromTitle('External Api Version'));
        self::assertSame('InventoryApi', PhpApiNameResolver::fromTitle('Inventory API'));
        $flat = new PhpModelNameResolver('OtherSdk', [
            new ModelDefinition('Casafari.Crm.Api.Dtos.Agency.AgencyDto', new SchemaDefinition(SchemaKind::Object)),
        ]);
        self::assertSame(
            '\OtherSdk\DTOs\Casafari\Crm\Api\Dtos\Agency\AgencyDto',
            $flat->fqcn('Casafari.Crm.Api.Dtos.Agency.AgencyDto'),
        );
    }

    public function testGeneratedPathsKeepAllSegmentsWithNoAutomaticPrefixReduction(): void
    {
        $files = $this->generate();
        $agencyPath = 'src/DTOs/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php';
        $userPath = 'src/DTOs/Casafari/Crm/Api/Dtos/User/UserDto.php';
        self::assertArrayHasKey($agencyPath, $files);
        self::assertArrayHasKey($userPath, $files);
        self::assertArrayNotHasKey('src/DTOs/Dtos/Agency/AgencyDto.php', $files);
        self::assertArrayNotHasKey('src/DTOs/Crm/Api/Dtos/Agency/AgencyDto.php', $files);
        self::assertStringContainsString(
            'namespace CasafariCrmApi\DTOs\Casafari\Crm\Api\Dtos\Agency;',
            $files[$agencyPath],
        );
        self::assertStringContainsString(
            'public \CasafariCrmApi\DTOs\Casafari\Crm\Api\Dtos\User\UserDto $owner;',
            $files[$agencyPath],
        );
        self::assertArrayHasKey(
            'src/Enums/Casafari/Crm/Api/Dtos/Agency/AgencyDto/StatusEnum.php',
            $files,
        );
        self::assertArrayHasKey(
            'src/Collections/StringCollection.php',
            $files,
        );
        self::assertStringContainsString(
            '\CasafariCrmApi\DTOs\Casafari\Crm\Api\Dtos\Agency\AgencyDto $body',
            $files['src/Api/Agencies/Agency.php'],
        );
        self::assertStringContainsString(
            'public function postById(',
            $files['src/Api/Agencies/Agency.php'],
        );
        self::assertSame('src/', json_decode($files['composer.json'], true, 512, JSON_THROW_ON_ERROR)
            ['autoload']['psr-4']['CasafariCrmApi\\']);
    }

    public function testGeneratedNestedModelsEnumsCollectionsAndOperationBodyExecute(): void
    {
        $files = $this->generate();
        foreach ([
            'src/Core/DTO.php',
            'src/Core/Request.php',
            'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/Casafari/Crm/Api/Dtos/Agency/AgencyDto/StatusEnum.php',
            'src/Collections/StringCollection.php',
            'src/DTOs/Casafari/Crm/Api/Dtos/User/UserDto.php',
            'src/DTOs/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/BasicAuthCredentials.php',
            'src/Core/SecurityCredentials.php',
            'src/Core/Security.php',
            'src/Core/ApiClient.php', 'src/Core/ApiException.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Client.php',
            'src/Api/Agencies/Agency.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }

        $agency = new \CasafariCrmApi\DTOs\Casafari\Crm\Api\Dtos\Agency\AgencyDto(
            (object) ['name' => 'An agency', 'owner' => (object)['email' => 'agency@example.test'],
                'status' => 'active', 'labels' => ['commercial', 'residential']],
        );
        self::assertSame('agency@example.test', $agency->owner->email);
        self::assertSame(
            \CasafariCrmApi\Enums\Casafari\Crm\Api\Dtos\Agency\AgencyDto\StatusEnum::Active,
            $agency->status,
        );
        self::assertSame(['commercial', 'residential'], $agency->labels->getArrayCopy());

        $transport = new class implements \CasafariCrmApi\Transport\TransportInterface {
            public array $calls = [];
            public function send(string $method, string $url, array $headers, ?string $body): \CasafariCrmApi\Transport\HttpResponse
            {
                $this->calls[] = compact('method', 'url', 'headers', 'body');
                return new \CasafariCrmApi\Transport\HttpResponse(
                    200,
                    '{"name":"An agency","owner":{"email":"agency@example.test"}}',
                );
            }
        };
        $client = \CasafariCrmApi\Client::connect('https://api.example.test', transport: $transport);
        $response = $client->resource(\CasafariCrmApi\Api\Agencies\Agency::class)->postById(27, $agency);
        self::assertSame('An agency', $response->name);
        // JSON objects are unordered; preserve the order-sensitive list assertions above.
        self::assertEquals(
            ['name' => 'An agency', 'owner' => ['email' => 'agency@example.test'],
                'labels' => ['commercial', 'residential'], 'status' => 'active'],
            json_decode($transport->calls[0]['body'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('https://api.example.test/agencies/27', $transport->calls[0]['url']);
    }

    public function testDifferentVendorsAreNotCollapsedAndCaseInsensitiveCollisionsFail(): void
    {
        $schema = new SchemaDefinition(SchemaKind::Object);
        $resolver = new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition('VendorA.Dto.Entity', $schema),
            new ModelDefinition('VendorB.Dto.Entity', $schema),
        ]);
        self::assertSame('src/DTOs/VendorA/Dto/Entity.php', $resolver->path('VendorA.Dto.Entity'));
        self::assertSame('src/DTOs/VendorB/Dto/Entity.php', $resolver->path('VendorB.Dto.Entity'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema name collision');
        new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition('Vendor.Model', $schema),
            new ModelDefinition('vendor.Model', $schema),
        ]);
    }

    public function testInvalidSegmentIsRejectedInsteadOfBeingSilentlyRewritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP schema namespace or class segment');
        new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition('Vendor..AgencyDto', new SchemaDefinition(SchemaKind::Object)),
        ]);
    }

    public function testDoubleUnderscoreHierarchyPreservesProviderAndVariantIdentity(): void
    {
        $schema = new SchemaDefinition(SchemaKind::Object);
        $names = [
            'AlertsApisrc__structures__schemas__ranges__IntRangeSchema__1' => 'AlertsApi/Ranges/IntRangeSchema1',
            'AlertsApisrc__structures__schemas__ranges__IntRangeSchema__2' => 'AlertsApi/Ranges/IntRangeSchema2',
            'MarketAnalyticsApiapi__schema__ranges__IntRangeSchema__1' => 'MarketAnalyticsApi/Ranges/IntRangeSchema1',
            'Other__src__structures__schemas__ranges__IntRangeSchema__1' => 'Other/Ranges/IntRangeSchema1',
            'Vendor__orders__Order' => 'Vendor/Orders/Order',
        ];
        $resolver = new PhpModelNameResolver('Example\Sdk', array_map(
            static fn (string $name) => new ModelDefinition($name, $schema), array_keys($names),
        ));
        foreach ($names as $source => $path) {
            self::assertSame('src/DTOs/' . $path . '.php', $resolver->path($source));
            self::assertSame('\\Example\\Sdk\\DTOs\\' . str_replace('/', '\\', $path), $resolver->fqcn($source));
        }
        $entry = $resolver->derived('Vendor__orders__Order.State', 'Enums');
        self::assertSame('src/Enums/Vendor/Orders/Order/StateEnum.php', $entry['path']);
    }

    public function testDecodedHierarchiesRejectCollisionsInsteadOfMergingSchemas(): void
    {
        $schema = new SchemaDefinition(SchemaKind::Object);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema name collision');
        new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition('Vendor__schemas__Range__1', $schema),
            new ModelDefinition('Vendor.RANGE1', $schema),
        ]);
    }

    public function testMalformedEncodedHierarchyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP schema namespace or class segment');
        new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition('Vendor____Entity', new SchemaDefinition(SchemaKind::Object)),
        ]);
    }

    public function testEncodedCompositionPrefixesOnlyTheLeafAndRebuildsItsDerivedFolders(): void
    {
        $source = 'Provider__schemas__payments__Payment__1';
        $resolver = new PhpModelNameResolver('Example\Sdk', [
            new ModelDefinition($source, new SchemaDefinition(SchemaKind::AnyOf)),
        ]);
        self::assertSame('src/DTOs/Provider/Payments/AnyOfPayment1.php', $resolver->path($source));
        self::assertSame('src/Collections/Provider/Payments/AnyOfPayment1/ItemsCollection.php', $resolver->derived($source . '.Items', 'Collections')['path']);
    }
}
