# v0.15.0 supported scope and known limitations

**Status: bounded Swagger/OpenAPI support; full conformance remains outside this release.**

## What is verified

- The normalizer accepts tested JSON and YAML subsets of Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x. YAML documents are parsed and passed through the same contract normalization as JSON.
- The canonical contract uses immutable typed classes: API, operation, schema, parameters, body, model and response. One PHP emitter path writes the output, independent of source dialect.
- `allOf` generates native inheritance for compatible single-parent object schemas and merges independent bases. `anyOf` and `oneOf` use shared runtime wrappers that evaluate the supported original schemas before hydration; `oneOf` requires exactly one match. Local discriminator mappings select the hydrated class independently of schema validity.
- Compositions support local model references, primitives, enums, typed arrays and inline object branches where those schemas are otherwise supported. Scalar enum refinements retain inherited PHP types; other conflicting `allOf` properties and unsupported branch/sibling forms fail explicitly.
- The normalizer preserves `default` metadata but does not materialize defaults into DTOs. `pattern` is validated with Unicode PCRE-compatible expressions. Type-less schemas are represented as `mixed` unless object properties, `additionalProperties`, or `pattern` provide a more specific shape.
- `readOnly` properties are omitted from request serialization and are not required for requests; `writeOnly` properties are omitted from response serialization and are not required for responses. Directional filtering is recursive for nested DTOs.
- Query arrays of non-null primitive items use native PHP lists, validated item constraints and bounds. OpenAPI form supports repeated keys (default) and CSV; Swagger supports multi and CSV (default). v0.10.0 adds OpenAPI spaceDelimited/pipeDelimited (omitted/false explode) and Swagger ssv/tsv/pipes. These formats reject items containing their own delimiter; separators are URI-encoded. Null/absent parameters and empty lists are omitted; empty string items are explicit. Other locations, objects, nested/nullable items and styles/flags remain pending. See [query arrays](QUERY_ARRAYS.md).
- OpenAPI flat deepObject query objects use typed DTOs/Maps with explicit closed or scalar additional-property schemas. Inline/local references, request-direction fields, JSON names, enums and mutations are validated. GET and POST parameter DTOs keep query and JSON body separate, including a query named body. Nested/null properties, unconstrained extras and other object styles remain pending. See [query objects](QUERY_OBJECTS.md).
- OpenAPI URL-encoded form bodies support explicit closed objects with scalar fields and primitive lists, inline/named schemas and local requestBody references. Per-field form/explode defaults, repeated-key/CSV arrays, required fields, enums, JSON names and directional mutation validation are verified. Query, headers and body remain separate in GetParams/PostParams. Swagger v0.13.0 formData normalizes scalar/list fields to the same runtime with CSV/multi formats and explicit URL-encoded consumes. OpenAPI multipart now supports the bounded binary-string subset below; Swagger multipart/files, other formats, nested/open form schemas and other styles remain pending. See [forms](FORMS.md).
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
- Not a general OpenAPI 3.1 / JSON Schema implementation; external references/mappings, arbitrary native unions, `not`/`const`, general schema composition, advanced formats, OAuth2/OpenID Connect authorization flows, API keys in cookies, streaming files and advanced form/multipart support are not implemented. Patterns using ECMA-regex features that differ from PCRE may not validate identically. For OAuth2, provide a previously obtained bearer token only when the security scheme is declared as HTTP bearer; the generator does not acquire or refresh tokens.
- OAuth2/OpenID Connect authorization flows are not implemented; credentials must be supplied by the caller, and tokens are not acquired or refreshed by the SDK.
- Multiple body-bearing success responses and some non-object inline error schemas are not supported. Multiple advertised media types are accepted when at least one JSON representation exists and all JSON variants share the same schema; incompatible JSON schemas are rejected. Versioned JSON media types are supported. The full original FakeRESTApi OpenAPI document generates successfully, with live GET list/single and POST response checks.
- Required fields and supported schema constraints are validated before outbound request serialization. Native PHP types constrain assigned values, but this is not full JSON Schema validation and does not cover every format or constraint.
- Explicit `additionalProperties` and discriminated families preserve permitted extra JSON fields. Other generated DTOs reject unknown fields; a general configurable policy remains pending. Input JSON property names must be representable by supported generated PHP identifiers.
- The generated SDK defaults to Guzzle 7; a PSR-18 client is optionally injectable. Shared HTTP behavior is provided by stable `sdk-runtime` v0.11.1 or later compatible versions (`^0.11.1`).
- No tested live integration with Casafari or a production external API; only owned synthetic fixtures. No general latency, memory or security superiority claim versus OpenAPI Generator.
- Existing Casafari SDK middleware that uses by-reference arguments and bool callbacks **is not** an interchangeable instance of Guzzle HandlerStack middleware. This release does not change Casafari SDK.

## Roadmap (not promises about 0.4.0)

Broader OpenAPI coverage, more live endpoint cases (including authenticated requests and PATCH where safe), and feature-equivalent performance/usability comparisons.

Please report supported-schema failures through GitHub Issues with a small **redacted** reproducible JSON specification (no keys or tokens).


## Polymorphic models (since 0.3.0)

Generator 0.3.0 ([PR #19](https://github.com/dmorenof/sdk-generator/pull/19)) is
coordinated with runtime 0.4.0 ([PR #6](https://github.com/dmorenof/sdk-runtime/pull/6)).
The current generator and generated Composer packages require stable runtime `^0.11.1`;
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


## Local HTTP Reference Objects (0.5.0)

Named document-local references to OpenAPI `components/parameters`,
`components/requestBodies` and `components/responses` execute through one shared
resolver. Swagger 2.0 supports `#/parameters/...` (including body parameters) and
`#/responses/...`. Path/operation parameter merging uses resolved `in` and `name`,
so operation overrides keep their normal precedence. Supported JSON Pointer
fragments decode URI escapes and `~0`/`~1`; inspection retains the original source
hash and reports the declaration pointer, operation and alias chain.

OAS 3.0 and Swagger Reference Object siblings are ignored. OAS 3.1 reference
`description`/`summary` annotations override the target, with the outer alias
winning; sibling `required`, `schema` and other execution fields do not replace
the target. This does not implement general Schema Object `$ref` sibling semantics.
Alias cycles are errors and chains beyond 128 hops are rejected; schema references
are kept as references, preserving existing recursive model support.

The referenced Accounts fixture produces the same canonical contract and SDK
sources as its inline equivalent. Its generated SDK is independently installed
and exercises mock GET/POST, enums, collections and DTO hydration in release smoke.

The named local HTTP reference categories above were added in v0.5.0; v0.6.0
adds Path Item and header resolution as described below. Arbitrary target
locations, security/example/link/callback Reference Objects, external
JSON/YAML/HTTP documents, anchors, dialect resolution and full JSON Schema remain
pending. `content` parameters, structured HTTP parameters and non-JSON transports
retain their existing diagnostics until their serializers are implemented.


## Path Item and header references (0.6.0)

Local Path Item references can target `#/paths/<escaped-path>` in Swagger 2.0,
OpenAPI 3.0 and 3.1, or named `#/components/pathItems/<name>` in OpenAPI 3.1.
Alias chains use the existing 128-hop bound and cycle checks. Reused operations
keep their declared operation IDs; duplicate IDs fail rather than being renamed.
Effective parameters and operation/path/root server precedence survive resolution.

A Path Item `$ref` is a fixed field, not a Reference Object. Disjoint sibling
fields are combined; the specification leaves overlapping fields undefined, so
these produce `conflicting_path_item` at the actual conflicting source field.
This includes repeated summary/description, parameters and methods. Per-field
provenance keeps local operations at their local pointer and inherited fields at
their declaration, with the applicable alias chain. Isolated acceptance preserves
these origins, enumerates resolved operations and reports `unresolvedPathCount`.
An unresolved route cannot pass acceptance as a zero-operation SDK.

OpenAPI `components/headers` references resolve through aliases in response
headers and media encoding headers, with the Reference Object annotation rules
already used for parameters/bodies/responses. Their raw schema/content metadata
is retained. This does **not** expose typed response headers from the runtime or
execute encoding rules; inspection still diagnoses those limitations. Recursive
HTTP-metadata graphs terminate with an explicit diagnostic, while supported
recursive Schema Objects keep their existing identity.

Accounts and server-routing fixtures verify byte-identical generated SDK output
against inline equivalents, including JSON/YAML, independent installation and
mock HTTP. Relative/external documents, arbitrary target positions and remaining
reference categories stay outside this release. No runtime change is required;
generated packages continue using stable `^0.5.0`.


## Explicit local file graphs (0.7.0)

`--resolve-local-references` and the new file APIs load supported local JSON/YAML
schema and HTTP fragments relative to each referring document. The default root
is the canonical input file's directory; `--reference-root=...` can explicitly
allow a shared parent. String-based APIs and CLI commands without this flag keep
their existing behavior. No HTTP(S), absolute URI or stream transport is added.

The loader preserves public root model names for pure external schema aliases,
collapses pure schema alias chains, retains recursive object references and
names otherwise colliding imported schemas deterministically. Supported HTTP
fragments cover parameters, request bodies, responses, header metadata and OAS
3.1 Path Items. Swagger 2.0 supports its existing schema/parameter/response subset;
external Path Items for Swagger/OAS 3.0 remain unsupported. Fragment pointers may
address objects in their source file; this does not expand all direct root-local
reference positions supported by the normalizer.

Reports keep the original root SHA-256 and add dependency hashes plus the bundle
hash. Known diagnostics map back to the original source and JSON Pointer with
qualified reference chains. Unlocalized legacy parser/emitter failures still use
a root diagnostic. File caches keep a single snapshot per canonical source.

Limits: 2 MiB/file, 16 MiB total source bytes, 64 documents, 100,000 semantic and
100,000 decoded/materialized payload nodes, depth 256, 64 MiB accounted payload,
and 128 pure schema alias hops (HTTP alias bounds remain unchanged). Root paths,
parent traversal and symlinks must remain inside the configured root. Examples
and literal values are retained as data; decoded payload accounting includes them.

Root external schema aliases and imported pure alias nodes must contain only
`$ref`; conflicting public names for the same target are explicit errors.
Resource IDs, anchors, custom/mixed dialect scopes and general JSON Schema remain
pending. Across multiple documents discriminators require explicit mapping; local
single-document discriminator behavior is unchanged. Schema fragments may come
from a compatible complete API document, but HTTP fragments from another full
API document are rejected until server/security namespace inheritance is handled.
The existing emitter's schema/model-type restrictions continue to apply.

See [file reference usage and limits](FILE_REFERENCES.md). The eight-file Accounts
fixture emits the same SDK as the consolidated fixture and executes mock HTTP
through an independently installed SDK; generated SDKs never read the source files.


## Local security-scheme references (0.8.0)

OpenAPI 3.0/3.1 security-scheme definitions may reference named local
`components/securitySchemes`, including multi-level aliases. The shared resolver
applies existing Reference Object annotation rules and bounded cycle/missing-target
checks. Inspection audits the actual definition and preserves its source chain.

The declaring key stays the public credential name: an alias `Bearer` referring
to `BearerBase` requires credentials named `Bearer` when the operation requires
that alias. Credentials named only `BearerBase` do not satisfy it. Existing root
inheritance, operation overrides (including empty security), AND groups and OR
alternatives retain their semantics.

Only the existing HTTP Basic/Bearer and header/query API-key types execute.
Unsupported target types, nonempty scopes and cookie API keys remain diagnosed;
Reference Object siblings cannot turn OAuth into a supported HTTP scheme. Swagger
2.0 security-definition references and external file/network scheme imports remain
outside this release. The local aliases also work when other supported parts of
the document are loaded through the v0.7.0 file API; the file bundler does not
fetch external security definitions. Runtime stays at stable `^0.5.0`.

## Shared implementation (v0.14.0)

SDK Client and Configuration inherit common execution/options from runtime ^0.10.1. SDK classes declare API-specific metadata and defaults; public request signatures and DTO types remain unchanged. Guzzle PSR-7 constructs the query; the runtime adapts OpenAPI styles and validates/serializes DTOs. See [architecture review](ARCHITECTURE_REVIEW.md).

## Buffered OpenAPI multipart (v0.15.0)

Closed flat object bodies support text scalars, repeated primitive lists and binary string file properties. Guzzle PSR-7 MultipartStream handles MIME/boundaries; generated SDKs only declare field metadata. JSON takes precedence over URL-encoded forms, which take precedence over multipart. Binary files use basename(field) + `.bin`; their default type is application/octet-stream and one explicit encoding.contentType MIME type is supported. Text parts use text/plain.

Inline/local referenced schemas and request bodies, GetParams/PostParams, query/header/body separation, binary bytes, direction rules and mutation validation are verified. Bodies are buffered through the existing string transport contract. Streaming/large-file memory control, custom filenames, binary arrays, nested objects, per-part headers and advanced encoding remain pending. Binary enum/pattern/Unicode length constraints are rejected; they cannot faithfully validate arbitrary file bytes. See [usage and exact limits](FORMS.md#openapi-multipart-v0150--runtime-v0111).
