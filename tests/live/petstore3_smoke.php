<?php
declare(strict_types=1);

/**
 * Real HTTP against the public Swagger Petstore (OpenAPI 3.0), with an SDK generated from its unmodified specification.
 * The service is shared and anyone can write to it, so the script only trusts what it created itself (a random id).
 */
require __DIR__ . '/../../live-results/petstore3-sdk/vendor/autoload.php';

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Example\Petstore\Client;
use Example\Petstore\Collections\StringCollection;
use Example\Petstore\Configuration;
use Example\Petstore\DTOs\Pet;
use Example\Petstore\Enums\Pet\FindByStatus\Get\Query\StatusEnum as QueryStatus;
use Example\Petstore\Enums\Pet\StatusEnum;
use Example\Petstore\GetParams\Pet\FindByStatus;
use Example\Petstore\Resources\Pet as PetResource;
use Example\Petstore\Resources\Pet\FindByStatus as FindByStatusResource;
use Example\Petstore\Resources\Store\Inventory;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("Live check failed: $what");
    }
    echo "ok  $what\n";
}

$configuration = new Configuration();
$configuration->baseUrl = 'https://petstore3.swagger.io/api/v3';
// The demo server accepts any value; the SDK still insists on a credential for every scheme an operation lists.
$configuration->securityCredentials = new SecurityCredentials(['petstore_auth' => 'demo-token', 'api_key' => 'special-key']);
$client = Client::connect($configuration);

$inventory = $client->resource(Inventory::class)->get();
check($inventory !== null && count($inventory) > 0, 'GET /store/inventory returns a map of integers');

$params = new FindByStatus();
$params->status = QueryStatus::Available;
$available = $client->resource(FindByStatusResource::class)->get($params);
check($available !== null && count($available) > 0 && $available[0] instanceof Pet, 'GET /pet/findByStatus returns typed pets');

$id = random_int(900_000_000, 999_999_999);
$pet = new Pet();
$pet->id = $id;
$pet->name = 'sdk-generator-live-' . $id;
$pet->photoUrls = new StringCollection(['https://example.com/p.png']);
$pet->status = StatusEnum::Available;

$pets = $client->resource(PetResource::class);
$created = $pets->post($pet);
check($created instanceof Pet && $created->id === $id && $created->status === StatusEnum::Available, 'POST /pet echoes the typed pet');

$read = $pets->getByPetId($id);
check($read instanceof Pet && $read->name === $pet->name && count($read->photoUrls) === 1, 'GET /pet/{id} reads it back');

$read->status = StatusEnum::Sold;
$updated = $pets->put($read);
check($updated instanceof Pet && $updated->status === StatusEnum::Sold, 'PUT /pet changes the status');

$pets->deleteByPetId($id);
try {
    $pets->getByPetId($id);
    check(false, 'a deleted pet is gone');
} catch (ApiException $gone) {
    check($gone->getCode() === 404, 'GET of a deleted pet is an ApiException 404');
}

try {
    $broken = new Pet();
    $broken->id = $id;
    $broken->validate();
    check(false, 'a pet without its required fields is refused before sending');
} catch (UnexpectedValueException) {
    check(true, 'a pet without its required fields is refused before sending');
}

echo "Petstore live smoke passed.\n";
