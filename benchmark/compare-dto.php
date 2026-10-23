<?php
declare(strict_types=1);

if ($argc !== 5) {
    throw new InvalidArgumentException('Usage: php benchmark/compare-dto.php <sdk> <runtime-before> <runtime-after> <report.json>');
}
[, $sdk, $before, $after, $reportPath] = $argv;
$runs = [];
foreach (['hydrate', 'serialize', 'roundtrip', 'deep'] as $mode) {
    for ($trial = 0; $trial < 5; $trial++) {
        foreach ($trial % 2 === 0 ? ['before' => $before, 'after' => $after] : ['after' => $after, 'before' => $before] as $version => $runtime) {
            $args = [PHP_BINARY, __DIR__ . '/dto-probe.php', $sdk, $runtime, $mode, $mode === 'deep' ? '24' : '1000', $mode === 'deep' ? '2000' : '20'];
            exec(implode(' ', array_map('escapeshellarg', $args)), $output, $status);
            if ($status !== 0) { throw new RuntimeException('Probe failed: ' . implode("\n", $output)); }
            $runs[] = ['version' => $version, 'trial' => $trial + 1] + json_decode(array_pop($output), true, 512, JSON_THROW_ON_ERROR);
            $output = [];
        }
    }
}
$median = static function (array $values): int|float { sort($values); return $values[intdiv(count($values), 2)]; };
$summary = [];
foreach (['hydrate', 'serialize', 'roundtrip', 'deep'] as $mode) {
    foreach (['before', 'after'] as $version) {
        $rows = array_filter($runs, static fn ($r) => $r['mode'] === $mode && $r['version'] === $version);
        foreach (['elapsed_ms', 'cpu_ms', 'peak_php_bytes', 'peak_allocated_bytes'] as $metric) {
            $summary[$mode][$version][$metric] = $median(array_column($rows, $metric));
        }
    }
}
$fingerprint = static function (string $directory): array {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen($directory) + 1)] = str_replace("\r\n", "\n", file_get_contents($file->getPathname()));
        }
    }
    ksort($files);
    return ['directory' => $directory, 'php_files' => count($files), 'sha256' => hash('sha256', serialize($files))];
};
$report = [
    'method' => 'Five fresh PHP processes per runtime version and workload, alternating order; mock-free DTO workloads, same generated SDK and Casafari payload, warmup excluded. PHP heap peaks are not process RSS.',
    'fixture_sha256' => hash_file('sha256', __DIR__ . '/../tests/fixtures/casafari-openapi31.json'),
    'sources' => ['sdk' => $fingerprint($sdk), 'before' => $fingerprint($before), 'after' => $fingerprint($after)],
    'summary' => $summary, 'runs' => $runs,
];
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
