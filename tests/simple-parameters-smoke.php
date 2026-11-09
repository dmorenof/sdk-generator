<?php
declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Simple SDK must run independently with a stable runtime.');
}
$http = empty($argv[2]) ? new class implements \Psr\Http\Client\ClientInterface {
    public int $calls = 0;
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        ++$this->calls;
        if ((string) $request->getUri() !== 'https://api.example.test/v1/items/a%2Cb,c%2Fd,%252F,%C3%B1?body=query%20body&ids=2&ids=3'
            || $request->getHeaderLine('X-Codes') !== 'alpha,x y'
            || ($request->getMethod() === 'POST' && ((string) $request->getBody() !== '{"name":"body name"}'
                || $request->getHeaderLine('Content-Type') !== 'application/json'))) {
            throw new RuntimeException('Simple path/header lists changed request values.');
        }
        return new \GuzzleHttp\Psr7\Response(204);
    }
} : null;
$resource = \Example\SimpleSdk\Client::connect(empty($argv[2]) ? 'https://api.example.test/v1' : $argv[2], httpClient: $http)
    ->resource(\Example\SimpleSdk\Resources\Items::class);
$path = new \Example\SimpleSdk\PathParams\Items\ByIds((object) ['ids' => ['a,b', 'c/d', '%2F', 'ñ']]);
$get = new \Example\SimpleSdk\GetParams\Items\ByIds((object) ['body' => 'query body', 'ids' => [2, 3], 'X-Codes' => ['alpha', 'x y']]);
$post = new \Example\SimpleSdk\PostParams\Items\ByIds((object) ['queryBody' => 'query body', 'ids' => [2, 3], 'X-Codes' => ['alpha', 'x y'], 'body' => (object) ['name' => 'body name']]);
for ($attempt = 0; $attempt < 2; ++$attempt) {
    $resource->getByIds($path, $get);
    $resource->postByIds($path, $post);
}
foreach ([['a,b'], ["a\r\nX: value"], [' a']] as $invalid) {
    $post->XCodes = $invalid;
    try { $resource->postByIds($path, $post); throw new RuntimeException('Invalid header mutation accepted.'); }
    catch (UnexpectedValueException) {}
}
$post->XCodes = ['alpha']; $path->ids = [];
try { $resource->postByIds($path, $post); throw new RuntimeException('Invalid path mutation accepted.'); }
catch (UnexpectedValueException) {}
$path->ids = ['valid']; $post->body->name = '';
try { $resource->postByIds($path, $post); throw new RuntimeException('Invalid body mutation accepted.'); }
catch (UnexpectedValueException) {}
if ($http !== null && $http->calls !== 4) { throw new RuntimeException('Invalid mutations reached HTTP.'); }
echo 'PUBLISHED_SIMPLE_PARAMETER_SDK_OK', PHP_EOL;
