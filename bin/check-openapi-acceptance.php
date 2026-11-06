#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dmorenof\SdkGenerator\Acceptance\OpenApiAcceptanceAnalyzer;

$arguments = [];
$resolveLocal = false;
$referenceRoot = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--resolve-local-references') {
        $resolveLocal = true;
    } elseif (str_starts_with($argument, '--reference-root=')) {
        $referenceRoot = substr($argument, strlen('--reference-root='));
    } else {
        $arguments[] = $argument;
    }
}
if (!in_array(count($arguments), [1, 3], true) || (count($arguments) === 3 && $arguments[1] !== '--sha256')
    || ($referenceRoot !== null && !$resolveLocal)) {
    fwrite(STDERR, "Usage: check-openapi-acceptance.php <spec.json|yaml> [--sha256 expected-hash] [--resolve-local-references [--reference-root=directory]]\n");
    exit(2);
}
try {
    $input = $arguments[0];
    $bundle = $resolveLocal ? (new \Dmorenof\SdkGenerator\Parsing\LocalFileBundler())->bundle($input, $referenceRoot,
        count($arguments) === 3 ? $arguments[2] : null) : null;
    $source = $bundle === null ? @file_get_contents($input) : null;
    if ($bundle === null && $source === false) {
        throw new RuntimeException("Cannot read OpenAPI specification: $input");
    }
    $actualHash = $bundle?->sourceSha256 ?? hash('sha256', $source);
    if (count($arguments) === 3 && !hash_equals(strtolower($arguments[2]), $actualHash)) {
        fwrite(STDERR, "SPECIFICATION DRIFT: expected {$arguments[2]}, received $actualHash. This is not the pinned acceptance input.\n");
        exit(3);
    }
    $analyzer = new OpenApiAcceptanceAnalyzer();
    $format = in_array(strtolower(pathinfo($input, PATHINFO_EXTENSION)), ['yaml', 'yml'], true) ? 'yaml' : 'json';
    $report = $bundle === null ? $analyzer->analyze($source, $format, $input) : $analyzer->analyzeBundle($bundle);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    // Short diagnostic summary to CI logs; full per-operation data stays in artifact.
    fwrite(STDERR, "OpenAPI acceptance: " . $report['summary']['generated'] . '/'
        . $report['operationCount'] . " isolated operations generated; full source: "
        . $report['full']['status'] . ".\n");
    foreach ($report['compatibility']['diagnostics'] as $diagnostic) {
        fwrite(STDERR, "Compatibility {$diagnostic->severity} [{$diagnostic->code}] {$diagnostic->source}#{$diagnostic->pointer}: {$diagnostic->message}\n");
    }
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
    $drift = $exception instanceof \Dmorenof\SdkGenerator\Parsing\FileReferenceException && $exception->diagnostic->code === 'source_hash_mismatch';
    fwrite(STDERR, ($drift ? 'SPECIFICATION DRIFT: ' : 'Acceptance analysis failed: ') . $exception->getMessage() . "\n");
    exit($drift ? 3 : 2);
}
