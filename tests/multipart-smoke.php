<?php
declare(strict_types=1);

// Runs in a separate PHP process with only the independently installed SDK.
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Multipart SDK must run independently with a stable runtime.');
}
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public array $calls = [];
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$realHttp = isset($argv[2]) && $argv[2] !== '';
$fileContentType = $argv[3] ?? 'image/png';
$client = \Example\MultipartSdk\Client::connect($realHttp ? $argv[2] : 'https://api.example.test/v1',
    transport: $realHttp ? null : $transport);
$items = $client->resource(\Example\MultipartSdk\Resources\Items::class);
$params = new \Example\MultipartSdk\PostParams\Items((object) [
    'name' => 'query name', 'queryBody' => 'query-body', 'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'active' => false, 'file' => "\x00\xff\r\n", 'ids' => [1, 2]],
]);
$items->post($params);
if ($realHttp) {
    echo "MULTIPART_REAL_HTTP_OK", PHP_EOL;
    exit;
}
$call = $transport->calls[0];
$boundary = substr($call['headers']['Content-Type'], strlen('multipart/form-data; boundary='));
if ($call['url'] !== 'https://api.example.test/v1/items?body=query-body&name=query%20name'
    || $call['headers']['X-Trace'] !== 'trace'
    || !str_starts_with($call['body'], '--' . $boundary . "\r\n")
    || !str_ends_with($call['body'], '--' . $boundary . "--\r\n")
    || !str_contains($call['body'], "\r\n\r\n\x00\xff\r\n\r\n")
    || !str_contains($call['body'], 'filename="file.bin"')
    || !str_contains($call['body'], 'Content-Type: ' . $fileContentType)
    || !str_contains($call['body'], "\r\n\r\nfalse\r\n")
    || substr_count($call['body'], 'name="ids"') !== 2) {
    throw new RuntimeException('Installed multipart SDK did not preserve its HTTP contract.');
}
try {
    $params->body->name = '';
    $items->post($params);
    throw new RuntimeException('Invalid multipart mutation accepted.');
} catch (UnexpectedValueException) {
    if (count($transport->calls) !== 1) {
        throw new RuntimeException('Invalid multipart mutation reached transport.');
    }
}
echo "PUBLISHED_MULTIPART_SDK_OK", PHP_EOL;
