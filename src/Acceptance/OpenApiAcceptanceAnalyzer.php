<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Acceptance;

use Dmorenof\SdkGenerator\Generation\PhpExecutableEmitter;
use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Strict, non-mutating compatibility measurement. Every operation is attempted
 * against the source-defined schemas it references, then the complete API is
 * attempted as a separate acceptance gate. Nothing is patched or ignored.
 */
final class OpenApiAcceptanceAnalyzer
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @return array<string, mixed> */
    public function analyze(string $source): array
    {
        try {
            $document = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON in acceptance input.', 0, $exception);
        }
        if (!is_array($document) || array_is_list($document) || !is_array($document['paths'] ?? null)) {
            throw new InvalidArgumentException('The acceptance input must be an OpenAPI/Swagger JSON object with paths.');
        }

        $operations = [];
        $paths = $document['paths'];
        ksort($paths, SORT_STRING);
        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem)) {
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
                    $subset = $this->operationSubset($document, $path, $method);
                    $result = $this->attempt(json_encode($subset, JSON_THROW_ON_ERROR));
                } catch (Throwable $exception) {
                    $result = $this->failure('selection_failed', $exception);
                }
                $operations[] = [...$entry, ...$result];
            }
        }

        $full = $this->attempt($source);
        $counts = [
            'generated' => 0,
            'normalization_failed' => 0,
            'generation_failed' => 0,
            'selection_failed' => 0,
        ];
        foreach ($operations as $operation) {
            ++$counts[$operation['status']];
        }

        return [
            'sourceSha256' => hash('sha256', $source),
            'openapi' => $document['openapi'] ?? $document['swagger'] ?? null,
            'title' => $document['info']['title'] ?? null,
            'pathCount' => count($paths),
            'operationCount' => count($operations),
            'schemaCount' => count($document['components']['schemas'] ?? $document['definitions'] ?? []),
            'summary' => $counts,
            'full' => $full,
            'operations' => $operations,
            'accepted' => $full['status'] === 'generated' && $counts['generated'] === count($operations),
        ];
    }

    /** @return array{status:string,error:?array<string,string>} */
    private function attempt(string $source): array
    {
        try {
            $contract = (new SpecificationNormalizer())->fromJson($source);
        } catch (Throwable $exception) {
            return $this->failure('normalization_failed', $exception);
        }
        try {
            (new PhpExecutableEmitter('Acceptance\\Probe', 'example/acceptance-probe'))->emit($contract);
        } catch (Throwable $exception) {
            return $this->failure('generation_failed', $exception);
        }
        return ['status' => 'generated', 'error' => null];
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
    private function operationSubset(array $document, string $path, string $method): array
    {
        $item = $document['paths'][$path];
        $kept = [$method => $item[$method]];
        if (isset($item['parameters'])) {
            $kept['parameters'] = $item['parameters'];
        }
        $document['paths'] = [$path => $kept];
        $definitionsKey = ($document['swagger'] ?? null) === '2.0' ? 'definitions' : 'schemas';
        $allDefinitions = $definitionsKey === 'definitions'
            ? ($document['definitions'] ?? [])
            : ($document['components']['schemas'] ?? []);

        if (!is_array($allDefinitions)) {
            return $document; // Let the normalizer report invalid source structure.
        }

        $selected = [];
        $queue = [];
        $this->findReferences($kept, $queue);
        for ($i = 0; $i < count($queue); ++$i) {
            $reference = $queue[$i];
            $prefix = $definitionsKey === 'definitions'
                ? '#/definitions/' : '#/components/schemas/';
            if (!str_starts_with($reference, $prefix)) {
                continue; // An unsupported reference remains unchanged in its original usage.
            }
            $encoded = substr($reference, strlen($prefix));
            $name = str_replace(['~1', '~0'], ['/', '~'], $encoded);
            if (!array_key_exists($name, $allDefinitions) || array_key_exists($name, $selected)) {
                continue;
            }
            $selected[$name] = $allDefinitions[$name];
            $this->findReferences($allDefinitions[$name], $queue);
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
    private function findReferences(mixed $value, array &$queue): void
    {
        if (!is_array($value)) {
            return;
        }
        if (is_string($value['$ref'] ?? null) && !in_array($value['$ref'], $queue, true)) {
            $queue[] = $value['$ref'];
        }
        foreach ($value as $child) {
            $this->findReferences($child, $queue);
        }
    }
}
