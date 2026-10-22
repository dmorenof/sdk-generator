<?php
declare(strict_types=1);

// A fresh PHP process loads exactly one generated SDK; no HTTP or generator is
// included in the timed region. Both versions receive the same real-API data.
if ($argc !== 5) {
    throw new InvalidArgumentException('Usage: php benchmark/dto-probe.php <sdk-directory> <hydrate|serialize|roundtrip|deep> <items> <iterations>');
}
[, $directory, $mode, $rawItems, $rawIterations] = $argv;
$items = filter_var($rawItems, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$iterations = filter_var($rawIterations, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($items === false || $iterations === false || !in_array($mode, ['hydrate', 'serialize', 'roundtrip', 'deep'], true)) {
    throw new InvalidArgumentException('Invalid workload.');
}
require dirname(__DIR__) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($directory): void {
    if (str_starts_with($class, 'CasafariSDK\\')) {
        $path = $directory . '/src/' . str_replace('\\', '/', substr($class, strlen('CasafariSDK\\'))) . '.php';
        if (is_file($path)) { require $path; }
    }
});
$rows = [];
for ($i = 0; $i < $items; $i++) {
    $rows[] = ['id' => $i + 1, 'name' => 'Lisbon homes ' . $i, 'user' => 'owner',
        'filter' => ['operation' => 'sale', 'location_ids' => range(1, 16)],
    ];
}
$json = json_encode($rows, JSON_THROW_ON_ERROR);
unset($rows);
$type = CasafariSDK\Collections\AlertsFeedsCollection::class;
$hydrate = static fn () => CasafariSDK\Support\JsonHydrator::decode($json, $type);
$data = $hydrate();
if (count($data) !== $items || !is_int($data[0]->id) || $data[0]->filter->operation->value !== 'sale') {
    throw new RuntimeException('Hydration semantics differ.');
}
if (json_decode(json_encode($data, JSON_THROW_ON_ERROR), true) != json_decode($json, true)) {
    throw new RuntimeException('Round-trip semantics differ.');
}
if ($mode === 'deep') {
    $data = new class extends CasafariSDK\Core\DTO { public ?CasafariSDK\Core\DTO $child; public string $name = 'node'; };
    $data->child = null;
    for ($i = 1; $i < $items; $i++) {
        $parent = clone $data;
        $parent->child = $data;
        $data = $parent;
    }
}
$execute = match ($mode) {
    'hydrate' => $hydrate,
    'serialize', 'deep' => static fn () => json_encode($data, JSON_THROW_ON_ERROR),
    'roundtrip' => static fn () => json_encode($hydrate(), JSON_THROW_ON_ERROR),
};
for ($i = 0; $i < 5; $i++) { $execute(); }
gc_collect_cycles();
memory_reset_peak_usage();
$cpuStart = getrusage();
$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) { $execute(); }
$milliseconds = (hrtime(true) - $start) / 1e6;
$cpuEnd = getrusage();
$cpu = static fn (array $v): float => $v['ru_utime.tv_sec'] * 1000 + $v['ru_utime.tv_usec'] / 1000
    + $v['ru_stime.tv_sec'] * 1000 + $v['ru_stime.tv_usec'] / 1000;
echo json_encode([
    'mode' => $mode, 'items' => $items, 'iterations' => $iterations,
    'elapsed_ms' => round($milliseconds, 3), 'cpu_ms' => round($cpu($cpuEnd) - $cpu($cpuStart), 3),
    'peak_php_bytes' => memory_get_peak_usage(false), 'peak_allocated_bytes' => memory_get_peak_usage(true),
    'php' => PHP_VERSION,
], JSON_THROW_ON_ERROR) . "\n";
