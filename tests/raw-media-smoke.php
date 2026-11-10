<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.'); require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Raw SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/raw-media-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 20); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\RawSdk\Client::connect('http://127.0.0.1:' . $port);
    if ($client->resource(\Example\RawSdk\Resources\Text::class)->post("ñ text\n") !== "sin JSON ñ\n") { throw new RuntimeException('Text was JSON encoded/decoded.'); }
    $optional = $client->resource(\Example\RawSdk\Resources\Optional::class); $optional->post(); $optional->post('');
    $file = tmpfile(); if ($file === false) { throw new RuntimeException('Missing temporary upload.'); }
    for ($n = 0; $n < 4096; ++$n) { fwrite($file, str_repeat('x', 8192)); }
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($file); $stream->seek(100);
    $params = new \Example\RawSdk\PostParams\Binary\ById((object) ['body' => $stream, 'queryBody' => 'separate', 'X-Test' => 'header']);
    $client->resource(\Example\RawSdk\Resources\Binary::class)->postById(7, $params); $stream->close();
    $mixed = $client->resource(\Example\RawSdk\Resources\MixedResource::class);
    if ($mixed->get() !== 'plain response' || $mixed->get() !== 7) { throw new RuntimeException('Mixed text/JSON codecs changed.'); }
    $binary = $mixed->get(); if (!$binary instanceof \Psr\Http\Message\ResponseInterface || (string) $binary->getBody() !== "\0\xffbin" || $mixed->get() !== null) { throw new RuntimeException('Mixed binary/empty codecs changed.'); }
    try { $optional->post(null); throw new RuntimeException('Null was silently omitted.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_RAW_MEDIA_SDK_HTTP_OK', PHP_EOL;
