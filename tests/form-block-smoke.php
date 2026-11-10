<?php
declare(strict_types=1);
$openapi = $argv[1] ?? throw new RuntimeException('Missing OpenAPI SDK.');
$swagger = $argv[2] ?? throw new RuntimeException('Missing Swagger SDK.');
require $openapi . '/vendor/autoload.php';
require $swagger . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Form SDKs must run independently with stable runtime.');
}
$process = proc_open([PHP_BINARY, $openapi . '/vendor/dmorenof/sdk-runtime/tests/form-block-http-server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $url = 'http://127.0.0.1:' . $port;
    $client = \Example\FormBlockSdk\Client::connect($url);
    $form = new \Example\FormBlockSdk\PostParams\Form((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) [
        'body' => 'form body', 'space' => ['one', 'two'], 'pipe' => ['a/b', '%2F'], 'filter' => (object) ['code' => 'a/b', 'flag' => false],
        'expanded' => (object) ['city' => 'Madrid', 'zip' => 0], 'deep' => (object) ['name' => 'niño']]]);
    $formResource = $client->resource(\Example\FormBlockSdk\Resources\Form::class);
    $formResource->post($form);
    $profile = (object) ['id' => 7, 'code' => 'a/b', 'nested' => (object) ['flag' => false]];
    $multipart = new \Example\FormBlockSdk\PostParams\Multipart((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) [
        'profile' => $profile, 'batch' => [$profile, $profile], 'asJson' => 'quoted', 'document' => "\x00\xfffile", 'count' => 0]]);
    $multipartResource = $client->resource(\Example\FormBlockSdk\Resources\Multipart::class);
    $multipartResource->post($multipart);
    $swaggerParams = new \Example\SwaggerFormBlockSdk\PostParams\Swagger((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) [
        'csv' => [1, 2], 'multi' => ['x', 'y'], 'ssv' => ['one', 'two'], 'tsv' => ['one', 'two'], 'pipes' => ['a/b', '%2F']]]);
    $swaggerResource = \Example\SwaggerFormBlockSdk\Client::connect($url)->resource(\Example\SwaggerFormBlockSdk\Resources\Swagger::class);
    $swaggerResource->post($swaggerParams);
    $form->body->filter->code = '';
    try { $formResource->post($form); throw new RuntimeException('Invalid form mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    $multipart->body->batch[0]->code = '';
    try { $multipartResource->post($multipart); throw new RuntimeException('Invalid JSON part mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    $swaggerParams->body->tsv[] = "a\tb";
    try { $swaggerResource->post($swaggerParams); throw new RuntimeException('Ambiguous form mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_FORM_BLOCK_SDK_HTTP_OK', PHP_EOL;
