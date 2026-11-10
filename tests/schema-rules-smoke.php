<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.');
require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || !\Composer\InstalledVersions::isInstalled('opis/json-schema')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Schema SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/schema-rules-http-server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\SchemaRulesSdk\Client::connect('http://127.0.0.1:' . $port);
    $params = new \Example\SchemaRulesSdk\PostParams\Rules((object) ['amount' => 0.3, 'body' => (object) [
        'id' => 8, 'amount' => 0.3, 'rows' => [1, 2], 'tag' => 7, 'profile' => (object) ['only' => 1], 'label' => 'ñ']]);
    $resource = $client->resource(\Example\SchemaRulesSdk\Resources\Rules::class);
    $result = $resource->post($params);
    if (!$result instanceof \Example\SchemaRulesSdk\DTOs\Record || $result->tag !== 7) { throw new RuntimeException('Wrong response model.'); }
    if ($client->resource(\Example\SchemaRulesSdk\Resources\Scalar::class)->get() !== 0.3) { throw new RuntimeException('Wrong scalar result.'); }
    try { $client->resource(\Example\SchemaRulesSdk\Resources\ScalarBad::class)->get(); throw new RuntimeException('Invalid response multipleOf accepted.'); } catch (UnexpectedValueException) {}
    $collection = $client->resource(\Example\SchemaRulesSdk\Resources\Collection::class)->get();
    if ((string) $collection !== '[1,2]') { throw new RuntimeException('Wrong collection shape.'); }
    $collection[] = 1;
    try { (string) $collection; throw new RuntimeException('Duplicate collection mutation accepted.'); } catch (UnexpectedValueException) {}
    $result->profile['second'] = 2;
    try { $result->jsonSerializeForResponse(); throw new RuntimeException('Map count mutation accepted.'); } catch (UnexpectedValueException) {}
    $params->body->tag = 8;
    try { $resource->post($params); throw new RuntimeException('Const mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    $params->body->tag = 7; $params->amount = 0.31;
    try { $resource->post($params); throw new RuntimeException('Invalid query amount reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_SCHEMA_RULES_SDK_HTTP_OK', PHP_EOL;
