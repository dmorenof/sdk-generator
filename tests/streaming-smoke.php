<?php
declare(strict_types=1);

// An independent generated SDK must stream a file larger than this process's memory limit.
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Streaming SDK must run independently with a stable runtime.');
}
$bytes = 32 * 1024 * 1024;
$list = ($argv[3] ?? '') === 'list';
$realHttp = isset($argv[2]) && $argv[2] !== '';
$http = $realHttp ? null : new class($bytes) implements \Psr\Http\Client\ClientInterface {
    public function __construct(private int $fileBytes) {}
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $body = $request->getBody();
        $size = $body->getSize();
        if (!$body instanceof \GuzzleHttp\Psr7\MultipartStream || $body->tell() !== 0
            || $size === null || $size <= $this->fileBytes || $size > $this->fileBytes + 4096
            || (string) $request->getUri() !== 'https://api.example.test/v1/items?body=query-body&name=query%20name'
            || $request->getHeaderLine('X-Trace') !== 'trace') {
            throw new RuntimeException('Streaming request lost its body or query/header contract.');
        }
        $read = 0;
        while (!$body->eof()) {
            $read += strlen($body->read(65536));
        }
        if ($read !== $size) {
            throw new RuntimeException('Streaming multipart body truncated.');
        }
        return new \GuzzleHttp\Psr7\Response(204);
    }
};
$files = [];
try {
    $uploads = [];
    $count = $list ? 2 : 1;
    for ($index = 0; $index < $count; ++$index) {
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('Cannot prepare streaming fixture.');
        }
        $files[] = $file;
        if (!ftruncate($file, intdiv($bytes, $count))) {
            throw new RuntimeException('Cannot size streaming fixture.');
        }
        $uploads[] = new \GuzzleHttp\Psr7\UploadedFile($file, intdiv($bytes, $count), UPLOAD_ERR_OK,
            $list ? ['first.png', 'second.pdf'][$index] : 'large.png', $index === 1 ? 'application/pdf' : 'image/png');
    }
    $body = (object) ['name' => 'body name'];
    $body->{$list ? 'attachments' : 'file'} = $list ? $uploads : $uploads[0];
    $client = \Example\MultipartSdk\Client::connect($realHttp ? $argv[2] : 'https://api.example.test/v1', $http);
    $items = $client->resource(\Example\MultipartSdk\Resources\Items::class);
    $params = new \Example\MultipartSdk\PostParams\Items((object) [
        'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
        'body' => $body,
    ]);
    $items->post($params);
    $items->post($params);
    if (memory_get_peak_usage(true) >= 16 * 1024 * 1024) {
        throw new RuntimeException('Streaming SDK exceeded its PHP memory bound.');
    }
    echo $list ? 'PUBLISHED_FILE_LIST_SDK_OK' : 'PUBLISHED_STREAMING_SDK_OK';
    echo ' size=', $bytes, ' peak=', memory_get_peak_usage(true), PHP_EOL;
} finally {
    foreach ($files as $file) {
        fclose($file);
    }
}
