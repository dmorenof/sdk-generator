<?php
declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Download SDK must run independently with a stable runtime.');
}
$bytes = 32 * 1024 * 1024;
$http = empty($argv[2]) ? new class($bytes) implements \Psr\Http\Client\ClientInterface {
    public function __construct(private int $bytes) {}
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        if ($request->getUri()->getQuery() !== 'body=query%20body'
            || $request->getHeaderLine('Accept') !== 'application/octet-stream'
            || $request->getHeaderLine('X-Trace') !== 'trace'
            || ($request->getMethod() === 'POST' && ((string) $request->getBody() !== '{"name":"export"}'
                || $request->getHeaderLine('Content-Type') !== 'application/json'))) {
            throw new RuntimeException('Query, headers or JSON export DTO changed.');
        }
        $file = tmpfile();
        if ($file === false || !ftruncate($file, $this->bytes)) { throw new RuntimeException('Cannot prepare download.'); }
        return new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string) $this->bytes,
            'Content-Disposition' => 'attachment; filename="large.bin"'], \GuzzleHttp\Psr7\Utils::streamFor($file));
    }
} : null;
$client = \Example\DownloadSdk\Client::connect(empty($argv[2]) ? 'https://api.example.test/v1' : $argv[2], httpClient: $http);
$resource = $client->resource(\Example\DownloadSdk\Resources\Download::class);
$expected = hash_init('sha256');
for ($i = 0; $i < $bytes / 65536; ++$i) { hash_update($expected, str_repeat("\0", 65536)); }
$expected = hash_final($expected);
for ($attempt = 0; $attempt < 4; ++$attempt) {
    $response = $attempt % 2 === 0
        ? $resource->get(new \Example\DownloadSdk\GetParams\Download((object) ['body' => 'query body', 'X-Trace' => 'trace']))
        : $resource->post(new \Example\DownloadSdk\PostParams\Download((object) [
            'queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['name' => 'export']]));
    $target = tmpfile();
    if ($target === false) { throw new RuntimeException('Cannot create destination.'); }
    $targetStream = \GuzzleHttp\Psr7\Utils::streamFor($target);
    \GuzzleHttp\Psr7\Utils::copyToStream($response->getBody(), $targetStream);
    rewind($target);
    $hash = hash_init('sha256');
    $count = hash_update_stream($hash, $target);
    if ($count !== $bytes || hash_final($hash) !== $expected
        || $response->getHeaderLine('Content-Length') !== (string) $bytes
        || $response->getHeaderLine('Content-Disposition') !== 'attachment; filename="large.bin"') {
        throw new RuntimeException('Incomplete download or lost headers.');
    }
    $response->getBody()->close();
    $targetStream->close();
}
echo 'PUBLISHED_DOWNLOAD_SDK_32M_UNDER_16M_OK peak=', memory_get_peak_usage(true), PHP_EOL;
