<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK directory.');
require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator')
    || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) {
    throw new RuntimeException('Delimited SDK must run independently with stable runtime.');
}
$server = $sdk . '/vendor/dmorenof/sdk-runtime/tests/delimited-http-server.php';
$process = proc_open([PHP_BINARY, $server], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $path = new \Example\DelimitedSdk\PathParams\Items\BySpaces\ByTabs\ByPipes((object) array_fill_keys(['spaces', 'tabs', 'pipes'], ['a/b', '%2F', 'ñ']));
    $headers = array_fill_keys(['X-Spaces', 'X-Tabs', 'X-Pipes'], ['alpha', 'beta']);
    $get = new \Example\DelimitedSdk\GetParams\Items\BySpaces\ByTabs\ByPipes((object) ($headers + ['body' => 'query body']));
    $post = new \Example\DelimitedSdk\PostParams\Items\BySpaces\ByTabs\ByPipes((object) ($headers + ['queryBody' => 'query body', 'body' => (object) ['name' => 'Ada']]));
    $resource = \Example\DelimitedSdk\Client::connect('http://127.0.0.1:' . $port)->resource(\Example\DelimitedSdk\Resources\Items\BySpaces\ByTabs::class);
    $resource->getByPipes($path, $get); $resource->postByPipes($path, $post);
    foreach (['spaces' => 'a b', 'tabs' => "a\tb", 'pipes' => 'a|b'] as $field => $value) {
        $path->$field = [$value];
        try { $resource->postByPipes($path, $post); throw new RuntimeException('Ambiguous path accepted.'); }
        catch (UnexpectedValueException) {}
        $path->$field = ['valid'];
    }
    foreach (['XSpaces' => 'a b', 'XTabs' => "a\tb", 'XPipes' => 'a|b'] as $field => $value) {
        $post->$field = [$value];
        try { $resource->postByPipes($path, $post); throw new RuntimeException('Ambiguous header accepted.'); }
        catch (UnexpectedValueException) {}
        $post->$field = ['valid'];
    }
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_DELIMITED_PARAMETER_SDK_HTTP_OK', PHP_EOL;
