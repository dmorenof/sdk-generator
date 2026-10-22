# v0.1.0 supported scope and known limitations

**Status: initial release, not fully conformant Swagger/OpenAPI generation.**

## What is verified

- The normalizer accepts tested JSON and YAML subsets of Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x. YAML documents are parsed and passed through the same contract normalization as JSON.
- The canonical contract uses immutable typed classes: API, operation, schema, parameters, body, model and response. One PHP emitter path writes the output, independent of source dialect.
- `allOf` merges compatible object schemas and required fields. `anyOf` and `oneOf` generate specific wrapper classes backed by shared `Core\\AnyOf` / `Core\\OneOf` behavior; `anyOf` preserves the original JSON and exposes every successfully hydrated variant.
- Compositions support local model references, primitives, enums, typed arrays and inline object branches where those schemas are otherwise supported. Conflicting `allOf` properties and unsupported branch/sibling forms fail explicitly.
- The normalizer preserves `default` metadata but does not materialize defaults into DTOs. `pattern` is validated with Unicode PCRE-compatible expressions. Type-less schemas are represented as `mixed` unless object properties, `additionalProperties`, or `pattern` provide a more specific shape.
- `readOnly` properties are omitted from request serialization and are not required for requests; `writeOnly` properties are omitted from response serialization and are not required for responses. Directional filtering is recursive for nested DTOs.
- Primitive HTTP parameters support nullable primitive `anyOf` and inline string/integer enums; enum membership is validated before request transmission.
- Inline object and array success responses generate deterministic operation-derived model/collection classes. Inline object error responses generate operation/status-derived response DTOs. One body-bearing success response may coexist with no-content success statuses, in which case the operation return type is nullable.
- HTTP Basic and token-based HTTP authentication, as well as API keys in headers or query parameters, are supported. Root security is inherited by operations; operation-level security overrides it, including `security: []`. Security requirement entries use OpenAPI's OR-between-alternatives and AND-within-an-alternative semantics. Supply credentials through the generated `Core\SecurityCredentials` object, keyed by the OpenAPI scheme name; Basic credentials use `Core\BasicAuthCredentials`.
- For the small supported subset, emits distinct DTO/model classes with native PHP property types, scalar-backed enums, collections that subclass the existing typedarray/typedarray library, and typed operation methods.
- Generates an installable Composer package and a minimal runnable API client. GET/POST path, query, headers, JSON body and typed response hydration are verified with **offline mock HTTP**. Nested object, enum and collection hydration have tests.
- `Client::connect(baseUrl)` returns an independent instance and constructs Guzzle 7 automatically. It also accepts a PSR-18 client, default headers, security credentials, or a custom transport. Route classes expose instance methods and inherit construction/execution behavior. They can be instantiated directly or obtained through `$client->resource(Route::class)`.
- Rejects a variety of unsupported schema keywords, media types, missing/ambiguous operation IDs, unresolved local references and unsupported input dialects instead of knowingly emitting incomplete code.
- The acceptance analyzer and its CLI also accept YAML (`.yaml`/`.yml`) and report isolated and full-source generation results through the same parser/emitter.

## What is deliberately NOT guaranteed

- YAML support does not expand the supported OpenAPI feature set: YAML input is subject to the same deliberate subset and explicit rejections as JSON.
- Not a general OpenAPI 3.1 / JSON Schema implementation; composition discriminators, external references, unconstrained inline objects/additionalProperties, advanced formats, OAuth2/OpenID Connect authorization flows, API keys in cookies, or form/multipart support are not implemented. Patterns using ECMA-regex features that differ from PCRE may not validate identically. For OAuth2, provide a previously obtained bearer token only when the security scheme is declared as HTTP bearer; the generator does not acquire or refresh tokens.
- OAuth2/OpenID Connect authorization flows are not implemented; credentials must be supplied by the caller, and tokens are not acquired or refreshed by the SDK.
- Multiple body-bearing success responses, some non-object inline error schemas, and multiple JSON media types per body are not supported. A single JSON media type is supported per request/response body, including `application/*+json` such as `application/problem+json`. Full-spec runtime acceptance is not yet verified.
- Required fields and supported schema constraints are validated before outbound request serialization. Native PHP types constrain assigned values, but this is not full JSON Schema validation and does not cover every format or constraint.
- Unknown incoming properties may be ignored; no configurable strict unknown-property policy. Input JSON property names must be representable by supported generated PHP identifiers.
- The generated SDK defaults to Guzzle 7; a PSR-18 client is optionally injectable. It is not yet wired to the separate experimental sdk-runtime package, which should not be assumed released.
- No tested live integration with Casafari or a production external API; only owned synthetic fixtures. No general latency, memory or security superiority claim versus OpenAPI Generator.
- Existing Casafari SDK middleware that uses by-reference arguments and bool callbacks **is not** an interchangeable instance of Guzzle HandlerStack middleware. This release does not change Casafari SDK.

## Roadmap (not promises about 0.1.0)

Complete pre-send DTO validation, deeper schema support, more realistic public API fixtures, stable shared runtime extraction, and feature-equivalent performance/usability comparisons.

Please report supported-schema failures through GitHub Issues with a small **redacted** reproducible JSON specification (no keys or tokens).
