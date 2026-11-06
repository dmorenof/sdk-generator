<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Symfony\Component\Yaml\Yaml;

/** String inspection stays in memory; explicit file inspection reads a confined local graph. */
final class DocumentInspector
{
    public function inspectFile(string $file, ?string $referenceRoot = null): array
    {
        try {
            return $this->inspectBundle((new \Dmorenof\SdkGenerator\Parsing\LocalFileBundler())->bundle($file, $referenceRoot));
        } catch (\Dmorenof\SdkGenerator\Parsing\FileReferenceException $exception) {
            return [
                'source' => realpath($file) ?: $file,
                'sourceSha256' => null,
                'status' => 'unsupported', 'normalized' => false, 'generatable' => false, 'strictlyAccepted' => false,
                'diagnostics' => [$exception->diagnostic],
            ];
        }
    }

    public function inspectBundle(\Dmorenof\SdkGenerator\Parsing\DocumentBundle $bundle): array
    {
        $json = json_encode($bundle->document, JSON_THROW_ON_ERROR);
        $report = $this->inspect($json, sourceName: $bundle->source);
        $report['sourceSha256'] = $bundle->sourceSha256;
        $report['bundleSha256'] = hash('sha256', $json);
        $report['sources'] = $bundle->sources;
        $report['diagnostics'] = array_map($bundle->mapDiagnostic(...), $report['diagnostics']);
        return $report;
    }

    public function inspect(string $source, string $format = 'json', string $sourceName = '<input>'): array
    {
        $diagnostics = [];
        $normalized = false;
        $generated = false;
        try {
            $document = match ($format) {
                'json' => json_decode($source, true, 512, JSON_THROW_ON_ERROR),
                'yaml' => Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE),
                default => throw new \InvalidArgumentException("Unsupported input format: $format"),
            };
            if (!is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException('API specification must be an object.');
            }
            $diagnostics = (new ContractAuditor())->audit($document, $sourceName);
            $normalizer = new SpecificationNormalizer();
            $contract = $format === 'yaml' ? $normalizer->fromYaml($source) : $normalizer->fromJson($source);
            $normalized = true;
            (new PhpExecutableEmitter('Inspection\\Probe', 'example/inspection-probe'))->emit($contract);
            $generated = true;
        } catch (\Throwable $exception) {
            $diagnostics[] = new Diagnostic($normalized ? 'generation_failed' : 'normalization_failed', 'error', '', $exception->getMessage(), $sourceName);
        }
        usort($diagnostics, static fn (Diagnostic $a, Diagnostic $b): int => [$a->pointer, $a->code, $a->operationId] <=> [$b->pointer, $b->code, $b->operationId]);
        $errors = count(array_filter($diagnostics, static fn (Diagnostic $diagnostic): bool => $diagnostic->severity === 'error'));
        return [
            'source' => $sourceName,
            'sourceSha256' => hash('sha256', $source),
            'status' => !$normalized ? 'unsupported' : ($diagnostics !== [] || !$generated ? 'partial' : 'supported'),
            'normalized' => $normalized,
            'generatable' => $generated,
            'strictlyAccepted' => $generated && $errors === 0,
            'diagnostics' => $diagnostics,
        ];
    }
}
