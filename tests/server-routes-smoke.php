<?php
declare(strict_types=1);
$sdk = $argv[1] ?? throw new RuntimeException('Missing SDK.'); require $sdk . '/vendor/autoload.php';
if (\Composer\InstalledVersions::isInstalled('dmorenof/sdk-generator') || str_contains((string) \Composer\InstalledVersions::getPrettyVersion('dmorenof/sdk-runtime'), 'dev')) { throw new RuntimeException('Server-route SDK needs independent stable dependencies.'); }
$process = proc_open([PHP_BINARY, __DIR__ . '/server-routes-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $base = 'http://127.0.0.1:' . $port;
    $configuration = new \Example\ServerRoutesSdk\Configuration();
    if ($configuration->baseUrl !== 'https://api.eu.example.test:443/v1') { throw new RuntimeException('Root server defaults changed.'); }
    $configuration->baseUrl = $base . '/configured'; $configuration->httpClient = new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]);
    $configuration->serverOverrides = ['https://audit.us.example.test/v2' => $base . '/audit', 'https://exports.eu.example.test/v3' => $base . '/export'];
    \Example\ServerRoutesSdk\Client::configure($configuration);
    $configuration->serverOverrides['https://audit.us.example.test/v2'] = 'https://changed.invalid';
    $client = \Example\ServerRoutesSdk\Client::current();
    $client->resource(\Example\ServerRoutesSdk\Resources\Root::class)->getById('a/b');
    \Example\ServerRoutesSdk\Items::getById('a/b', new \Example\ServerRoutesSdk\GetParams\Items\ById((object) ['body' => 'query body', 'X-Trace' => 'trace']));
    $params = new \Example\ServerRoutesSdk\PostParams\Items\ById((object) ['queryBody' => 'query body', 'X-Trace' => 'trace', 'body' => (object) ['value' => 'valid']]);
    \Example\ServerRoutesSdk\Items::postById('a/b', $params);
    $client->resource(\Example\ServerRoutesSdk\Resources\Equivalent::class)->getById('a/b');
    $client->resource(\Example\ServerRoutesSdk\Resources\Mirror\ById::class)->getById('a/b');
    $client->resource(\Example\ServerRoutesSdk\Resources\Assets\StemExt::class)->get(new \Example\ServerRoutesSdk\PathParams\Assets\StemExt((object) ['stem' => 'report', 'ext' => 'csv']));
    $params->body->value = 'x';
    try { \Example\ServerRoutesSdk\Items::postById('a/b', $params); throw new RuntimeException('Nested mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'PUBLISHED_SERVER_ROUTES_SDK_HTTP_OK', PHP_EOL;
