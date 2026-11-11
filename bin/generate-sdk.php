#!/usr/bin/env php
<?php
declare(strict_types=1);

require $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Generation\PhpApiNameResolver;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;

$arguments = [];
$inspect = false;
$strict = false;
$resolveLocal = false;
$validateFormats = false;
$referenceRoot = null;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--inspect', '--dry-run'], true)) {
        $inspect = true;
    } elseif ($argument === '--strict') {
        $strict = true;
    } elseif ($argument === '--validate-formats') {
        $validateFormats = true;
    } elseif ($argument === '--resolve-local-references') {
        $resolveLocal = true;
    } elseif (str_starts_with($argument, '--reference-root=')) {
        $referenceRoot = substr($argument, strlen('--reference-root='));
    } else {
        $arguments[] = $argument;
    }
}
if (($referenceRoot !== null && !$resolveLocal) || ($inspect && count($arguments) !== 1) || (!$inspect && !in_array(count($arguments), [3, 4], true))) {
    fwrite(STDERR, "Usage: generate-sdk.php [--strict] [--validate-formats] <spec.json|yaml> <output-directory> [Php\\Namespace] <vendor/package>\n"
        . "       generate-sdk.php --inspect|--dry-run <spec.json|yaml>\n"
        . "       Add --validate-formats to assert declared string/integer formats (date-time, uuid, email, ...) at runtime.\n"
        . "       Add --resolve-local-references [--reference-root=directory] for confined local JSON/YAML files.\n");
    exit(2);
}
$input = $arguments[0];
if (!$inspect) {
    $output = $arguments[1];
    $namespace = count($arguments) === 4 ? $arguments[2] : null;
    $packageName = $arguments[count($arguments) - 1];
}

try {
    if (!is_file($input) || !is_readable($input)) {
        throw new RuntimeException("Specification is missing or unreadable: $input");
    }
    $bundle = null;
    if ($resolveLocal) {
        try {
            $bundle = (new \Dmorenof\SdkGenerator\Parsing\LocalFileBundler())->bundle($input, $referenceRoot);
        } catch (\Dmorenof\SdkGenerator\Parsing\FileReferenceException $exception) {
            if ($inspect) {
                echo json_encode((new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector())->inspectFile($input, $referenceRoot), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
                exit(1);
            }
            throw $exception;
        }
    }
    $source = $bundle === null ? file_get_contents($input) : json_encode($bundle->document, JSON_THROW_ON_ERROR);
    if ($source === false) {
        throw new RuntimeException("Unable to read specification: $input");
    }

    $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
    $format = $bundle === null && in_array($extension, ['yaml', 'yml'], true) ? 'yaml' : 'json';
    $inspector = new \Dmorenof\SdkGenerator\Diagnostics\DocumentInspector();
    $report = $bundle === null ? $inspector->inspect($source, $format, $input) : $inspector->inspectBundle($bundle);
    if ($inspect) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit($report['strictlyAccepted'] ? 0 : 1);
    }
    foreach ($report['diagnostics'] as $diagnostic) {
        fwrite(STDERR, "Compatibility {$diagnostic->severity} [{$diagnostic->code}] {$diagnostic->source}#{$diagnostic->pointer}: {$diagnostic->message}\n");
    }
    if ($strict && !$report['strictlyAccepted']) {
        throw new RuntimeException('Strict compatibility inspection rejected this document; no files were generated.');
    }
    if (file_exists($output)) {
        if (!is_dir($output)) {
            throw new RuntimeException("Destination exists but is not a directory: $output");
        }
        if ((new FilesystemIterator($output))->valid()) {
            throw new RuntimeException("Destination must be empty to avoid overwriting files: $output");
        }
    }

    // Build and validate all files before writing anything to disk.
    $normalizer = new SpecificationNormalizer($validateFormats);
    $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
    $contract = $bundle === null && in_array($extension, ['yaml', 'yml'], true)
        ? $normalizer->fromYaml($source)
        : $normalizer->fromJson($source);
    $namespace ??= PhpApiNameResolver::fromTitle($contract->title);
    $files = (new PhpExecutableEmitter($namespace, $packageName))->emit($contract);

    foreach ($files as $relative => $content) {
        if (str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException("Unsafe generated output path: $relative");
        }
    }

    if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
        throw new RuntimeException("Unable to create output directory: $output");
    }
    foreach ($files as $relative => $content) {
        $destination = rtrim($output, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: $directory");
        }
        if (file_put_contents($destination, $content) === false) {
            throw new RuntimeException("Unable to write: $destination");
        }
    }

    // Formatting is a build-time concern. Generated packages stay tiny and
    // do not depend on PHP-CS-Fixer. Use the same PSR-12 rules as the project.
    $fixerClass = new ReflectionClass(\PhpCsFixer\Config::class);
    $fixerRoot = dirname((string) $fixerClass->getFileName(), 2);
    $fixerBinary = $fixerRoot . '/php-cs-fixer';
    if (!is_file($fixerBinary)) {
        throw new RuntimeException('PHP-CS-Fixer executable is missing from the generator dependencies.');
    }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixerBinary)
        . ' fix ' . escapeshellarg(rtrim($output, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src')
        . ' --rules=' . escapeshellarg('@PSR12')
        . ' --using-cache=no --show-progress=none --quiet 2>&1';
    exec($command, $formatterOutput, $formatterCode);
    if ($formatterCode !== 0) {
        throw new RuntimeException("PSR-12 formatter failed:\n" . implode("\n", $formatterOutput));
    }

    fwrite(STDOUT, "Generated " . count($files) . " files in $output\n");
    fwrite(STDOUT, "Source: " . $input . "\n");
    fwrite(STDOUT, "Generated SDK uses Guzzle 7 by default (PSR-18); a custom client or transport can be injected.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Generation failed: ' . $error->getMessage() . "\n");
    exit(1);
}
