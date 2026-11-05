<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;

/** Audit HTTP-affecting options which the current executable pipeline would ignore. */
final class ContractAuditor
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
    private const ANNOTATIONS = ['summary', 'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', 'tags'];
    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private array $document = [];
    private string $source = '<input>';

    /** @return list<Diagnostic> */
    public function audit(array $document, string $source = '<input>'): array
    {
        $this->diagnostics = [];
        $this->document = $document;
        $this->source = $source;
        $this->keys($document, ['openapi', 'swagger', 'info', 'servers', 'host', 'basePath', 'schemes', 'paths', 'components', 'definitions', 'security', 'securityDefinitions', 'consumes', 'produces'], '');
        $rootServers = $document['servers'] ?? [];
        $this->servers($rootServers, '/servers', null, null);
        foreach ($document['paths'] ?? [] as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            $pointer = JsonPointer::append('/paths', $path);
            $this->keys($item, [...self::METHODS, 'parameters', 'servers', '$ref'], $pointer);
            $this->servers($item['servers'] ?? null, "$pointer/servers", null, null);
            if (isset($item['$ref'])) {
                $this->reference($item['$ref'], "$pointer/\$ref", null, false);
            }
            foreach (self::METHODS as $method) {
                $operation = $item[$method] ?? null;
                if (!is_array($operation)) {
                    continue;
                }
                $where = "$pointer/$method";
                $id = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : strtoupper($method) . ' ' . $path;
                $this->keys($operation, ['operationId', 'parameters', 'requestBody', 'responses', 'security', 'servers', 'consumes', 'produces', 'schemes'], $where, $id);
                if (($document['swagger'] ?? null) === '2.0') {
                    foreach (['consumes' => true, 'produces' => false] as $keyword => $request) {
                        $mediaTypes = $operation[$keyword] ?? $document[$keyword] ?? ['application/json'];
                        if (is_array($mediaTypes)) {
                            $declared = array_fill_keys(array_filter($mediaTypes, 'is_string'), []);
                            $this->selectedMedia($declared, isset($operation[$keyword]) ? "$where/$keyword" : "/$keyword", $id, $request);
                        }
                    }
                }
                if (array_key_exists('servers', $operation)) {
                    $this->servers($operation['servers'], "$where/servers", $id, $rootServers);
                } elseif (array_key_exists('servers', $item)) {
                    $this->servers($item['servers'], "$pointer/servers", $id, $rootServers);
                }
                if (isset($operation['schemes']) && $operation['schemes'] !== ($document['schemes'] ?? [])) {
                    $this->add('operation_schemes', "$where/schemes", 'Operation-specific Swagger schemes are not executed.', $id);
                }
                $overrides = [];
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    if (is_array($parameter) && isset($parameter['in'], $parameter['name'])) {
                        $overrides[$parameter['in'] . ':' . $parameter['name']] = true;
                    }
                }
                $pathParameters = array_filter($item['parameters'] ?? [], static fn ($parameter): bool =>
                    !is_array($parameter) || !isset($parameter['in'], $parameter['name'])
                    || !isset($overrides[$parameter['in'] . ':' . $parameter['name']]));
                $this->parameters($pathParameters, "$pointer/parameters", $id);
                $this->parameters($operation['parameters'] ?? [], "$where/parameters", $id);
                if (is_array($operation['requestBody'] ?? null)) {
                    $body = $operation['requestBody'];
                    $this->keys($body, ['required', 'content', '$ref'], "$where/requestBody", $id);
                    if (isset($body['$ref'])) {
                        $this->reference($body['$ref'], "$where/requestBody/\$ref", $id, false);
                    }
                    $this->content($body['content'] ?? [], "$where/requestBody/content", $id);
                    $this->selectedMedia($body['content'] ?? [], "$where/requestBody/content", $id, true);
                }
                foreach ($operation['responses'] ?? [] as $status => $response) {
                    if (!is_array($response)) {
                        continue;
                    }
                    $at = JsonPointer::append("$where/responses", $status);
                    $this->keys($response, ['content', 'schema', 'headers', 'links', '$ref'], $at, $id);
                    if (isset($response['$ref'])) {
                        $this->reference($response['$ref'], "$at/\$ref", $id, false);
                    }
                    foreach (['headers', 'links'] as $keyword) {
                        if (!empty($response[$keyword])) {
                            $this->add('response_' . $keyword, "$at/$keyword", "Response $keyword are preserved but not exposed by the runtime.", $id);
                        }
                    }
                    $this->content($response['content'] ?? [], "$at/content", $id);
                    $this->selectedMedia($response['content'] ?? [], "$at/content", $id, false);
                    if (isset($response['schema'])) {
                        $this->schema($response['schema'], "$at/schema", $id);
                    }
                    foreach ($response['headers'] ?? [] as $name => $header) {
                        if (!is_array($header)) {
                            continue;
                        }
                        $headerAt = JsonPointer::append("$at/headers", $name);
                        if (isset($header['$ref'])) {
                            $this->reference($header['$ref'], "$headerAt/\$ref", $id, false);
                        }
                        if (isset($header['schema'])) {
                            $this->schema($header['schema'], "$headerAt/schema", $id);
                        }
                    }
                }
            }
        }
        foreach ($document['components']['schemas'] ?? [] as $name => $schema) {
            $this->schema($schema, JsonPointer::append('/components/schemas', $name), null);
        }
        foreach ($document['definitions'] ?? [] as $name => $schema) {
            $this->schema($schema, JsonPointer::append('/definitions', $name), null);
        }
        $unique = [];
        foreach ($this->diagnostics as $diagnostic) {
            $key = $diagnostic->pointer . ':' . $diagnostic->code . ':' . $diagnostic->operationId;
            $unique[$key] = $diagnostic;
        }
        $result = array_values($unique);
        usort($result, static fn (Diagnostic $a, Diagnostic $b): int => [$a->pointer, $a->code, $a->operationId] <=> [$b->pointer, $b->code, $b->operationId]);
        return $result;
    }

    private function keys(array $node, array $allowed, string $pointer, ?string $operation = null): void
    {
        foreach ($node as $key => $_) {
            if (in_array($key, $allowed, true) || in_array($key, self::ANNOTATIONS, true)) {
                continue;
            }
            $extension = str_starts_with((string) $key, 'x-');
            $this->add($extension ? 'uninterpreted_extension' : 'unsupported_keyword', JsonPointer::append($pointer, $key),
                $extension ? 'Vendor extension is not interpreted by the executable SDK.' : "Unsupported HTTP contract keyword '$key'.", $operation, $extension ? 'warning' : 'error');
        }
    }

    private function servers(mixed $servers, string $pointer, ?string $operation, mixed $root): void
    {
        if ($servers === null) {
            return;
        }
        if (!is_array($servers)) {
            $this->add('invalid_servers', $pointer, 'Servers must be an array.', $operation);
            return;
        }
        $urls = static fn (array $values): array => array_map(static fn ($server): mixed => is_array($server) && is_string($server['url'] ?? null) ? rtrim($server['url'], '/') : null, $values);
        if (is_array($root) && $urls($servers) !== $urls($root)) {
            $this->add('server_override', $pointer, 'Path/operation server overrides are not executed by the shared client.', $operation);
        }
        foreach ($servers as $index => $server) {
            if (is_array($server) && isset($server['variables'])) {
                $this->add('server_variables', "$pointer/$index/variables", 'Server variables are not expanded.', $operation);
            }
        }
    }

    private function parameters(mixed $parameters, string $pointer, ?string $operation): void
    {
        if (!is_array($parameters)) {
            return;
        }
        foreach ($parameters as $index => $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $at = "$pointer/$index";
            $this->keys($parameter, ['name', 'in', 'required', 'schema', 'content', 'style', 'explode', 'allowReserved', 'allowEmptyValue', 'collectionFormat', '$ref', 'type', 'format', 'items', 'enum', 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'nullable', 'x-nullable'], $at, $operation);
            if (isset($parameter['$ref'])) {
                $this->reference($parameter['$ref'], "$at/\$ref", $operation, false);
            }
            foreach (['explode', 'allowReserved', 'allowEmptyValue'] as $flag) {
                if (array_key_exists($flag, $parameter) && !is_bool($parameter[$flag])) {
                    $this->add('invalid_parameter_option', "$at/$flag", "Parameter $flag must be boolean.", $operation);
                }
            }
            $location = $parameter['in'] ?? null;
            if ($location === 'cookie') {
                $this->add('cookie_parameter', "$at/in", 'Cookie parameter serialization is not implemented.', $operation);
            }
            $default = in_array($location, ['query', 'cookie'], true) ? 'form' : 'simple';
            if (isset($parameter['style']) && $parameter['style'] !== $default) {
                $this->add('parameter_style', "$at/style", 'This parameter style is not executed by the scalar serializer.', $operation);
            }
            foreach (['allowReserved', 'allowEmptyValue'] as $flag) {
                if (($parameter[$flag] ?? false) !== false) {
                    $this->add('parameter_' . $flag, "$at/$flag", "Parameter $flag is not executed.", $operation);
                }
            }
            if (isset($parameter['schema'], $parameter['content'])) {
                $this->add('conflicting_parameter_schema', "$at/content", 'A parameter must declare either schema or content, not both.', $operation);
            }
            if (isset($parameter['content'])) {
                $this->add('parameter_content', "$at/content", 'Content-based parameter serialization is not implemented.', $operation);
            }
            if (isset($parameter['collectionFormat'])) {
                $this->add('parameter_collectionFormat', "$at/collectionFormat", 'Swagger collection formats are not executed.', $operation);
            }
            if (isset($parameter['schema'])) {
                $this->schema($parameter['schema'], "$at/schema", $operation);
            } elseif (($this->document['swagger'] ?? null) === '2.0') {
                $schema = array_intersect_key($parameter, array_flip(SpecificationNormalizer::SCHEMA_KEYWORDS));
                $this->schema($schema, $at, $operation);
            }
            $this->content($parameter['content'] ?? [], "$at/content", $operation);
            $type = $parameter['schema']['type'] ?? $parameter['type'] ?? null;
            if (in_array($type, ['array', 'object'], true)) {
                $this->add('structured_parameter', isset($parameter['schema']) ? "$at/schema" : "$at/type", 'Array/object parameter serialization is not implemented.', $operation);
            }
        }
    }

    private function content(mixed $content, string $pointer, ?string $operation): void
    {
        if (!is_array($content)) {
            return;
        }
        foreach ($content as $media => $definition) {
            $at = JsonPointer::append($pointer, $media);
            if (!is_array($definition)) {
                continue;
            }
            $this->keys($definition, ['schema', 'encoding'], $at, $operation);
            if (array_key_exists('schema', $definition)) {
                $this->schema($definition['schema'], "$at/schema", $operation);
            }
            if (!preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media)) {
                $this->add('media_type', $at, 'This representation is preserved but the SDK sends/accepts JSON only.', $operation, 'warning');
            }
            if (!empty($definition['encoding'])) {
                $this->add('media_encoding', "$at/encoding", 'Per-property media encoding is not executed.', $operation);
            }
        }
    }

    private function selectedMedia(mixed $content, string $pointer, ?string $operation, bool $request): void
    {
        if (!is_array($content) || $content === []) {
            return;
        }
        foreach (array_keys($content) as $media) {
            if (is_string($media) && strtolower(trim($media)) === 'application/json') {
                return;
            }
        }
        $header = $request ? 'Content-Type' : 'Accept';
        $this->add($request ? 'request_media_type' : 'response_media_type', $pointer,
            "The shared runtime uses $header: application/json; none of the declared media types match that value.", $operation);
    }

    private function schema(mixed $schema, string $pointer, ?string $operation): void
    {
        if (!is_array($schema)) {
            return; // The normalizer retains responsibility for structural schema errors.
        }
        foreach ($schema as $keyword => $_) {
            if (!in_array($keyword, SpecificationNormalizer::SCHEMA_KEYWORDS, true)) {
                $this->add('unsupported_schema_keyword', JsonPointer::append($pointer, $keyword), "Unsupported schema keyword '$keyword'.", $operation);
            }
        }
        if (isset($schema['$ref'])) {
            $this->reference($schema['$ref'], "$pointer/\$ref", $operation, true);
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            $this->schema($property, JsonPointer::append("$pointer/properties", $name), $operation);
        }
        foreach (['items', 'additionalProperties'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $this->schema($schema[$keyword], "$pointer/$keyword", $operation);
            }
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach ($schema[$keyword] ?? [] as $index => $branch) {
                $this->schema($branch, "$pointer/$keyword/$index", $operation);
            }
        }
    }

    private function reference(mixed $reference, string $pointer, ?string $operation, bool $schema): void
    {
        if (!is_string($reference)) {
            $this->add('invalid_reference', $pointer, 'Reference must be a string.', $operation);
            return;
        }
        $chain = [];
        $current = $reference;
        try {
            while (true) {
                if (in_array($current, $chain, true)) {
                    break; // Report resolution only; schema recursion is not itself invalid.
                }
                $chain[] = $current;
                if (!str_starts_with($current, '#/')) {
                    $this->add('external_reference', $pointer, 'External references are not fetched during inspection.', $operation, 'error', $chain);
                    return;
                }
                $target = JsonPointer::resolve($this->document, $current);
                if (!is_array($target) || !is_string($target['$ref'] ?? null)) {
                    break;
                }
                $current = $target['$ref'];
            }
        } catch (\InvalidArgumentException $exception) {
            $this->add('unresolved_reference', $pointer, $exception->getMessage(), $operation, 'error', $chain);
            return;
        }
        if (!$schema || (!str_starts_with($reference, '#/components/schemas/') && !str_starts_with($reference, '#/definitions/'))) {
            $this->add('unsupported_reference', $pointer, 'Only local model references are executable in the current pipeline.', $operation, 'error', $chain);
        }
    }

    private function add(string $code, string $pointer, string $message, ?string $operation = null, string $severity = 'error', array $chain = []): void
    {
        $this->diagnostics[] = new Diagnostic($code, $severity, $pointer, $message, $this->source, $operation, $chain);
    }
}
