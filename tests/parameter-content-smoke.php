<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Independent stable SDK required.');
}
$process = proc_open([PHP_BINARY, $argv[1] . '/vendor/dmorenof/sdk-runtime/tests/parameter-content-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start HTTP server.');
}
try {
    stream_set_timeout($pipes[1], 15);
    $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) {
        throw new RuntimeException('Missing port.');
    }
    $client = \Example\ContentSdk\Client::connect('http://127.0.0.1:' . $port, headers: ['Cookie' => 'default=base'], securityCredentials: new \Dmorenof\SdkRuntime\Security\SecurityCredentials(['Key' => 'token+/==']));
    $resource = $client->resource(\Example\ContentSdk\Resources\Items\BySelector::class);
    $path = new \Example\ContentSdk\PathParams\Items\BySelector\ByToken((object) ['selector' => (object) ['code' => 'path/x', 'flag' => false], 'token' => 'a/b%']);
    $values = ['body' => (object) ['id' => 7, 'name' => 'query/x'], 'records' => [(object) ['id' => 8, 'name' => 'row/x']], 'empty' => [], 'scalar' => '', 'active' => false, 'values' => (object) ['0' => 'zero'], 'plain' => '100% / ñ', 'optional' => null, 'X-JSON' => (object) ['code' => 'header/x', 'flag' => false], 'X-Trace' => 'h,/=x', 'session' => 'a+/b==', 'theme' => 'a/b'];
    $get = new \Example\ContentSdk\GetParams\Items\BySelector\ByToken((object) $values);
    $resource->getByToken($path, $get);
    $values['queryBody'] = $values['body'];
    $values['body'] = (object) ['id' => 9, 'name' => 'body/x'];
    $post = new \Example\ContentSdk\PostParams\Items\BySelector\ByToken((object) $values);
    $resource->postByToken($path, $post);
    $client->resource(\Example\ContentSdk\Resources\Scalar::class)->getById('Cat');
    $post->records[] = new \Example\ContentSdk\DTOs\Payload((object) ['id' => 10, 'name' => 'second']);
    unset($post->records[0]); $resource->postByToken($path, $post);
    $post->records[1]->name = '';
    try {
        $resource->postByToken($path, $post);
        throw new RuntimeException('Invalid mutation accepted.');
    } catch (UnexpectedValueException) {
    }
    try {
        $client->resource(\Example\ContentSdk\Resources\Scalar::class)->getById('Cow');
        throw new RuntimeException('JSON serialization bypassed enum constraints.');
    } catch (UnexpectedValueException) {
    }
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    } proc_terminate($process);
    proc_close($process);
}
echo 'PUBLISHED_PARAMETER_CONTENT_SDK_HTTP_OK', PHP_EOL;
