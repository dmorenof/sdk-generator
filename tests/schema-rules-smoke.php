<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.');
require $sdk . '/vendor/autoload.php';
require_once __DIR__ . '/Support/Cfg.php'; // the SDK runs on its own vendor tree, which has no test autoloader
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || !\Composer\InstalledVersions::isInstalled('opis/json-schema')
    || (getenv('SDK_RUNTIME_REF') === false && str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev'))) { throw new RuntimeException('Schema SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/schema-rules-http-server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\SchemaRulesSdk\Client::connect(\Dmorenof\SdkGenerator\Tests\Support\Cfg::make('http://127.0.0.1:' . $port));
    $params = new \Example\SchemaRulesSdk\PostParams\Rules((object) ['amount' => 0.3, 'body' => (object) [
        'id' => 8, 'amount' => 0.3, 'rows' => [1, 2], 'tag' => 7, 'profile' => (object) ['only' => 1], 'label' => 'ñ']]);
    $resource = $client->resource(\Example\SchemaRulesSdk\Resources\Rules::class);
    $result = $resource->post($params);
    if (!$result instanceof \Example\SchemaRulesSdk\DTOs\Record || $result->tag !== 7) { throw new RuntimeException('Wrong response model.'); }
    if ($client->resource(\Example\SchemaRulesSdk\Resources\Scalar::class)->get() !== 0.3) { throw new RuntimeException('Wrong scalar result.'); }
    // The answer is trusted: a value outside the rules of its schema is returned as it came.
    if ($client->resource(\Example\SchemaRulesSdk\Resources\ScalarBad::class)->get() !== 0.31) { throw new RuntimeException('A scalar answer outside its rules was not read.'); }
    $collection = $client->resource(\Example\SchemaRulesSdk\Resources\Collection::class)->get();
    if ((string) $collection !== '[1,2]') { throw new RuntimeException('Wrong collection shape.'); }
    $collection[] = 1;
    try { $collection->validateForMode(); throw new RuntimeException('Duplicate collection mutation accepted.'); } catch (UnexpectedValueException) {}
    $result->profile['second'] = 2;
    try { $result->validateForResponse(); throw new RuntimeException('Map count mutation accepted.'); } catch (UnexpectedValueException) {}
    try { $params->body->tag = 8; $resource->post($params); throw new RuntimeException('Const mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    $params->body->tag = 7;
    try { $params->amount = 0.31; throw new RuntimeException('Invalid query amount accepted.'); } catch (UnexpectedValueException) {} // checked where it is assigned
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_SCHEMA_RULES_SDK_HTTP_OK', PHP_EOL;
