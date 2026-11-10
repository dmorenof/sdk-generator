<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Diagnostics\JsonPointer;

/** Resolve supported Reference Objects; never dereference schemas or example data. */
final class LocalObjectResolver
{
    public function __construct(private readonly array $document)
    {
    }

    /**
     * @return array{value:array,pointer:string,chain:list<string>,origins:array}
     */
    public function resolve(array $node, string $kind, string $pointer): array
    {
        $chain = [];
        $seen = $kind === 'pathItems' ? [$pointer => true] : [];
        $overrides = [];
        $fields = [];
        $origins = [];
        while (array_key_exists('$ref', $node)) {
            if ($kind === 'pathItems') {
                $this->pathFields($node, $pointer, $chain, $fields, $origins);
            }
            $reference = $node['$ref'];
            if (!is_string($reference)) {
                throw new LocalReferenceException('invalid_reference', 'Reference must be a string.', $chain);
            }
            $chain[] = $reference;
            if (!str_starts_with($reference, '#')) {
                throw new LocalReferenceException('external_reference', 'External references are not fetched.', $chain);
            }
            try {
                $tokens = JsonPointer::tokens($reference);
            } catch (\InvalidArgumentException $exception) {
                throw new LocalReferenceException('invalid_reference', $exception->getMessage(), $chain);
            }
            $swagger = ($this->document['swagger'] ?? null) === '2.0';
            if (!$this->supportedTarget($tokens, $kind, $swagger)) {
                throw new LocalReferenceException('unsupported_reference', "Expected a local named $kind reference for this object.", $chain);
            }
            $pointer = '';
            foreach ($tokens as $token) {
                $pointer = JsonPointer::append($pointer, $token);
            }
            if (isset($seen[$pointer])) {
                throw new LocalReferenceException('reference_cycle', 'Reference Object aliases form a cycle.', $chain);
            }
            if (count($chain) > 128) {
                throw new LocalReferenceException('reference_depth', 'Reference Object alias depth exceeds 128.', $chain);
            }
            $seen[$pointer] = true;
            // OAS 3.0 / Swagger ignore Reference Object siblings. OAS 3.1
            // permits summary/description overrides; outer aliases take precedence.
            if ($kind !== 'pathItems' && preg_match('/^3\.1\./', (string) ($this->document['openapi'] ?? '')) === 1) {
                foreach (['summary', 'description'] as $annotation) {
                    if (array_key_exists($annotation, $node) && !array_key_exists($annotation, $overrides)) {
                        $overrides[$annotation] = $node[$annotation];
                    }
                }
            }
            try {
                $node = JsonPointer::resolve($this->document, $reference);
            } catch (\InvalidArgumentException $exception) {
                throw new LocalReferenceException('unresolved_reference', $exception->getMessage(), $chain);
            }
            if (!is_array($node) || ($node !== [] && array_is_list($node))) {
                throw new LocalReferenceException('invalid_reference_target', 'Reference target must be an object.', $chain);
            }
        }
        if ($kind === 'pathItems') {
            $this->pathFields($node, $pointer, $chain, $fields, $origins);
            return ['value' => $fields, 'pointer' => $pointer, 'chain' => $chain, 'origins' => $origins];
        }
        return ['value' => array_replace($node, $overrides), 'pointer' => $pointer, 'chain' => $chain, 'origins' => []];
    }

    private function supportedTarget(array $tokens, string $kind, bool $swagger): bool
    {
        if ($kind === 'pathItems') {
            return (count($tokens) === 2 && $tokens[0] === 'paths')
                || (!$swagger && preg_match('/^3\.1\./', (string) ($this->document['openapi'] ?? '')) === 1
                    && count($tokens) === 3 && array_slice($tokens, 0, 2) === ['components', 'pathItems']);
        }
        $prefix = $swagger ? [$kind] : ['components', $kind];
        return in_array($kind, $swagger ? ['parameters', 'responses'] : ['parameters', 'requestBodies', 'responses', 'headers', 'securitySchemes', 'examples', 'links', 'callbacks'], true)
            && count($tokens) === count($prefix) + 1 && array_slice($tokens, 0, -1) === $prefix;
    }

    /** Path Item $ref is a fixed field, not a Reference Object with ignored siblings. */
    private function pathFields(array $node, string $pointer, array $chain, array &$fields, array &$origins): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                continue;
            }
            if (array_key_exists($key, $fields)) {
                throw new LocalReferenceException('conflicting_path_item', "Path Item field '$key' occurs both beside \$ref and in its target; the specification leaves this overlap undefined.",
                    $chain, JsonPointer::append($origins[$key]['pointer'], $key));
            }
            $fields[$key] = $value;
            $origins[$key] = ['pointer' => $pointer, 'chain' => $chain];
        }
    }

    /** Expand supported HTTP positions, preserving schema identity and example data. */
    public function expandOperations(): array
    {
        $document = $this->document;
        if (($document['swagger'] ?? null) !== '2.0') {
            foreach ($document['components']['securitySchemes'] ?? [] as $name => $scheme) {
                if (is_array($scheme)) {
                    // The declaring key remains the public credential name.
                    $document['components']['securitySchemes'][$name] = $this->resolve($scheme, 'securitySchemes',
                        JsonPointer::append('/components/securitySchemes', $name))['value'];
                }
            }
        }
        foreach ($document['paths'] ?? [] as $path => $item) {
            if (is_string($path) && str_starts_with($path, 'x-')) { continue; }
            if (!is_array($item)) {
                continue;
            }
            $at = JsonPointer::append('/paths', $path);
            $item = $this->resolve($item, 'pathItems', $at)['value'];
            if (is_array($item['parameters'] ?? null)) {
                $item['parameters'] = $this->parameters($item['parameters'], "$at/parameters");
            }
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                if (!is_array($item[$method] ?? null)) {
                    continue;
                }
                $operation = $item[$method];
                if (is_array($operation['parameters'] ?? null)) {
                    $operation['parameters'] = $this->parameters($operation['parameters'], "$at/$method/parameters");
                }
                if (is_array($operation['requestBody'] ?? null)) {
                    $operation['requestBody'] = $this->contentHeaders($this->resolve($operation['requestBody'], 'requestBodies', "$at/$method/requestBody")['value'], "$at/$method/requestBody");
                }
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if (is_array($response)) {
                        $responseAt = JsonPointer::append("$at/$method/responses", $status);
                        $response = $this->resolve($response, 'responses', $responseAt)['value'];
                        if (is_array($response['headers'] ?? null)) {
                            $response['headers'] = $this->headers($response['headers'], "$responseAt/headers");
                        }
                        if (($document['swagger'] ?? null) !== '2.0' && is_array($response['links'] ?? null)) {
                            $response['links'] = $this->objects($response['links'], 'links', "$responseAt/links");
                        }
                        $operation['responses'][$status] = $this->contentHeaders($response, $responseAt);
                    }
                }
                $item[$method] = $operation;
            }
            $document['paths'][$path] = $item;
        }
        return $document;
    }

    private function parameters(array $parameters, string $pointer): array
    {
        foreach ($parameters as $index => $parameter) {
            if (is_array($parameter)) {
                $at = JsonPointer::append($pointer, $index);
                $parameters[$index] = $this->contentHeaders($this->resolve($parameter, 'parameters', $at)['value'], $at);
            }
        }
        return $parameters;
    }

    private function headers(array $headers, string $pointer): array
    {
        foreach ($headers as $name => $header) {
            if (is_array($header)) {
                $at = JsonPointer::append($pointer, $name);
                $headers[$name] = $this->contentHeaders($this->resolve($header, 'headers', $at)['value'], $at);
            }
        }
        return $headers;
    }

    /** Reference Object maps only: Example.value and Link parameter values stay opaque. */
    private function objects(array $nodes, string $kind, string $pointer): array
    {
        foreach ($nodes as $name => $node) {
            if (!is_array($node)) { throw new \InvalidArgumentException("Expected $kind object at $pointer."); }
            $nodes[$name] = $this->resolve($node, $kind, JsonPointer::append($pointer, $name))['value'];
        }
        return $nodes;
    }

    private function contentHeaders(array $node, string $pointer): array
    {
        if (($this->document['swagger'] ?? null) !== '2.0' && is_array($node['examples'] ?? null)) {
            $node['examples'] = $this->objects($node['examples'], 'examples', "$pointer/examples");
        }
        foreach ($node['content'] ?? [] as $media => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            if (($this->document['swagger'] ?? null) !== '2.0' && is_array($definition['examples'] ?? null)) {
                $node['content'][$media]['examples'] = $this->objects($definition['examples'], 'examples', JsonPointer::append("$pointer/content", $media) . '/examples');
            }
            foreach ($definition['encoding'] ?? [] as $name => $encoding) {
                if (is_array($encoding) && is_array($encoding['headers'] ?? null)) {
                    $node['content'][$media]['encoding'][$name]['headers'] = $this->headers($encoding['headers'],
                        JsonPointer::append(JsonPointer::append("$pointer/content", $media) . '/encoding', $name) . '/headers');
                }
            }
        }
        return $node;
    }
}
