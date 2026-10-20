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
            '\OtherSdk\Casafari\Crm\Api\Dtos\Agency\AgencyDto',
            $flat->fqcn('Casafari.Crm.Api.Dtos.Agency.AgencyDto'),
        );
    }

    public function testGeneratedPathsKeepAllSegmentsWithNoAutomaticPrefixReduction(): void
    {
        $files = $this->generate();
        $agencyPath = 'src/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php';
        $userPath = 'src/Casafari/Crm/Api/Dtos/User/UserDto.php';
        self::assertArrayHasKey($agencyPath, $files);
        self::assertArrayHasKey($userPath, $files);
        self::assertArrayNotHasKey('src/Dtos/Agency/AgencyDto.php', $files);
        self::assertArrayNotHasKey('src/Crm/Api/Dtos/Agency/AgencyDto.php', $files);
        self::assertStringContainsString(
            'namespace CasafariCrmApi\Casafari\Crm\Api\Dtos\Agency;',
            $files[$agencyPath],
        );
        self::assertStringContainsString(
            'public \CasafariCrmApi\Casafari\Crm\Api\Dtos\User\UserDto $owner;',
            $files[$agencyPath],
        );
        self::assertArrayHasKey(
            'src/Enums/Casafari/Crm/Api/Dtos/Agency/AgencyDtoStatusEnum.php',
            $files,
        );
        self::assertArrayHasKey(
            'src/Collections/Casafari/Crm/Api/Dtos/Agency/AgencyDtoLabelsArray.php',
            $files,
        );
        self::assertStringContainsString(
            '\CasafariCrmApi\Casafari\Crm\Api\Dtos\Agency\AgencyDto $body;',
            $files['src/Requests/UpdateAgencyRequest.php'],
        );
        self::assertStringContainsString(
            'function updateAgency(\CasafariCrmApi\Requests\UpdateAgencyRequest $request)',
            $files['src/Api/Operations.php'],
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
            'src/Enums/Casafari/Crm/Api/Dtos/Agency/AgencyDtoStatusEnum.php',
            'src/Collections/Casafari/Crm/Api/Dtos/Agency/AgencyDtoLabelsArray.php',
            'src/Casafari/Crm/Api/Dtos/User/UserDto.php',
            'src/Casafari/Crm/Api/Dtos/Agency/AgencyDto.php',
            'src/Requests/GetAgencyRequest.php',
            'src/Requests/UpdateAgencyRequest.php',
            'src/Transport/HttpResponse.php',
            'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php',
            'src/Core/HttpClient.php',
            'src/Core/ApiClient.php',
            'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php',
            'src/Api/Operations.php',
            'src/Api/ApiClient.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }

        $agency = new \CasafariCrmApi\Casafari\Crm\Api\Dtos\Agency\AgencyDto(
            (object) ['name' => 'An agency', 'owner' => (object)['email' => 'agency@example.test'],
                'status' => 'active', 'labels' => ['commercial', 'residential']],
        );
        self::assertSame('agency@example.test', $agency->owner->email);
        self::assertSame(
            \CasafariCrmApi\Enums\Casafari\Crm\Api\Dtos\Agency\AgencyDtoStatusEnum::Active,
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
        $api = new \CasafariCrmApi\Api\ApiClient($transport, 'https://api.example.test');
        $request = new \CasafariCrmApi\Requests\UpdateAgencyRequest((object)[
            'id' => 27, 'body' => $agency,
        ]);
        $response = $api->updateAgency($request);
        self::assertSame('An agency', $response->name);
        self::assertSame(
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
        self::assertSame('src/VendorA/Dto/Entity.php', $resolver->path('VendorA.Dto.Entity'));
        self::assertSame('src/VendorB/Dto/Entity.php', $resolver->path('VendorB.Dto.Entity'));

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
}
