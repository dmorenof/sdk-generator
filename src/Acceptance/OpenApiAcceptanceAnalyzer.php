<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Acceptance;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Diagnostics\ContractAuditor;
use Dmorenof\SdkGenerator\Diagnostics\Diagnostic;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Parsing\LocalObjectResolver;
use Dmorenof\SdkGenerator\Parsing\LocalReferenceException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Strict, non-mutating compatibility measurement. Every operation is attempted
 * against the source-defined schemas it references, then the complete API is
 * attempted as a separate acceptance gate. Nothing is patched or ignored.
 */
final class OpenApiAcceptanceAnalyzer
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    public function analyzeFile(string $file, ?string $referenceRoot = null): array
    {
        return $this->analyzeBundle((new \Dmorenof\SdkGenerator\Parsing\LocalFileBundler())->bundle($file, $referenceRoot));
    }

    public function analyzeBundle(\Dmorenof\SdkGenerator\Parsing\DocumentBundle $bundle): array
    {
        $json = json_encode($bundle->document, JSON_THROW_ON_ERROR);
        $report = $this->analyze($json, sourceName: $bundle->source);
        $report['sourceSha256'] = $bundle->sourceSha256;
        $report['bundleSha256'] = hash('sha256', $json);
        $report['sources'] = $bundle->sources;
        $report['compatibility']['diagnostics'] = array_map($bundle->mapDiagnostic(...), $report['compatibility']['diagnostics']);
        $report['full']['diagnostics'] = array_map($bundle->mapDiagnostic(...), $report['full']['diagnostics'] ?? []);
        foreach ($report['operations'] as &$operation) {
            if (isset($operation['diagnostics'])) {
                $operation['diagnostics'] = array_map($bundle->mapDiagnostic(...), $operation['diagnostics']);
            }
        }
        unset($operation);
        return $report;
    }

    /** @return array<string, mixed> */
    public function analyze(string $source, string $format = 'json', string $sourceName = '<input>'): array
    {
        $document = $this->decodeDocument($source, $format);
        if (!is_array($document) || array_is_list($document) || !is_array($document['paths'] ?? null)) {
            throw new InvalidArgumentException('The acceptance input must be an OpenAPI/Swagger object with paths.');
        }

        $operations = [];
        $resolver = new LocalObjectResolver($document);
        $selectionDocument = $document;
        $declarationOrigins = [];
        // Materialize reusable Path Items before dropping other actual paths.
        // Their field origins retain the original source and reference chains.
        foreach ($document['components']['pathItems'] ?? [] as $name => $item) {
            if (!is_array($item)) {
                continue;
            }
            $pointer = JsonPointer::append('/components/pathItems', $name);
            try {
                $resolved = $resolver->resolve($item, 'pathItems', $pointer);
                $selectionDocument['components']['pathItems'][$name] = $resolved['value'];
                $declarationOrigins[$pointer] = $resolved['origins'];
            } catch (LocalReferenceException) {
                // The full audit retains the precise failure on the original source.
            }
        }
        $unresolvedPaths = 0;
        $paths = array_filter($document['paths'], static fn ($path): bool => !is_string($path) || !str_starts_with($path, 'x-'), ARRAY_FILTER_USE_KEY);
        ksort($paths, SORT_STRING);
        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            $pointer = JsonPointer::append('/paths', $path);
            try {
                $resolved = $resolver->resolve($pathItem, 'pathItems', $pointer);
                $pathItem = $resolved['value'];
            } catch (LocalReferenceException) {
                ++$unresolvedPaths;
                continue;
            }
            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }
                $name = is_array($pathItem[$method])
                    ? ($pathItem[$method]['operationId'] ?? null) : null;
                $entry = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'operationId' => is_string($name) ? $name : null,
                ];
                try {
                    $subset = $this->operationSubset($selectionDocument, $path, $method, $pathItem);
                    $result = $this->attempt(json_encode($subset, JSON_THROW_ON_ERROR), 'json', $subset, $sourceName,
                        [$pointer => $resolved['origins']] + $declarationOrigins);
                } catch (Throwable $exception) {
                    $result = $this->failure('selection_failed', $exception);
                }
                $operations[] = [...$entry, ...$result];
            }
        }

        $full = $this->attempt($source, $format, $document, $sourceName);
        $counts = [
            'generated' => 0,
            'normalization_failed' => 0,
            'generation_failed' => 0,
            'selection_failed' => 0,
            'compatibility_failed' => 0,
        ];
        foreach ($operations as $operation) {
            ++$counts[$operation['status']];
        }

        return [
            'sourceSha256' => hash('sha256', $source),
            'source' => $sourceName,
            'compatibility' => [
                'status' => $full['status'] === 'normalization_failed' ? 'unsupported'
                    : ($full['status'] === 'generated' && ($full['diagnostics'] ?? []) === [] ? 'supported' : 'partial'),
                'strictlyAccepted' => $full['status'] === 'generated',
                'diagnostics' => $full['diagnostics'] ?? [],
            ],
            'openapi' => $document['openapi'] ?? $document['swagger'] ?? null,
            'title' => $document['info']['title'] ?? null,
            'pathCount' => count($paths),
            'unresolvedPathCount' => $unresolvedPaths,
            'operationCount' => count($operations),
            'schemaCount' => count($document['components']['schemas'] ?? $document['definitions'] ?? []),
            'summary' => $counts,
            'full' => $full,
            'operations' => $operations,
            'accepted' => $full['status'] === 'generated' && $counts['generated'] === count($operations),
        ];
    }

    private function decodeDocument(string $source, string $format): mixed
    {
        if ($format === 'json') {
            try {
                return json_decode($source, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid JSON in acceptance input.', 0, $exception);
            }
        }
        if ($format === 'yaml') {
            try {
                return Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (ParseException $exception) {
                throw new InvalidArgumentException('Invalid YAML in acceptance input.', 0, $exception);
            }
        }

        throw new InvalidArgumentException("Unsupported acceptance input format: $format.");
    }

    /** @return array{status:string,error:?array<string,string>,diagnostics:list<Diagnostic>} */
    private function attempt(string $source, string $format, array $document, string $sourceName, array $pathItemOrigins = []): array
    {
        $diagnostics = (new ContractAuditor())->audit($document, $sourceName, $pathItemOrigins);
        try {
            $normalizer = new SpecificationNormalizer();
            $contract = $format === 'yaml'
                ? $normalizer->fromYaml($source)
                : $normalizer->fromJson($source);
        } catch (Throwable $exception) {
            return [...$this->failure('normalization_failed', $exception), 'diagnostics' => $diagnostics];
        }
        try {
            (new PhpExecutableEmitter('Acceptance\\Probe', 'example/acceptance-probe'))->emit($contract);
        } catch (Throwable $exception) {
            return [...$this->failure('generation_failed', $exception), 'diagnostics' => $diagnostics];
        }
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === 'error') {
                return [...$this->failure('compatibility_failed', new InvalidArgumentException(
                    $diagnostic->message . ' [' . $diagnostic->source . '#' . $diagnostic->pointer . ']'
                )), 'diagnostics' => $diagnostics];
            }
        }
        return ['status' => 'generated', 'error' => null, 'diagnostics' => $diagnostics];
    }

    /** @return array{status:string,error:array<string,string>} */
    private function failure(string $status, Throwable $exception): array
    {
        return ['status' => $status, 'error' => [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ]];
    }

    /**
     * Keep one operation and its transitive schema-reference closure.
     * This is diagnostic isolation, NOT a modified contract accepted as a
     * replacement for the original: full-source generation is still required.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function operationSubset(array $document, string $path, string $method, array $item): array
    {
        // Keep effective Path Item metadata as well as the selected operation.
        $kept = array_diff_key($item, array_fill_keys(self::METHODS, true));
        $kept[$method] = $item[$method];
        $document['paths'] = [$path => $kept];
        $definitionsKey = ($document['swagger'] ?? null) === '2.0' ? 'definitions' : 'schemas';
        $allDefinitions = $definitionsKey === 'definitions'
            ? ($document['definitions'] ?? [])
            : ($document['components']['schemas'] ?? []);

        if (!is_array($allDefinitions)) {
            return $document; // Let the normalizer report invalid source structure.
        }

        $selected = [];
        $polymorphic = [];
        $queue = [];
        $prefix = $definitionsKey === 'definitions' ? '#/definitions/' : '#/components/schemas/';
        $this->findReferences($kept, $queue, $prefix);
        // Retained reusable declarations must keep their schema dependencies,
        // including those hidden behind a parameter/body/response alias.
        $this->findReferences(array_diff_key($document['components'] ?? [], ['schemas' => true]), $queue, $prefix);
        $this->findReferences($document['parameters'] ?? [], $queue, $prefix);
        $this->findReferences($document['responses'] ?? [], $queue, $prefix);
        $visitedObjects = [];
        for ($i = 0; $i < count($queue); ++$i) {
            $reference = $queue[$i];
            $prefix = $definitionsKey === 'definitions'
                ? '#/definitions/' : '#/components/schemas/';
            if (!str_starts_with($reference, $prefix)) {
                if (!isset($visitedObjects[$reference]) && str_starts_with($reference, '#')) {
                    $visitedObjects[$reference] = true;
                    try {
                        $target = JsonPointer::resolve($document, $reference);
                        $this->findReferences($target, $queue, $prefix);
                    } catch (InvalidArgumentException) {
                        // Preserve the original reference for the audit/normalizer.
                    }
                }
                continue;
            }
            $encoded = substr($reference, strlen($prefix));
            $name = str_replace(['~1', '~0'], ['/', '~'], $encoded);
            if (!array_key_exists($name, $allDefinitions) || array_key_exists($name, $selected)) {
                continue;
            }
            $selected[$name] = $allDefinitions[$name];
            $this->findReferences($allDefinitions[$name], $queue, $prefix);
            $definition = $allDefinitions[$name];
            $parents = array_column($definition['allOf'] ?? [], '$ref');
            if (isset($definition['discriminator']) || array_intersect($parents, array_keys($polymorphic)) !== []) {
                $polymorphic[$reference] = true;
                foreach ($allDefinitions as $child => $candidate) {
                    $bases = array_column($candidate['allOf'] ?? [], '$ref');
                    if ($bases === [$reference] && !in_array($prefix . $child, $queue, true)) {
                        $queue[] = $prefix . $child;
                    }
                }
            }
        }
        ksort($selected, SORT_STRING);
        if ($definitionsKey === 'definitions') {
            $document['definitions'] = $selected;
        } else {
            $document['components']['schemas'] = $selected;
        }
        return $document;
    }

    /** @param list<string> $queue */
    private function findReferences(mixed $value, array &$queue, string $prefix, string $context = 'object'): void
    {
        if (!is_array($value)) {
            return;
        }
        if (is_string($value['$ref'] ?? null) && !in_array($value['$ref'], $queue, true)) {
            $queue[] = $value['$ref'];
        }
        foreach ($value['discriminator']['mapping'] ?? [] as $target) {
            if (is_string($target)) {
                $reference = str_starts_with($target, '#/') || str_contains($target, ':') || str_contains($target, '/')
                    ? $target : $prefix . $target;
                if (!in_array($reference, $queue, true)) {
                    $queue[] = $reference;
                }
            }
        }
        $map = in_array($context, ['map', 'links'], true);
        foreach ($value as $key => $child) {
            if (!$map && (in_array($key, ['example', 'examples', 'default', 'enum', 'const', 'value'], true)
                || ($context === 'link' && in_array($key, ['parameters', 'requestBody'], true))
                || str_starts_with((string) $key, 'x-'))) {
                continue;
            }
            $childContext = $context === 'links' ? 'link' : 'object';
            if (!$map && $key === 'links') {
                $childContext = 'links';
            } elseif (!$map && in_array($key, ['properties', 'patternProperties', 'dependentSchemas', 'schemas', 'definitions', '$defs', 'headers',
                'parameters', 'requestBodies', 'responses', 'callbacks', 'pathItems', 'encoding', 'securitySchemes'], true)) {
                $childContext = 'map';
            }
            $this->findReferences($child, $queue, $prefix, $childContext);
        }
    }
}
