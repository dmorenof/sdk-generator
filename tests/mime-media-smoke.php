<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.'); require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('MIME SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, $sdk . '/vendor/dmorenof/sdk-runtime/tests/mime-media-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 20); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = \Example\MimeMediaSdk\Client::connect('http://127.0.0.1:' . $port);
    if ($client->resource(\Example\MimeMediaSdk\Resources\Csv::class)->post("a,b\n1,2\n") !== "a,b\n3,4\n" || $client->resource(\Example\MimeMediaSdk\Resources\Xml::class)->post('<input>ñ</input>') !== '<reply/>') { throw new RuntimeException('Raw CSV/XML codecs changed.'); }
    $file = tmpfile(); if ($file === false) { throw new RuntimeException('Missing upload.'); } for ($n = 0; $n < 4096; ++$n) { fwrite($file, str_repeat('x', 8192)); }
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($file); $stream->seek(100);
    $params = new \Example\MimeMediaSdk\PostParams\Image\ById((object) ['body' => $stream, 'queryBody' => 'separate', 'X-Test' => 'header']);
    $image = $client->resource(\Example\MimeMediaSdk\Resources\Image::class);
    if ((string) $image->postById(7, $params)->getBody() !== "\0\xffpng") { throw new RuntimeException('Image binary response changed.'); } $stream->close();
    $pdf = $client->resource(\Example\MimeMediaSdk\Resources\Pdf::class); $response = $pdf->get();
    if ($response->getHeaderLine('Content-Type') !== 'application/pdf') { throw new RuntimeException('PDF media lost.'); }
    $actual = hash_init('sha256'); $bytes = 0;
    while (!$response->getBody()->eof()) { $chunk = $response->getBody()->read(8192); hash_update($actual, $chunk); $bytes += strlen($chunk); }
    $response->getBody()->close(); $expected = hash_init('sha256'); for ($n = 0; $n < 4096; ++$n) { hash_update($expected, str_repeat('z', 8192)); }
    if ($bytes !== 33554432 || hash_final($actual) !== hash_final($expected) || (string) $pdf->get()->getBody() !== '<svg/>') { throw new RuntimeException('PDF/SVG binary codecs changed.'); }
    $payload = new \Example\MimeMediaSdk\DTOs\InputModel((object) ['value' => 7]); $problem = $client->resource(\Example\MimeMediaSdk\Resources\Problem::class);
    if (!$problem->post($payload)->ok) { throw new RuntimeException('Vendor JSON changed.'); }
    $mixed = $client->resource(\Example\MimeMediaSdk\Resources\MixedResource::class);
    if ($mixed->get() !== '<xml/>' || $mixed->get() !== 7 || (string) $mixed->get()->getBody() !== '%PDF bytes' || $mixed->get() !== null) { throw new RuntimeException('Mixed MIME status codecs changed.'); }
    foreach (["error,csv\n", "\0\xfferror"] as $expectedError) {
        try { $mixed->get(); throw new RuntimeException('Raw MIME error became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== $expectedError || $error->getPrevious() !== null) { throw new RuntimeException('Raw MIME error changed.'); } }
    }
    try { $pdf->get(); throw new RuntimeException('XML error became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== '<error/>') { throw new RuntimeException('XML error decoding changed.'); } }
    $params->body = 'small';
    try { $image->postById(7, $params); throw new RuntimeException('Problem DTO became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->error?->code !== 400 || $error->payload !== $error->error) { throw new RuntimeException('Problem DTO decoding changed.'); } }
    try { $problem->post($payload); throw new RuntimeException('Problem JSON error became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== 'problem') { throw new RuntimeException('Problem JSON decoded as raw text.'); } }
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_MIME_MEDIA_SDK_HTTP_OK size=', $bytes, ' peak=', memory_get_peak_usage(true), PHP_EOL;
