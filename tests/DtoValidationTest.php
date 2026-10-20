<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Tests;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Generation\PhpLiteral;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class DtoValidationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function spec(): array
    {
        $content = file_get_contents(__DIR__ . '/fixtures/accounts-openapi31.json');
        self::assertIsString($content);
        $raw = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $models = &$raw['components']['schemas'];
        $models['NewUser']['properties']['name']['minLength'] = 3;
        $models['NewUser']['properties']['name']['maxLength'] = 12;
        $models['Profile']['properties']['city']['minLength'] = 3;
        $models['Profile']['properties']['visits']['minimum'] = 0;
        $models['User']['properties']['id']['minimum'] = 1;
        $models['User']['properties']['id']['maximum'] = 999;
        $models['User']['properties']['name']['maxLength'] = 8;
        $models['User']['properties']['nickname']['minLength'] = 2;
        $models['User']['properties']['labels']['minItems'] = 1;
        $models['User']['properties']['labels']['maxItems'] = 2;
        $raw['paths']['/users/{id}']['get']['parameters'][0]['schema']['minimum'] = 1;
        return $raw;
    }

    /** @return array<string, string> */
    private static function generated(): array
    {
        $contract = (new SpecificationNormalizer())->fromJson(json_encode(self::spec(), JSON_THROW_ON_ERROR));
        return (new PhpExecutableEmitter('Example\\ConstraintSdk', 'example/constraint-sdk'))->emit($contract);
    }

    public static function setUpBeforeClass(): void
    {
        $files = self::generated();
        foreach ([
            'src/Core/DTO.php', 'src/Core/Request.php', 'src/Core/Response.php',
            'src/Core/TypedArray.php',
            'src/Enums/NewUserRoleEnum.php', 'src/Enums/UserRoleEnum.php',
            'src/Collections/UserLabelsArray.php',
            'src/Models/Profile.php', 'src/Models/NewUser.php', 'src/Models/User.php',
            'src/Requests/GetUserRequest.php',
            'src/Transport/HttpResponse.php', 'src/Transport/TransportInterface.php',
            'src/Core/HttpMiddlewareInterface.php', 'src/Core/HttpClient.php',
            'src/Core/ApiClient.php', 'src/Core/ApiException.php', 'src/Transport/Psr18Transport.php',
            'src/Support/JsonHydrator.php', 'src/Api/Operations.php', 'src/Api/ApiClient.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public function testConstraintsAreDeclarationsWithOneInheritedImplementation(): void
    {
        $files = self::generated();
        self::assertStringContainsString("'minLength' => 3", $files['src/Models/NewUser.php']);
        self::assertStringContainsString("'maxLength' => 12", $files['src/Models/NewUser.php']);
        self::assertStringContainsString("'maxItems' => 2", $files['src/Models/User.php']);
        self::assertStringContainsString("'minimum' => 1", $files['src/Requests/GetUserRequest.php']);
        self::assertStringNotContainsString('function validate(', $files['src/Models/NewUser.php']);
        self::assertStringContainsString('function validate(): void', $files['src/Core/DTO.php']);
        foreach ($files as $path => $content) {
            if (str_ends_with($path, '.php')) {
                self::assertDoesNotMatchRegularExpression('/\barray\s*\(/i', $content, $path);
            }
        }
    }

    public function testUnicodeOptionalNullAndNestedValidDTO(): void
    {
        $new = new \Example\ConstraintSdk\Models\NewUser((object)['name' => 'Été']);
        self::assertSame('Été', $new->name);
        $user = new \Example\ConstraintSdk\Models\User((object)[
            'id' => 7, 'name' => 'Ada', 'nickname' => null,
            'labels' => ['php'], 'profile' => (object)['city' => 'Madrid', 'visits' => 0],
        ]);
        $user->validate();
        self::assertSame('Madrid', $user->profile->city);
        self::assertNull($user->nickname);
        $withoutOptionals = new \Example\ConstraintSdk\Models\User((object)['id' => 7, 'name' => 'Ada']);
        self::assertArrayNotHasKey(
            'labels',
            json_decode((string)$withoutOptionals, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testInboundMinLengthConstraintThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('minLength');
        new \Example\ConstraintSdk\Models\NewUser((object)['name' => 'Li']);
    }

    public function testOutboundMaxLengthRevalidatesAfterMutation(): void
    {
        $dto = new \Example\ConstraintSdk\Models\NewUser((object)['name' => 'Grace']);
        $dto->name = 'An excessively long name';
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('maxLength');
        json_encode($dto, JSON_THROW_ON_ERROR);
    }

    public function testNumberBoundsAndNestedDTOValidation(): void
    {
        $dto = new \Example\ConstraintSdk\Models\User((object)[
            'id' => 7, 'name' => 'Ada', 'profile' => (object)['city' => 'Madrid'],
        ]);
        $dto->id = 1000;
        try {
            $dto->validate();
            self::fail('Expected maximum failure');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('maximum', $e->getMessage());
        }
        $dto->id = 7;
        $dto->profile->city = 'A';
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('minLength');
        $dto->validate();
    }

    public function testTypedCollectionCountsAreRevalidated(): void
    {
        $dto = new \Example\ConstraintSdk\Models\User((object)[
            'id' => 7, 'name' => 'Ada', 'labels' => ['php'],
        ]);
        $dto->labels->append('sdk');
        $dto->validate();
        $dto->labels->append('extra');
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('maxItems');
        $dto->validate();
    }

    public function testEmptyCollectionFailsMinItems(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('minItems');
        new \Example\ConstraintSdk\Models\User((object)[
            'id' => 7, 'name' => 'Ada', 'labels' => [],
        ]);
    }

    public function testInvalidPathDTOCannotReachHTTP(): void
    {
        $transport = new class implements \Example\ConstraintSdk\Transport\TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Example\ConstraintSdk\Transport\HttpResponse
            {
                ++$this->calls;
                throw new \LogicException('Unexpected HTTP');
            }
        };
        $api = new \Example\ConstraintSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
        $request = new \Example\ConstraintSdk\Requests\GetUserRequest();
        $request->id = 0;
        try {
            $api->getUser($request);
            self::fail('Expected validation error');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('minimum', $e->getMessage());
        }
        self::assertSame(0, $transport->calls);
    }

    public function testInvalidBodyDTOCannotReachHTTP(): void
    {
        $transport = new class implements \Example\ConstraintSdk\Transport\TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Example\ConstraintSdk\Transport\HttpResponse
            {
                ++$this->calls;
                throw new \LogicException('Unexpected HTTP');
            }
        };
        $api = new \Example\ConstraintSdk\Api\ApiClient($transport, 'https://api.example.test/v1');
        $dto = new \Example\ConstraintSdk\Models\NewUser();
        $dto->name = 'Li';
        try {
            $api->createUser($dto);
            self::fail('Expected validation error');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('minLength', $e->getMessage());
        }
        self::assertSame(0, $transport->calls);
    }

    public function testContradictoryBoundsAreNotSilentlyAccepted(): void
    {
        $spec = self::spec();
        $spec['components']['schemas']['NewUser']['properties']['name']['minLength'] = 25;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Contradictory minLength and maxLength');
        (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
    }

    public function testUnsupportedArrayItemConstraintFailsExplicitly(): void
    {
        $spec = self::spec();
        $spec['components']['schemas']['User']['properties']['labels']['items']['minLength'] = 2;
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraints on individual array items');
        (new PhpExecutableEmitter('Example\\UnsupportedSdk', 'example/unsupported-sdk'))->emit($contract);
    }

    public function testPhpLiteralUsesSquareBracketsForNestedArrays(): void
    {
        $input = ['words' => ['a', 'b'], 'nullable' => null, 'minLength' => 3];
        $literal = PhpLiteral::export($input);
        self::assertStringNotContainsString('array (', $literal);
        self::assertSame($input, eval('return ' . $literal . ';'));
    }
}
