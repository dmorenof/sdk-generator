<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.'); require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Error SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/error-payloads-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $resource = \Example\ErrorPayloadSdk\Client::connect('http://127.0.0.1:' . $port)->resource(\Example\ErrorPayloadSdk\Resources\Errors::class);
    $params = new \Example\ErrorPayloadSdk\GetParams\Errors\ById((object) ['trace' => 'all', 'X-Test' => 'header']); $dto = null;
    foreach ([400 => 'issue', 401 => [1, 2], 402 => ['one' => 1], 403 => null, 404 => null, 405 => 'denied', 406 => 'plain error', 407 => "\0\xffbin", 409 => 'many', 422 => 7, 500 => ['code' => 500, 'message' => 'failed']] as $status => $expected) {
        try { $resource->getById($status, $params); throw new RuntimeException('HTTP failure became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || $error->getPrevious() !== null) { throw new RuntimeException('HTTP status or valid error decoding changed.'); }
            $value = $error->payload;
            if ($value instanceof \Dmorenof\SdkRuntime\TypedArray || $value instanceof \Dmorenof\SdkRuntime\Map) { $value = iterator_to_array($value); }
            elseif ($value instanceof \BackedEnum) { $value = $value->value; }
            elseif ($value instanceof \Dmorenof\SdkRuntime\AnyOf) { $value = $value->matchedVariants()[0]; }
            elseif ($value instanceof \Dmorenof\SdkRuntime\DTO) { $dto = $value; $value = (array) $value->jsonSerializeForResponse(); }
            if ($value !== $expected || ($status === 500 && $error->error !== $error->payload) || ($status !== 500 && $error->error !== null)) { throw new RuntimeException('Error payload or legacy DTO property changed.'); }
        }
    }
    foreach ([[400, 'short', UnexpectedValueException::class], [401, 'json', JsonException::class]] as [$status, $trace, $cause]) {
        $params->trace = $trace;
        try { $resource->getById($status, $params); throw new RuntimeException('Invalid error payload became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || $error->payload !== null || !$error->getPrevious() instanceof $cause || $error->response->body === '') { throw new RuntimeException('Invalid decoding lost its HTTP failure/cause.'); }
        }
    }
    if ($dto === null) { throw new RuntimeException('Missing DTO error.'); } $dto->message = 'x';
    try { $dto->jsonSerializeForResponse(); throw new RuntimeException('Invalid error DTO mutation accepted.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_ERROR_PAYLOADS_SDK_HTTP_OK', PHP_EOL;
