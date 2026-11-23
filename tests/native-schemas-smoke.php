<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.');
require $sdk . '/vendor/autoload.php';
require_once __DIR__ . '/Support/Cfg.php'; // the SDK runs on its own vendor tree, which has no test autoloader
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || (getenv('SDK_RUNTIME_REF') === false && str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev'))) { throw new RuntimeException('Native SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/native-schemas-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\NativeSdk\Client::connect(\Dmorenof\SdkGenerator\Tests\Support\Cfg::make('http://127.0.0.1:' . $port));
    $native = $client->resource(\Example\NativeSdk\Resources\Native::class);
    $record = $native->get();
    if ($record->id !== 1) { throw new RuntimeException('Integral JSON number lost its integer value.'); }
    $native->post($record);
    if ($client->resource(\Example\NativeSdk\Resources\Value::class)->get() !== false || $client->resource(\Example\NativeSdk\Resources\Any::class)->get()->nested !== [1, null]) { throw new RuntimeException('Native JSON kinds changed.'); }
    $client->resource(\Example\NativeSdk\Resources\NeverResource::class)->get(); // a schema nothing satisfies is not applied to an answer: it is trusted
    if ($client->resource(\Example\NativeSdk\Resources\Items::class)->get() !== [1, 'x', null]) { throw new RuntimeException('Native array items changed.'); }
    $params = new \Example\NativeSdk\GetParams\Param\ById((object) ['value' => false, 'json' => 1.0, 'plain' => 'hello', 'X-Alias' => 'ab', 'session' => 'ab']);
    $parameter = $client->resource(\Example\NativeSdk\Resources\Param::class);
    if ($parameter->getById(1, $params) !== 'result') { throw new RuntimeException('Wrong parameter response.'); }
    $params->value = null; $parameter->getById(1, $params);
    try { $record->rejected = null; $native->post($record); throw new RuntimeException('False property mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    $record->items[] = false; // a property with a hook cannot be unset (PHP 8.4), so `rejected` is left as it was
    try { $record->validateForRequest(); throw new RuntimeException('Invalid array mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_NATIVE_SCHEMAS_SDK_HTTP_OK', PHP_EOL;
