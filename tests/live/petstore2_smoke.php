<?php
declare(strict_types=1);

/**
 * Real HTTP against the public Swagger Petstore (Swagger 2.0), with an SDK generated from its unmodified
 * specification: JSON next to XML in `consumes`, an `addPet` that describes no success, and a body that is a list.
 * The service is shared and anyone can write to it, so the script only trusts what it created itself.
 */
require __DIR__ . '/../../live-results/petstore2-sdk/vendor/autoload.php';

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Example\PetstoreV2\Client;
use Example\PetstoreV2\Collections\InputBodyCollection;
use Example\PetstoreV2\Collections\StringCollection;
use Example\PetstoreV2\Configuration;
use Example\PetstoreV2\DTOs\Pet;
use Example\PetstoreV2\DTOs\User;
use Example\PetstoreV2\Enums\Pet\StatusEnum;
use Example\PetstoreV2\Resources\Pet as PetResource;
use Example\PetstoreV2\Resources\Store\Inventory;
use Example\PetstoreV2\Resources\User as UserResource;
use Example\PetstoreV2\Resources\User\CreateWithArray;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("Live check failed: $what");
    }
    echo "ok  $what\n";
}

$configuration = new Configuration();
$configuration->securityCredentials = new SecurityCredentials(['petstore_auth' => 'demo-token', 'api_key' => 'special-key']);
$client = Client::connect($configuration);

$inventory = $client->resource(Inventory::class)->get();
check(count($inventory) > 0, 'GET /store/inventory returns a map of integers');

$id = random_int(900_000_000, 999_999_999);
$pet = new Pet();
$pet->id = $id;
$pet->name = 'sdk-generator-live-' . $id;
$pet->photoUrls = new StringCollection(['https://example.com/p.png']);
$pet->status = StatusEnum::Available;

$pets = $client->resource(PetResource::class);
// `addPet` declares only a 405: the SDK returns nothing, and the real 200 with the pet is not an error.
$pets->post($pet);
$read = $pets->getByPetId($id);
check($read->id === $id && $read->name === $pet->name && $read->status === StatusEnum::Available, 'POST /pet (no success declared) then GET /pet/{id} reads it back');

$read->status = StatusEnum::Sold;
$pets->put($read);
check($pets->getByPetId($id)->status === StatusEnum::Sold, 'PUT /pet changes the status');

$pets->deleteByPetId($id);
try {
    $pets->getByPetId($id);
    check(false, 'a deleted pet is gone');
} catch (ApiException $gone) {
    check($gone->getCode() === 404, 'GET of a deleted pet is an ApiException 404');
}

$username = 'sdk-live-' . $id;
$user = new User();
$user->username = $username;
$user->firstName = 'Live';
$list = new InputBodyCollection([$user]);
$client->resource(CreateWithArray::class)->post($list);
$stored = $client->resource(UserResource::class)->getByUsername($username);
check($stored->username === $username, 'POST /user/createWithArray sends a typed list as the body');
$client->resource(UserResource::class)->deleteByUsername($username);

echo "Petstore v2 live smoke passed.\n";
