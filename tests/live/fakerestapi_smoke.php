<?php
declare(strict_types=1);

require __DIR__ . '/../../live-results/fakerest-sdk/vendor/autoload.php';

use Example\FakeRestLive\Client;
use Example\FakeRestLive\DTOs\Activity;
use Example\FakeRestLive\Resources\Api\V1\Activities;
use Dmorenof\SdkRuntime\TypedArray;

$client = Client::connect('https://fakerestapi.azurewebsites.net');
$api = $client->resource(Activities::class);

$one = $api->getById(1);
if (!$one instanceof Activity || $one->id !== 1 || !is_string($one->title)) {
    throw new RuntimeException('Actual GET /api/v1/Activities/1 failed typed DTO assertion');
}

$all = $api->get();
if (!$all instanceof TypedArray || count($all) === 0 || !$all[0] instanceof Activity) {
    throw new RuntimeException('Actual GET /api/v1/Activities failed typed collection assertion');
}

$proof = [
    'api' => 'https://fakerestapi.azurewebsites.net',
    'endpoint_single' => 'GET /api/v1/Activities/1',
    'endpoint_list' => 'GET /api/v1/Activities',
    'response_class' => $one::class,
    'collection_class' => $all::class,
    'single_id' => $one->id,
    'single_title' => $one->title,
    'single_completed' => $one->completed,
    'collection_count' => count($all),
    'first_collection_item_class' => $all[0]::class,
    'live_http' => true,
];
file_put_contents(
    __DIR__ . '/../../live-results/live-response.json',
    json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
echo json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
