<?php
declare(strict_types=1);

namespace Dmorenof\SdkGenerator\Parsing;

use Dmorenof\SdkGenerator\Contract\ApiContract;
use Dmorenof\SdkGenerator\Contract\ModelDefinition;
use Dmorenof\SdkGenerator\Contract\OperationDefinition;
use Dmorenof\SdkGenerator\Contract\ParameterDefinition;
use Dmorenof\SdkGenerator\Contract\ParameterSerialization;
use Dmorenof\SdkGenerator\Contract\MediaTypeDefinition;
use Dmorenof\SdkGenerator\Contract\PropertyDefinition;
use Dmorenof\SdkGenerator\Contract\RequestBodyDefinition;
use Dmorenof\SdkGenerator\Contract\ResponseDefinition;
use Dmorenof\SdkGenerator\Contract\SchemaDefinition;
use Dmorenof\SdkGenerator\Contract\DiscriminatorDefinition;
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
 * refs, named local HTTP parameter/body/response refs, primitive/object/array/enum
 * schemas, HTTP path/query/header parameters,
 * JSON bodies and status-based JSON responses. Anything beyond the
 * supported contract subset fails visibly rather than disappearing.
 */
final class SpecificationNormalizer
{
    private bool $jsonSchema31 = false;
    private const METHODS = [
        'get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace',
    ];

    public const array SCHEMA_KEYWORDS = [
            '$ref', 'type', 'format', 'properties', 'required', 'items',
            'enum', 'nullable', 'x-nullable', 'additionalProperties',
            'description', 'title', 'example', 'minLength', 'maxLength',
            'minimum', 'maximum', 'minItems', 'maxItems', 'pattern', 'readOnly',
            'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'uniqueItems', 'minProperties', 'maxProperties', 'const',
            'writeOnly', 'default', 'anyOf', 'oneOf', 'allOf', 'discriminator',
    ];

    public function fromYaml(string $source, bool $strict = false, string $sourceName = '<input>'): ApiContract
    {
        try {
            $document = Yaml::parse($source, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            $json = json_encode($document, JSON_THROW_ON_ERROR);
        } catch (ParseException|JsonException $exception) {
            throw new InvalidArgumentException('Invalid specification YAML.', 0, $exception);
        }

        return $this->fromJson($json, $strict, $sourceName);
    }

    /** Explicit local file graph loading; string-based normalization stays in memory. */
    public function fromFile(string $file, bool $strict = false, ?string $referenceRoot = null): ApiContract
    {
        try {
            $bundle = (new LocalFileBundler())->bundle($file, $referenceRoot);
        } catch (FileReferenceException $exception) {
            if ($strict) {
                throw new \Dmorenof\SdkGenerator\Diagnostics\CompatibilityException([$exception->diagnostic]);
            }
            throw $exception;
        }
        if ($strict) {
            $errors = [];
            foreach ((new \Dmorenof\SdkGenerator\Diagnostics\ContractAuditor())->audit($bundle->document, $bundle->source) as $issue) {
                if ($issue->severity === 'error') {
                    $errors[] = $bundle->mapDiagnostic($issue);
                }
            }
            if ($errors !== []) {
                throw new \Dmorenof\SdkGenerator\Diagnostics\CompatibilityException($errors);
            }
        }
        return $this->fromJson(json_encode($bundle->document, JSON_THROW_ON_ERROR), sourceName: $bundle->source);
    }

    public function fromJson(string $source, bool $strict = false, string $sourceName = '<input>'): ApiContract
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
        $this->jsonSchema31 = str_starts_with($document['openapi'] ?? '', '3.1.');
        if ($strict) {
            $errors = array_values(array_filter(
                (new \Dmorenof\SdkGenerator\Diagnostics\ContractAuditor())->audit($document, $sourceName),
                static fn (\Dmorenof\SdkGenerator\Diagnostics\Diagnostic $issue): bool => $issue->severity === 'error',
            ));
            if ($errors !== []) {
                throw new \Dmorenof\SdkGenerator\Diagnostics\CompatibilityException($errors);
            }
        }
        $document = (new LocalObjectResolver($document))->expandOperations();
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
            if (array_key_exists('parameters', $pathItem)
                && (!is_array($pathItem['parameters']) || !array_is_list($pathItem['parameters']))) {
                throw new InvalidArgumentException("Parameters must be a list at $path (Path Item).");
            }
            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }

                $raw = $pathItem[$method];
                if (!is_array($raw)) {
                    throw new InvalidArgumentException("Invalid operation: $method $path.");
                }
                $id = $raw['operationId'] ?? self::fallbackOperationId($method, $path);
                if (!is_string($id) || $id === '' || isset($seen[$id])) {
                    throw new InvalidArgumentException("Invalid or duplicate operationId at $method $path.");
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
                $serverSource = array_key_exists('servers', $raw) ? 'operation'
                    : (array_key_exists('servers', $pathItem) ? 'path' : 'root');
                $serverDefinitions = $dialect === 'swagger2'
                    ? array_map(static fn (string $url): array => ['url' => $url], $servers)
                    : ($raw['servers'] ?? $pathItem['servers'] ?? $document['servers'] ?? []);
                $operationServers = $serverSource === 'root' ? $servers : array_map(
                    static fn (array $server): string => rtrim($server['url'], '/'), $serverDefinitions,
                );
                $operations[] = new OperationDefinition(
                    $id, strtoupper($method), $path, $parameters, $requestBody, $responses, $security,
                    $operationServers, $serverDefinitions, $serverSource, $this->annotations($raw),
                );
            }
        }

        usort($operations, static fn (OperationDefinition $a, OperationDefinition $b): int
            => [$a->path, $a->method] <=> [$b->path, $b->method]);

        $serverDefinitions = $dialect === 'swagger2'
            ? array_map(static fn (string $url): array => ['url' => $url], $servers)
            : ($document['servers'] ?? []);
        $contract = new ApiContract($title, $servers, $models, $operations, $securitySchemes, $rootSecurity,
            $serverDefinitions, ['info' => $info] + $this->annotations($document));
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

    /** Generate a deterministic operation name without inventing route semantics. */
    private static function fallbackOperationId(string $method, string $path): string
    {
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\{([^{}]+)\}$/D', $part, $match) === 1) {
                $parts[] = 'by';
                $parts[] = $match[1];
            } else {
                $parts[] = $part;
            }
        }
        return implode('.', [strtolower($method), ...($parts === [] ? ['root'] : $parts)]);
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
                if (!in_array($location, $dialect === 'swagger2' ? ['header', 'query'] : ['header', 'query', 'cookie'], true) || !is_string($parameterName) || $parameterName === '') {
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
        // Server order is preference order; sorting can change the default host.
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
        foreach (['Path Item' => $pathParameters, 'operation' => array_key_exists('parameters', $operation) ? $operation['parameters'] : []] as $level => $declarations) {
            if (!is_array($declarations) || !array_is_list($declarations)) {
                throw new InvalidArgumentException("Parameters must be a list at $where ($level).");
            }
            $seen = [];
            foreach ($declarations as $raw) {
                if (!is_array($raw) || isset($raw['$ref'])) {
                    throw new InvalidArgumentException("Unsupported parameter reference at $where.");
                }
                $loc = $raw['in'] ?? null;
                $name = $raw['name'] ?? null;
                if (!is_string($loc) || !is_string($name)) {
                    throw new InvalidArgumentException("Invalid parameter at $where.");
                }
                $identity = $loc . ':' . $name;
                if (isset($seen[$identity])) {
                    throw new InvalidArgumentException("Duplicate parameter $name in $loc at $where ($level).");
                }
                $seen[$identity] = true;
                $merged[$identity] = $raw; // Replace the complete declaration only across levels.
            }
        }
        ksort($merged);

        $parameters = [];
        $requestBody = null;
        $formFields = [];
        $formEncoding = [];
        $hasForm = $dialect === 'swagger2' && array_filter($merged, static fn ($field): bool => $field['in'] === 'formData') !== [];
        $formMedia = FormSupport::swaggerMedia(array_key_exists('consumes', $operation) ? $operation['consumes'] : ($document['consumes'] ?? []));
        if ($hasForm
            && array_filter($merged, static fn ($field): bool => $field['in'] === 'body') !== []) {
            throw new InvalidArgumentException("Swagger body and formData cannot coexist at $where.");
        }
        if ($hasForm && $formMedia === null) {
            throw new InvalidArgumentException("Swagger formData requires a single URL-encoded or multipart consumes representation at $where.");
        }
        foreach ($merged as $raw) {
            if ($dialect === 'swagger2' && (array_key_exists('style', $raw) || array_key_exists('explode', $raw) || array_key_exists('allowReserved', $raw))) {
                throw new InvalidArgumentException("OpenAPI style/explode/allowReserved options are not valid Swagger parameters at $where.");
            }
            $loc = $raw['in'];
            if ($loc === 'formData' && $dialect === 'swagger2') {
                if (!FormSupport::swaggerField($raw, $formMedia)) {
                    throw new InvalidArgumentException("Unsupported Swagger formData field {$raw['name']} at $where.");
                }
                $fieldSchema = array_diff_key($raw, array_flip(['name', 'in', 'required', 'collectionFormat', 'allowEmptyValue']), $this->annotations($raw));
                if (isset($raw['description'])) {
                    $fieldSchema['description'] = $raw['description'];
                }
                if (array_key_exists('x-nullable', $raw)) {
                    $fieldSchema['x-nullable'] = $raw['x-nullable'];
                }
                if ($raw['type'] === 'file') {
                    $fieldSchema['type'] = 'string';
                    $fieldSchema['format'] = 'binary';
                }
                $formFields[] = new PropertyDefinition($raw['name'],
                    $this->schema($fieldSchema, $dialect, "$where.formData." . $raw['name']), $raw['required'] ?? false);
                if ($raw['type'] === 'array') {
                    $formEncoding[$raw['name']] = ['style' => 'form', 'explode' => ($raw['collectionFormat'] ?? 'csv') === 'multi']
                        + (in_array($raw['collectionFormat'] ?? 'csv', ['ssv', 'tsv', 'pipes'], true) ? ['collectionFormat' => $raw['collectionFormat']] : []);
                }
                continue;
            }
            if ($loc === 'body' && $dialect === 'swagger2') {
                if ($requestBody !== null || !is_array($raw['schema'] ?? null)) {
                    throw new InvalidArgumentException("Invalid or duplicated body at $where.");
                }
                $mediaTypes = $operation['consumes'] ?? $document['consumes'] ?? ['application/json'];
                $this->requireJsonMedia($mediaTypes, "$where consumes");
                $requestBody = new RequestBodyDefinition(
                    $mediaTypes[0],
                    $this->schema($raw['schema'], $dialect, "$where.body"),
                    (bool) ($raw['required'] ?? false),
                    $this->swaggerContent($operation['consumes'] ?? $document['consumes'] ?? ['application/json'], $raw['schema'], $dialect, "$where.body"),
                    $this->annotations($raw),
                );
                continue;
            }
            if (!in_array($loc, $dialect === 'swagger2' ? ['path', 'query', 'header'] : ['path', 'query', 'header', 'cookie'], true)) {
                throw new InvalidArgumentException("Unsupported parameter location $loc at $where.");
            }
            $parameterContent = null;
            if (array_key_exists('content', $raw)) {
                $parameterContent = $dialect !== 'swagger2' ? ParameterContentSupport::raw($raw) : null;
                if ($parameterContent === null) {
                    throw new InvalidArgumentException("Unsupported or conflicting parameter content at $where.");
                }
            }
            $schema = $dialect === 'swagger2'
                ? array_diff_key($raw, array_flip(['name', 'in', 'required', 'description', 'style', 'explode', 'allowReserved', 'allowEmptyValue', 'collectionFormat', 'deprecated', 'example', 'examples']), $this->annotations($raw))
                : ($raw['schema'] ?? $parameterContent['schema'] ?? null);
            if (!is_array($schema)) {
                throw new InvalidArgumentException("Missing parameter schema at $where.");
            }
            $normalizedSchema = $this->schema($schema, $dialect, "$where.parameters." . $raw['name']);
            if ($dialect === 'swagger2' && $normalizedSchema->kind === SchemaKind::Object) {
                throw new InvalidArgumentException("Swagger non-body object parameters are unsupported at $where.");
            }
            $arraySchema = $normalizedSchema->kind === SchemaKind::Array
                || ($normalizedSchema->kind === SchemaKind::AnyOf && count(array_filter($normalizedSchema->branches,
                    static fn (SchemaDefinition $branch): bool => $branch->kind === SchemaKind::Array)) > 0);
            $parameters[] = new ParameterDefinition(
                $raw['name'], $loc,
                $normalizedSchema,
                (bool) ($raw['required'] ?? false),
                new ParameterSerialization($raw['style'] ?? null, $raw['explode'] ?? null,
                    $raw['allowReserved'] ?? null, $raw['allowEmptyValue'] ?? null, $raw['collectionFormat'] ?? null,
                    $dialect === 'swagger2' && $arraySchema ? 'csv' : null),
                $this->mediaContent($raw['content'] ?? [], $dialect, "$where.parameters." . $raw['name']),
                $this->annotations($raw),
            );
        }

        if ($formFields !== []) {
            $formSchema = new SchemaDefinition(SchemaKind::Object, properties: $formFields, additionalProperties: false);
            $requestBody = new RequestBodyDefinition($formMedia, $formSchema,
                count(array_filter($formFields, static fn ($field): bool => $field->required)) > 0,
                [new MediaTypeDefinition($formMedia, $formSchema, $formEncoding)]);
        }

        if ($dialect !== 'swagger2' && isset($operation['requestBody'])) {
            $raw = $operation['requestBody'];
            if (!is_array($raw) || isset($raw['$ref'])) {
                throw new InvalidArgumentException("Unsupported requestBody reference at $where.");
            }
            $content = $raw['content'] ?? [];
            $hasJson = count(array_filter(array_keys($content), fn ($media): bool => is_string($media) && $this->isJsonMediaType($media))) > 0;
            $formMedia = isset($content[FormSupport::MEDIA]['schema']) ? FormSupport::MEDIA : FormSupport::MULTIPART;
            $bodyContent = !$hasJson && isset($content[$formMedia]['schema'])
                ? ['mediaType' => $formMedia, 'schema' => $content[$formMedia]['schema']]
                : $this->jsonContent($content, "$where.requestBody");
            if ($bodyContent === null) {
                throw new InvalidArgumentException("Missing supported request body at $where.");
            }
            $requestBody = new RequestBodyDefinition(
                $bodyContent['mediaType'],
                $this->schema($bodyContent['schema'], $dialect, "$where.requestBody"),
                (bool) ($raw['required'] ?? false),
                $this->mediaContent($raw['content'] ?? [], $dialect, "$where.requestBody"),
                $this->annotations($raw),
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
            if ($status !== 'default' && preg_match($dialect === 'swagger2' ? '/^[1-5][0-9]{2}$/' : '/^[1-5](?:[0-9]{2}|XX)$/', $status) !== 1) {
                throw new InvalidArgumentException("Unsupported response status $status at $where.");
            }
            if (!is_array($raw) || isset($raw['$ref'])) {
                throw new InvalidArgumentException("Unsupported response reference at $where.");
            }
            if ($dialect === 'swagger2') {
                $schema = $raw['schema'] ?? null;
                $produces = $operation['produces'] ?? $document['produces'] ?? ['application/json'];
                $binarySchema = is_array($schema) ? BinaryResponseSupport::swaggerSchema($schema, $produces) : null;
                if ($binarySchema !== null) {
                    if (preg_match('/^2(?:[0-9]{2}|XX)$/', $status) !== 1) {
                        throw new InvalidArgumentException("Binary error responses are unsupported at $where.responses.$status.");
                    }
                    $schema = $binarySchema;
                } elseif ($schema !== null) {
                    $this->requireJsonMedia($produces, "$where produces");
                }
                $mediaType = $schema === null ? null : $produces[0];
            } else {
                $representations = $raw['content'] ?? [];
                $binary = BinaryResponseSupport::selected($representations);
                if ($binary && preg_match('/^2(?:[0-9]{2}|XX)$/', $status) !== 1) {
                    throw new InvalidArgumentException("Binary error responses are unsupported at $where.responses.$status.");
                }
                $content = $binary
                    ? ['mediaType' => BinaryResponseSupport::MEDIA, 'schema' => $representations[BinaryResponseSupport::MEDIA]['schema']]
                    : $this->jsonContent($representations, "$where.responses.$status");
                $schema = $content['schema'] ?? null;
                $mediaType = $content['mediaType'] ?? null;
            }
            $responses[] = new ResponseDefinition(
                $status,
                $mediaType,
                $schema === null ? null : $this->schema($schema, $dialect, "$where.responses.$status"),
                $dialect === 'swagger2'
                    ? ($schema === null ? [] : $this->swaggerContent($operation['produces'] ?? $document['produces'] ?? ['application/json'], $schema, $dialect, "$where.responses.$status"))
                    : $this->mediaContent($raw['content'] ?? [], $dialect, "$where.responses.$status"),
                $raw['headers'] ?? [], $raw['links'] ?? [], $this->annotations($raw),
            );
        }
        return $responses;
    }

    /** @return array<string, mixed> */
    private function annotations(array $raw): array
    {
        return array_filter($raw, static fn ($key): bool => in_array($key,
            ['summary', 'description', 'title', 'example', 'examples', 'deprecated', 'externalDocs', 'tags'], true)
            || str_starts_with((string) $key, 'x-'), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<MediaTypeDefinition> */
    private function mediaContent(array $content, string $dialect, string $where): array
    {
        $representations = [];
        foreach ($content as $media => $raw) {
            if (!is_string($media) || !is_array($raw)) {
                throw new InvalidArgumentException("Invalid content representation at $where.");
            }
            $schema = null;
            $unparsed = null;
            if (array_key_exists('schema', $raw)) {
                try {
                    $schema = $this->schema($raw['schema'], $dialect, "$where.content.$media");
                } catch (InvalidArgumentException) {
                    // An unsupported, unselected alternative must remain visible.
                    // The selected JSON schema is normalized separately and still fails.
                    $unparsed = $raw['schema'];
                }
            }
            $representations[] = new MediaTypeDefinition($media, $schema, $raw['encoding'] ?? [], $this->annotations($raw), $unparsed);
        }
        return $representations;
    }

    /** @return list<MediaTypeDefinition> */
    private function swaggerContent(array $mediaTypes, array $schema, string $dialect, string $where): array
    {
        $content = [];
        foreach ($mediaTypes as $media) {
            $content[$media] = ['schema' => $schema];
        }
        return $this->mediaContent($content, $dialect, $where);
    }

    private function requireJsonMedia(array $mediaTypes, string $where): void
    {
        if (count($mediaTypes) !== 1 || !is_string($mediaTypes[0] ?? null)
            || !$this->isJsonMediaType($mediaTypes[0])) {
            throw new InvalidArgumentException("Unsupported media type at $where: only JSON media types are implemented.");
        }
    }

    /** @return array{mediaType:string,schema:array|bool}|null */
    private function jsonContent(array $content, string $where): ?array
    {
        if ($content === []) {
            return null;
        }
        $json = [];
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !$this->isJsonMediaType($mediaType)) {
                continue; // XML/text alternatives are not emitted by a JSON SDK.
            }
            if (!is_array($definition) || (!is_array($definition['schema'] ?? null) && !($this->jsonSchema31 && is_bool($definition['schema'] ?? null)))) {
                throw new InvalidArgumentException("Missing JSON schema for media type $mediaType at $where.");
            }
            $json[$mediaType] = $definition['schema'];
        }
        if ($json === []) {
            throw new InvalidArgumentException("No supported JSON representation at $where.");
        }
        // Do not silently choose between incompatible JSON contract variants.
        $first = reset($json);
        foreach ($json as $mediaType => $schema) {
            if ($schema !== $first) {
                throw new InvalidArgumentException(
                    "Conflicting JSON schemas across media types at $where ($mediaType)."
                );
            }
        }
        uksort($json, static function (string $a, string $b): int {
            $rank = static function (string $media): int {
                $media = strtolower(trim($media));
                if ($media === 'application/json') {
                    return 0;
                }
                return str_starts_with($media, 'application/json;') ? 1 : 2;
            };
            return ($rank($a) <=> $rank($b)) ?: strcmp($a, $b);
        });
        $mediaType = (string) array_key_first($json);
        return ['mediaType' => $mediaType, 'schema' => $json[$mediaType]];
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        // MIME parameters such as "application/json; v=1.0" are legal.
        $mediaType = strtolower(trim(explode(';', $mediaType, 2)[0]));
        return preg_match('~^application/(?:[a-z0-9!#$&^_.+-]+\+)?json$~iD', $mediaType) === 1;
    }

    private function schema(mixed $raw, string $dialect, string $where): SchemaDefinition
    {
        if (is_bool($raw) && $this->jsonSchema31) {
            return new SchemaDefinition(SchemaKind::Mixed, validation: ['schema' => $raw]);
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException("Schema must be an object at $where.");
        }

        $allowed = self::SCHEMA_KEYWORDS;
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

        if (is_array($raw['type'] ?? null) && !(count($raw['type']) === 2 && in_array('null', $raw['type'], true))) {
            $types = $raw['type'];
            if (!$this->jsonSchema31 || !array_is_list($types) || $types === []
                || array_filter($types, static fn ($type): bool => !is_string($type)) !== []
                || count(array_unique($types)) !== count($types)
                || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null']) !== []) {
                throw new InvalidArgumentException("Unsupported type union at $where.");
            }
            $allowed = ['type', 'enum', 'const', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'title', 'description', 'default', 'readOnly', 'writeOnly', 'format'];
            if (array_diff(array_keys($raw), $allowed) !== []) { throw new InvalidArgumentException("Unsupported union siblings at $where."); }
            foreach (['readOnly', 'writeOnly'] as $flag) {
                if (array_key_exists($flag, $raw) && !is_bool($raw[$flag])) { throw new InvalidArgumentException("Invalid $flag at $where."); }
            }
            foreach (['title', 'description', 'format'] as $annotation) {
                if (array_key_exists($annotation, $raw) && !is_string($raw[$annotation])) { throw new InvalidArgumentException("Invalid $annotation at $where."); }
            }
            if (isset($raw['enum']) && (!is_array($raw['enum']) || !array_is_list($raw['enum']))) { throw new InvalidArgumentException("Invalid union enum at $where."); }
            $numeric = array_intersect_key($raw, array_flip(['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf']));
            $textual = array_intersect_key($raw, array_flip(['minLength', 'maxLength', 'pattern']));
            if ($numeric !== []) { $this->schema(['type' => 'number'] + $numeric, $dialect, $where . '.numeric'); }
            if ($textual !== []) { $this->schema(['type' => 'string'] + $textual, $dialect, $where . '.string'); }
            $guard = array_intersect_key($raw, array_flip(['type', 'enum', 'const', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern']));
            // Parse each applicable rule with its declared primitive type for structural checks.
            foreach ($types as $type) {
                $applicable = array_intersect_key($raw, array_flip(match ($type) {
                    'integer', 'number' => ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
                    'string' => ['minLength', 'maxLength', 'pattern'], default => [],
                }));
                $this->schema(['type' => $type] + $applicable, $dialect, $where . '.type.' . $type);
            }
            return new SchemaDefinition(SchemaKind::AnyOf, validation: ['schema' => $guard],
                branches: array_map(static fn (string $type): SchemaDefinition => new SchemaDefinition(SchemaKind::from($type)), $types),
                readOnly: $raw['readOnly'] ?? false, writeOnly: $raw['writeOnly'] ?? false,
                title: $raw['title'] ?? null, description: $raw['description'] ?? null,
                format: $raw['format'] ?? null, default: $raw['default'] ?? null, hasDefault: array_key_exists('default', $raw));
        }

        $discriminator = null;
        if (array_key_exists('discriminator', $raw)) {
            $value = $raw['discriminator'];
            if (!is_array($value) || !is_string($value['propertyName'] ?? null) || $value['propertyName'] === ''
                || array_diff(array_keys($value), ['propertyName', 'mapping']) !== []) {
                throw new InvalidArgumentException("Invalid or unsupported discriminator at $where.");
            }
            $mapping = $value['mapping'] ?? [];
            if (!is_array($mapping)) {
                throw new InvalidArgumentException("Invalid discriminator mapping at $where.");
            }
            $prefix = $dialect === 'swagger2' ? '#/definitions/' : '#/components/schemas/';
            foreach ($mapping as $tag => $target) {
                if (!is_string($target) || $target === '') {
                    throw new InvalidArgumentException("Invalid discriminator mapping target at $where.");
                }
                $name = str_starts_with($target, $prefix) ? substr($target, strlen($prefix)) : $target;
                if ($name === '' || strpbrk($name, '/#:') !== false) {
                    throw new InvalidArgumentException("Unsupported non-local discriminator mapping at $where.");
                }
                $mapping[$tag] = $name;
            }
            ksort($mapping);
            $discriminator = new DiscriminatorDefinition($value['propertyName'], $mapping);
        }

        $compositionKeys = array_values(array_intersect(['anyOf', 'oneOf', 'allOf'], array_keys($raw)));
        if ($compositionKeys !== []) {
            if (count($compositionKeys) !== 1) {
                throw new InvalidArgumentException("Only one composition keyword is supported at $where.");
            }
            $composition = $compositionKeys[0];
            $siblings = array_diff(array_keys($raw), [$composition, 'description', 'title', 'discriminator']);
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
                discriminator: $discriminator,
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
            if (!is_array($raw['items'] ?? null) && !($this->jsonSchema31 && is_bool($raw['items'] ?? null))) {
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
            'multipleOf' => SchemaKind::Number,
            'minProperties' => SchemaKind::Object,
            'maxProperties' => SchemaKind::Object,
        ];
        $validation = [];
        foreach ($rules as $rule => $expected) {
            if (!array_key_exists($rule, $raw)) {
                continue;
            }
            $value = $raw[$rule];
            // Numeric bounds also apply to floating-point JSON numbers.
            $applies = $kind === $expected
                || (in_array($rule, ['minimum', 'maximum', 'multipleOf'], true) && in_array($kind, [SchemaKind::Number, SchemaKind::Integer], true));
            if (!$applies) {
                throw new InvalidArgumentException("Constraint $rule is not applicable to $kind->value at $where.");
            }
            $numeric = in_array($rule, ['minimum', 'maximum', 'multipleOf'], true);
            if ($numeric ? (!is_int($value) && !is_float($value))
                : (!is_int($value) || $value < 0)) {
                throw new InvalidArgumentException("Invalid $rule limit at $where.");
            }
            if ($numeric && (!is_finite((float) $value) || ($rule === 'multipleOf' && $value <= 0))) {
                throw new InvalidArgumentException("Invalid $rule limit at $where.");
            }
            $validation[$rule] = $value;
        }
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $inclusive) {
            if (!array_key_exists($exclusive, $raw)) { continue; }
            if (!in_array($kind, [SchemaKind::Integer, SchemaKind::Number], true)) { throw new InvalidArgumentException("Numeric $exclusive requires a numeric type at $where."); }
            $value = $raw[$exclusive];
            if (!$this->jsonSchema31) {
                if (!is_bool($value) || ($value && !isset($validation[$inclusive]))) { throw new InvalidArgumentException("Invalid boolean $exclusive at $where."); }
                if ($value) { $validation[$exclusive] = $validation[$inclusive]; unset($validation[$inclusive]); }
            } else {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) { throw new InvalidArgumentException("Invalid numeric $exclusive at $where."); }
                $validation[$exclusive] = $value;
            }
        }
        if (array_key_exists('uniqueItems', $raw)) {
            if ($kind !== SchemaKind::Array || !is_bool($raw['uniqueItems'])) { throw new InvalidArgumentException("Invalid uniqueItems at $where."); }
            $validation['uniqueItems'] = $raw['uniqueItems'];
        }
        if (array_key_exists('const', $raw)) {
            if (!$this->jsonSchema31 || !in_array($kind, [SchemaKind::String, SchemaKind::Integer, SchemaKind::Number, SchemaKind::Boolean, SchemaKind::Null, SchemaKind::Mixed], true) || (is_array($raw['const']) || is_object($raw['const']))) { throw new InvalidArgumentException("Only OpenAPI 3.1 scalar/null const values are supported at $where."); }
            $validation['const'] = $raw['const'];
        }
        foreach ([['minLength', 'maxLength'], ['minimum', 'maximum'], ['minItems', 'maxItems'], ['minProperties', 'maxProperties']] as [$lower, $upper]) {
            if (isset($validation[$lower], $validation[$upper]) && $validation[$lower] > $validation[$upper]) {
                throw new InvalidArgumentException("Contradictory $lower and $upper at $where.");
            }
        }

        if ($kind === SchemaKind::Array && $items !== null && array_key_exists('schema', $items->validation)) {
            $validation['schema'] = ['type' => $nullable ? ['array', 'null'] : 'array', 'items' => $items->validation['schema']];
        }

        if ($discriminator !== null && $kind !== SchemaKind::Object) {
            throw new InvalidArgumentException("Discriminator requires an object schema at $where.");
        }
        return new SchemaDefinition(
            kind: $kind,
            discriminator: $discriminator,
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
        foreach ($schema->discriminator?->mapping ?? [] as $target) {
            if (!isset($knownModels[$target])) {
                throw new InvalidArgumentException("Unresolved discriminator mapping '$target' at $where.");
            }
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
