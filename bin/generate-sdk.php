#!/usr/bin/env php
<?php
declare(strict_types=1);

require $_composer_autoload_path ?? __DIR__ . '/../vendor/autoload.php';

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Generation\PhpApiNameResolver;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;

if (!in_array($argc, [4, 5], true)) {
    fwrite(STDERR, "Usage: php bin/generate-sdk.php <swagger-or-openapi.json|yaml|yml> <output-directory> [Php\\Namespace] <vendor/package>\n");
    exit(2);
}

if ($argc === 5) {
    [, $input, $output, $namespace, $packageName] = $argv;
} else {
    [, $input, $output, $packageName] = $argv;
    $namespace = null; // Use the API's declared info.title, not schema prefixes.
}

try {
    if (!is_file($input) || !is_readable($input)) {
        throw new RuntimeException("Specification is missing or unreadable: $input");
    }
    if (file_exists($output)) {
        if (!is_dir($output)) {
            throw new RuntimeException("Destination exists but is not a directory: $output");
        }
        if ((new FilesystemIterator($output))->valid()) {
            throw new RuntimeException("Destination must be empty to avoid overwriting files: $output");
        }
    }

    $source = file_get_contents($input);
    if ($source === false) {
        throw new RuntimeException("Unable to read specification: $input");
    }

    // Build and validate all files before writing anything to disk.
    $normalizer = new SpecificationNormalizer();
    $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
    $contract = in_array($extension, ['yaml', 'yml'], true)
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
