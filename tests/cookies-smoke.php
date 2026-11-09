<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Independent stable SDK required.');
}
$process = proc_open([PHP_BINARY, $argv[1] . '/vendor/dmorenof/sdk-runtime/tests/cookie-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start HTTP server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('Missing HTTP port.');
    }
    $client = \Example\CookieSdk\Client::connect('http://127.0.0.1:' . $port, headers: ['COOKIE' => 'default=base'], securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Session' => 'a+/b==']));
    $resource = $client->resource(\Example\CookieSdk\Resources\Items::class);
    $values = ['theme' => 'a/b,ñ', 'enabled' => false, 'count' => 0, 'empty' => '', 'cookie' => 'caller=ok', 'body' => 'query body'];
    $resource->get(new \Example\CookieSdk\GetParams\Items((object) $values));
    $values['queryBody'] = $values['body'];
    $values['body'] = (object) ['name' => 'body name'];
    $params = new \Example\CookieSdk\PostParams\Items((object) $values);
    $resource->post($params);
    $params->theme = '';
    try {
        $resource->post($params);
        throw new RuntimeException('Invalid mutation accepted.');
    } catch (UnexpectedValueException) {
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    } proc_terminate($process);
    proc_close($process);
}
echo 'PUBLISHED_COOKIE_SDK_HTTP_OK', PHP_EOL;
