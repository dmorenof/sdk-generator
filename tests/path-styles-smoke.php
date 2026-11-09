<?php

declare(strict_types=1);
require ($argv[1] ?? throw new RuntimeException('Missing SDK directory.')).'/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string)\Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('SDK must be independent and stable.');
}
$http = empty($argv[2]) ? new class () implements \Psr\Http\Client\ClientInterface {
    public int $calls = 0;
    public function sendRequest(\Psr\Http\Message\RequestInterface $r): \Psr\Http\Message\ResponseInterface
    {
        $prefix = $this->calls < 2 ? '.x%2Fy' : '.';
        ++$this->calls;
        if ((string)$r->getUri() !== 'https://api.example.test/v1/items/'.$prefix.'/;ids=a%2Cb;ids=%C3%B1/.active=false.code=a%2Fb.count=0?body=query%20body&ids=2&ids=3' || $r->getHeaderLine('X-Trace') !== 'trace' || ($r->getMethod() === 'POST' && (string)$r->getBody() !== '{"name":"body name"}')) {
            throw new RuntimeException('Path expansion changed the request.');
        }
        return new \GuzzleHttp\Psr7\Response(204);
    }
} : null;
$r = \Example\PathSdk\Client::connect(empty($argv[2]) ? 'https://api.example.test/v1' : $argv[2], httpClient:$http)->resource(\Example\PathSdk\Resources\Items\ByCode\ByIds::class);
$p = new \Example\PathSdk\PathParams\Items\ByCode\ByIds\BySelector((object)['code' => 'x/y','ids' => ['a,b','ñ'],'selector' => (object)['active' => false,'code' => 'a/b','count' => 0]]);
$get = new \Example\PathSdk\GetParams\Items\ByCode\ByIds\BySelector((object)['body' => 'query body','ids' => [2,3],'X-Trace' => 'trace']);
$post = new \Example\PathSdk\PostParams\Items\ByCode\ByIds\BySelector((object)['queryBody' => 'query body','ids' => [2,3],'X-Trace' => 'trace','body' => (object)['name' => 'body name']]);
foreach (['x/y',''] as $code) {
    $p->code = $code;
    $r->getBySelector($p, $get);
    $r->postBySelector($p, $post);
}
$p->ids = [];
try {
    $r->postBySelector($p, $post);
    throw new RuntimeException('Mutation accepted.');
} catch (UnexpectedValueException) {
}
if ($http !== null && $http->calls !== 4) {
    throw new RuntimeException('Invalid mutation reached HTTP.');
}
echo 'PUBLISHED_PATH_STYLE_SDK_OK',PHP_EOL;
