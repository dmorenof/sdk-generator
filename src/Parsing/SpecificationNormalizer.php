<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\ParameterDefinition;
use Dmorenof\SdkGenerator\Contract\PropertyDefinition;
use Dmorenof\SdkGenerator\Contract\RequestBodyDefinition;
use Dmorenof\SdkGenerator\Contract\ResponseDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaKind;
use InvalidArgumentException;
use JsonException;

/**
 * First, intentionally small cross-dialect normalization slice.
 *
 * Supported: JSON Swagger 2.0, OpenAPI 3.0.x and 3.1.x; local model refs,
 * primitive/object/array/enum schemas, HTTP path/query/header parameters,
 * JSON bodies and status-based JSON responses. Anything beyond the
 * supported contract subset fails visibly rather than disappearing.
 */
final class SpecificationNormalizer
{
    private const METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace',
    ];

    public function fromJson(string $source): ApiContract
    {
        try {
            $document = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid specification JSON.', 0, $exception);
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('API specification must be a JSON object.');
        }

        $dialect = $this->dialect($document);
        $info = $document['info'] ?? [];
        $title = $info['title'] ?? null;
        if (!is_string($title) || $title === '') {
            throw new InvalidArgumentException('info.title must be a nonempty string.');
        }

        // Security is deliberately not silently discarded until the IR models it.
        if (!empty($document['security'])) {
            throw new InvalidArgumentException('Unsupported security requirements at specification root.');
        }

        $servers = $this->servers($document, $dialect);
        $definitions = $dialect === 'swagger2'
            ? ($document['definitions'] ?? [])
            : ($document['components']['schemas'] ?? []);

        if (!is_array($definitions)) {
            throw new InvalidArgumentException('Model definitions must be an object.');
        }
        ksort($definitions);
        $models = [];
        foreach ($definitions as $name => $raw) {
            $models[] = new ModelDefinition(
                (string) $name,
                $this->schema($raw, $dialect, "models.$name"),
            );
        }

        $paths = $document['paths'] ?? null;
        if (!is_array($paths)) {
            throw new InvalidArgumentException('paths must be an object.');
        }
        ksort($paths);
        $operations = [];
        $seen = [];
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($pathItem)) {
                throw new InvalidArgumentException('Invalid path item in paths.');
            }
            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }

                $raw = $pathItem[$method];
                if (!is_array($raw)) {
                    throw new InvalidArgumentException("Invalid operation: $method $path.");
                }
                $id = $raw['operationId'] ?? null;
                if (!is_string($id) || $id === '' || isset($seen[$id])) {
                    throw new InvalidArgumentException("Missing or duplicate operationId at $method $path.");
                }
                $seen[$id] = true;
                if (!empty($raw['security'])) {
                    throw new InvalidArgumentException("Unsupported operation security at $method $path.");
                }

                [$parameters, $requestBody] = $this->parametersAndBody(
                    $pathItem['parameters'] ?? [],
                    $raw,
                    $document,
                    $dialect,
                    "$method $path",
                );
                $responses = $this->responses($raw, $document, $dialect, "$method $path");
                $operations[] = new OperationDefinition(
                    $id, strtoupper($method), $path, $parameters, $requestBody, $responses,
                );
            }
        }

        usort($operations, static fn (OperationDefinition $a, OperationDefinition $b): int
            => [$a->path, $a->method] <=> [$b->path, $b->method]);

        $contract = new ApiContract($title, $servers, $models, $operations);
        $knownModels = array_fill_keys(array_map(
            static fn (ModelDefinition $model): string => $model->name,
            $models,
        ), true);

        foreach ($models as $model) {
            $this->checkReferences($model->schema, $knownModels, "models.$model->name");
        }
        foreach ($operations as $operation) {
            foreach ($operation->parameters as $parameter) {
                $this->checkReferences($parameter->schema, $knownModels, $operation->operationId . '.' . $parameter->wireName);
            }
            if ($operation->requestBody !== null) {
                $this->checkReferences($operation->requestBody->schema, $knownModels, $operation->operationId . '.requestBody');
            }
            foreach ($operation->responses as $response) {
                if ($response->schema !== null) {
                    $this->checkReferences($response->schema, $knownModels, $operation->operationId . '.responses.' . $response->status);
                }
            }
        }

        return $contract;
    }

    private function dialect(array $document): string
    {
        if (($document['swagger'] ?? null) === '2.0') {
            return 'swagger2';
        }

        $version = $document['openapi'] ?? null;
        if (is_string($version) && preg_match('/^3\.(0|1)\.\d+$/', $version) === 1) {
            return 'openapi3';
        }

        throw new InvalidArgumentException('Unsupported specification version (expected Swagger 2.0 or OpenAPI 3.0/3.1).');
    }

    /** @return list<string> */
    private function servers(array $document, string $dialect): array
    {
        if ($dialect === 'swagger2') {
            $host = $document['host'] ?? null;
            $schemes = $document['schemes'] ?? [];
            $basePath = rtrim((string) ($document['basePath'] ?? ''), '/');
            if (!is_string($host) || $host === '' || !is_array($schemes)) {
                throw new InvalidArgumentException('Swagger 2.0 fixture requires explicit host and schemes.');
            }
            $servers = array_map(static fn ($scheme): string => $scheme . '://' . $host . $basePath, $schemes);
        } else {
            $servers = [];
            foreach ($document['servers'] ?? [] as $server) {
                if (!is_array($server) || !is_string($server['url'] ?? null) || isset($server['variables'])) {
                    throw new InvalidArgumentException('Only literal OpenAPI server URLs are supported in this slice.');
                }
                $servers[] = rtrim($server['url'], '/');
            }
        }
        sort($servers);
        return $servers;
    }

    /**
     * @return array{0: list<ParameterDefinition>, 1: ?RequestBodyDefinition}
     */
    private function parametersAndBody(
        array $pathParameters,
        array $operation,
        array $document,
        string $dialect,
        string $where,
    ): array {
        $merged = [];
        foreach (array_merge($pathParameters, $operation['parameters'] ?? []) as $raw) {
            if (!is_array($raw) || isset($raw['$ref'])) {
                throw new InvalidArgumentException("Unsupported parameter reference at $where.");
            }
            $loc = $raw['in'] ?? null;
            $name = $raw['name'] ?? null;
            if (!is_string($loc) || !is_string($name)) {
                throw new InvalidArgumentException("Invalid parameter at $where.");
            }
            $merged[$loc . ':' . $name] = $raw; // Operation overrides path-level.
        }
        ksort($merged);

        $parameters = [];
        $requestBody = null;
        foreach ($merged as $raw) {
            $loc = $raw['in'];
            if ($loc === 'body' && $dialect === 'swagger2') {
                if ($requestBody !== null || !is_array($raw['schema'] ?? null)) {
                    throw new InvalidArgumentException("Invalid or duplicated body at $where.");
                }
                $this->requireJsonMedia($operation['consumes'] ?? $document['consumes'] ?? ['application/json'], "$where consumes");
                $requestBody = new RequestBodyDefinition(
                    'application/json',
                    $this->schema($raw['schema'], $dialect, "$where.body"),
                    (bool) ($raw['required'] ?? false),
                );
                continue;
            }
            if (!in_array($loc, ['path', 'query', 'header'], true)) {
                throw new InvalidArgumentException("Unsupported parameter location $loc at $where.");
            }
            $schema = $dialect === 'swagger2' ? $raw : ($raw['schema'] ?? null);
            if (!is_array($schema)) {
                throw new InvalidArgumentException("Missing parameter schema at $where.");
            }
            $parameters[] = new ParameterDefinition(
                $raw['name'], $loc,
                $this->schema($schema, $dialect, "$where.parameters." . $raw['name']),
                (bool) ($raw['required'] ?? false),
            );
        }

        if ($dialect !== 'swagger2' && isset($operation['requestBody'])) {
            $raw = $operation['requestBody'];
            if (!is_array($raw) || isset($raw['$ref'])) {
                throw new InvalidArgumentException("Unsupported requestBody reference at $where.");
            }
            $bodySchema = $this->jsonContentSchema($raw['content'] ?? [], "$where.requestBody");
            if ($bodySchema === null) {
                throw new InvalidArgumentException("Missing JSON request body at $where.");
            }
            $requestBody = new RequestBodyDefinition(
                'application/json',
                $this->schema($bodySchema, $dialect, "$where.requestBody"),
                (bool) ($raw['required'] ?? false),
            );
        }

        return [$parameters, $requestBody];
    }

    /** @return list<ResponseDefinition> */
    private function responses(array $operation, array $document, string $dialect, string $where): array
    {
        $rawResponses = $operation['responses'] ?? null;
        if (!is_array($rawResponses) || $rawResponses === []) {
            throw new InvalidArgumentException("Missing responses at $where.");
        }
        ksort($rawResponses);
        $responses = [];
        foreach ($rawResponses as $status => $raw) {
            $status = (string) $status;
            if ($status !== 'default' && preg_match('/^[1-5][0-9]{2}$/', $status) !== 1) {
                throw new InvalidArgumentException("Unsupported response status $status at $where.");
            }
            if (!is_array($raw) || isset($raw['$ref'])) {
                throw new InvalidArgumentException("Unsupported response reference at $where.");
            }
            if ($dialect === 'swagger2') {
                $schema = $raw['schema'] ?? null;
                if ($schema !== null) {
                    $this->requireJsonMedia($operation['produces'] ?? $document['produces'] ?? ['application/json'], "$where produces");
                }
            } else {
                $schema = $this->jsonContentSchema($raw['content'] ?? [], "$where.responses.$status");
            }
            $responses[] = new ResponseDefinition(
                $status,
                $schema === null ? null : 'application/json',
                $schema === null ? null : $this->schema($schema, $dialect, "$where.responses.$status"),
            );
        }
        return $responses;
    }

    private function requireJsonMedia(array $mediaTypes, string $where): void
    {
        if ($mediaTypes !== ['application/json']) {
            throw new InvalidArgumentException("Unsupported media type at $where: only application/json is implemented.");
        }
    }

    private function jsonContentSchema(array $content, string $where): ?array
    {
        if ($content === []) {
            return null;
        }
        if (count($content) !== 1 || !is_array($content['application/json']['schema'] ?? null)) {
            throw new InvalidArgumentException("Unsupported response/request media type or missing schema at $where.");
        }
        return $content['application/json']['schema'];
    }

    private function schema(mixed $raw, string $dialect, string $where): SchemaDefinition
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException("Schema must be an object at $where.");
        }

        $allowed = [
            '$ref', 'type', 'format', 'properties', 'required', 'items',
            'enum', 'nullable', 'x-nullable', 'additionalProperties',
            'description', 'title', 'example',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported schema keyword '$key' at $where.");
            }
        }

        if (isset($raw['$ref'])) {
            if (count(array_diff(array_keys($raw), ['$ref', 'description'])) > 0) {
                throw new InvalidArgumentException("Reference siblings are not supported at $where.");
            }
            $prefix = $dialect === 'swagger2' ? '#/definitions/' : '#/components/schemas/';
            $reference = $raw['$ref'];
            if (!is_string($reference) || !str_starts_with($reference, $prefix)) {
                throw new InvalidArgumentException("Unsupported non-local model reference at $where.");
            }
            $name = substr($reference, strlen($prefix));
            if ($name === '' || str_contains($name, '/')) {
                throw new InvalidArgumentException("Invalid local model reference at $where.");
            }
            return new SchemaDefinition(SchemaKind::Reference, reference: $name);
        }

        $type = $raw['type'] ?? null;
        $nullable = (bool) ($raw['nullable'] ?? $raw['x-nullable'] ?? false);
        if (is_array($type)) {
            // OAS 3.1's JSON Schema nullable union subset.
            $notNull = array_values(array_filter($type, static fn ($value): bool => $value !== 'null'));
            if (count($notNull) !== 1 || count($type) !== 2 || !in_array('null', $type, true)) {
                throw new InvalidArgumentException("Unsupported union schema type at $where.");
            }
            $type = $notNull[0];
            $nullable = true;
        }
        if ($type === null && (isset($raw['properties']) || array_key_exists('additionalProperties', $raw))) {
            $type = 'object';
        }
        if (!is_string($type)) {
            throw new InvalidArgumentException("Schema type is missing or unsupported at $where.");
        }
        $kind = SchemaKind::tryFrom($type);
        if ($kind === null || $kind === SchemaKind::Reference) {
            throw new InvalidArgumentException("Unsupported schema type '$type' at $where.");
        }
        if (isset($raw['format']) && !is_string($raw['format'])) {
            throw new InvalidArgumentException("Invalid schema format at $where.");
        }

        $properties = [];
        $items = null;
        $additional = null;
        if ($kind === SchemaKind::Object) {
            $propertySchemas = $raw['properties'] ?? [];
            $required = $raw['required'] ?? [];
            if (!is_array($propertySchemas) || !is_array($required)) {
                throw new InvalidArgumentException("Invalid object properties at $where.");
            }
            ksort($propertySchemas);
            foreach ($propertySchemas as $name => $propSchema) {
                $properties[] = new PropertyDefinition(
                    (string) $name,
                    $this->schema($propSchema, $dialect, "$where.properties.$name"),
                    in_array($name, $required, true),
                );
            }
            if (array_key_exists('additionalProperties', $raw)) {
                $v = $raw['additionalProperties'];
                if (is_bool($v)) {
                    $additional = $v;
                } elseif (is_array($v)) {
                    $additional = $this->schema($v, $dialect, "$where.additionalProperties");
                } else {
                    throw new InvalidArgumentException("Invalid additionalProperties at $where.");
                }
            }
        } elseif ($kind === SchemaKind::Array) {
            if (!is_array($raw['items'] ?? null)) {
                throw new InvalidArgumentException("Array items are missing at $where.");
            }
            $items = $this->schema($raw['items'], $dialect, "$where.items");
        }

        $enumValues = $raw['enum'] ?? [];
        if (!is_array($enumValues)) {
            throw new InvalidArgumentException("Invalid enum at $where.");
        }
        usort($enumValues, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));

        return new SchemaDefinition(
            kind: $kind,
            nullable: $nullable,
            format: $raw['format'] ?? null,
            items: $items,
            properties: $properties,
            enumValues: $enumValues,
            additionalProperties: $additional,
        );
    }

    /** @param array<string, true> $knownModels */
    private function checkReferences(SchemaDefinition $schema, array $knownModels, string $where): void
    {
        if ($schema->kind === SchemaKind::Reference && !isset($knownModels[$schema->reference])) {
            throw new InvalidArgumentException("Unresolved schema reference '$schema->reference' at $where.");
        }
        if ($schema->items !== null) {
            $this->checkReferences($schema->items, $knownModels, "$where.items");
        }
        foreach ($schema->properties as $property) {
            $this->checkReferences($property->schema, $knownModels, "$where.properties.$property->wireName");
        }
        if ($schema->additionalProperties instanceof SchemaDefinition) {
            $this->checkReferences($schema->additionalProperties, $knownModels, "$where.additionalProperties");
        }
    }
}
