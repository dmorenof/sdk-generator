<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.'); require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Alias SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, __DIR__ . '/schema-aliases-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\AliasSdk\Client::connect('http://127.0.0.1:' . $port);
    $child = new \Example\AliasSdk\DTOs\ChildRecord((object) ['id' => 1, 'name' => 'Alice', 'status' => 'open']);
    $result = $client->resource(\Example\AliasSdk\Resources\Record::class)->post($child);
    if (!$result instanceof \Example\AliasSdk\DTOs\BaseRecord || $result->status !== \Example\AliasSdk\Enums\StatusCode::Open) { throw new RuntimeException('Canonical alias inheritance/enum changed.'); }
    $node = new \Example\AliasSdk\DTOs\NodeRecord((object) ['id' => 1, 'next' => (object) ['id' => 2]]);
    if ($client->resource(\Example\AliasSdk\Resources\Node::class)->post($node)->next->id !== 2) { throw new RuntimeException('Recursive alias changed.'); }
    $choice = \Example\AliasSdk\DTOs\OneOfRecordChoice::fromValue((object) ['id' => 1, 'name' => 'Alice']);
    if (!$client->resource(\Example\AliasSdk\Resources\Choice::class)->post($choice)->matchedVariants()[0] instanceof $child) { throw new RuntimeException('Composition alias changed.'); }
    $scores = new \Example\AliasSdk\DTOs\ScoreMap((object) ['one' => 1]);
    if ($client->resource(\Example\AliasSdk\Resources\Map::class)->post($scores)['one'] !== 1) { throw new RuntimeException('Map alias changed.'); }
    if ($client->resource(\Example\AliasSdk\Resources\Status::class)->post($child->status) !== $child->status) { throw new RuntimeException('Enum alias changed.'); }
    $params = new \Example\AliasSdk\GetParams\Filter((object) ['filter' => (object) ['state' => 'closed']]);
    if ($client->resource(\Example\AliasSdk\Resources\Filter::class)->get($params) !== $child->status) { throw new RuntimeException('Query alias changed.'); }
    $child->name = 'x';
    try { $client->resource(\Example\AliasSdk\Resources\Record::class)->post($child); throw new RuntimeException('Alias mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_SCHEMA_ALIASES_SDK_HTTP_OK', PHP_EOL;
