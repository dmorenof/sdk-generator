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
    /** The document being read, to resolve the references a key-name schema holds. @var array<mixed> */
    private array $document = [];

    /** Formats that only document a value: the runtime never asserts them, so no rule is worth emitting. */
    private const array ANNOTATION_FORMATS = ['binary', 'password', 'float', 'double'];
    private const array NUMERIC_WIDTH_HINTS = ['int32', 'int64', 'float', 'double'];

    /** @param bool $validateFormats Emit a runtime `format` rule for string and integer schemas, so declared formats are asserted (they are annotations otherwise). */
    public function __construct(private readonly bool $validateFormats = false, private readonly bool $skipUnsupported = false, private readonly ?string $origin = null, private readonly bool $repair = false) { $this->policy = new RepairPolicy($repair); }

    /** @var array<string, true> References into the inside of a model being read in place, against loops. */
    private array $inlining = [];

    /** Defects of the document that are repaired instead of refused (only when asked to repair); see {@see RepairPolicy}. */
    private RepairPolicy $policy;

    /**
     * What the last contract read changed to make a defective document executable, each with where and what was done.
     *
     * @return list<array{where: string, message: string}>
     */
    public function repairs(): array
    {
        return $this->policy->notes();
    }

    /** @var array<string, string> The operations left out because the generator cannot execute them, with the reason. */
    private array $skipped = [];

    /**
     * Operations left out of the last contract read (only when asked to skip what is unsupported), each with its reason.
     *
     * @return array<string, string>
     */
    public function skippedOperations(): array
    {
        return $this->skipped;
    }
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
            'not', 'if', 'then', 'else', 'contains', 'minContains', 'maxContains',
            'dependentRequired', 'dependentSchemas', 'propertyNames', 'prefixItems', 'patternProperties', 'unevaluatedProperties', 'unevaluatedItems',
    ];

    /**
     * Keywords of JSON Schema 2020-12 (and its predecessors) that carry meaning this generator does not execute. Any other keyword
     * in an OpenAPI 3.1 schema is not defined by the dialect, which reads it as an annotation; these are not annotations.
     */
    public const array UNSUPPORTED_VOCABULARY = [
        '$id', '$schema', '$anchor', '$dynamicRef', '$dynamicAnchor', '$vocabulary', '$defs', '$recursiveRef', '$recursiveAnchor',
        'definitions', 'dependencies', 'additionalItems',
    ];

    /** Annotation-only keywords of JSON Schema 2020-12's content vocabulary: a string that holds encoded data stays that string. */
    public const array CONTENT_VOCABULARY = ['contentEncoding', 'contentMediaType', 'contentSchema'];

    public function fromYaml(string $source, bool $strict = false, string $sourceName = '<input>'): ApiContract
    {
        try {
            $document = SpecificationData::yaml($source);
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
            $document = SpecificationData::json($source);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid specification JSON.', 0, $exception);
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('API specification must be a JSON object.');
        }

        $document = EnumUnions::collapse($document);
        $dialect = $this->dialect($document);
        $this->jsonSchema31 = preg_match('/^3\.[12]\./', $document['openapi'] ?? '') === 1;
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
        $this->document = $document;
        $this->skipped = [];
        $this->policy = new RepairPolicy($this->repair);
        $info = $document['info'] ?? [];
        $title = $info['title'] ?? null;
        if (!is_string($title) || $title === '') {
            throw new InvalidArgumentException('info.title must be a nonempty string.');
        }

        $servers = $this->servers($document, $dialect);
        $securitySchemes = $this->securitySchemes($document, $dialect);
        // Scheme name => whether its requirements may list scopes (only OAuth2 and OpenID Connect use them).
        $securitySchemeNames = [];
        foreach ($securitySchemes as $scheme) {
            $securitySchemeNames[$scheme->name] = in_array($scheme->type, ['oauth2', 'openIdConnect'], true);
        }
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
        if ($this->policy->enabled()) { $paths = $this->mergeEquivalentPaths($paths); }
        $operations = [];
        $seen = [];
        $pathIdentities = [];
        foreach ($paths as $path => $pathItem) {
            if (is_string($path) && str_starts_with($path, 'x-')) { continue; }
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($pathItem)) {
                throw new InvalidArgumentException('Invalid path item in paths.');
            }
            if (strpbrk($path, '?#') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) {
                throw new InvalidArgumentException("Path keys cannot contain query, fragment or control bytes: $path.");
            }
            $this->pathNames($path);
            $identity = preg_replace('/\{[^{}]+\}/', '{}', $path);
            if (isset($pathIdentities[$identity])) { throw new InvalidArgumentException("Equivalent templated paths: {$pathIdentities[$identity]} and $path."); }
            $pathIdentities[$identity] = $path;
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
                try {
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
                    if ($method === 'trace' && $requestBody !== null) {
                        throw new InvalidArgumentException("TRACE cannot declare a request body at $path.");
                    }
                    $parameters = $this->pathParameters($path, $parameters, "$method $path");
                    $responses = $this->responses($raw, $document, $dialect, "$method $path", $method === 'head');
                    $serverSource = array_key_exists('servers', $raw) ? 'operation'
                        : (array_key_exists('servers', $pathItem) ? 'path' : 'root');
                    $serverDefinitions = $dialect === 'swagger2'
                        ? array_map(static fn (string $url): array => ['url' => $url], $servers)
                        : ($serverSource === 'operation' ? $raw['servers'] : ($serverSource === 'path' ? $pathItem['servers'] : ($document['servers'] ?? [])));
                    $operationServers = $serverSource === 'root' ? $servers : $this->serverUrls($serverDefinitions);
                    if ($dialect === 'swagger2' && array_key_exists('schemes', $raw)) {
                        $operationServers = $this->servers(array_replace($document, ['schemes' => $raw['schemes']]), $dialect);
                        $serverDefinitions = array_map(static fn (string $url): array => ['url' => $url], $operationServers);
                        $serverSource = 'operation';
                    }
                    // The emitter refuses a form body it cannot build, which would abort the whole API after this point: in skip
                    // mode that operation is left out like any other unsupported one (without the option the emitter still refuses).
                    if ($this->skipUnsupported && $requestBody !== null
                        && in_array($requestBody->mediaType, [FormSupport::MEDIA, FormSupport::MULTIPART], true)
                        && FormSupport::formats($requestBody, $models) === null) {
                        throw new InvalidArgumentException("Unsupported form body schema or encoding at $id.");
                    }
                    $operations[] = new OperationDefinition(
                        $id, strtoupper($method), $path, $parameters, $requestBody, $responses, $security,
                        $operationServers, $serverDefinitions, $serverSource, $this->annotations($raw),
                    );
                } catch (InvalidArgumentException $error) {
                    if (!$this->skipUnsupported) { throw $error; }
                    $this->skipped["$method $path"] = $error->getMessage();
                }
            }
        }

        usort($operations, static fn (OperationDefinition $a, OperationDefinition $b): int
            => [$a->path, $a->method] <=> [$b->path, $b->method]);

        $serverDefinitions = $dialect === 'swagger2'
            ? array_map(static fn (string $url): array => ['url' => $url], $servers)
            : ($document['servers'] ?? []);
        $contract = new ApiContract($title, $servers, $models, $operations, $securitySchemes, $rootSecurity,
            $serverDefinitions, ($this->policy->enabled() ? ['x-sdk-repair' => true] : []) + ['info' => $info] + $this->annotations($document) + ($this->annotations($paths) === [] ? [] : ['paths' => $this->annotations($paths)]));
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
        if (is_string($version) && preg_match('/^3\.(0|1|2)\.\d+$/', $version) === 1) {
            return 'openapi3';
        }

        throw new InvalidArgumentException('Unsupported specification version (expected Swagger 2.0 or OpenAPI 3.0/3.1/3.2).');
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
            } elseif ($type === 'oauth2') {
                $problem = OAuthSchemeRules::oauth2Problem($definition, $dialect === 'swagger2');
                if ($problem !== null) {
                    throw new InvalidArgumentException("$problem (security scheme $name)");
                }
                $schemes[] = new SecuritySchemeDefinition($name, 'oauth2');
            } elseif ($type === 'openIdConnect' && $dialect !== 'swagger2') {
                $problem = OAuthSchemeRules::openIdConnectProblem($definition);
                if ($problem !== null) {
                    throw new InvalidArgumentException("$problem (security scheme $name)");
                }
                $schemes[] = new SecuritySchemeDefinition($name, 'openIdConnect', openIdConnectUrl: (string) $definition['openIdConnectUrl']);
            } elseif ($type === 'mutualTLS' && preg_match('/^3\.[12]\./', (string) ($document['openapi'] ?? '')) === 1) {
                // The client proves who it is with a certificate in the TLS handshake: no header, query or cookie, no scopes.
                $schemes[] = new SecuritySchemeDefinition($name, 'mutualTLS');
            } else {
                throw new InvalidArgumentException("Unsupported security scheme type at $name.");
            }
        }
        return $schemes;
    }

    /**
     * @param mixed $raw
     * @param array<string, bool> $knownSchemes Scheme name => whether its requirements may list scopes.
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
                if ($scopes !== [] && !$knownSchemes[$name]) {
                    throw new InvalidArgumentException("Scopes are only valid for OAuth2 and OpenID Connect schemes ($name at $where).");
                }
                $normalized[$name] = $scopes;
            }
            $requirements[] = $normalized;
        }
        return $requirements;
    }

    /**
     * Two templates that differ only in the names of their placeholders (`.../{id}` and `.../{templateName}`) are one URL. The second
     * one's operations move under the first, with its path parameters renamed; an operation that would collide is left out.
     *
     * @param array<mixed> $paths
     * @return array<mixed>
     */
    private function mergeEquivalentPaths(array $paths): array
    {
        $first = [];
        foreach ($paths as $path => $item) {
            if (!is_string($path) || !is_array($item) || str_starts_with($path, 'x-')) { continue; }
            $identity = preg_replace('/\{[^{}]+\}/', '{}', $path);
            if (!is_string($identity) || !isset($first[$identity])) { $first[$identity ?? $path] = $path; continue; }
            $base = $first[$identity];
            preg_match_all('/\{([^{}]+)\}/', $base, $to);
            preg_match_all('/\{([^{}]+)\}/', $path, $from);
            $rename = array_combine($from[1], $to[1]) ?: [];
            $rewrite = static function (array $parameters) use ($rename): array {
                foreach ($parameters as $i => $parameter) {
                    if (is_array($parameter) && ($parameter['in'] ?? null) === 'path' && isset($rename[$parameter['name'] ?? ''])) { $parameters[$i]['name'] = $rename[$parameter['name']]; }
                }
                return $parameters;
            };
            foreach ($item as $key => $value) {
                if (!is_array($value)) { continue; }
                if ($key === 'parameters') { $item[$key] = $rewrite($value); continue; }
                if (isset($value['parameters']) && is_array($value['parameters'])) { $item[$key]['parameters'] = $rewrite($value['parameters']); }
            }
            foreach ($item as $key => $value) {
                if (in_array($key, self::METHODS, true) && !isset($paths[$base][$key])) {
                    $paths[$base][$key] = $value;
                } elseif (in_array($key, self::METHODS, true)) {
                    $this->policy->note("$key $path", "The path is the same URL as $base and that one already has this method: it was left out.");
                }
            }
            $this->policy->note($path, "The path is the same URL as $base with other placeholder names: its operations were moved under it.");
            unset($paths[$path]);
        }
        return $paths;
    }

    /** @return list<string> */
    private function pathNames(string $path): array {
        preg_match_all('/\{([^{}]+)\}/', $path, $matches);
        if (strpbrk(preg_replace('/\{[^{}]+\}/', '', $path), '{}') !== false) {
            throw new InvalidArgumentException("Malformed path template at $path.");
        }
        return $matches[1];
    }

    /**
     * Path placeholders and path parameters must match, and a path parameter is required. A document that breaks this is refused;
     * when asked to repair, a placeholder without a declaration becomes a required string, a declaration without a placeholder
     * is dropped, and a path parameter that is not required is made required (each recorded).
     *
     * @param list<ParameterDefinition> $parameters
     * @return list<ParameterDefinition>
     */
    private function pathParameters(string $path, array $parameters, string $where): array {
        $names = $this->pathNames($path);
        $declared = [];
        $kept = [];
        foreach ($parameters as $parameter) {
            if ($parameter->location !== 'path') { $kept[] = $parameter; continue; }
            if (!in_array($parameter->wireName, $names, true)) {
                $this->policy->mend($where, "Path parameter '$parameter->wireName' has no matching placeholder at $where.", "The path parameter '$parameter->wireName' has no placeholder in the path: it was dropped.");
                continue;
            }
            if (!$parameter->required) {
                $this->policy->mend($where, "Path parameter '$parameter->wireName' must be required at $where.", "The path parameter '$parameter->wireName' is not required, and a placeholder always is: it was made required.");
                $parameter = new ParameterDefinition($parameter->wireName, $parameter->location, $parameter->schema, true, $parameter->serialization, $parameter->content, $parameter->annotations);
            }
            $declared[$parameter->wireName] = true;
            $kept[] = $parameter;
        }
        foreach ($names as $name) {
            if (isset($declared[$name])) { continue; }
            $this->policy->mend($where, "Missing declared path parameter '$name' at $where.", "The placeholder '{{$name}}' has no path parameter: a required string was declared.");
            // A placeholder called `url_path` or `full_path` is the rest of the path (Starlette's `path` converter, which uDA uses): it
            // keeps its slashes.
            $restOfPath = preg_match('/(^|_)path$/', $name) === 1;
            $kept[] = new ParameterDefinition($name, 'path', new SchemaDefinition(SchemaKind::String), true, $restOfPath ? new \Dmorenof\SdkGenerator\Contract\ParameterSerialization(allowReserved: true) : new \Dmorenof\SdkGenerator\Contract\ParameterSerialization());
        }
        return $kept;
    }

    /** @return list<string> */
    private function serverUrls(mixed $definitions): array {
        if (!is_array($definitions) || !array_is_list($definitions)) { throw new InvalidArgumentException('Servers must be a list.'); }
        $urls = [];
        foreach ($definitions as $server) {
            if (!is_array($server)) { throw new InvalidArgumentException('Server definitions must be objects.'); }
            $urls[] = ServerUrl::expand($server);
        }
        return $urls;
    }

    /** @return list<string> */
    private function servers(array $document, string $dialect): array
    {
        if ($dialect === 'swagger2') {
            $host = $document['host'] ?? null;
            $schemes = $document['schemes'] ?? [];
            $basePath = $document['basePath'] ?? '';
            // A document that does not say where its API is served is served where it was read from: `--server` tells us.
            if (($host === null || $host === '') && $this->origin !== null) {
                $parts = parse_url($this->origin);
                $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $schemes = $schemes === [] && isset($parts['scheme']) ? [$parts['scheme']] : $schemes;
            }
            if (!is_string($host) || $host === '' || !is_array($schemes) || !array_is_list($schemes) || $schemes === []
                || !is_string($basePath) || ($basePath !== '' && !str_starts_with($basePath, '/'))
                || preg_match('/[?#\x00-\x20\x7f{}]/', $basePath)) {
                throw new InvalidArgumentException('Swagger 2.0 requires an explicit host, HTTP(S) schemes and a valid absolute basePath; source-relative defaults need document-origin resolution.');
            }
            try {
                $authority = new \GuzzleHttp\Psr7\Uri('//' . $host);
                if ($authority->getHost() === '' || $authority->getUserInfo() !== '' || $authority->getPath() !== ''
                    || $authority->getQuery() !== '' || $authority->getFragment() !== '') {
                    throw new InvalidArgumentException('Swagger host must contain only a hostname and optional port.');
                }
            } catch (\Throwable $error) {
                throw new InvalidArgumentException('Invalid Swagger host: ' . $error->getMessage(), 0, $error);
            }
            $servers = [];
            foreach ($schemes as $scheme) {
                if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Swagger transfer schemes currently support only http and https.');
                }
                $servers[] = \Dmorenof\SdkRuntime\ServerUrl::normalize($scheme . '://' . $host . $basePath);
            }
        } else {
            $declared = array_key_exists('servers', $document) ? $document['servers'] : [];
            $servers = $this->serverUrls($declared === [] && $this->origin !== null ? [['url' => $this->origin]] : $declared);
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
        $formMedia = FormSupport::swaggerMedia(array_key_exists('consumes', $operation) ? $operation['consumes'] : (array_key_exists('consumes', $document) ? $document['consumes'] : false), $merged);
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
            if (array_key_exists('required', $raw) && !is_bool($raw['required'])) { throw new InvalidArgumentException("Parameter required flag must be boolean at $where."); }
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
                $mediaTypes = $this->collapseJsonMedia($operation['consumes'] ?? $document['consumes'] ?? ['application/json']);
                // A Swagger `in: body` parameter with a model schema under a form `consumes` (Marketo's asset API) contradicts itself:
                // a form has fields, not a model. When asked to repair, the body is the JSON model it describes.
                if ($this->policy->enabled() && count($mediaTypes) === 1 && in_array($mediaTypes[0], [FormSupport::MEDIA, FormSupport::MULTIPART], true)) {
                    $this->policy->note($where, "A body model under consumes {$mediaTypes[0]} was read as application/json.");
                    $mediaTypes = ['application/json'];
                }
                if (count($mediaTypes) !== 1 || !RawMediaSupport::supported(['schema' => $raw['schema']], $mediaTypes[0] ?? '', $document, true)) {
                    $this->requireJsonMedia($mediaTypes, "$where consumes");
                }
                $requestBody = new RequestBodyDefinition(
                    $mediaTypes[0],
                    $this->schema($raw['schema'], $dialect, "$where.body"),
                    (bool) ($raw['required'] ?? false),
                    $this->swaggerContent($mediaTypes, $raw['schema'], $dialect, "$where.body"),
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
            // A free-form object in the query with no style (GoCardless's `metadata`) cannot be written in the default form style
            // without naming its keys; when asked to repair it is a map of strings written as `metadata[key]=value`, the usual way.
            if ($this->policy->enabled() && $loc === 'query' && !isset($raw['style']) && !isset($raw['content']) && $dialect !== 'swagger2') {
                try { $plain = SchemaReferences::raw($schema, $this->document); } catch (InvalidArgumentException|\TypeError) { $plain = []; }
                if (($plain['type'] ?? null) === 'object' && !isset($plain['properties']) && !array_key_exists('additionalProperties', $plain)
                    && array_intersect(['allOf', 'anyOf', 'oneOf'], array_keys($plain)) === []) {
                    $this->policy->note("$where.parameters." . $raw['name'], 'A free-form object parameter without style was read as a map of strings in deepObject style.');
                    $normalizedSchema = new SchemaDefinition(SchemaKind::Object, additionalProperties: new SchemaDefinition(SchemaKind::String));
                    $raw['style'] = 'deepObject';
                    $raw['explode'] = true;
                }
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
            // A multipart body whose schema is just `type: string, format: binary` (Infobip's audio upload) is one file field: when
            // asked to repair, it is an object with a `file` part.
            if ($this->policy->enabled() && is_array($content) && is_array($content['multipart/form-data']['schema'] ?? null)
                && ($content['multipart/form-data']['schema']['type'] ?? null) === 'string' && ($content['multipart/form-data']['schema']['format'] ?? null) === 'binary') {
                $this->policy->note("$where.requestBody", 'A multipart body that is a single binary string was read as an object with one `file` part.');
                $content['multipart/form-data']['schema'] = ['type' => 'object', 'required' => ['file'], 'additionalProperties' => false,
                    'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]];
            }
            $hasJson = count(array_filter(array_keys($content), fn ($media): bool => is_string($media) && $this->isJsonMediaType($media))) > 0;
            $formMedia = isset($content[FormSupport::MEDIA]['schema']) ? FormSupport::MEDIA : FormSupport::MULTIPART;
            $bodyContent = !$hasJson && isset($content[$formMedia]['schema'])
                ? ['mediaType' => $formMedia, 'schema' => $content[$formMedia]['schema']]
                : (RawMediaSupport::selected($content, $document, true) ?? $this->jsonContent($content, "$where.requestBody"));
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
    private function responses(array $operation, array $document, string $dialect, string $where, bool $head = false): array
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
            $declared = null;
            $bodyless = $head || $status === '1XX' || (ctype_digit($status)
                && !\Dmorenof\SdkRuntime\Operation::allowsResponseBody('GET', (int) $status));
            if ($bodyless) {
                $schema = null;
                $mediaType = null;
            } elseif ($dialect === 'swagger2') {
                $schema = $raw['schema'] ?? null;
                $produces = $this->collapseJsonMedia($operation['produces'] ?? $document['produces'] ?? ['application/json']);
                $json = array_values(array_filter($produces, fn ($media): bool => is_string($media) && $this->isJsonMediaType($media)));
                if ($schema === null && preg_match('/^2\d\d$/', $status) === 1 && $produces !== [] && $json === []
                    && array_filter($produces, static fn ($media): bool => !is_string($media) || !RawMediaSupport::rawMedia($media)) === []) {
                    // `produces: [image/svg+xml, image/png]` and a response with no schema: a file, in the first form listed.
                    $schema = ['type' => 'file'];
                    $produces = [$produces[0]];
                } elseif ($schema !== null && count($produces) > 1 && $json !== [] && !RawMediaSupport::supported(['schema' => $schema], (string) $produces[0], $document, false)) {
                    // `produces` is for the whole operation: a response with a JSON schema speaks the JSON one of them.
                    $produces = [$json[0]];
                }
                $binarySchema = is_array($schema) ? BinaryResponseSupport::swaggerSchema($schema, $produces) : null;
                if ($binarySchema !== null) {
                    $schema = $binarySchema;
                } elseif ($schema !== null) {
                    if (count($produces) !== 1 || !RawMediaSupport::supported(['schema' => $schema], $produces[0] ?? '', $document, false)) {
                        $this->requireJsonMedia($produces, "$where produces");
                    }
                }
                $mediaType = $schema === null ? null : $produces[0];
            } else {
                $representations = $raw['content'] ?? [];
                // `application/octet-stream` means bytes, whatever schema is declared beside it (Infobip's recordings point at an object
                // model): when asked to repair, the answer is read as binary.
                if ($this->policy->enabled() && is_array($representations) && isset($representations['application/octet-stream']) && is_array($representations['application/octet-stream'])
                    && count($representations) === 1 && BinaryResponseSupport::media($representations, $document) === null) {
                    $this->policy->note("$where.responses.$status", 'An application/octet-stream answer with a model schema was read as binary.');
                    $representations['application/octet-stream']['schema'] = ['type' => 'string', 'format' => 'binary'];
                }
                $binary = BinaryResponseSupport::media($representations, $document);
                $isSuccess = preg_match('/^2\d\d$/', $status) === 1 && is_array($representations);
                $streamed = $isSuccess ? StreamingSupport::selected($representations) : null;
                $declared = $isSuccess && $streamed === null ? AlternativesSupport::select($representations, $document) : null;
                $content = $declared !== null
                    ? ['mediaType' => $declared[0]['mediaType'], 'schema' => $declared[0]['schema']]
                    : ($streamed ?? ($binary !== null
                    ? ['mediaType' => $binary, 'schema' => BinaryResponseSupport::withSchema($representations[$binary])['schema']]
                    : (RawMediaSupport::selected($representations, $document, false) ?? $this->jsonContent($representations, "$where.responses.$status"))));
                $schema = $content['schema'] ?? null;
                $mediaType = $content['mediaType'] ?? null;
            }
            $schemaForContent = $bodyless && $dialect === 'swagger2' ? ($raw['schema'] ?? null) : $schema;
            $responses[] = new ResponseDefinition(
                $status,
                $mediaType,
                $schema === null ? null : $this->schema($schema, $dialect, "$where.responses.$status"),
                $dialect === 'swagger2'
                    ? (!is_array($schemaForContent) ? [] : $this->swaggerContent($operation['produces'] ?? $document['produces'] ?? ['application/json'], $schemaForContent, $dialect, "$where.responses.$status"))
                    : $this->mediaContent($raw['content'] ?? [], $dialect, "$where.responses.$status"),
                $raw['headers'] ?? [], $raw['links'] ?? [], $this->annotations($raw) + ($bodyless ? ['bodySuppressedByHttp' => true] : []),
                array_map(fn (array $alternative): array => [
                    'mediaType' => $alternative['mediaType'], 'encoding' => $alternative['encoding'],
                    'schema' => $alternative['schema'] === null ? null : $this->schema($alternative['schema'], $dialect, "$where.responses.$status.content.{$alternative['mediaType']}"),
                ], $declared ?? []),
            );
        }
        // An operation that lists only failures (Petstore's `addPet` declares just a 405 for invalid input) succeeds
        // with any 2XX: the answer is not described, so it is returned as nothing rather than refused.
        $declaresSuccess = array_filter($responses, static fn (ResponseDefinition $response): bool => $response->status === 'default'
            || preg_match('/^2(?:[0-9]{2}|XX)$/', $response->status) === 1) !== [];
        if ($responses !== [] && !$declaresSuccess && !$head) {
            $responses[] = new ResponseDefinition('2XX', null, null, [], [], [], ['x-implicit-success' => true], []);
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

    /**
     * Swagger 2 tools often list several names for the same JSON payload (`application/json` and `text/json`).
     * When every declared media type is JSON (`text/json` is the legacy alias ASP.NET tooling adds), the first one
     * stands for all of them.
     *
     * @param array<mixed> $mediaTypes
     * @return array<mixed>
     */
    /**
     * `consumes` / `produces` list alternatives for one schema, preferred first. When the first one is JSON, the SDK
     * speaks JSON and the others (an XML twin of the same schema, say) are not emitted, as in OpenAPI 3.x.
     *
     * @param list<mixed> $mediaTypes
     * @return list<mixed>
     */
    private function collapseJsonMedia(array $mediaTypes): array
    {
        if (count($mediaTypes) > 1 && is_string($mediaTypes[0] ?? null) && $this->isJsonMediaType($mediaTypes[0])
            && array_filter($mediaTypes, static fn ($media): bool => !is_string($media)) === []) {
            return [$mediaTypes[0]];
        }
        return $mediaTypes;
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
        // A contract whose only representation is `*/*` (Infobip's answers) with a schema describes JSON: when asked to repair, it is read so.
        if ($this->policy->enabled() && isset($content['*/*']) && is_array($content['*/*']) && array_key_exists('schema', $content['*/*'])
            && array_filter(array_keys($content), fn ($type): bool => is_string($type) && $this->isJsonMediaType($type)) === []) {
            $this->policy->note($where, 'The only representation is */* with a schema: it was read as application/json.');
            $content = ['application/json' => $content['*/*']] + $content;
        }
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !$this->isJsonMediaType($mediaType)) {
                continue; // XML/text alternatives are not emitted by a JSON SDK.
            }
            if (is_array($definition) && !array_key_exists('schema', $definition)) {
                // A media type that declares no schema accepts any JSON value (OpenAPI: `schema` is optional).
                $json[$mediaType] = [];
                continue;
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
        return RawMediaSupport::json($mediaType);
    }

    /**
     * `{allOf: [{$ref: X}], default: ...}` (or `readOnly`) is the way OpenAPI 3.0 puts an annotation beside a reference, and what
     * 3.1 documents still do: it means the referenced schema with that default, so it is read as `{$ref: X, default: ...}`.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>|null
     */
    private function referenceWrapper(array $raw): ?array
    {
        $branches = $raw['allOf'] ?? null;
        if (!is_array($branches) || !array_is_list($branches) || $branches === []
            || array_diff(array_keys($raw), ['allOf', 'default', 'description', 'title', 'readOnly', 'writeOnly']) !== []) {
            return null;
        }
        // One reference branch; any other branch only annotates (a description in a branch of its own is how OpenAPI 3.0 spells
        // "a reference with a description").
        $reference = null;
        $annotations = [];
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return null;
            }
            if ($reference === null && is_string($branch['$ref'] ?? null) && array_diff(array_keys($branch), ['$ref', 'description', 'title']) === []) {
                $reference = $branch;
            } elseif ($branch !== [] && array_diff(array_keys($branch), ['description', 'title', 'example', 'examples', 'deprecated']) === []) {
                $annotations += $branch;
            } else {
                return null;
            }
        }
        if ($reference === null) {
            return null;
        }
        // An annotation of a property beside it (a default, readOnly or writeOnly) is enough: a bare one-branch allOf is
        // inheritance, which has its own handling. A description or a title, or a branch that only annotates, is the same idiom when
        // the schema it reaches is not an object: only an object can be inherited.
        $nonObject = $this->reachesANonObject($reference);
        if ($annotations !== [] && !$nonObject) {
            return null;
        }
        $annotated = array_key_exists('default', $raw) || array_key_exists('readOnly', $raw) || array_key_exists('writeOnly', $raw)
            || ($nonObject && (array_key_exists('description', $raw) || array_key_exists('title', $raw) || $annotations !== []))
            // A bare allOf of one reference to something that is not an object (Infobip's `{allOf: [{$ref: SortField}]}` for a
            // parameter) can only mean that reference.
            || ($nonObject && count($branches) === 1);
        if (!$annotated) {
            return null;
        }
        return array_diff_key($raw, ['allOf' => true]) + $annotations + $reference;
    }

    /**
     * `{required: [...], allOf: [{$ref: Base}, {properties: ...}]}` lists beside the composition the properties the
     * result needs, which is the same as listing them in the inline branch: `required` is read where it is declared,
     * not where the property is defined. Read that way the composition has no sibling to refuse.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function foldRequired(array $raw): ?array
    {
        $branches = $raw['allOf'] ?? null;
        if (!is_array($raw['required'] ?? null) || !is_array($branches) || !array_is_list($branches) || count($branches) < 2
            || array_diff(array_keys($raw), ['allOf', 'required', 'description', 'title', 'type', 'readOnly', 'writeOnly']) !== []
            || ($raw['type'] ?? 'object') !== 'object') {
            return null;
        }
        $last = array_key_last($branches);
        foreach (array_reverse(array_keys($branches)) as $index) {
            $branch = $branches[$index];
            if (is_array($branch) && !isset($branch['$ref']) && is_array($branch['properties'] ?? null)) {
                $branches[$index]['required'] = array_values(array_unique([...($branch['required'] ?? []), ...$raw['required']]));
                return array_diff_key($raw, ['required' => true, 'type' => true]) + ['allOf' => $branches];
            }
        }
        return null;
    }

    /**
     * `{anyOf: [{$ref: X}], nullable: true}` (or `oneOf`) is how OpenAPI 3.0 writes "X, or null" (Stripe writes it 426 times): a union
     * of one reference is that reference, so it is read as `{$ref: X, nullable: true}` and does not get a wrapper class of its own.
     *
     * @param array<mixed> $raw
     * @return array<string, mixed>|null
     */
    private static function singleBranchReference(array $raw): ?array
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $raw[$keyword] ?? null;
            if (!is_array($branches) || !array_is_list($branches) || count($branches) !== 1 || !is_array($branches[0])
                || !is_string($branches[0]['$ref'] ?? null) || array_diff(array_keys($branches[0]), ['$ref', 'description', 'title']) !== []
                || array_diff(array_keys($raw), [$keyword, 'nullable', 'description', 'title', 'readOnly', 'writeOnly', 'default']) !== []) {
                continue;
            }
            return array_diff_key($raw, [$keyword => true]) + $branches[0];
        }
        return null;
    }

    /**
     * Whether a `$ref` branch reaches a schema that declares a type other than `object` (a string, an array, a number...), which
     * cannot be inherited from. A missing type, an object or a reference that does not resolve is not.
     *
     * @param array<mixed> $branch
     */
    private function reachesANonObject(array $branch): bool
    {
        if (!is_string($branch['$ref'] ?? null)) {
            return false;
        }
        try {
            $target = SchemaReferences::raw(['$ref' => $branch['$ref']], $this->document);
        } catch (InvalidArgumentException) {
            return false;
        }
        return is_string($target['type'] ?? null) && $target['type'] !== 'object' && !isset($target['properties']);
    }

    /**
     * Whether the `type` beside a `oneOf`/`anyOf` is what every branch already is (the branch itself or the schema its reference
     * reaches). One branch that differs, has no type or cannot be resolved makes it a contradiction or a new assertion, not a repetition.
     *
     * @param array<mixed> $raw
     */
    /**
     * Whether every branch of a union declares all these property names, itself or through the `allOf` it extends.
     *
     * @param array<int, string|int> $names
     */
    private function branchesDeclare(mixed $branches, array $names): bool
    {
        if (!is_array($branches) || !array_is_list($branches) || $branches === [] || $names === []) {
            return false;
        }
        foreach ($branches as $branch) {
            $declared = $this->declaredProperties($branch, 0);
            if ($declared === null || array_diff(array_map('strval', $names), $declared) !== []) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string>|null The property names a schema declares, through references and allOf; null when it cannot be told. */
    private function declaredProperties(mixed $schema, int $depth): ?array
    {
        if (!is_array($schema) || $depth > 8) {
            return null;
        }
        if (isset($schema['$ref'])) {
            try { $schema = SchemaReferences::raw(['$ref' => $schema['$ref']], $this->document); }
            catch (InvalidArgumentException|\TypeError) { return null; }
        }
        $names = array_map('strval', array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []));
        foreach ($schema['allOf'] ?? [] as $part) {
            $inner = $this->declaredProperties($part, $depth + 1);
            if ($inner === null) {
                return null;
            }
            $names = [...$names, ...$inner];
        }
        return array_values(array_unique($names));
    }

    /** Whether every model the discriminator maps to extends this schema through its `allOf`. */
    private function mappingExtends(array $raw): bool
    {
        foreach ($raw['discriminator']['mapping'] as $target) {
            if (!is_string($target)) {
                return false;
            }
            $reference = str_starts_with($target, '#') ? $target : '#/components/schemas/' . $target;
            try { $subtype = SchemaReferences::raw(['$ref' => $reference], $this->document); }
            catch (InvalidArgumentException|\TypeError) { return false; }
            $extends = false;
            foreach ($subtype['allOf'] ?? [] as $parent) {
                if (!is_array($parent) || !is_string($parent['$ref'] ?? null)) {
                    continue;
                }
                try { $base = SchemaReferences::raw(['$ref' => $parent['$ref']], $this->document); }
                catch (InvalidArgumentException|\TypeError) { continue; }
                if ($base == $raw) {
                    $extends = true;
                }
            }
            if (!$extends) {
                return false;
            }
        }
        return true;
    }

    /** Whether every branch of the union is a reference to a model that extends, through its `allOf`, this very schema. */
    private function listsItsSubtypes(array $raw, string $union): bool
    {
        $branches = $raw[$union];
        if (!is_array($branches) || !array_is_list($branches) || $branches === []) {
            return false;
        }
        $self = array_diff_key($raw, [$union => true]);
        foreach ($branches as $branch) {
            if (!is_array($branch) || !is_string($branch['$ref'] ?? null)) {
                return false;
            }
            try { $subtype = SchemaReferences::raw(['$ref' => $branch['$ref']], $this->document); }
            catch (InvalidArgumentException|\TypeError) { return false; }
            $extends = false;
            foreach ($subtype['allOf'] ?? [] as $parent) {
                if (!is_array($parent) || !is_string($parent['$ref'] ?? null)) {
                    continue;
                }
                try { $base = SchemaReferences::raw(['$ref' => $parent['$ref']], $this->document); }
                catch (InvalidArgumentException|\TypeError) { continue; }
                if (array_diff_key($base, [$union => true]) == $self) {
                    $extends = true;
                }
            }
            if (!$extends) {
                return false;
            }
        }
        return true;
    }

    private function compositionRepeatsType(array $raw, string $composition): bool
    {
        $branches = $raw[$composition] ?? null;
        if (!is_string($raw['type'] ?? null) || !is_array($branches) || !array_is_list($branches) || $branches === []) {
            return false;
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                return false;
            }
            try {
                $resolved = is_string($branch['$ref'] ?? null) ? SchemaReferences::raw(['$ref' => $branch['$ref']], $this->document) : $branch;
            } catch (InvalidArgumentException) {
                return false;
            }
            if (($resolved['type'] ?? null) !== $raw['type']) {
                // A branch that does not say its type but is built like an object (properties, or a composition) is one.
                $objectLike = $raw['type'] === 'object' && is_string($branch['$ref'] ?? null) && !isset($resolved['type'])
                    && array_intersect(['properties', 'allOf', 'oneOf', 'anyOf'], array_keys($resolved)) !== [];
                if (!$objectLike) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Whether the `type` or `format` beside a `$ref` says what the schema the reference reaches (through any alias chain) already
     * says, so it asserts nothing new. A type set, a missing keyword or an unresolvable reference is not a repetition. A width hint
     * (`float`, `int64`...) beside a reference to a number or an integer is not either, but no SDK checks it, so it is read as one.
     *
     * @param array<mixed> $raw
     */
    private function repeatsReferenced(array $raw, string $keyword): bool
    {
        if (!in_array($keyword, ['type', 'format'], true) || !is_string($raw[$keyword] ?? null) || !is_string($raw['$ref'] ?? null)) {
            return false;
        }
        try {
            $target = SchemaReferences::raw(['$ref' => $raw['$ref']], $this->document);
        } catch (InvalidArgumentException) {
            return false;
        }
        if (($target[$keyword] ?? null) === $raw[$keyword]) {
            return true;
        }
        return $keyword === 'format'
            && in_array($raw['format'], self::NUMERIC_WIDTH_HINTS, true)
            && in_array($target['type'] ?? null, ['integer', 'number'], true);
    }

    /**
     * The schema a `propertyNames` reference points to, written in its place: key names are checked as plain
     * assertions, which cannot hold references, and a key-name schema is a value schema (an enum, a pattern).
     *
     * @param array<mixed> $reference
     * @return array<mixed>
     */
    private function keyNameSchema(array $reference, string $where): array
    {
        try {
            $schema = SchemaReferences::raw($reference, $this->document);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("Unsupported propertyNames reference at $where: " . $error->getMessage(), 0, $error);
        }
        if (str_contains(json_encode($schema, JSON_THROW_ON_ERROR), '"$ref"')) {
            throw new InvalidArgumentException("A propertyNames schema that references other schemas is unsupported at $where.");
        }
        return $schema;
    }

    private function schema(mixed $raw, string $dialect, string $where): SchemaDefinition
    {
        if (is_bool($raw) && $this->jsonSchema31) {
            return new SchemaDefinition(SchemaKind::Mixed, validation: ['schema' => $raw]);
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException("Schema must be an object at $where.");
        }

        // Annotations are kept and never interpreted: `summary` beside a reference (OpenAPI 3.1 Reference Object), and in a 3.1 schema
        // any keyword JSON Schema 2020-12 does not define, which that dialect reads as an annotation. A keyword that means something
        // and that this generator cannot execute (`contentEncoding`, `$defs`, ...) is not among them and is still refused.
        $metadata = array_filter($raw, fn ($key): bool => in_array($key, ['example', 'examples', 'deprecated', 'externalDocs', 'xml', '$comment'], true)
            || (str_starts_with((string) $key, 'x-') && $key !== 'x-nullable')
            || ($key === 'summary' && isset($raw['$ref']))
            || ($this->jsonSchema31 && in_array($key, self::CONTENT_VOCABULARY, true))
            || ($this->jsonSchema31 && is_string($key) && !in_array($key, self::SCHEMA_KEYWORDS, true) && !in_array($key, self::UNSUPPORTED_VOCABULARY, true)),
            ARRAY_FILTER_USE_KEY);
        if ($metadata !== []) {
            if (array_key_exists('deprecated', $metadata) && !is_bool($metadata['deprecated'])) {
                throw new InvalidArgumentException("Invalid schema deprecated flag at $where.");
            }
            $schema = $this->schema(array_diff_key($raw, $metadata), $dialect, $where);
            $arguments = get_object_vars($schema);
            $arguments['annotations'] = $metadata;
            return new SchemaDefinition(...$arguments);
        }

        if (is_array($raw['propertyNames'] ?? null) && isset($raw['propertyNames']['$ref'])) {
            $raw['propertyNames'] = $this->keyNameSchema($raw['propertyNames'], $where);
            return $this->schema($raw, $dialect, $where);
        }

        $folded = self::foldRequired($raw);
        if ($folded !== null) {
            return $this->schema($folded, $dialect, $where);
        }

        $wrapped = $this->referenceWrapper($raw);
        if ($wrapped !== null) {
            return $this->schema($wrapped, $dialect, $where);
        }

        // A `oneOf` of scalars of different JSON types (GoCardless's `limit`: integer or string) cannot match two branches: it is the
        // plain union, which is how it is executed.
        if (isset($raw['oneOf']) && is_array($raw['oneOf']) && array_is_list($raw['oneOf']) && count($raw['oneOf']) > 1
            && count(array_intersect(['anyOf', 'allOf'], array_keys($raw))) === 0) {
            $types = [];
            foreach ($raw['oneOf'] as $branch) {
                $type = is_array($branch) && !isset($branch['enum']) && array_diff(array_keys($branch), ['type', 'description', 'title', 'example', 'format']) === []
                    ? ($branch['type'] ?? null) : null;
                if (!is_string($type) || !in_array($type, ['string', 'integer', 'number', 'boolean'], true)) { $types = null; break; }
                $types[] = $type;
            }
            if ($types !== null && count(array_unique($types)) === count($types)
                && !(in_array('integer', $types, true) && in_array('number', $types, true))) {
                $raw['anyOf'] = $raw['oneOf'];
                unset($raw['oneOf']);
            }
        }

        // An allOf of one inline schema and branches that only annotate (GoCardless's refund `mandate`: a string, then a description)
        // is that schema with the annotations; the last description wins.
        if (isset($raw['allOf']) && is_array($raw['allOf']) && array_is_list($raw['allOf']) && count($raw['allOf']) > 1
            && count(array_intersect(['anyOf', 'oneOf'], array_keys($raw))) === 0) {
            $annotationKeys = ['description', 'title', 'example', 'examples', 'deprecated', 'externalDocs'];
            $substantive = array_values(array_filter($raw['allOf'], static fn ($branch): bool => !is_array($branch)
                || array_diff(array_keys($branch), $annotationKeys) !== [] && array_filter(array_keys($branch), static fn ($k): bool => !str_starts_with((string) $k, 'x-') && !in_array($k, $annotationKeys, true)) !== []));
            if (count($substantive) === 1 && is_array($substantive[0]) && !isset($substantive[0]['$ref'])) {
                $notes = [];
                foreach ($raw['allOf'] as $branch) {
                    if ($branch !== $substantive[0]) { $notes = array_replace($notes, $branch); }
                }
                $rest = array_diff_key($raw, ['allOf' => true]);
                return $this->schema(array_replace($substantive[0], $notes, $rest), $dialect, $where);
            }
        }

        // `type: null` beside an `allOf` of one reference (Infobip writes it for "this model, or null") cannot match anything as
        // written; what it means is the nullable reference.
        if (($raw['type'] ?? null) === 'null' && isset($raw['allOf']) && is_array($raw['allOf']) && count($raw['allOf']) === 1) {
            $this->policy->mend($where, "A null type beside allOf matches nothing at $where.", 'type: null beside allOf matches nothing: it was read as a nullable reference.');
            $raw = array_diff_key($raw, ['type' => true]) + ['nullable' => true];
        }

        // An object that declares its own `properties` and also `allOf` (Mailjet's `NewApikey`) is the conjunction of both: the own
        // properties are one more branch of the allOf, after the others.
        if (isset($raw['allOf']) && is_array($raw['allOf']) && array_is_list($raw['allOf'])
            && count(array_intersect(['anyOf', 'oneOf'], array_keys($raw))) === 0
            && (isset($raw['properties']) || isset($raw['required'])) && in_array($raw['type'] ?? 'object', ['object'], true)
            && is_array($raw['properties'] ?? [])) {
            $own = ['type' => 'object'] + array_intersect_key($raw, array_flip(['properties', 'required', 'additionalProperties']));
            $raw = array_diff_key($raw, array_flip(['type', 'properties', 'required', 'additionalProperties']));
            $raw['allOf'][] = $own;
        }

        // A discriminator whose mapping points at models that do not extend this one (Infobip's `Component`, mapped to standalone
        // `Box`, `Text`...) selects nothing this SDK can build by inheritance: repaired by dropping the discriminator.
        if ($this->policy->enabled() && isset($raw['discriminator']['mapping'], $raw['properties']) && is_array($raw['discriminator']['mapping'])
            && count(array_intersect(['allOf', 'oneOf', 'anyOf'], array_keys($raw))) === 0
            && !$this->mappingExtends($raw)) {
            $this->policy->mend($where, "The discriminator mapping targets models that do not extend this one at $where.", 'The discriminator maps to models that do not extend this one: it was dropped.');
            unset($raw['discriminator']);
        }

        // A base model that lists, in `oneOf`, the models that extend it (Infobip's `CampaignBuilderRequest`: properties, a discriminator
        // and a oneOf of subtypes whose `allOf` points back to it) is the base of a discriminated hierarchy; the list repeats it.
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($raw['discriminator'], $raw['properties'], $raw[$union]) && $this->listsItsSubtypes($raw, $union)) {
                unset($raw[$union]);
            }
        }

        // `properties` (and `required`) beside a union whose every branch already declares those properties say nothing the branches
        // do not (Infobip's `LineOaOutboundMessagePart`): the bare union.
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($raw[$union], $raw['properties']) && count(array_intersect(['allOf', 'oneOf', 'anyOf'], array_keys($raw))) === 1
                && $this->branchesDeclare($raw[$union], array_keys(is_array($raw['properties']) ? $raw['properties'] : []))) {
                $raw = array_diff_key($raw, array_flip(['properties', 'required']));
                if (($raw['type'] ?? null) === 'object') { unset($raw['type']); }
            }
        }

        $single = self::singleBranchReference($raw);
        if ($single !== null) {
            return $this->schema($single, $dialect, $where);
        }

        $compositionKeywords = array_values(array_intersect(['allOf', 'oneOf', 'anyOf'], array_keys($raw)));
        if ($this->jsonSchema31 && $compositionKeywords !== []) {
            $siblings = array_values(array_diff(array_keys($raw), [...$compositionKeywords, 'title', 'description', 'discriminator', 'readOnly', 'writeOnly', 'nullable']));
            // `type: object` beside a oneOf/anyOf whose every branch is an object says nothing the branches do not: the bare union.
            if ($siblings === ['type'] && count($compositionKeywords) === 1 && $compositionKeywords[0] !== 'allOf'
                && $this->compositionRepeatsType($raw, $compositionKeywords[0])) {
                unset($raw['type']);
                $siblings = [];
            }
            $nonObjectAllOf = isset($raw['allOf']) && is_array($raw['allOf'])
                && count(array_filter($raw['allOf'], static fn ($branch): bool => !DeclarativeSchemaRules::objectBranch($branch))) > 0;
            // `type: object` beside an allOf of objects (Trustpilot's `PrivateReview`) repeats what the branches already say.
            // Limits (`minLength: 1`) beside an allOf of a single reference say what the referenced schema already says or less
            // (Infobip's `EventFieldResource.type`): when asked to repair, they are dropped.
            if ($this->policy->enabled() && $siblings !== [] && $compositionKeywords === ['allOf'] && is_array($raw['allOf']) && count($raw['allOf']) === 1
                && is_array($raw['allOf'][0] ?? null) && isset($raw['allOf'][0]['$ref'])
                && array_diff($siblings, ['minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems', 'pattern', 'multipleOf', 'uniqueItems', 'minProperties', 'maxProperties']) === []) {
                $this->policy->note($where, 'Limits (' . implode(', ', $siblings) . ') beside an allOf of one reference were ignored.');
                $raw = array_diff_key($raw, array_flip($siblings));
                $siblings = [];
            }
            // The same for `type: string` beside an allOf of a single reference to a string (Infobip's `grant_type`).
            if ($siblings === ['type'] && $compositionKeywords === ['allOf'] && is_string($raw['type'] ?? null) && $raw['type'] !== 'object'
                && is_array($raw['allOf']) && count($raw['allOf']) === 1 && is_array($raw['allOf'][0] ?? null) && is_string($raw['allOf'][0]['$ref'] ?? null)) {
                try { $target = SchemaReferences::raw(['$ref' => $raw['allOf'][0]['$ref']], $this->document); }
                catch (InvalidArgumentException|\TypeError) { $target = []; }
                if (($target['type'] ?? null) === $raw['type']) {
                    unset($raw['type']);
                    return $this->schema($raw, $dialect, $where); // Read again: it is now an allOf of one reference.
                }
            }
            if ($siblings === ['type'] && $compositionKeywords === ['allOf'] && ($raw['type'] ?? null) === 'object' && !$nonObjectAllOf) {
                unset($raw['type']);
                $siblings = [];
            }
            if (count($compositionKeywords) > 1 || $siblings !== [] || $nonObjectAllOf) {
                return DeclarativeSchemaRules::native($raw, $where);
            }
        }

        $declarative = DeclarativeSchemaRules::extract(
            $raw,
            $this->jsonSchema31,
            $where,
            fn (string $reference): array => SchemaReferences::raw(['$ref' => $reference], $this->document),
        );
        if ($declarative !== null) {
            [$structural, $guard] = $declarative;
            $schema = $this->schema($structural, $dialect, $where);
            $arguments = get_object_vars($schema);
            $arguments['validation']['jsonSchema'] = DeclarativeSchemaRules::conjoin($arguments['validation']['jsonSchema'] ?? null, $guard);
            return new SchemaDefinition(...$arguments);
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

        if ($this->jsonSchema31 && is_array($raw['type'] ?? null) && array_is_list($raw['type']) && count($raw['type']) === 1) {
            $raw['type'] = $raw['type'][0];
            return $this->schema($raw, $dialect, $where);
        }

        if ($this->jsonSchema31 && is_array($raw['type'] ?? null)
            && count($raw['type']) > 1 && array_intersect($raw['type'], ['object', 'array']) !== []
            && !(count($raw['type']) === 2 && in_array('null', $raw['type'], true))) {
            $types = $raw['type'];
            if (!array_is_list($types) || count(array_filter($types, 'is_string')) !== count($types)
                || count(array_unique($types)) !== count($types)
                || array_diff($types, ['string', 'integer', 'number', 'boolean', 'null', 'object', 'array']) !== []) {
                throw new InvalidArgumentException("Invalid JSON Schema type set at $where.");
            }
            foreach (['readOnly', 'writeOnly'] as $flag) {
                if (array_key_exists($flag, $raw) && !is_bool($raw[$flag])) { throw new InvalidArgumentException("Invalid $flag at $where."); }
            }
            $guard = DeclarativeSchemaRules::encode(array_diff_key($raw, array_flip(['readOnly', 'writeOnly'])), $where);
            return new SchemaDefinition(SchemaKind::AnyOf,
                validation: ['schema' => true, 'jsonSchema' => $guard],
                readOnly: $raw['readOnly'] ?? false, writeOnly: $raw['writeOnly'] ?? false,
                branches: array_map(static fn (string $type): SchemaDefinition => new SchemaDefinition(SchemaKind::from($type)), $types),
                title: $raw['title'] ?? null, description: $raw['description'] ?? null,
                default: $raw['default'] ?? null, hasDefault: array_key_exists('default', $raw));
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
            return new SchemaDefinition(SchemaKind::AnyOf, validation: ['schema' => true, 'jsonSchema' => DeclarativeSchemaRules::encode($guard, $where)],
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
            foreach ($mapping as $tag => $target) {
                if (!is_string($target) || $target === '') {
                    throw new InvalidArgumentException("Invalid discriminator mapping target at $where.");
                }
                $local = str_starts_with($target, '#');
                $name = $local ? SchemaReferences::localName($target, $dialect) : $target;
                if ($name === '' || (!$local && strpbrk($name, '/#:') !== false)) {
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
            $siblings = array_values(array_diff(array_keys($raw), [$composition, 'description', 'title', 'discriminator', 'readOnly', 'writeOnly', 'nullable']));
            if ($siblings === ['type'] && $composition !== 'allOf' && $this->compositionRepeatsType($raw, $composition)) {
                $siblings = [];
            }
            if ($siblings !== []) {
                $this->policy->mend($where, "Composition siblings are not supported at $where.", 'Keywords beside ' . $composition . ' (' . implode(', ', $siblings) . ') are not executed with it: they were ignored.');
                $raw = array_diff_key($raw, array_flip($siblings));
            }
            if (isset($raw['nullable']) && !is_bool($raw['nullable'])) {
                throw new InvalidArgumentException("Invalid nullable value at $where.");
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
                readOnly: $raw['readOnly'] ?? false,
                writeOnly: $raw['writeOnly'] ?? false,
                nullable: ($raw['nullable'] ?? false) === true,
                branches: array_map(
                    fn (mixed $branch, int $index): SchemaDefinition =>
                        $this->schema($branch, $dialect, "$where.$composition.$index"),
                    $branches,
                    array_keys($branches),
                ),
            );
        }

        if (isset($raw['$ref'])) {
            // readOnly and writeOnly beside a $ref are the usual way Swagger tooling marks a model-typed property;
            // the property reads them from its own node, so the reference only has to tolerate them.
            $siblings = array_values(array_diff(array_keys($raw), ['$ref', 'description', 'title', 'readOnly', 'writeOnly', 'default', 'nullable']));
            // OpenAPI 3.1 documents write `{$ref: Status, type: string}`; a `type` or `format` that repeats what the referenced
            // schema says (or a width hint such as `float`) asserts nothing new. Any other sibling could change the meaning and
            // stays refused.
            $siblings = array_values(array_filter($siblings, fn (string $keyword): bool => !$this->repeatsReferenced($raw, $keyword)));
            // `{$ref: Recipient, required: [Email]}` asks for the referenced model and, besides, that these fields be present:
            // the referenced model extended by an object that requires them.
            if ($siblings === ['required'] && is_array($raw['required']) && array_is_list($raw['required'])
                && array_filter($raw['required'], static fn ($name): bool => !is_string($name)) === []) {
                $annotations = array_diff_key($raw, ['$ref' => true, 'required' => true]);
                return $this->schema($annotations + ['allOf' => [['$ref' => $raw['$ref']], ['type' => 'object', 'required' => $raw['required']]]], $dialect, $where);
            }
            if ($siblings !== []) {
                throw new InvalidArgumentException("Reference siblings are not supported at $where.");
            }
            if (isset($raw['nullable']) && !is_bool($raw['nullable'])) {
                throw new InvalidArgumentException("Invalid nullable value at $where.");
            }
            foreach (['readOnly', 'writeOnly'] as $flag) {
                if (isset($raw[$flag]) && !is_bool($raw[$flag])) {
                    throw new InvalidArgumentException("Invalid $flag value at $where.");
                }
            }
            $reference = $raw['$ref'];
            if (!is_string($reference)) { throw new InvalidArgumentException("Invalid model reference at $where."); }
            try { $name = SchemaReferences::localName($reference, $dialect); }
            catch (InvalidArgumentException $error) {
                // A reference into the inside of a model (`#/components/schemas/Query/properties/Id`) is that schema, read in place.
                if (preg_match('~^#/(components/schemas|definitions)/[^/]+/~', $reference) === 1 && !isset($this->inlining[$reference]) && count($this->inlining) < 32) {
                    $target = \Dmorenof\SdkGenerator\Diagnostics\JsonPointer::resolve($this->document, $reference);
                    if (is_array($target)) {
                        $this->inlining[$reference] = true;
                        try { return $this->schema(array_diff_key($raw, ['$ref' => true]) + $target, $dialect, $where); }
                        finally { unset($this->inlining[$reference]); }
                    }
                }
                throw new InvalidArgumentException("Unsupported model reference at $where: " . $error->getMessage(), 0, $error); }
            return new SchemaDefinition(
                SchemaKind::Reference,
                reference: $name,
                readOnly: $raw['readOnly'] ?? false,
                writeOnly: $raw['writeOnly'] ?? false,
                title: $raw['title'] ?? null,
                description: $raw['description'] ?? null,
                default: $raw['default'] ?? null,
                hasDefault: array_key_exists('default', $raw),
                nullable: ($raw['nullable'] ?? false) === true,
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
            // JSON Schema patterns are ECMA-262: a code unit is `\u0080` there and `\x{0080}` in PCRE, which the SDK runs.
            $raw['pattern'] = EcmaPattern::toPcre($raw['pattern']);
            $regex = '~' . str_replace('~', '\\~', $raw['pattern']) . '~u';
            if (@preg_match($regex, '') === false) {
                throw new InvalidArgumentException("Unsupported or invalid schema pattern at $where.");
            }
            if (!in_array($kind, [SchemaKind::String, SchemaKind::Mixed], true)) {
                $this->policy->mend($where, "Constraint pattern is not applicable to $kind->value at $where.", "The constraint pattern does not apply to $kind->value: it was ignored.");
                unset($raw['pattern']);
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
            if ($this->jsonSchema31 && !array_key_exists('items', $raw)) { $raw['items'] = true; }
            // OpenAPI 3.0 requires `items` on an array, but a contract that leaves it out (GoCardless's `ids`) means a list of anything.
            if ($this->policy->enabled() && !array_key_exists('items', $raw)) {
                $this->policy->note($where, 'An array without items was read as a list of any value.');
                $raw['items'] = [];
            }
            if (!is_array($raw['items'] ?? null) && !($this->jsonSchema31 && is_bool($raw['items'] ?? null))) {
                throw new InvalidArgumentException("Array items are missing at $where.");
            }
            $items = $this->schema($raw['items'], $dialect, "$where.items");
        }

        $enumValues = $raw['enum'] ?? [];
        if (!is_array($enumValues) || !array_is_list($enumValues) || (array_key_exists('enum', $raw) && $enumValues === [])) {
            throw new InvalidArgumentException("Invalid enum at $where.");
        }
        usort($enumValues, static fn ($a, $b): int => strcmp(json_encode($a), json_encode($b)));
        // A value listed twice is one value (Infobip lists `CARD` twice, and PHP refuses an enum with the same value in two cases).
        $distinct = [];
        foreach ($enumValues as $value) { $distinct[json_encode($value)] = $value; }
        if (count($distinct) !== count($enumValues)) {
            $this->policy->note($where, 'An enum lists a value more than once: the duplicates were dropped.');
            $enumValues = array_values($distinct);
        }
        // A null among the values means the field may be null; the other values can still be a backed enum.
        $enumHasNull = in_array(null, $enumValues, true);
        $enumCandidates = array_values(array_filter($enumValues, static fn ($value): bool => $value !== null));
        if ($kind === SchemaKind::Mixed && $enumCandidates !== []) {
            // An enum with no declared type is still an enum of strings (or of integers) when all its values are.
            if (count(array_filter($enumCandidates, 'is_string')) === count($enumCandidates)) {
                $kind = SchemaKind::String;
            } elseif (count(array_filter($enumCandidates, 'is_int')) === count($enumCandidates)) {
                $kind = SchemaKind::Integer;
            }
        }

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
                // JSON Schema ignores a constraint on a type it does not constrain (`minLength` on an array): repaired, not refused.
                $this->policy->mend($where, "Constraint $rule is not applicable to $kind->value at $where.", "The constraint $rule does not apply to $kind->value: it was ignored.");
                continue;
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
            if (!$this->jsonSchema31) { throw new InvalidArgumentException("Const requires OpenAPI 3.1 at $where."); }
            if (is_array($raw['const']) || is_object($raw['const'])) {
                $validation['jsonSchema'] = DeclarativeSchemaRules::encode(['const' => $raw['const']], $where);
            } else {
                $validation['const'] = $raw['const'];
            }
        }
        $backedEnum = $enumCandidates !== []
            && (($kind === SchemaKind::String && count(array_filter($enumCandidates, 'is_string')) === count($enumCandidates))
                || ($kind === SchemaKind::Integer && count(array_filter($enumCandidates, 'is_int')) === count($enumCandidates)));
        if ($enumValues !== [] && !$backedEnum) {
            // PHP backed enums hold only strings or integers: any other list stays a plain type with a JSON Schema guard.
            $validation['jsonSchema'] = DeclarativeSchemaRules::conjoin($validation['jsonSchema'] ?? null,
                DeclarativeSchemaRules::encode(['enum' => $enumValues], $where));
            $enumValues = [];
        } elseif ($backedEnum) {
            $enumValues = $enumCandidates;
            $nullable = $nullable || $enumHasNull;
        }
        foreach ([['minLength', 'maxLength'], ['minimum', 'maximum'], ['minItems', 'maxItems'], ['minProperties', 'maxProperties']] as [$lower, $upper]) {
            if (isset($validation[$lower], $validation[$upper]) && $validation[$lower] > $validation[$upper]) {
                throw new InvalidArgumentException("Contradictory $lower and $upper at $where.");
            }
        }

        if ($kind === SchemaKind::Array && $items !== null && array_key_exists('schema', $items->validation)) {
            $validation['schema'] = ['type' => $nullable ? ['array', 'null'] : 'array', 'items' => $items->validation['schema']];
        }

        if ($kind === SchemaKind::Array && $items !== null && isset($items->validation['jsonSchema'])) {
            $validation['jsonSchema'] = DeclarativeSchemaRules::conjoin($validation['jsonSchema'] ?? null, json_encode(['items' => json_decode($items->validation['jsonSchema'], false, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        if ($discriminator !== null && $kind !== SchemaKind::Object) {
            throw new InvalidArgumentException("Discriminator requires an object schema at $where.");
        }
        $format = $raw['format'] ?? null;
        // PHP has no 32-bit integer type, so the range of `int32` is always asserted; other formats only when asked.
        $int32 = $kind === SchemaKind::Integer && $format === 'int32';
        if (($this->validateFormats || $int32) && is_string($format) && in_array($kind, [SchemaKind::String, SchemaKind::Integer], true)
            && !in_array($format, self::ANNOTATION_FORMATS, true)) {
            $validation['format'] = $format;
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
