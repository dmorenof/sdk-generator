<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Independent stable SDK required.');
}
$process = proc_open([PHP_BINARY, __DIR__ . '/parameter-overrides-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start HTTP server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('Missing HTTP port.');
    }
    $client = \Example\OverrideSdk\Client::connect('http://127.0.0.1:' . $port);
    $resource = $client->resource(\Example\OverrideSdk\Resources\Items::class);
    $get = new \Example\OverrideSdk\GetParams\Items\ById((object) ['querySelector' => [0], 'selector' => 'hdr', 'body' => 'query body', 'theme' => 'dark']);
    $resource->getById('a', $get);
    $post = new \Example\OverrideSdk\PostParams\Items\ById((object) ['querySelector' => 'x/y', 'selector' => 'h', 'queryBody' => 'query body', 'theme' => 'dark', 'body' => (object) ['name' => 'body name']]);
    $resource->postById('a', $post);
    $get->querySelector = [];
    try {
        $resource->getById('a', $get);
        throw new RuntimeException('Invalid GET mutation accepted.');
    } catch (UnexpectedValueException) {
    }
    $post->querySelector = '';
    try {
        $resource->postById('a', $post);
        throw new RuntimeException('Invalid POST mutation accepted.');
    } catch (UnexpectedValueException) {
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    } proc_terminate($process);
    proc_close($process);
}
echo 'PUBLISHED_PARAMETER_OVERRIDE_HTTP_OK', PHP_EOL;
