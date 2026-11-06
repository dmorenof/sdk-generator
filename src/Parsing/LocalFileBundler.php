<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Diagnostics\Diagnostic;
use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;
use Symfony\Component\Yaml\Yaml;

/** Explicit, confined file loading. Never uses a URI stream or makes a network request. */
final class LocalFileBundler
{
    private array $documents = [];
    private array $hashes = [];
    private array $imports = [];
    private array $names = [];
    private array $origins = [];
    private array $aliases = [];
    private array $implicitDiscriminators = [];
    private array $output = [];
    private string $rootFile;
    private string $directory;
    private bool $swagger;
    private int $bytes = 0;
    private int $nodes = 0;
    private int $payloadNodes = 0;
    private int $payloadBytes = 0;
    private ?string $family = null;
    private ?string $expectedRootHash = null;

    public function __construct(
        private readonly int $maxFileBytes = 2_097_152,
        private readonly int $maxTotalBytes = 16_777_216,
        private readonly int $maxDocuments = 64,
    ) {
        if (min($maxFileBytes, $maxTotalBytes, $maxDocuments) < 1) {
            throw new \InvalidArgumentException('File reference limits must be positive.');
        }
    }

    public function bundle(string $file, ?string $referenceRoot = null, ?string $expectedRootHash = null): DocumentBundle
    {
        $root = realpath($file);
        if ($root === false || !is_file($root)) {
            $this->fail('unreadable_reference', 'Root specification is missing or unreadable.', $file, '', []);
        }
        $directory = $referenceRoot !== null && trim($referenceRoot) === '' ? false : realpath($referenceRoot ?? dirname($root));
        if ($directory === false || !is_dir($directory)) {
            $this->fail('reference_root', 'Reference root must be an existing directory.', $root, '', []);
        }
        $this->rootFile = $root;
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->documents = $this->hashes = $this->imports = $this->names = $this->origins = $this->aliases = [];
        $this->implicitDiscriminators = [];
        $this->bytes = $this->nodes = 0;
        $this->payloadNodes = $this->payloadBytes = 0;
        $this->family = null;
        $this->expectedRootHash = $expectedRootHash;
        $document = $this->load($root, $root, '', []);
        $this->family = $this->dialectFamily($document);
        $this->swagger = ($document['swagger'] ?? null) === '2.0';
        $this->output = $document;
        foreach ($this->catalogs() as $kind => $path) {
            $catalog = $this->catalog($document, $path);
            foreach ($catalog as $name => $_) {
                $this->names[$kind][strtolower((string) $name)] = true;
                $at = $this->pointer([...$path, (string) $name]);
                $this->imports[$kind . ':' . $root . '#' . $at] = '#' . $at;
            }
        }
        // Root schema aliases name public PHP models. Reserve their identities
        // before following references, including references back from another file.
        $schemaPath = $this->catalogs()['schemas'];
        $schemas = $this->catalog($document, $schemaPath);
        ksort($schemas);
        foreach ($schemas as $name => $schema) {
            if (!is_array($schema) || !is_string($schema['$ref'] ?? null) || str_starts_with($schema['$ref'], '#')) {
                continue;
            }
            $at = $this->pointer([...$schemaPath, (string) $name]);
            if (count($schema) !== 1) {
                $this->fail('unsupported_reference_siblings', 'External root schema aliases must contain only $ref in this release.', $root, $at, [$schema['$ref']]);
            }
            [$targetFile, $targetPointer, $keys, $chain] = $this->schemaTarget($schema['$ref'], $root, "$at/\$ref", []);
            foreach ($keys as $key) {
                if (isset($this->imports['schemas:' . $key]) && $this->imports['schemas:' . $key] !== '#' . $at) {
                    $this->fail('ambiguous_schema_alias', 'The same external schema has multiple public root names.', $root, $at, $chain);
                }
                $this->imports['schemas:' . $key] = '#' . $at;
            }
            $this->aliases[$at] = [$targetFile, $targetPointer, $chain];
        }
        $walked = $this->walk($document, 'document', $root, '', [], 0);
        if (count($this->hashes) > 1 && $this->implicitDiscriminators !== []) {
            [$source, $pointer, $chain] = $this->implicitDiscriminators[0];
            $this->fail('unsupported_external_discriminator', 'Multi-document discriminators require explicit mappings in this release.', $source, $pointer, $chain);
        }
        // Imports created during traversal must survive root catalog assignment.
        foreach ($this->catalogs() as $kind => $path) {
            $original = $this->catalog($walked, $path);
            $imported = $this->catalog($this->output, $path);
            $this->setCatalog($walked, $path, $original + $imported);
        }
        ksort($this->hashes);
        return new DocumentBundle($walked, $root, $this->hashes[$root], $this->hashes, $this->origins);
    }

    private function catalogs(): array
    {
        return $this->swagger ? ['schemas' => ['definitions'], 'parameters' => ['parameters'], 'responses' => ['responses']]
            : array_combine(['schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'pathItems'],
                array_map(static fn ($kind): array => ['components', $kind], ['schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'pathItems']));
    }

    private function catalog(array $document, array $path): array
    {
        foreach ($path as $key) {
            $document = $document[$key] ?? [];
            if (!is_array($document)) {
                return [];
            }
        }
        return $document;
    }

    private function setCatalog(array &$document, array $path, array $value): void
    {
        if ($value === [] && $this->catalog($document, $path) === []) {
            return;
        }
        $slot = &$document;
        foreach ($path as $key) {
            $slot = &$slot[$key];
        }
        $slot = $value;
    }

    private function pointer(array $tokens): string
    {
        $pointer = '';
        foreach ($tokens as $token) {
            $pointer = JsonPointer::append($pointer, $token);
        }
        return $pointer;
    }

    private function load(string $file, string $source, string $pointer, array $chain): array
    {
        if (isset($this->documents[$file])) {
            return $this->documents[$file];
        }
        if (!str_starts_with($file, $this->directory) || !is_file($file) || !is_readable($file)) {
            $this->fail('reference_access', 'Reference must be a readable regular file inside the configured root.', $source, $pointer, $chain);
        }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            $this->fail('reference_format', 'Only JSON/YAML files are supported.', $source, $pointer, $chain);
        }
        if (count($this->documents) >= $this->maxDocuments) {
            $this->fail('reference_limit', 'Maximum number of source documents exceeded.', $source, $pointer, $chain);
        }
        $bytes = @file_get_contents($file, false, null, 0, $this->maxFileBytes + 1);
        if ($bytes === false) {
            $this->fail('unreadable_reference', 'Unable to read the referenced source.', $source, $pointer, $chain);
        }
        if (strlen($bytes) > $this->maxFileBytes || $this->bytes + strlen($bytes) > $this->maxTotalBytes) {
            $this->fail('reference_limit', 'Source document byte limit exceeded.', $source, $pointer, $chain);
        }
        $hash = hash('sha256', $bytes);
        if ($file === $this->rootFile && $this->expectedRootHash !== null && !hash_equals(strtolower($this->expectedRootHash), $hash)) {
            $this->fail('source_hash_mismatch', "Root SHA-256 does not match the pinned input: expected {$this->expectedRootHash}, received $hash.", $file, '', []);
        }
        try {
            $document = $extension === 'json' ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($bytes, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (\Throwable $exception) {
            $this->fail('invalid_reference_document', 'Invalid JSON/YAML source: ' . $exception->getMessage(), $source, $pointer, $chain);
        }
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            $this->fail('invalid_reference_document', 'Source document must be an object.', $source, $pointer, $chain);
        }
        $family = $this->dialectFamily($document);
        if ($this->family !== null && $family !== null && $family !== $this->family) {
            $this->fail('unsupported_reference_dialect', 'Mixed Swagger/OpenAPI dialect families are not bundled.', $source, $pointer, $chain);
        }
        if (isset($document['jsonSchemaDialect'])) {
            $this->fail('unsupported_reference_scope', 'Custom JSON Schema dialect scopes are not bundled.', $source, $pointer, $chain);
        }
        $this->accountPayload($document, $source, $pointer, $chain);
        $this->bytes += strlen($bytes);
        $this->hashes[$file] = $hash;
        return $this->documents[$file] = $document;
    }

    private function accountPayload(mixed $value, string $source, string $pointer, array $chain, int $depth = 0): void
    {
        $this->payloadBytes += is_string($value) ? strlen($value) : 16;
        if (++$this->payloadNodes > 100_000 || $depth > 256 || $this->payloadBytes > $this->maxTotalBytes * 4) {
            $this->fail('reference_limit', 'Decoded/materialized payload node, byte or depth limit exceeded.', $source, $pointer, $chain);
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->payloadBytes += strlen((string) $key);
                $this->accountPayload($child, $source, $pointer, $chain, $depth + 1);
            }
        }
    }

    private function dialectFamily(array $document): ?string
    {
        if (isset($document['swagger'])) {
            return 'swagger:' . $document['swagger'];
        }
        return isset($document['openapi']) ? 'openapi:' . implode('.', array_slice(explode('.', (string) $document['openapi']), 0, 2)) : null;
    }

    private function target(string $reference, string $file, string $pointer, array $chain): array
    {
        $chain[] = $reference;
        [$path, $fragment] = array_pad(explode('#', $reference, 2), 2, '');
        if (preg_match('/%(?![0-9a-f]{2})/i', $path) || str_contains($path, '?')) {
            $this->fail('invalid_reference', 'Invalid local file reference URI.', $file, $pointer, $chain);
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || preg_match('//u', $path) !== 1 || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
            $this->fail('unsupported_reference_uri', 'Only relative local file paths and JSON Pointer fragments are supported; no network or URI streams.', $file, $pointer, $chain);
        }
        $target = $path === '' ? $file : realpath(dirname($file) . '/' . $path);
        if ($target === false) {
            $this->fail('unresolved_reference', 'Referenced file does not exist.', $file, $pointer, $chain);
        }
        $this->load($target, $file, $pointer, $chain);
        try {
            $tokens = JsonPointer::tokens('#' . $fragment);
            $ancestor = $this->documents[$target];
            foreach ([...$tokens, null] as $token) {
                if (is_array($ancestor)) {
                    foreach (['$id', '$anchor', '$dynamicAnchor', '$schema'] as $scope) {
                        if (is_string($ancestor[$scope] ?? null)) {
                            $this->fail('unsupported_reference_scope', 'Reference target has an unsupported schema resource scope.', $file, $pointer, $chain);
                        }
                    }
                }
                $ancestor = is_array($ancestor) && $token !== null ? ($ancestor[$token] ?? null) : null;
            }
            JsonPointer::resolve($this->documents[$target], '#' . $fragment);
        } catch (FileReferenceException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            $this->fail('unresolved_reference', $exception->getMessage(), $file, $pointer, $chain);
        }
        return [$target, $this->pointer($tokens)];
    }

    /** Follow pure schema aliases without emitting unsupported PHP alias models. */
    private function schemaTarget(string $reference, string $file, string $pointer, array $chain): array
    {
        $seen = [];
        $keys = [];
        for ($hop = 0; $hop < 128; ++$hop) {
            [$targetFile, $targetPointer] = $this->target($reference, $file, $pointer, $chain);
            $chain[] = $reference;
            $key = $targetFile . '#' . $targetPointer;
            if (isset($seen[$key])) {
                $this->fail('reference_cycle', 'Pure schema aliases form a cycle.', $file, $pointer, $chain);
            }
            $seen[$key] = true;
            $keys[] = $key;
            $target = JsonPointer::resolve($this->documents[$targetFile], '#' . $targetPointer);
            if (!is_array($target) || !array_key_exists('$ref', $target)) {
                return [$targetFile, $targetPointer, $keys, $chain];
            }
            if (count($target) !== 1 || !is_string($target['$ref'])) {
                $this->fail('unsupported_reference_siblings', 'Imported schema aliases must contain only a string $ref in this release.', $targetFile, $targetPointer, $chain);
            }
            $reference = $target['$ref'];
            $file = $targetFile;
            $pointer = $targetPointer . '/$ref';
        }
        $this->fail('reference_limit', 'Schema alias depth exceeds 128.', $file, $pointer, $chain);
    }

    private function import(string $kind, string $reference, string $file, string $pointer, array $chain, int $depth): string
    {
        if (!isset($this->catalogs()[$kind]) || ($kind === 'pathItems' && !str_starts_with((string) ($this->output['openapi'] ?? ''), '3.1.'))) {
            $this->fail('unsupported_reference_kind', 'This external reference category/dialect is not supported.', $file, $pointer, [...$chain, $reference]);
        }
        if ($kind === 'schemas') {
            [$targetFile, $targetPointer, $aliases, $resolvedChain] = $this->schemaTarget($reference, $file, $pointer, $chain);
            foreach ($aliases as $alias) {
                if (isset($this->imports['schemas:' . $alias])) {
                    return $this->imports['schemas:' . $alias];
                }
            }
        } else {
            [$targetFile, $targetPointer] = $this->target($reference, $file, $pointer, $chain);
            $resolvedChain = [...$chain, $reference];
        }
        if ($kind !== 'schemas' && $targetFile !== $this->rootFile && $this->dialectFamily($this->documents[$targetFile]) !== null) {
            $this->fail('unsupported_external_document_context', 'HTTP references into another complete API document require server/security namespace handling, which is not implemented; use fragment-only files.',
                $file, $pointer, [...$chain, $reference]);
        }
        $key = $kind . ':' . $targetFile . '#' . $targetPointer;
        if (isset($this->imports[$key])) {
            return $this->imports[$key];
        }
        $tokens = JsonPointer::tokens('#' . $targetPointer);
        $name = $tokens === [] ? pathinfo($targetFile, PATHINFO_FILENAME) : end($tokens);
        $name = preg_replace('/[^a-z0-9_]/i', '_', (string) $name);
        if ($name === '' || ctype_digit($name[0])) {
            $name = 'External_' . $name;
        }
        $base = $name;
        $identity = substr($targetFile, strlen($this->directory)) . '#' . $targetPointer;
        if (isset($this->names[$kind][strtolower($name)])) {
            $name .= '_' . substr(hash('sha256', $identity), 0, 10);
        }
        if (isset($this->names[$kind][strtolower($name)])) {
            $this->fail('reference_name_collision', "Cannot assign a unique name for imported $base.", $file, $pointer, [...$chain, $reference]);
        }
        $this->names[$kind][strtolower($name)] = true;
        $at = $this->pointer([...$this->catalogs()[$kind], $name]);
        $this->imports[$key] = '#' . $at; // Reserve before following recursive schemas.
        foreach ($aliases ?? [] as $alias) {
            $this->imports['schemas:' . $alias] = '#' . $at;
        }
        $target = JsonPointer::resolve($this->documents[$targetFile], '#' . $targetPointer);
        if (!is_array($target) || ($target !== [] && array_is_list($target))) {
            $this->fail('invalid_reference_target', 'Reference target must be an object.', $file, $pointer, [...$chain, $reference]);
        }
        $this->accountPayload($target, $file, $pointer, $resolvedChain);
        $this->origins[$at] = ['source' => $targetFile, 'pointer' => $targetPointer];
        $value = $this->walk($target, $kind === 'schemas' ? 'schema' : $kind, $targetFile, $targetPointer, $resolvedChain, $depth + 1);
        $catalog = $this->catalog($this->output, $this->catalogs()[$kind]);
        $catalog[$name] = $value;
        $this->setCatalog($this->output, $this->catalogs()[$kind], $catalog);
        return '#' . $at;
    }

    private function walk(mixed $node, string $context, string $file, string $pointer, array $chain, int $depth): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (++$this->nodes > 100_000 || $depth > 256) {
            $this->fail('reference_limit', 'Reference graph node/depth limit exceeded.', $file, $pointer, $chain);
        }
        if ($file === $this->rootFile && isset($this->aliases[$pointer])) {
            [$targetFile, $targetPointer, $references] = $this->aliases[$pointer];
            $target = JsonPointer::resolve($this->documents[$targetFile], '#' . $targetPointer);
            if (!is_array($target) || ($target !== [] && array_is_list($target))) {
                $this->fail('invalid_reference_target', 'Root schema alias target must be an object.', $file, "$pointer/\$ref", [...$chain, ...$references]);
            }
            $this->accountPayload($target, $file, $pointer, [...$chain, ...$references]);
            $this->origins[$pointer] = ['source' => $targetFile, 'pointer' => $targetPointer];
            return $this->walk($target, 'schema', $targetFile, $targetPointer, [...$chain, ...$references], $depth + 1);
        }
        if ($context === 'schema') {
            foreach (['$id', '$anchor', '$dynamicAnchor', '$dynamicRef', '$schema'] as $keyword) {
                if (array_key_exists($keyword, $node)) {
                    $this->fail('unsupported_reference_scope', 'Schema IDs, anchors and dialect scopes are not resolved by this file bundler.', $file, JsonPointer::append($pointer, $keyword), $chain);
                }
            }
            if (isset($node['discriminator']) && empty($node['discriminator']['mapping'])) {
                $this->implicitDiscriminators[] = [$file, "$pointer/discriminator", $chain];
            }
        }
        $kind = $context === 'schema' ? 'schemas' : $context;
        if (array_key_exists('$ref', $node) && in_array($kind, ['schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'pathItems'], true)) {
            if (!is_string($node['$ref'])) {
                $this->fail('invalid_reference', 'Reference must be a string.', $file, "$pointer/\$ref", $chain);
            }
            if ($file !== $this->rootFile || !str_starts_with($node['$ref'], '#')) {
                $node['$ref'] = $this->import($kind, $node['$ref'], $file, "$pointer/\$ref", $chain, $depth);
            }
        }
        $ordered = $node;
        if (!array_is_list($ordered)) {
            ksort($ordered);
        }
        foreach ($ordered as $key => $value) {
            $at = JsonPointer::append($pointer, $key);
            if (str_starts_with($context, 'map:')) {
                $node[$key] = $this->walk($value, substr($context, 4), $file, $at, $chain, $depth + 1);
                continue;
            }
            $next = $this->childContext($context, (string) $key);
            if ($next !== null) {
                $node[$key] = $this->walk($value, $next, $file, $at, $chain, $depth + 1);
            }
        }
        if ($context === 'schema' && is_array($node['discriminator']['mapping'] ?? null)) {
            foreach ($node['discriminator']['mapping'] as $tag => $target) {
                $isReference = is_string($target) && (str_starts_with($target, '#') || str_contains($target, '/')
                    || preg_match('/\.(?:json|ya?ml)(?:#|$)/i', $target) || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target));
                if (is_string($target) && ($file !== $this->rootFile || ($isReference && !isset($this->documents[$file]['components']['schemas'][$target])))) {
                    if (!$isReference || isset($this->documents[$file]['components']['schemas'][$target])) {
                        $target = '#/components/schemas/' . str_replace(['~', '/'], ['~0', '~1'], $target);
                    }
                    $node['discriminator']['mapping'][$tag] = $this->import('schemas', $target, $file, JsonPointer::append("$pointer/discriminator/mapping", $tag), $chain, $depth);
                }
            }
        }
        return $node;
    }

    private function childContext(string $context, string $key): ?string
    {
        if ($context === 'document') {
            return match ($key) {
                'components' => 'components', 'paths' => 'map:pathItems', 'definitions' => 'map:schema',
                'parameters' => 'map:parameters', 'responses' => 'map:responses', default => null,
            };
        }
        if ($context === 'components') {
            return isset($this->catalogs()[$key]) ? 'map:' . ($key === 'schemas' ? 'schema' : $key) : null;
        }
        if ($context === 'pathItems') {
            return in_array($key, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true) ? 'operation'
                : ($key === 'parameters' ? 'map:parameters' : null);
        }
        if ($context === 'operation') {
            return match ($key) {
                'parameters' => 'map:parameters', 'requestBody' => 'requestBodies', 'responses' => 'map:responses', default => null,
            };
        }
        if (in_array($context, ['parameters', 'requestBodies', 'responses', 'headers'], true)) {
            return match ($key) {
                'schema', 'items' => 'schema', 'content' => 'map:media', 'headers' => 'map:headers', default => null,
            };
        }
        if ($context === 'media') {
            return match ($key) { 'schema' => 'schema', 'encoding' => 'map:encoding', default => null };
        }
        if ($context === 'encoding') {
            return $key === 'headers' ? 'map:headers' : null;
        }
        if ($context === 'schema') {
            return match ($key) {
                'properties' => 'map:schema', 'items', 'additionalProperties' => 'schema',
                'allOf', 'oneOf', 'anyOf' => 'map:schema', default => null,
            };
        }
        return null;
    }

    private function fail(string $code, string $message, string $source, string $pointer, array $chain): never
    {
        throw new FileReferenceException(new Diagnostic($code, 'error', $pointer, $message, $source, null, $chain));
    }
}
