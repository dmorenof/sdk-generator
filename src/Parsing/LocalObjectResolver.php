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
     * @return array{value:array,pointer:string,chain:list<string>}
     */
    public function resolve(array $node, string $kind, string $pointer): array
    {
        $chain = [];
        $seen = [];
        $overrides = [];
        while (array_key_exists('$ref', $node)) {
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
            $prefix = $swagger ? [$kind] : ['components', $kind];
            if (!in_array($kind, $swagger ? ['parameters', 'responses'] : ['parameters', 'requestBodies', 'responses'], true)
                || count($tokens) !== count($prefix) + 1 || array_slice($tokens, 0, -1) !== $prefix) {
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
            if (preg_match('/^3\.1\./', (string) ($this->document['openapi'] ?? '')) === 1) {
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
        return ['value' => array_replace($node, $overrides), 'pointer' => $pointer, 'chain' => $chain];
    }

    /** Expand only executable parameter/body/response positions, preserving schema identity. */
    public function expandOperations(): array
    {
        $document = $this->document;
        foreach ($document['paths'] ?? [] as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            $at = JsonPointer::append('/paths', $path);
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
                    $operation['requestBody'] = $this->resolve($operation['requestBody'], 'requestBodies', "$at/$method/requestBody")['value'];
                }
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if (is_array($response)) {
                        $operation['responses'][$status] = $this->resolve($response, 'responses', JsonPointer::append("$at/$method/responses", $status))['value'];
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
                $parameters[$index] = $this->resolve($parameter, 'parameters', JsonPointer::append($pointer, $index))['value'];
            }
        }
        return $parameters;
    }
}
