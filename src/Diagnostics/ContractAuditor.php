<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Diagnostics;

use Dmorenof\SdkGenerator\Parsing\SpecificationNormalizer;
use Dmorenof\SdkGenerator\Parsing\LocalObjectResolver;
use Dmorenof\SdkGenerator\Parsing\LocalReferenceException;

/** Audit HTTP-affecting options which the current executable pipeline would ignore. */
final class ContractAuditor
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
    private const ANNOTATIONS = ['summary', 'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', 'tags'];
    /** @var list<Diagnostic> */
    private array $diagnostics = [];
    private array $document = [];
    private string $source = '<input>';
    /** @var list<string> */
    private array $referenceChain = [];
    private array $pathItemOrigins = [];
    private array $activeObjects = [];

    /**
     * @param array<string, array<string, array{pointer:string,chain:list<string>}>> $pathItemOrigins Original field locations for derived operation subsets.
     * @return list<Diagnostic>
     */
    public function audit(array $document, string $source = '<input>', array $pathItemOrigins = []): array
    {
        $this->diagnostics = [];
        $this->document = $document;
        $this->source = $source;
        $this->referenceChain = [];
        $this->pathItemOrigins = $pathItemOrigins;
        $this->activeObjects = [];
        $this->keys($document, ['openapi', 'swagger', 'info', 'servers', 'host', 'basePath', 'schemes', 'paths', 'components', 'definitions', 'security', 'securityDefinitions', 'consumes', 'produces',
            ...(($document['swagger'] ?? null) === '2.0' ? ['parameters', 'responses'] : [])], '');
        $this->servers($document['servers'] ?? [], '/servers', null, null);
        $this->securityRequirements($document['security'] ?? [], '/security', null);
        $this->pathItems($document['paths'] ?? [], '/paths');
        $this->components();
        foreach ($document['components']['schemas'] ?? [] as $name => $schema) {
            $this->schema($schema, JsonPointer::append('/components/schemas', $name), null);
        }
        foreach ($document['definitions'] ?? [] as $name => $schema) {
            $this->schema($schema, JsonPointer::append('/definitions', $name), null);
        }
        $unique = [];
        foreach ($this->diagnostics as $diagnostic) {
            $key = json_encode([$diagnostic->pointer, $diagnostic->code, $diagnostic->operationId, $diagnostic->referenceChain], JSON_THROW_ON_ERROR);
            $unique[$key] = $diagnostic;
        }
        $result = array_values($unique);
        usort($result, static fn (Diagnostic $a, Diagnostic $b): int => [$a->pointer, $a->code, $a->operationId, $a->referenceChain] <=> [$b->pointer, $b->code, $b->operationId, $b->referenceChain]);
        return $result;
    }

    /** Source origins are supplied only when auditing a derived operation subset. */
    private function pathItems(mixed $paths, string $base): void
    {
        if (!is_array($paths)) {
            return;
        }
        foreach ($paths as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            $pointer = JsonPointer::append($base, $path);
            try {
                $resolved = (new LocalObjectResolver($this->document))->resolve($item, 'pathItems', $pointer);
            } catch (LocalReferenceException $exception) {
                $this->add($exception->diagnosticCode, $exception->pointer ?? "$pointer/\$ref", $exception->getMessage(), null, 'error', $exception->referenceChain);
                continue;
            }
            $active = 'pathItems:' . $resolved['pointer'];
            if (isset($this->activeObjects[$active])) {
                $this->add('recursive_http_reference', "$pointer/\$ref", 'Recursive HTTP metadata is not expanded.', null, 'error', $resolved['chain']);
                continue;
            }
            $this->activeObjects[$active] = true;
            try {
                $origins = $this->pathItemOrigins[$pointer] ?? $resolved['origins'];
                $this->auditPathItem($resolved['value'], $origins, $pointer, (string) $path);
            } finally {
                unset($this->activeObjects[$active]);
            }
        }
    }

    private function auditPathItem(array $item, array $origins, string $pointer, string $path): void
    {
        $outer = $this->referenceChain;
        $fallback = ['pointer' => $pointer, 'chain' => []];
        foreach ($item as $key => $value) {
            $origin = $origins[$key] ?? $fallback;
            $this->withOrigin($outer, $origin, fn () => $this->keys([$key => $value], [...self::METHODS, 'parameters', 'servers'], $origin['pointer']));
        }
        $serverOrigin = $origins['servers'] ?? $fallback;
        $this->withOrigin($outer, $serverOrigin, fn () => $this->servers($item['servers'] ?? null, $serverOrigin['pointer'] . '/servers', null, null));
        foreach (self::METHODS as $method) {
            $operation = $item[$method] ?? null;
            if (!is_array($operation)) {
                continue;
            }
            $origin = $origins[$method] ?? $fallback;
            $where = $origin['pointer'] . '/' . $method;
            $id = is_string($operation['operationId'] ?? null) ? $operation['operationId'] : strtoupper($method) . ' ' . $path;
            $this->withOrigin($outer, $origin, fn () => $this->pathOperation($item, $operation, $where, $id,
                $origins['parameters'] ?? $fallback, $serverOrigin, $outer));
        }
    }

    private function withOrigin(array $outer, array $origin, callable $audit): void
    {
        $previous = $this->referenceChain;
        $this->referenceChain = [...$outer, ...$origin['chain']];
        try {
            $audit();
        } finally {
            $this->referenceChain = $previous;
        }
    }

    private function pathOperation(array $item, array $operation, string $where, string $id,
        array $parameterOrigin, array $serverOrigin, array $outer): void
    {
        $document = $this->document;
        $rootServers = $document['servers'] ?? [];
        $serverPointer = $serverOrigin['pointer'];
        $this->keys($operation, ['operationId', 'parameters', 'requestBody', 'responses', 'security', 'servers', 'consumes', 'produces', 'schemes', 'callbacks'], $where, $id);
        $this->securityRequirements($operation['security'] ?? [], "$where/security", $id);
        $this->callbacks($operation['callbacks'] ?? [], "$where/callbacks", $id);
        $formMedia = null;
        if (($document['swagger'] ?? null) === '2.0') {
            $effective = [];
            foreach (array_merge($item['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
                if (!is_array($parameter)) {
                    continue;
                }
                try {
                    $value = (new LocalObjectResolver($document))->resolve($parameter, 'parameters', "$where/parameters")['value'];
                    if (isset($value['in'], $value['name'])) {
                        $effective[$value['in'] . ':' . $value['name']] = $value;
                    }
                } catch (LocalReferenceException) {
                    // The parameter audit below retains the exact usage/declaration diagnostic.
                }
            }
            $hasForm = array_filter($effective, static fn ($field): bool => ($field['in'] ?? null) === 'formData') !== [];
            $hasBody = array_filter($effective, static fn ($field): bool => ($field['in'] ?? null) === 'body') !== [];
            $formMedia = \Dmorenof\SdkGenerator\Parsing\FormSupport::swaggerMedia(
                array_key_exists('consumes', $operation) ? $operation['consumes'] : ($document['consumes'] ?? []));
            if ($hasForm) {
                if ($hasBody) {
                    $this->add('swagger_form_body_conflict', "$where/parameters", 'Swagger body and formData parameters cannot coexist.', $id);
                }
                if ($formMedia === null) {
                    $this->add('swagger_form_media', array_key_exists('consumes', $operation) ? "$where/consumes" : '/consumes',
                        'Swagger formData requires one application/x-www-form-urlencoded or multipart/form-data representation; media negotiation remains unsupported.', $id);
                }
            }
            if (!$hasForm && $hasBody) {
                $mediaTypes = $operation['consumes'] ?? $document['consumes'] ?? ['application/json'];
                if (is_array($mediaTypes)) {
                    $declared = array_fill_keys(array_filter($mediaTypes, 'is_string'), []);
                    $this->selectedMedia($declared, isset($operation['consumes']) ? "$where/consumes" : '/consumes', $id, true);
                }
            }
        }
        if (array_key_exists('servers', $operation)) {
            $this->servers($operation['servers'], "$where/servers", $id, $rootServers);
        } elseif (array_key_exists('servers', $item)) {
            $this->withOrigin($outer, $serverOrigin, fn () => $this->servers($item['servers'], "$serverPointer/servers", $id, $rootServers));
        }
        if (isset($operation['schemes']) && $operation['schemes'] !== ($document['schemes'] ?? [])) {
            $this->add('operation_schemes', "$where/schemes", 'Operation-specific Swagger schemes are not executed.', $id);
        }
        $overrides = [];
        foreach ($operation['parameters'] ?? [] as $parameter) {
            $identity = $this->parameterIdentity($parameter);
            if ($identity !== null) {
                $overrides[$identity] = true;
            }
        }
        $pathParameters = array_filter($item['parameters'] ?? [], fn ($parameter): bool =>
            !isset($overrides[$this->parameterIdentity($parameter) ?? '']));
        $this->withOrigin($outer, $parameterOrigin, fn () => $this->parameters($pathParameters, $parameterOrigin['pointer'] . '/parameters', $id, $formMedia));
        $this->parameters($operation['parameters'] ?? [], "$where/parameters", $id, $formMedia);
        $this->requestBody($operation['requestBody'] ?? null, "$where/requestBody", $id);
        foreach ($operation['responses'] ?? [] as $status => $response) {
            $at = JsonPointer::append("$where/responses", $status);
            $this->response($response, $at, $id);
            // Error media types do not negotiate success representations.
            if (is_array($response) && preg_match('/^2[0-9]{2}$/', (string) $status) === 1) {
                $this->resolvedObject($response, 'responses', $at, $id,
                    function (array $value, string $source) use ($operation, $where, $document, $id): void {
                        if (($document['swagger'] ?? null) !== '2.0') {
                            $this->selectedMedia($value['content'] ?? [], "$source/content", $id, false);
                            return;
                        }
                        if (!is_array($value['schema'] ?? null)) {
                            return; // Bodyless responses do not select a response codec.
                        }
                        $mediaTypes = $operation['produces'] ?? $document['produces'] ?? ['application/json'];
                        if (!is_array($mediaTypes)) {
                            return;
                        }
                        $schema = \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::swaggerSchema($value['schema'], $mediaTypes)
                            ?? $value['schema'];
                        $content = [];
                        foreach ($mediaTypes as $media) {
                            if (is_string($media)) {
                                $content[$media] = ['schema' => $schema];
                            }
                        }
                        $this->selectedMedia($content, isset($operation['produces']) ? "$where/produces" : '/produces', $id, false);
                    });
            }
        }
    }

    /** Audit declarations too; declaration coverage does not implement reference execution. */
    private function components(): void
    {
        $components = $this->document['components'] ?? [];
        if (is_array($components)) {
            $this->keys($components, ['schemas', 'parameters', 'requestBodies', 'responses', 'headers', 'links', 'examples', 'callbacks', 'pathItems', 'securitySchemes'], '/components');
            $this->parameters($components['parameters'] ?? [], '/components/parameters', null);
            foreach (['requestBodies' => 'requestBody', 'responses' => 'response', 'headers' => 'header', 'links' => 'link'] as $collection => $handler) {
                foreach ($components[$collection] ?? [] as $name => $node) {
                    $this->$handler($node, JsonPointer::append("/components/$collection", $name), null);
                }
            }
            $this->pathItems($components['pathItems'] ?? [], '/components/pathItems');
            $this->callbacks($components['callbacks'] ?? [], '/components/callbacks', null);
        }
        if (($this->document['swagger'] ?? null) === '2.0') {
            $this->parameters($this->document['parameters'] ?? [], '/parameters', null);
            foreach ($this->document['responses'] ?? [] as $name => $response) {
                $this->response($response, JsonPointer::append('/responses', $name), null);
            }
        }
        $swagger = ($this->document['swagger'] ?? null) === '2.0';
        $schemes = $swagger ? ($this->document['securityDefinitions'] ?? []) : ($components['securitySchemes'] ?? []);
        foreach ($schemes as $name => $scheme) {
            $this->securityScheme($scheme, JsonPointer::append($swagger ? '/securityDefinitions' : '/components/securitySchemes', $name));
        }
    }

    private function requestBody(mixed $body, string $pointer, ?string $operation): void
    {
        if (is_array($body)) {
            $this->resolvedObject($body, 'requestBodies', $pointer, $operation,
                fn (array $value, string $at) => $this->auditRequestBody($value, $at, $operation));
        }
    }

    private function auditRequestBody(array $body, string $pointer, ?string $operation): void
    {
        $this->keys($body, ['required', 'content', '$ref'], $pointer, $operation);
        $this->content($body['content'] ?? [], "$pointer/content", $operation, true);
        $this->selectedMedia($body['content'] ?? [], "$pointer/content", $operation, true);
    }

    private function response(mixed $response, string $pointer, ?string $operation): void
    {
        if (is_array($response)) {
            $this->resolvedObject($response, 'responses', $pointer, $operation,
                fn (array $value, string $at) => $this->auditResponse($value, $at, $operation));
        }
    }

    private function auditResponse(array $response, string $pointer, ?string $operation): void
    {
        $this->keys($response, ['content', 'schema', 'headers', 'links', '$ref', 'examples'], $pointer, $operation);
        foreach (['headers', 'links'] as $keyword) {
            if (!empty($response[$keyword])) {
                $this->add('response_' . $keyword, "$pointer/$keyword", ($keyword === 'headers' ? 'Declared response header schemas are preserved but not validated by the runtime.' : 'Response links are preserved but not executed by the runtime.'), $operation);
            }
        }
        $this->content($response['content'] ?? [], "$pointer/content", $operation, response: true);
        if (isset($response['schema'])) {
            $this->schema($response['schema'], "$pointer/schema", $operation);
        }
        $this->headers($response['headers'] ?? [], "$pointer/headers", $operation);
        foreach ($response['links'] ?? [] as $name => $link) {
            $this->link($link, JsonPointer::append("$pointer/links", $name), $operation);
        }
    }

    private function headers(mixed $headers, string $pointer, ?string $operation): void
    {
        if (!is_array($headers)) {
            return;
        }
        foreach ($headers as $name => $header) {
            $this->header($header, JsonPointer::append($pointer, $name), $operation);
        }
    }

    private function header(mixed $header, string $pointer, ?string $operation): void
    {
        if (!is_array($header)) {
            return;
        }
        if (($this->document['swagger'] ?? null) === '2.0') {
            // Swagger Header Objects carry the schema directly, without schema:.
            $this->schema(array_diff_key($header, array_flip(['description', 'collectionFormat', 'example', 'examples'])), $pointer, $operation);
            return;
        }
        $this->resolvedObject($header, 'headers', $pointer, $operation,
            fn (array $value, string $at) => $this->auditHeader($value, $at, $operation));
    }

    private function auditHeader(array $header, string $pointer, ?string $operation): void
    {
        $this->keys($header, ['required', 'schema', 'content', 'style', 'explode', 'allowReserved', 'allowEmptyValue', '$ref'], $pointer, $operation);
        if (isset($header['schema'])) {
            $this->schema($header['schema'], "$pointer/schema", $operation);
        }
        $this->content($header['content'] ?? [], "$pointer/content", $operation);
    }

    private function link(mixed $link, string $pointer, ?string $operation): void
    {
        if (!is_array($link)) {
            return;
        }
        $this->keys($link, ['operationRef', 'operationId', 'parameters', 'requestBody', 'server', '$ref'], $pointer, $operation);
        $this->objectReference($link, $pointer, $operation);
        // parameters/requestBody contain runtime expressions or literal values,
        // not Schema Objects or Reference Objects.
        if (isset($link['server'])) {
            $this->server($link['server'], "$pointer/server", $operation);
        }
    }

    private function callbacks(mixed $callbacks, string $pointer, ?string $operation): void
    {
        if (!is_array($callbacks)) {
            return;
        }
        foreach ($callbacks as $name => $callback) {
            $at = JsonPointer::append($pointer, $name);
            $this->add('unsupported_callback', $at, 'Callback operations are not generated as incoming contracts.', $operation);
            if (!is_array($callback)) {
                continue;
            }
            $this->objectReference($callback, $at, $operation);
            $this->pathItems(array_diff_key($callback, ['$ref' => true]), $at);
        }
    }

    private function securityScheme(mixed $scheme, string $pointer): void
    {
        if (!is_array($scheme)) {
            return;
        }
        if (($this->document['swagger'] ?? null) !== '2.0') {
            $this->resolvedObject($scheme, 'securitySchemes', $pointer, null,
                fn (array $value, string $at) => $this->auditSecurityScheme($value, $at));
            return;
        }
        $this->auditSecurityScheme($scheme, $pointer);
    }

    private function auditSecurityScheme(array $scheme, string $pointer): void
    {
        $this->keys($scheme, ['type', 'name', 'in', 'scheme', 'bearerFormat', 'flows', 'openIdConnectUrl', 'flow', 'authorizationUrl', 'tokenUrl', 'scopes', '$ref'], $pointer);
        $this->objectReference($scheme, $pointer, null);
        if (array_key_exists('$ref', $scheme)) {
            return;
        }
        $type = $scheme['type'] ?? null;
        if ($type === 'apiKey') {
            if (!in_array($scheme['in'] ?? null, ['header', 'query'], true)) {
                $this->add('unsupported_security_scheme', "$pointer/in", 'API keys are executable only in headers or query parameters.');
            }
        } elseif ($type === 'http') {
            if (!in_array(strtolower((string) ($scheme['scheme'] ?? '')), ['basic', 'bearer'], true)) {
                $this->add('unsupported_security_scheme', "$pointer/scheme", 'Only HTTP Basic and Bearer schemes are executable.');
            }
        } elseif ($type !== 'basic' || ($this->document['swagger'] ?? null) !== '2.0') {
            $this->add('unsupported_security_scheme', "$pointer/type", 'This security scheme is not executable.');
        }
    }

    private function securityRequirements(mixed $requirements, string $pointer, ?string $operation): void
    {
        if (!is_array($requirements)) {
            return;
        }
        $schemes = ($this->document['swagger'] ?? null) === '2.0'
            ? ($this->document['securityDefinitions'] ?? []) : ($this->document['components']['securitySchemes'] ?? []);
        foreach ($requirements as $index => $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            foreach ($requirement as $name => $scopes) {
                $at = JsonPointer::append(JsonPointer::append($pointer, $index), $name);
                if (!array_key_exists($name, $schemes)) {
                    $this->add('unknown_security_scheme', $at, 'Security requirement names an undefined scheme.', $operation);
                }
                if (is_array($scopes) && $scopes !== []) {
                    $this->add('unsupported_security_scopes', $at, 'OAuth security scopes are not executed.', $operation);
                }
            }
        }
    }

    private function parameterIdentity(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        try {
            $value = (new LocalObjectResolver($this->document))->resolve($node, 'parameters', '')['value'];
            return isset($value['in'], $value['name']) ? $value['in'] . ':' . $value['name'] : null;
        } catch (LocalReferenceException) {
            return null; // The parameter audit reports the failure with its source pointer.
        }
    }

    /** @param callable(array, string): void $audit */
    private function resolvedObject(array $node, string $kind, string $pointer, ?string $operation, callable $audit): void
    {
        try {
            $resolved = (new LocalObjectResolver($this->document))->resolve($node, $kind, $pointer);
        } catch (LocalReferenceException $exception) {
            $this->add($exception->diagnosticCode, $exception->pointer ?? "$pointer/\$ref", $exception->getMessage(), $operation, 'error', $exception->referenceChain);
            return;
        }
        $active = $kind . ':' . $resolved['pointer'];
        if (isset($this->activeObjects[$active])) {
            $this->add('recursive_http_reference', "$pointer/\$ref", 'Recursive HTTP metadata is not expanded.', $operation, 'error', $resolved['chain']);
            return;
        }
        $this->activeObjects[$active] = true;
        $previous = $this->referenceChain;
        $this->referenceChain = [...$previous, ...$resolved['chain']];
        try {
            $audit($resolved['value'], $resolved['pointer']);
        } finally {
            unset($this->activeObjects[$active]);
            $this->referenceChain = $previous;
        }
    }

    private function objectReference(array $node, string $pointer, ?string $operation): void
    {
        if (array_key_exists('$ref', $node)) {
            $this->reference($node['$ref'], "$pointer/\$ref", $operation, false);
        }
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
        if ($root !== null && $servers === []) {
            $this->add('unsupported_server_override', $pointer, 'An empty server override requires relative default-server resolution, which is not implemented.', $operation);
        }
        foreach ($servers as $index => $server) {
            $this->server($server, JsonPointer::append($pointer, $index), $operation);
        }
    }

    private function server(mixed $server, string $pointer, ?string $operation): void
    {
        if (is_array($server)) {
            $this->keys($server, ['url', 'variables'], $pointer, $operation);
        }
        $url = is_array($server) ? ($server['url'] ?? null) : null;
        if (!is_string($url) || !\Dmorenof\SdkGenerator\Parsing\ServerUrl::isSupported($url)) {
            $this->add('unsupported_server_url', "$pointer/url", 'Only literal absolute HTTP(S) server URLs without credentials, query or fragment are executable.', $operation);
        }
        if (is_array($server) && isset($server['variables'])) {
            $this->add('server_variables', "$pointer/variables", 'Server variables are not expanded.', $operation);
        }
    }

    private function parameters(mixed $parameters, string $pointer, ?string $operation, ?string $formMedia = null): void
    {
        if (!is_array($parameters)) {
            return;
        }
        foreach ($parameters as $index => $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $this->resolvedObject($parameter, 'parameters', JsonPointer::append($pointer, $index), $operation,
                fn (array $value, string $at) => $this->parameter($value, $at, $operation, $formMedia));
        }
    }

    private function parameter(array $parameter, string $at, ?string $operation, ?string $formMedia): void
    {
        $formField = ($this->document['swagger'] ?? null) === '2.0' && ($parameter['in'] ?? null) === 'formData';
        $swaggerForm = $formField && \Dmorenof\SdkGenerator\Parsing\FormSupport::swaggerField($parameter, $formMedia);
        if ($formField && !$swaggerForm) {
            $this->add('swagger_form_field', "$at/type", 'Only scalar formData fields and primitive lists matching consumes are executable; files require multipart and multipart lists require explicit multi.', $operation);
        }
        $parameterObject = \Dmorenof\SdkGenerator\Parsing\QueryObjectSupport::rawSupported($parameter, $this->document);
        $parameterArray = \Dmorenof\SdkGenerator\Parsing\QueryArraySupport::rawSupported($parameter, ($this->document['swagger'] ?? null) === '2.0');
        $this->keys($parameter, ['name', 'in', 'required', 'schema', 'content', 'style', 'explode', 'allowReserved', 'allowEmptyValue', 'collectionFormat', '$ref', 'type', 'format', 'items', 'enum', 'default', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'nullable', 'x-nullable'], $at, $operation);
        foreach (['explode', 'allowReserved', 'allowEmptyValue'] as $flag) {
            if (array_key_exists($flag, $parameter) && !is_bool($parameter[$flag])) {
                $this->add('invalid_parameter_option', "$at/$flag", "Parameter $flag must be boolean.", $operation);
            }
        }
        $location = $parameter['in'] ?? null;
        if ($location === 'cookie') {
            $this->add('cookie_parameter', "$at/in", 'Cookie parameter serialization is not implemented.', $operation);
        }
        $pathScalarStyle = !isset($this->document['swagger']) && $location === 'path'
            && in_array($parameter['schema']['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
            && in_array($parameter['style'] ?? null, ['label', 'matrix'], true);
        $default = in_array($location, ['query', 'cookie'], true) ? 'form' : 'simple';
        if (isset($parameter['style']) && $parameter['style'] !== $default && !$parameterArray && !$parameterObject && !$pathScalarStyle) {
            $this->add('parameter_style', "$at/style", 'This parameter style/explode combination is not executable for the declared location and schema.', $operation);
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
        if (isset($parameter['collectionFormat']) && !$parameterArray && !$parameterObject && !$swaggerForm) {
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
        $structured = in_array($type, ['array', 'object'], true)
            || (is_array($type) && array_intersect($type, ['array', 'object']) !== []);
        if ($structured && !$parameterArray && !$parameterObject && !$swaggerForm) {
            $this->add('structured_parameter', isset($parameter['schema']) ? "$at/schema" : "$at/type", 'Only supported primitive query/simple path/header lists and flat deepObject/form/simple parameter objects are executable; other structured parameters remain unsupported.', $operation);
        }
    }

    private function content(mixed $content, string $pointer, ?string $operation, bool $request = false, bool $response = false): void
    {
        if (!is_array($content)) {
            return;
        }
        foreach ($content as $media => $definition) {
            $at = JsonPointer::append($pointer, $media);
            if (!is_array($definition)) {
                continue;
            }
            $form = $request && in_array($media, [\Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA, \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART], true)
                && \Dmorenof\SdkGenerator\Parsing\FormSupport::rawSupported($definition, $this->document, $media === \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART);
            $this->keys($definition, ['schema', 'encoding'], $at, $operation);
            if (array_key_exists('schema', $definition)) {
                $this->schema($definition['schema'], "$at/schema", $operation);
            }
            $binary = $response && $media === \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::MEDIA
                && \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::supported($definition);
            if (!$form && !$binary && !preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media)) {
                $this->add('media_type', $at, 'This representation is preserved but has no supported serializer in this position.', $operation, 'warning');
            }
            if (!empty($definition['encoding']) && !$form) {
                $this->add('media_encoding', "$at/encoding", 'Per-property media encoding is not executed.', $operation);
            }
            foreach ($definition['encoding'] ?? [] as $name => $encoding) {
                if (!is_array($encoding)) {
                    continue;
                }
                $encodingAt = JsonPointer::append("$at/encoding", $name);
                $this->keys($encoding, ['contentType', 'headers', 'style', 'explode', 'allowReserved'], $encodingAt, $operation);
                $this->headers($encoding['headers'] ?? [], "$encodingAt/headers", $operation);
            }
        }
    }

    private function selectedMedia(mixed $content, string $pointer, ?string $operation, bool $request): void
    {
        if (!is_array($content) || $content === []) {
            return;
        }
        if (!$request && \Dmorenof\SdkGenerator\Parsing\BinaryResponseSupport::selected($content)) {
            return;
        }
        $hasJson = $request && count(array_filter(array_keys($content), static fn ($media): bool => is_string($media)
            && preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media) === 1)) > 0;
        foreach (array_keys($content) as $media) {
            if (is_string($media) && strtolower(trim($request ? $media : explode(';', $media, 2)[0])) === 'application/json') {
                return;
            }
            $formMedia = isset($content[\Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA]['schema'])
                ? \Dmorenof\SdkGenerator\Parsing\FormSupport::MEDIA : \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART;
            if ($request && !$hasJson && $media === $formMedia
                && is_array($content[$media]) && \Dmorenof\SdkGenerator\Parsing\FormSupport::rawSupported($content[$media], $this->document, $media === \Dmorenof\SdkGenerator\Parsing\FormSupport::MULTIPART)) {
                return;
            }
        }
        $header = $request ? 'Content-Type' : 'Accept';
        $this->add($request ? 'request_media_type' : 'response_media_type', $pointer,
            ($request ? "No declared request representation matches a supported selected Content-Type serializer." : "No declared success representation matches a supported selected $header codec (application/json or bounded application/octet-stream)."), $operation);
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
        $this->diagnostics[] = new Diagnostic($code, $severity, $pointer, $message, $this->source, $operation, [...$this->referenceChain, ...$chain]);
    }
}
