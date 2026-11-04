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

// FakeRESTApi is a public synthetic test service. This POST exercises real
// request DTO serialization and response hydration without user data.
$input = new Activity((object) [
    'id' => 99302,
    'title' => 'SDK generator live validation',
    'dueDate' => '2026-11-04T12:00:00.000Z',
    'completed' => false,
]);
$created = $resource->post($input);
if (!$created instanceof Activity || $created->id !== 99302 ||
    $created->title !== 'SDK generator live validation') {
    throw new RuntimeException('Full-spec SDK live POST did not return the typed Activity DTO.');
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
    'post' => 'POST /api/v1/Activities',
    'created_class' => $created::class,
    'created_id' => $created->id,
    'created_title' => $created->title,
];

file_put_contents(
    __DIR__ . '/../../live-results/full-response.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
