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
use Dmorenof\SdkGenerator\Contract\SecuritySchemeDefinition;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * First, intentionally small cross-dialect normalization slice.
 *
 * Supported: JSON or YAML Swagger 2.0, OpenAPI 3.0.x and 3.1.x; local model
 * refs, primitive/object/array/enum schemas, HTTP path/query/header parameters,
 * JSON bodies and status-based JSON responses. Anything beyond the
 * supported contract subset fails visibly rather than disappearing.
 */
final class SpecificationNormalizer
{
    private const METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace',
    ];

    public function fromYaml(string $source): ApiContract
    {
        try {
            $document = Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            $json = json_encode($document, JSON_THROW_ON_ERROR);
        } catch (ParseException|JsonException $exception) {
            throw new InvalidArgumentException('Invalid specification YAML.', 0, $exception);
        }

        return $this->fromJson($json);
    }

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

        $servers = $this->servers($document, $dialect);
        $securitySchemes = $this->securitySchemes($document, $dialect);
        $securitySchemeNames = array_fill_keys(
            array_map(static fn (SecuritySchemeDefinition $scheme): string => $scheme->name, $securitySchemes),
            true,
        );
        $rootSecurity = $this->securityRequirements(
            $document['security'] ?? [],
            $securitySchemeNames,
            'specification root',
        );
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
                $security = array_key_exists('security', $raw)
                    ? $this->securityRequirements(
                        $raw['security'],
                        $securitySchemeNames,
                        "$method $path",
                    )
                    : $rootSecurity;

                [$parameters, $requestBody] = $this->parametersAndBody(
                    $pathItem['parameters'] ?? [],
                    $raw,
                    $document,
                    $dialect,
                    "$method $path",
                );
                $responses = $this->responses($raw, $document, $dialect, "$method $path");
                $operations[] = new OperationDefinition(
                    $id, strtoupper($method), $path, $parameters, $requestBody, $responses, $security,
                );
            }
        }

        usort($operations, static fn (OperationDefinition $a, OperationDefinition $b): int
            => [$a->path, $a->method] <=> [$b->path, $b->method]);

        $contract = new ApiContract($title, $servers, $models, $operations, $securitySchemes, $rootSecurity);
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

    /** @return list<SecuritySchemeDefinition> */
    private function securitySchemes(array $document, string $dialect): array
    {
        $definitions = $dialect === 'swagger2'
            ? ($document['securityDefinitions'] ?? [])
            : ($document['components']['securitySchemes'] ?? []);
        if (!is_array($definitions) || array_is_list($definitions) && $definitions !== []) {
            throw new InvalidArgumentException('Security scheme definitions must be an object.');
        }

        $schemes = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new InvalidArgumentException('Invalid security scheme definition.');
            }
            $type = $definition['type'] ?? null;
            if ($dialect === 'swagger2' && $type === 'basic') {
                $schemes[] = new SecuritySchemeDefinition($name, 'http', 'basic');
            } elseif ($type === 'http') {
                $httpScheme = strtolower((string) ($definition['scheme'] ?? ''));
                if (!in_array($httpScheme, ['basic', 'bearer'], true)) {
                    throw new InvalidArgumentException("Unsupported HTTP security scheme at $name.");
                }
                $schemes[] = new SecuritySchemeDefinition($name, 'http', $httpScheme);
            } elseif ($type === 'apiKey') {
                $location = $definition['in'] ?? null;
                $parameterName = $definition['name'] ?? null;
                if (!in_array($location, ['header', 'query'], true) || !is_string($parameterName) || $parameterName === '') {
                    throw new InvalidArgumentException("Unsupported API key security scheme at $name.");
                }
                $schemes[] = new SecuritySchemeDefinition($name, 'apiKey', null, $location, $parameterName);
            } else {
                throw new InvalidArgumentException("Unsupported security scheme type at $name.");
            }
        }
        return $schemes;
    }

    /**
     * @param mixed $raw
     * @param array<string, true> $knownSchemes
     * @return list<array<string, list<string>>>
     */
    private function securityRequirements(mixed $raw, array $knownSchemes, string $where): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException("Security requirements must be an array at $where.");
        }

        $requirements = [];
        foreach ($raw as $requirement) {
            if (!is_array($requirement) || (array_is_list($requirement) && $requirement !== [])) {
                throw new InvalidArgumentException("Invalid security requirement at $where.");
            }
            $normalized = [];
            foreach ($requirement as $name => $scopes) {
                if (!is_string($name) || !isset($knownSchemes[$name])) {
                    throw new InvalidArgumentException("Unknown security scheme $name at $where.");
                }
                if (!is_array($scopes) || !array_is_list($scopes)
                    || array_filter($scopes, static fn ($scope): bool => !is_string($scope)) !== []) {
                    throw new InvalidArgumentException("Invalid security scopes for $name at $where.");
                }
                if ($scopes !== []) {
                    throw new InvalidArgumentException("OAuth security scopes are unsupported at $where.");
                }
                $normalized[$name] = [];
            }
            $requirements[] = $normalized;
        }
        return $requirements;
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
            $schema = $dialect === 'swagger2'
                ? array_diff_key($raw, array_flip(['name', 'in', 'required', 'description']))
                : ($raw['schema'] ?? null);
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
            $bodyContent = $this->jsonContent($raw['content'] ?? [], "$where.requestBody");
            if ($bodyContent === null) {
                throw new InvalidArgumentException("Missing JSON request body at $where.");
            }
            $requestBody = new RequestBodyDefinition(
                $bodyContent['mediaType'],
                $this->schema($bodyContent['schema'], $dialect, "$where.requestBody"),
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
                $mediaType = $schema === null ? null : 'application/json';
            } else {
                $content = $this->jsonContent($raw['content'] ?? [], "$where.responses.$status");
                $schema = $content['schema'] ?? null;
                $mediaType = $content['mediaType'] ?? null;
            }
            $responses[] = new ResponseDefinition(
                $status,
                $mediaType,
                $schema === null ? null : $this->schema($schema, $dialect, "$where.responses.$status"),
            );
        }
        return $responses;
    }

    private function requireJsonMedia(array $mediaTypes, string $where): void
    {
        if (count($mediaTypes) !== 1 || !is_string($mediaTypes[0] ?? null)
            || !$this->isJsonMediaType($mediaTypes[0])) {
            throw new InvalidArgumentException("Unsupported media type at $where: only JSON media types are implemented.");
        }
    }

    /** @return array{mediaType:string,schema:array}|null */
    private function jsonContent(array $content, string $where): ?array
    {
        if ($content === []) {
            return null;
        }
        if (count($content) !== 1) {
            throw new InvalidArgumentException("Unsupported multiple response/request media types at $where.");
        }
        $mediaType = (string) array_key_first($content);
        if (!$this->isJsonMediaType($mediaType) || !is_array($content[$mediaType] ?? null)
            || !is_array($content[$mediaType]['schema'] ?? null)) {
            throw new InvalidArgumentException("Unsupported response/request media type or missing schema at $where.");
        }
        return ['mediaType' => $mediaType, 'schema' => $content[$mediaType]['schema']];
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return preg_match(
            '~^application/(?:[a-z0-9!#$&^_.+-]+\+)?json$~iD',
            $mediaType,
        ) === 1;
    }

    private function schema(mixed $raw, string $dialect, string $where): SchemaDefinition
    {
        if (!is_array($raw)) {
            throw new InvalidArgumentException("Schema must be an object at $where.");
        }

        $allowed = [
            '$ref', 'type', 'format', 'properties', 'required', 'items',
            'enum', 'nullable', 'x-nullable', 'additionalProperties',
            'description', 'title', 'example', 'minLength', 'maxLength',
            'minimum', 'maximum', 'minItems', 'maxItems', 'pattern', 'readOnly',
            'writeOnly', 'default', 'anyOf', 'oneOf', 'allOf',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported schema keyword '$key' at $where.");
            }
        }
        foreach (['title', 'description'] as $annotation) {
            if (isset($raw[$annotation]) && !is_string($raw[$annotation])) {
                throw new InvalidArgumentException("Invalid schema $annotation at $where.");
            }
        }

        $compositionKeys = array_values(array_intersect(['anyOf', 'oneOf', 'allOf'], array_keys($raw)));
        if ($compositionKeys !== []) {
            if (count($compositionKeys) !== 1) {
                throw new InvalidArgumentException("Only one composition keyword is supported at $where.");
            }
            $composition = $compositionKeys[0];
            $siblings = array_diff(array_keys($raw), [$composition, 'description', 'title']);
            if ($siblings !== []) {
                throw new InvalidArgumentException("Composition siblings are not supported at $where.");
            }
            $branches = $raw[$composition];
            if (!is_array($branches) || !array_is_list($branches) || $branches === []) {
                throw new InvalidArgumentException("Composition '$composition' must contain a nonempty list at $where.");
            }
            $kind = SchemaKind::from($composition);
            return new SchemaDefinition(
                kind: $kind,
                title: $raw['title'] ?? null,
                description: $raw['description'] ?? null,
                branches: array_map(
                    fn (mixed $branch, int $index): SchemaDefinition =>
                        $this->schema($branch, $dialect, "$where.$composition.$index"),
                    $branches,
                    array_keys($branches),
                ),
            );
        }

        if (isset($raw['$ref'])) {
            if (count(array_diff(array_keys($raw), ['$ref', 'description', 'title'])) > 0) {
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
            return new SchemaDefinition(
                SchemaKind::Reference,
                reference: $name,
                title: $raw['title'] ?? null,
                description: $raw['description'] ?? null,
            );
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
        if ($type === null && array_key_exists('pattern', $raw)) {
            $type = 'string';
        }
        if ($type === null) {
            $type = 'mixed';
        }
        if (!is_string($type)) {
            throw new InvalidArgumentException("Schema type is invalid at $where.");
        }
        $kind = SchemaKind::tryFrom($type);
        if ($kind === null || in_array($kind, [SchemaKind::Reference, SchemaKind::AnyOf, SchemaKind::OneOf, SchemaKind::AllOf], true)) {
            throw new InvalidArgumentException("Unsupported schema type '$type' at $where.");
        }
        if (isset($raw['format']) && !is_string($raw['format'])) {
            throw new InvalidArgumentException("Invalid schema format at $where.");
        }
        if (array_key_exists('pattern', $raw) && !is_string($raw['pattern'])) {
            throw new InvalidArgumentException("Invalid schema pattern at $where.");
        }
        if (isset($raw['pattern'])) {
            $regex = '~' . str_replace('~', '\\~', $raw['pattern']) . '~u';
            if (@preg_match($regex, '') === false) {
                throw new InvalidArgumentException("Unsupported or invalid schema pattern at $where.");
            }
            if (!in_array($kind, [SchemaKind::String, SchemaKind::Mixed], true)) {
                throw new InvalidArgumentException("Constraint pattern is not applicable to $kind->value at $where.");
            }
        }
        if (isset($raw['readOnly']) && !is_bool($raw['readOnly'])) {
            throw new InvalidArgumentException("Invalid readOnly value at $where.");
        }
        if (isset($raw['writeOnly']) && !is_bool($raw['writeOnly'])) {
            throw new InvalidArgumentException("Invalid writeOnly value at $where.");
        }
        if (array_key_exists('default', $raw)) {
            try {
                json_encode($raw['default'], JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new InvalidArgumentException("Invalid default value at $where.", 0, $exception);
            }
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

        $rules = [
            'minLength' => SchemaKind::String,
            'maxLength' => SchemaKind::String,
            'minimum' => SchemaKind::Integer,
            'maximum' => SchemaKind::Integer,
            'minItems' => SchemaKind::Array,
            'maxItems' => SchemaKind::Array,
        ];
        $validation = [];
        foreach ($rules as $rule => $expected) {
            if (!array_key_exists($rule, $raw)) {
                continue;
            }
            $value = $raw[$rule];
            // Numeric bounds also apply to floating-point JSON numbers.
            $applies = $kind === $expected
                || (in_array($rule, ['minimum', 'maximum'], true) && $kind === SchemaKind::Number);
            if (!$applies) {
                throw new InvalidArgumentException("Constraint $rule is not applicable to $kind->value at $where.");
            }
            $numeric = in_array($rule, ['minimum', 'maximum'], true);
            if ($numeric ? (!is_int($value) && !is_float($value))
                : (!is_int($value) || $value < 0)) {
                throw new InvalidArgumentException("Invalid $rule limit at $where.");
            }
            $validation[$rule] = $value;
        }
        foreach ([['minLength', 'maxLength'], ['minimum', 'maximum'], ['minItems', 'maxItems']] as [$lower, $upper]) {
            if (isset($validation[$lower], $validation[$upper]) && $validation[$lower] > $validation[$upper]) {
                throw new InvalidArgumentException("Contradictory $lower and $upper at $where.");
            }
        }

        return new SchemaDefinition(
            kind: $kind,
            nullable: $nullable,
            format: $raw['format'] ?? null,
            items: $items,
            properties: $properties,
            enumValues: $enumValues,
            additionalProperties: $additional,
            validation: $validation,
            pattern: $raw['pattern'] ?? null,
            readOnly: $raw['readOnly'] ?? false,
            default: $raw['default'] ?? null,
            hasDefault: array_key_exists('default', $raw),
            writeOnly: $raw['writeOnly'] ?? false,
            title: $raw['title'] ?? null,
            description: $raw['description'] ?? null,
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
        foreach ($schema->branches as $index => $branch) {
            $this->checkReferences($branch, $knownModels, "$where.branches.$index");
        }
        if ($schema->additionalProperties instanceof SchemaDefinition) {
            $this->checkReferences($schema->additionalProperties, $knownModels, "$where.additionalProperties");
        }
    }
}
