<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.');
require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Body SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/request-bodies-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $configuration = new \Example\BodySdk\Configuration(); $configuration->baseUrl = 'http://127.0.0.1:' . $port;
    \Example\BodySdk\Client::configure($configuration); $client = \Example\BodySdk\Client::current();
    $client->resource(\Example\BodySdk\Resources\Scalar::class)->post('valid');
    \Example\BodySdk\Optional::post(); \Example\BodySdk\Optional::post(null);
    $optional = $client->resource(\Example\BodySdk\Resources\Optional::class); $optional->post(0); $optional->post('text');
    $client->resource(\Example\BodySdk\Resources\NullResource::class)->post(null);
    $params = new \Example\BodySdk\PostParams\ListResource\ById((object) ['queryBody' => 'separate', 'X-Test' => 'header', 'body' => [(object) ['name' => 'Alice', 'readOnlyId' => 7]]]);
    $client->resource(\Example\BodySdk\Resources\ListResource::class)->postById(7, $params);
    $client->resource(\Example\BodySdk\Resources\Map::class)->post(new \Example\BodySdk\DTOs\Scores((object) ['one' => 1]));
    $client->resource(\Example\BodySdk\Resources\Loose::class)->post([1, 'x', null]);
    $client->resource(\Example\BodySdk\Resources\Open::class)->post((object) ['nested' => [false, null]]);
    $client->resource(\Example\BodySdk\Resources\NeverResource::class)->post();
    $params->body[0]->name = 'x';
    try { $client->resource(\Example\BodySdk\Resources\ListResource::class)->postById(7, $params); throw new RuntimeException('Invalid body mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    try { $optional->post(-1); throw new RuntimeException('Invalid scalar body reached HTTP.'); } catch (UnexpectedValueException) {}
    try { $client->resource(\Example\BodySdk\Resources\NeverResource::class)->post(null); throw new RuntimeException('False body schema accepted.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_REQUEST_BODIES_SDK_HTTP_OK', PHP_EOL;
