<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Independent stable SDK required.');
}
$process = proc_open([PHP_BINARY, __DIR__ . '/empty-values-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start HTTP server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('Missing HTTP port.');
    }
    $client = \Example\EmptySdk\Client::connect('http://127.0.0.1:' . $port);
    $resource = $client->resource(\Example\EmptySdk\Resources\Items::class);
    $values = ['requiredEmpty' => '', 'q' => '', 'body' => '', 'nullable' => null, 'count' => 0, 'enabled' => false, 'labels' => [''], 'csv' => [''], 'strict' => 'x', 'X-Trace' => 'trace'];
    $get = new \Example\EmptySdk\GetParams\Items((object) $values);
    $resource->get($get);
    $get->q = null;
    $get->labels = [];
    $get->csv = [];
    $resource->get($get);
    $values['queryBody'] = $values['body'];
    $values['body'] = (object) ['name' => '', 'nullable' => null, 'strict' => 'x', 'count' => 0, 'enabled' => false, 'labels' => ['']];
    $post = new \Example\EmptySdk\PostParams\Items((object) $values);
    $resource->post($post);
    $client->resource(\Example\EmptySdk\Resources\Json::class)->post(new \Example\EmptySdk\PostParams\Json((object) $values));
    $get->strict = '';
    try {
        $resource->get($get);
        throw new RuntimeException('Empty flag bypassed minLength.');
    } catch (UnexpectedValueException) {
    }
    $post->body->strict = '';
    try {
        $resource->post($post);
        throw new RuntimeException('Empty flag bypassed form constraints.');
    } catch (UnexpectedValueException) {
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    } proc_terminate($process);
    proc_close($process);
}
echo 'PUBLISHED_EMPTY_VALUES_SDK_HTTP_OK', PHP_EOL;
