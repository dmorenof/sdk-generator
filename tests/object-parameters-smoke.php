<?php
declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')) . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Object SDK must run independently with a stable runtime.');
}
$http = empty($argv[2]) ? new class implements \Psr\Http\Client\ClientInterface {
    public int $calls = 0;
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
        ++$this->calls;
        if ((string) $request->getUri() !== 'https://api.example.test/v1/items/active,false,code,a%2Cb%2F%C3%B1,count,0?body=query%20body&filter=active,false,code,safe,count,0&tag=x%20y'
            || $request->getHeaderLine('X-Filter') !== 'active=false,code=safe,count=0'
            || ($request->getMethod() === 'POST' && ((string) $request->getBody() !== '{"name":"body name"}'
                || $request->getHeaderLine('Content-Type') !== 'application/json'))) { throw new RuntimeException('Object parameter bytes changed.'); }
        return new \GuzzleHttp\Psr7\Response(204);
    }
} : null;
$resource = \Example\ObjectSdk\Client::connect(empty($argv[2]) ? 'https://api.example.test/v1' : $argv[2], httpClient: $http)->resource(\Example\ObjectSdk\Resources\Items::class);
$path = new \Example\ObjectSdk\PathParams\Items\BySelector((object) ['selector' => (object) ['code' => 'a,b/ñ', 'active' => false, 'count' => 0, 'server-id' => 'hidden,field']]);
$filter = (object) ['code' => 'safe', 'active' => false, 'count' => 0, 'server-id' => 'hidden,field'];
$get = new \Example\ObjectSdk\GetParams\Items\BySelector((object) ['X-Filter' => $filter, 'filter' => $filter, 'labels' => (object) ['tag' => 'x y'], 'body' => 'query body']);
$post = new \Example\ObjectSdk\PostParams\Items\BySelector((object) ['X-Filter' => $filter, 'filter' => $filter, 'labels' => (object) ['tag' => 'x y'], 'queryBody' => 'query body', 'body' => (object) ['name' => 'body name']]);
for ($attempt = 0; $attempt < 2; ++$attempt) {
    $resource->getBySelector($path, $get); $resource->postBySelector($path, $post);
}
$post->XFilter->code = 'a,b';
try { $resource->postBySelector($path, $post); throw new RuntimeException('Ambiguous header mutation accepted.'); }
catch (UnexpectedValueException) {}
$post->XFilter->code = 'safe'; $path->selector->code = '';
try { $resource->postBySelector($path, $post); throw new RuntimeException('Invalid nested path mutation accepted.'); }
catch (UnexpectedValueException) {}
$path->selector->code = 'valid'; $post->labels['body'] = 'collision';
try { $resource->postBySelector($path, $post); throw new RuntimeException('Expanded query collision accepted.'); }
catch (UnexpectedValueException) {}
if ($http !== null && $http->calls !== 4) { throw new RuntimeException('Invalid mutations reached HTTP.'); }
echo 'PUBLISHED_OBJECT_PARAMETER_SDK_OK', PHP_EOL;
