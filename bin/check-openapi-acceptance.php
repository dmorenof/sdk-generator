#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;

if (!in_array($argc, [2, 4], true) || ($argc === 4 && $argv[2] !== '--sha256')) {
    fwrite(STDERR, "Usage: php bin/check-openapi-acceptance.php <spec.json|spec.yaml|spec.yml> [--sha256 expected-hash]\n");
    exit(2);
}
$source = @file_get_contents($argv[1]);
if ($source === false) {
    fwrite(STDERR, "Cannot read OpenAPI specification: {$argv[1]}\n");
    exit(2);
}
$actualHash = hash('sha256', $source);
if ($argc === 4 && !hash_equals(strtolower($argv[3]), $actualHash)) {
    fwrite(STDERR, "SPECIFICATION DRIFT: expected {$argv[3]}, received $actualHash. This is not the pinned acceptance input.\n");
    exit(3);
}
try {
    $format = in_array(strtolower(pathinfo($argv[1], PATHINFO_EXTENSION)), ['yaml', 'yml'], true)
        ? 'yaml'
        : 'json';
    $report = (new OpenApiAcceptanceAnalyzer())->analyze($source, $format);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    // Short diagnostic summary to CI logs; full per-operation data stays in artifact.
    fwrite(STDERR, "Casafari acceptance: " . $report['summary']['generated'] . '/'
        . $report['operationCount'] . " isolated operations generated; full source: "
        . $report['full']['status'] . ".\n");
    if ($report['full']['error'] !== null) {
        fwrite(STDERR, "Full source blocker: " . $report['full']['error']['message'] . "\n");
    }
    $groups = [];
    foreach ($report['operations'] as $operation) {
        if ($operation['status'] === 'generated') {
            continue;
        }
        $key = $operation['status'] . ': ' . ($operation['error']['message'] ?? 'unknown');
        if (!isset($groups[$key])) {
            $groups[$key] = ['count' => 0, 'example' => $operation['method'] . ' ' . $operation['path']];
        }
        ++$groups[$key]['count'];
    }
    uasort($groups, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
    foreach (array_slice($groups, 0, 14, true) as $reason => $details) {
        fwrite(STDERR, $details['count'] . " operation(s): " . $reason
            . " [e.g. " . $details['example'] . "]\n");
    }
    // An honest failing gate until EVERY operation and the entire source generate.
    exit($report['accepted'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Acceptance analysis failed: " . $exception->getMessage() . "\n");
    exit(2);
}
