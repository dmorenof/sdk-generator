<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Independent stable SDK required.');
}
$process = proc_open([PHP_BINARY, $argv[1] . '/vendor/dmorenof/sdk-runtime/tests/reserved-query-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start HTTP server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('Missing HTTP port.');
    }
    $client = \Example\ReservedSdk\Client::connect('http://127.0.0.1:' . $port, securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => '%2F']));
    $resource = $client->resource(\Example\ReservedSdk\Resources\Items::class);
    $values = ['q' => 'a/b%26c?x', 'body' => 'query/body', 'labels' => ['a/b', 'c?d'], 'filter' => (object) ['code' => 'x/y'], 'plain' => 'a/b', 'k' => 'ignored/value', 'X-Trace' => 'h/x', 'theme' => 'a/b'];
    $get = new \Example\ReservedSdk\GetParams\Items((object) $values);
    $resource->get($get);
    $values['queryBody'] = $values['body'];
    $values['body'] = (object) ['name' => 'body/x'];
    $post = new \Example\ReservedSdk\PostParams\Items((object) $values);
    $resource->post($post);
    $get->q = 'bad&extra=value';
    try {
        $resource->get($get);
        throw new RuntimeException('Unsafe mutation accepted.');
    } catch (UnexpectedValueException) {
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    } proc_terminate($process);
    proc_close($process);
}
echo 'PUBLISHED_RESERVED_QUERY_SDK_HTTP_OK', PHP_EOL;
