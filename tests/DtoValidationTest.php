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
        $models['NewUser']['properties']['name']['pattern'] = '^\p{Lu}.+$';
        $models['NewUser']['properties']['generatedId'] = [
            'type' => 'integer',
            'readOnly' => true,
        ];
        $models['NewUser']['required'][] = 'generatedId';
        $models['Profile']['properties']['city']['minLength'] = 3;
        $models['Profile']['properties']['serverLabel'] = [
            'type' => 'string',
            'readOnly' => true,
        ];
        $models['Profile']['properties']['privateLabel'] = [
            'type' => 'string',
            'writeOnly' => true,
        ];
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
            'src/Enums/NewUser/RoleEnum.php', 'src/Enums/User/RoleEnum.php',
            'src/Collections/StringCollection.php',
            'src/DTOs/Profile.php', 'src/Requests/NewUser.php', 'src/Responses/User.php',
            'src/Client.php', 'src/Api/Users.php',
            'src/Api/Users/User.php',
        ] as $path) {
            self::assertArrayHasKey($path, $files);
            eval(substr($files[$path], strlen('<?php')));
        }
    }

    public function testConstraintsAreDeclarationsWithOneInheritedImplementation(): void
    {
        $files = self::generated();
        self::assertStringContainsString("'minLength' => 3", $files['src/Requests/NewUser.php']);
        self::assertStringContainsString("'maxLength' => 12", $files['src/Requests/NewUser.php']);
        self::assertStringContainsString("'maxItems' => 2", $files['src/Responses/User.php']);
        self::assertStringContainsString("'minimum' => 1", $files['src/Api/Users/User.php']);
        self::assertStringNotContainsString('function validate(', $files['src/Requests/NewUser.php']);
        foreach ($files as $path => $content) {
            if (str_ends_with($path, '.php')) {
                self::assertDoesNotMatchRegularExpression('/\barray\s*\(/i', $content, $path);
            }
        }
    }

    public function testUnicodeOptionalNullAndNestedValidDTO(): void
    {
        $new = new \Example\ConstraintSdk\Requests\NewUser((object)['name' => 'Été']);
        self::assertSame('Été', $new->name);
        $user = new \Example\ConstraintSdk\Responses\User((object)[
            'id' => 7, 'name' => 'Ada', 'nickname' => null,
            'labels' => ['php'], 'profile' => (object)['city' => 'Madrid', 'visits' => 0],
        ]);
        $user->validate();
        self::assertSame('Madrid', $user->profile->city);
        self::assertNull($user->nickname);
        $withoutOptionals = new \Example\ConstraintSdk\Responses\User((object)['id' => 7, 'name' => 'Ada']);
        self::assertArrayNotHasKey(
            'labels',
            json_decode((string)$withoutOptionals, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testInboundMinLengthConstraintThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('minLength');
        new \Example\ConstraintSdk\Requests\NewUser((object)['name' => 'Li']);
    }

    public function testPatternConstraintValidatesStringValues(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('pattern');
        new \Example\ConstraintSdk\Requests\NewUser((object)['name' => 'lowercase']);
    }

    public function testReadOnlyRequiredFieldIsOmittedFromRequestSerialization(): void
    {
        $request = new \Example\ConstraintSdk\Requests\NewUser((object) [
            'name' => 'Grace',
        ]);
        self::assertArrayNotHasKey(
            'generatedId',
            json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testReadOnlyFieldsRemainAndWriteOnlyFieldsAreOmittedInResponseSerialization(): void
    {
        $user = new \Example\ConstraintSdk\Responses\User((object) [
            'id' => 1,
            'name' => 'Ada',
            'profile' => (object) [
                'city' => 'Madrid',
                'serverLabel' => 'trusted',
                'privateLabel' => 'secret',
            ],
        ]);
        $profile = json_decode((string) $user, true, 512, JSON_THROW_ON_ERROR)['profile'];
        self::assertSame(
            'trusted',
            $profile['serverLabel'],
        );
        self::assertArrayNotHasKey('privateLabel', $profile);
    }

    public function testReadOnlyFieldsAreFilteredRecursivelyFromRequestPayloads(): void
    {
        $request = new class((object) ['profile' => (object) [
            'city' => 'Madrid',
            'serverLabel' => 'trusted',
            'privateLabel' => 'secret',
        ]]) extends \Dmorenof\SdkRuntime\Request {
            public \Example\ConstraintSdk\DTOs\Profile $profile;
        };
        self::assertSame(
            ['profile' => ['city' => 'Madrid', 'privateLabel' => 'secret']],
            json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testOutboundMaxLengthRevalidatesAfterMutation(): void
    {
        $dto = new \Example\ConstraintSdk\Requests\NewUser((object)['name' => 'Grace']);
        $dto->name = 'An excessively long name';
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('maxLength');
        json_encode($dto, JSON_THROW_ON_ERROR);
    }

    public function testNumberBoundsAndNestedDTOValidation(): void
    {
        $dto = new \Example\ConstraintSdk\Responses\User((object)[
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
        $dto = new \Example\ConstraintSdk\Responses\User((object)[
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
        new \Example\ConstraintSdk\Responses\User((object)[
            'id' => 7, 'name' => 'Ada', 'labels' => [],
        ]);
    }

    public function testInvalidPathDTOCannotReachHTTP(): void
    {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                ++$this->calls;
                throw new \LogicException('Unexpected HTTP');
            }
        };
        $client = \Example\ConstraintSdk\Client::connect('https://api.example.test/v1', transport: $transport);
        try {
            $client->resource(\Example\ConstraintSdk\Api\Users\User::class)->getById(0);
            self::fail('Expected validation error');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('minimum', $e->getMessage());
        }
        self::assertSame(0, $transport->calls);
    }

    public function testInvalidBodyDTOCannotReachHTTP(): void
    {
        $transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
            public int $calls = 0;
            public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
            {
                ++$this->calls;
                throw new \LogicException('Unexpected HTTP');
            }
        };
        $client = \Example\ConstraintSdk\Client::connect('https://api.example.test/v1', transport: $transport);
        $dto = new \Example\ConstraintSdk\Requests\NewUser();
        $dto->name = 'Li';
        try {
            $client->resource(\Example\ConstraintSdk\Api\Users::class)->post($dto);
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

    public function testArrayItemConstraintIsEmittedOnCollectionType(): void
    {
        $spec = self::spec();
        $spec['components']['schemas']['User']['properties']['labels']['items']['minLength'] = 2;
        $contract = (new SpecificationNormalizer())->fromJson(json_encode($spec, JSON_THROW_ON_ERROR));
        $files = (new PhpExecutableEmitter('Example\\UnsupportedSdk', 'example/unsupported-sdk'))->emit($contract);
        self::assertStringContainsString(
            'protected const array ITEM_VALIDATION',
            $files['src/Collections/User/LabelsCollection.php'],
        );
        self::assertStringContainsString("'minLength' => 2", $files['src/Collections/User/LabelsCollection.php']);
    }

    public function testPhpLiteralUsesSquareBracketsForNestedArrays(): void
    {
        $input = ['words' => ['a', 'b'], 'nullable' => null, 'minLength' => 3];
        $literal = PhpLiteral::export($input);
        self::assertStringNotContainsString('array (', $literal);
        self::assertSame($input, eval('return ' . $literal . ';'));
    }
}
