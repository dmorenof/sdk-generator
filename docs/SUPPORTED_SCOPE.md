# v0.4.0 supported scope and known limitations

**Status: bounded Swagger/OpenAPI support; full conformance remains outside this release.**

## What is verified

- The normalizer accepts tested JSON and YAML subsets of Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x. YAML documents are parsed and passed through the same contract normalization as JSON.
- The canonical contract uses immutable typed classes: API, operation, schema, parameters, body, model and response. One PHP emitter path writes the output, independent of source dialect.
- `allOf` generates native inheritance for compatible single-parent object schemas and merges independent bases. `anyOf` and `oneOf` use shared runtime wrappers that evaluate the supported original schemas before hydration; `oneOf` requires exactly one match. Local discriminator mappings select the hydrated class independently of schema validity.
- Compositions support local model references, primitives, enums, typed arrays and inline object branches where those schemas are otherwise supported. Scalar enum refinements retain inherited PHP types; other conflicting `allOf` properties and unsupported branch/sibling forms fail explicitly.
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
- Not a general OpenAPI 3.1 / JSON Schema implementation; external references/mappings, arbitrary native unions, `not`/`const`, general schema composition, advanced formats, OAuth2/OpenID Connect authorization flows, API keys in cookies, or form/multipart support are not implemented. Patterns using ECMA-regex features that differ from PCRE may not validate identically. For OAuth2, provide a previously obtained bearer token only when the security scheme is declared as HTTP bearer; the generator does not acquire or refresh tokens.
- OAuth2/OpenID Connect authorization flows are not implemented; credentials must be supplied by the caller, and tokens are not acquired or refreshed by the SDK.
- Multiple body-bearing success responses and some non-object inline error schemas are not supported. Multiple advertised media types are accepted when at least one JSON representation exists and all JSON variants share the same schema; incompatible JSON schemas are rejected. Versioned JSON media types are supported. The full original FakeRESTApi OpenAPI document generates successfully, with live GET list/single and POST response checks.
- Required fields and supported schema constraints are validated before outbound request serialization. Native PHP types constrain assigned values, but this is not full JSON Schema validation and does not cover every format or constraint.
- Explicit `additionalProperties` and discriminated families preserve permitted extra JSON fields. Other generated DTOs reject unknown fields; a general configurable policy remains pending. Input JSON property names must be representable by supported generated PHP identifiers.
- The generated SDK defaults to Guzzle 7; a PSR-18 client is optionally injectable. Shared HTTP behavior is provided by stable `sdk-runtime` v0.5.0 or later compatible versions (`^0.5.0`).
- No tested live integration with Casafari or a production external API; only owned synthetic fixtures. No general latency, memory or security superiority claim versus OpenAPI Generator.
- Existing Casafari SDK middleware that uses by-reference arguments and bool callbacks **is not** an interchangeable instance of Guzzle HandlerStack middleware. This release does not change Casafari SDK.

## Roadmap (not promises about 0.4.0)

Broader OpenAPI coverage, more live endpoint cases (including authenticated requests and PATCH where safe), and feature-equivalent performance/usability comparisons.

Please report supported-schema failures through GitHub Issues with a small **redacted** reproducible JSON specification (no keys or tokens).


## Polymorphic models (since 0.3.0)

Generator 0.3.0 ([PR #19](https://github.com/dmorenof/sdk-generator/pull/19)) is
coordinated with runtime 0.4.0 ([PR #6](https://github.com/dmorenof/sdk-runtime/pull/6)).
The current generator and generated Composer packages require stable runtime `^0.5.0`;
installation no longer depends on a development branch.

- Compatible single-parent object `allOf` models use real PHP inheritance. Only
  parents lose `final`; multiple independent bases retain flattening. Closed
  branches cannot gain forbidden properties.
- Supported string/integer enum intersections refine inherited validation without
  changing the parent's PHP property type or backed enum class. Other differing
  inherited-property constraints still fail explicitly.
- OAS 3 discriminator objects accept `propertyName` and local explicit mappings
  (component names or schema references), plus implicit schema-name mappings.
  Base-object mappings must target compatible descendants; composition mappings
  target named reference branches. Missing/unknown/non-string tags fail.
- `Pet::fromValue()` and shared runtime hydration dispatch to `Dog`/`Cat`, including
  nested properties, collections, maps and operation values. `new Pet(...)` keeps
  the explicitly constructed PHP class. Concrete subclasses do not redispatch.
- Composition descriptors preserve the original branches, local references and
  supported constraints. Matching evaluates an independent JSON snapshot before
  hydration and ignores discriminator dispatch. `oneOf` requires exactly one
  valid schema; `anyOf` permits several. Permitted extra fields of discriminated
  families are preserved.
- A single selected variant supplies serialized output after mutation, with
  branch cardinality and discriminator identity checked again. Several `anyOf`
  matches remain views of the original snapshot; concurrent edits to several
  views are not merged into an outbound payload.

These changes do not provide arbitrary native unions, general non-object `allOf`,
`not`, `const`, external mapping URIs, OAS 3.2 `defaultMapping`, or full JSON Schema
validation. Existing wrappers remain part of the public API.


## Contract fidelity and operation servers (0.4.0)

The contract preserves explicit parameter serialization options, effective server
precedence/order, media definitions/encoding, response headers/links and annotations.
Inspection diagnoses unsupported HTTP behavior; preserving metadata does not
implement every serializer or transport. `--inspect` / `--dry-run` create no files;
`--strict` and strict normalization reject detected incompatibilities. The audit
is bounded and some existing parser/emitter errors still use a root diagnostic.

Literal HTTP(S) operation servers execute in the shared runtime with operation >
path > root precedence, prefixes/encoded parameters and per-client `serverOverrides`.
All 46 Casafari operations pass strict acceptance; seven HTTP mock regressions
verify platform URLs and synthetic authentication. Relative/template servers,
variables, dynamic selection, general references and serializers remain pending.
No live Casafari authentication or production migration is claimed.
