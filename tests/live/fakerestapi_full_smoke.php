<?php
declare(strict_types=1);

require __DIR__ . '/../../live-results/full-sdk/vendor/autoload.php';

use Dmorenof\SdkRuntime\TypedArray;
use Example\FakeRestComplete\Client;
use Example\FakeRestComplete\DTOs\Activity;
use Example\FakeRestComplete\Resources\Api\V1\Activities;

$client = Client::connect('https://fakerestapi.azurewebsites.net');
$resource = $client->resource(Activities::class);

$single = $resource->getById(1);
if (!$single instanceof Activity || $single->id !== 1) {
    throw new RuntimeException('Full-spec SDK single GET did not produce the expected typed Activity DTO.');
}

$collection = $resource->get();
if (!$collection instanceof TypedArray || count($collection) < 1 || !$collection[0] instanceof Activity) {
    throw new RuntimeException('Full-spec SDK list GET did not produce an Activity TypedArray.');
}

$result = [
    'specification' => 'full original OpenAPI, unmodified',
    'live_http' => true,
    'single' => 'GET /api/v1/Activities/1',
    'list' => 'GET /api/v1/Activities',
    'single_class' => $single::class,
    'single_id' => $single->id,
    'single_title' => $single->title,
    'collection_class' => $collection::class,
    'count' => count($collection),
    'item_class' => $collection[0]::class,
];

file_put_contents(
    __DIR__ . '/../../live-results/full-response.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
