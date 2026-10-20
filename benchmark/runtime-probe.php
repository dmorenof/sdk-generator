<?php
declare(strict_types=1);

// Run as an independent process. Each mode loads ONLY its own generated
// package and installed Composer dependencies. No network calls.
if ($argc !== 5) {
    fwrite(STDERR, "Usage: php runtime-probe.php <package-directory> <ours|openapi> <accounts|catalog> <iterations>\n");
    exit(2);
}
[, $package, $kind, $api, $rawIterations] = $argv;
$count = filter_var($rawIterations, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($count === false || !in_array($api, ['accounts','catalog'], true)
    || !in_array($kind, ['ours','openapi'], true)) {
    throw new InvalidArgumentException('Unsupported benchmark arguments.');
}

$start = hrtime(true);
require rtrim($package, '/') . '/vendor/autoload.php';

$payload = $api === 'accounts'
    ? json_encode([
        'id' => 88, 'name' => 'Ada', 'role' => 'admin',
        'profile' => ['city' => 'Madrid', 'visits' => 3],
        'labels' => ['php', 'sdk'],
    ], JSON_THROW_ON_ERROR)
    : json_encode([
        'sku' => 'BOOK-09', 'title' => 'A book', 'price' => 19.75,
        'genre' => 'fiction', 'stock' => 8, 'available' => true,
        'warehouse' => ['region' => 'Castilla', 'temperature' => 18.5],
        'tags' => ['php', 'sdk'],
    ], JSON_THROW_ON_ERROR);
$url = $api === 'accounts'
    ? 'https://api.example.test/v1'
    : 'https://catalog.example.test/api/v2';
$expectedPath = $api === 'accounts' ? '/v1/users/88' : '/api/v2/books/BOOK-09';
$expectedName = $api === 'accounts' ? 'Ada' : 'A book';
$expectedId = $api === 'accounts' ? 88 : 'BOOK-09';
$requests = 0;
$body = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $payload);
$handler = static function (\Psr\Http\Message\RequestInterface $request, array $options) use (
    $body, $expectedPath, &$requests
): \GuzzleHttp\Promise\PromiseInterface {
    if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== $expectedPath) {
        throw new RuntimeException('Endpoint mismatch: ' . $request->getMethod() . ' ' . $request->getUri());
    }
    $requests++;
    return \GuzzleHttp\Promise\Create::promiseFor($body);
};
$http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($handler)]);

if ($kind === 'ours') {
    $class = 'Benchmark\\' . ucfirst($api) . 'Sdk\\Api\\ApiClient';
    $client = $class::connect($url, $http);
} else {
    $config = \OpenAPI\Client\Configuration::getDefaultConfiguration()->setHost($url);
    $client = new \OpenAPI\Client\Api\DefaultApi($http, $config);
}
$ready = hrtime(true);
$check = static function (object $result) use ($kind, $api, $expectedName, $expectedId): void {
    if ($kind === 'ours') {
        $id = $api === 'accounts' ? $result->id : $result->sku;
        $name = $api === 'accounts' ? $result->name : $result->title;
    } else {
        $id = $api === 'accounts' ? $result->getId() : $result->getSku();
        $name = $api === 'accounts' ? $result->getName() : $result->getTitle();
    }
    if ($id !== $expectedId || $name !== $expectedName) {
        throw new RuntimeException('Response DTO semantics differ from fixture.');
    }
};
$execute = static function () use ($client, $api): object {
    return $api === 'accounts' ? $client->getUser(new \Benchmark\AccountsSdk\Requests\GetUserRequest((object)['id' => 88, 'verbose' => false])) : $client->findBook(new \Benchmark\CatalogSdk\Requests\FindBookRequest((object)['sku' => 'BOOK-09', 'expand' => false]));
};

// Warm both implementations to avoid counting lazy autoload initialization
// in the throughput loop. Cold start is recorded independently.
for ($i = 0; $i < 12; $i++) {
    $check($execute());
}
$beforePeak = memory_get_peak_usage(true);
$before = hrtime(true);
for ($i = 0; $i < $count; $i++) {
    $check($execute());
}
$elapsedNs = hrtime(true) - $before;
if ($requests !== ($count + 12)) {
    throw new RuntimeException("Wrong number of fake requests: $requests");
}
$out = [
    'api' => $api, 'implementation' => $kind, 'iterations' => $count,
    'startup_ms' => round(($ready - $start) / 1e6, 3),
    'ops_ms' => round($elapsedNs / 1e6, 3),
    'us_per_request' => round($elapsedNs / 1e3 / $count, 3),
    'php_peak_mib' => round(memory_get_peak_usage(true) / 1048576, 3),
    'php_peak_after_warmup_mib' => round($beforePeak / 1048576, 3),
    'response_checks' => $count + 12,
];
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
