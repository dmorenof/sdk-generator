<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.');
require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Selection SDK must run independently with stable runtime.');
}
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/response-selection-http-server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\SelectionSdk\Client::connect('http://127.0.0.1:' . $port);
    $resource = $client->resource(\Example\SelectionSdk\Resources\Responses::class);
    foreach ([200, 201, 204, 299, 400, 404, 500] as $status) {
        $params = new \Example\SelectionSdk\GetParams\Responses((object) ['status' => $status, 'body' => 'query body', 'X-Trace' => 'trace']);
        try {
            $result = $resource->get($params);
            if ($status >= 400) { throw new RuntimeException('Error status returned as success.'); }
            if ($status === 201) {
                if (!$result instanceof \Psr\Http\Message\ResponseInterface || $result->getBody()->tell() !== 0
                    || (string) $result->getBody() !== "\x00\xffbinary" || $result->getHeaderLine('X-Result') !== 'preserved') {
                    throw new RuntimeException('Binary stream changed.');
                }
            } elseif ($status === 204) {
                if ($result !== null) { throw new RuntimeException('Bodyless status selected a schema.'); }
            } else {
                $class = $status === 200 ? \Example\SelectionSdk\DTOs\Exact::class : \Example\SelectionSdk\DTOs\Range::class;
                if (!$result instanceof $class || $result->jsonSerializeForResponse()->name !== 'value') { throw new RuntimeException('Wrong exact/range schema.'); }
                $result->name = '';
                try { $result->jsonSerializeForResponse(); throw new RuntimeException('Invalid response mutation accepted.'); }
                catch (UnexpectedValueException) {}
            }
        } catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || $error->response->status !== $status || ($error->response->headers['X-Result'] ?? '') !== 'preserved') { throw new RuntimeException('Raw error response lost.'); }
            if ($status === 400) {
                if ($error->error !== null || $error->getPrevious() !== null || $error->response->body !== '') { throw new RuntimeException('Bodyless exact error fell through.'); }
            } else {
                $class = $status === 404 ? \Example\SelectionSdk\DTOs\Problem::class : \Example\SelectionSdk\DTOs\Fallback::class;
                if (!$error->error instanceof $class) { throw new RuntimeException('Wrong range/default error.'); }
            }
        }
    }
    $post = new \Example\SelectionSdk\PostParams\Responses((object) ['status' => 201, 'queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['name' => 'request']]);
    if (!$resource->post($post) instanceof \Psr\Http\Message\ResponseInterface) { throw new RuntimeException('POST binary selection failed.'); }
    $fallback = $client->resource(\Example\SelectionSdk\Resources\Fallback::class)->get(
        new \Example\SelectionSdk\GetParams\Fallback((object) ['status' => 202, 'body' => 'query body', 'X-Trace' => 'trace']));
    if (!$fallback instanceof \Example\SelectionSdk\DTOs\Fallback || $fallback->city !== 'Madrid') { throw new RuntimeException('Default success failed.'); }
    $post->body->name = '';
    try { $resource->post($post); throw new RuntimeException('Invalid request mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_RESPONSE_SELECTION_SDK_HTTP_OK', PHP_EOL;
