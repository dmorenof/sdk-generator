# v0.40.0 supported scope and known limitations

**Stable dependency: sdk-runtime ^0.31.0. Support remains bounded; this is not full OpenAPI/JSON Schema conformance.**

## Current verified coverage

- Swagger 2.0, OpenAPI 3.0.x and 3.1.x use the same normalization/emission path for the tested JSON/YAML subset. Strict inspection rejects known unsupported execution and does not silently enable it.
- Canonical local scalar/object/enum/map/array/composition aliases, recursive object graphs, JSON Pointer URI/~0/~1 schema decoding and explicit opt-in local file bundles are supported. Alias components reuse canonical types without extra wrappers; pure cycles, missing targets and excessive chain depth fail.
- Compatible single-parent object allOf uses PHP inheritance. Discriminators select classes independently of validity. Existing composition wrappers validate the original JSON snapshot and count every branch: oneOf requires exactly one match, anyOf at least one. Equivalent aliases do not hide ambiguity.
- OpenAPI 3.1 primitive type sets use native PHP unions. Boolean schemas and scalar/null const, numeric bounds/multiples, Unicode-aware string constraints, property counts and array rules are checked within their supported contexts. Named array aliases reuse native arrays or existing typed collections. Defaults are preserved as metadata and are not materialized into DTOs.
- Required/readOnly/writeOnly behavior and JSON names are directional and recursive. Validation remains active before sending/serialization and after mutations. Existing shared enum/DTO/Map/TypedArray behavior is reused.
- Supported HTTP parameters include scalar/list/flat-object form, deepObject, simple, label/matrix and Swagger delimiter formats, bounded allowReserved/allowEmptyValue, scalar cookies/API key cookies, and JSON/text parameter content. GetParams/PostParams keep path/query/header/cookie/body locations separate, including a query named body. Optional null wire parameters stay omitted; required ones cannot silently disappear.
- Supported JSON root bodies include DTOs, maps, collections, native arrays, scalar values and null. Optional omission differs from explicit JSON null in resources, facades and combined parameter DTOs. Bounded URL-encoded and multipart forms include existing list/object encodings, binary uploads/file lists and JSON parts.
- Concrete raw MIME string schemas retain opaque CSV/XML/text bytes and constraints. Bounded binary schemas reuse PSR streams for PNG/PDF/SVG and other concrete media, including binary aliases. Vendor/problem +json retains JSON hydration and its declared Accept/Content-Type. Raw CSV/XML are strings, not structured model codecs.
- Success and error response codecs follow exact/range/default status precedence. Bodyless overrides suppress fallback decoding. ApiException keeps the HTTP status/raw response and DTO-only error property; payload exposes supported scalar/null/list/map/enum/composition/DTO and raw errors. Invalid decoding preserves the HTTP failure and its cause.
- Runtime execution, security and configuration stay shared. Client::connect constructs Guzzle by default and accepts PSR-18 or legacy custom transports. Public middleware remains reusable; generated resources declare types and operation metadata. Existing protected DTO executor signatures remain supported.
- HTTP Basic, bearer tokens and API keys in supported locations retain inherited/overridden OR/AND security requirements. Absolute HTTP(S) server templates expand validated defaults/enums by scope and retain existing per-environment overrides. Path declarations and equivalent template hierarchies are checked; Paths extensions remain data.
- Local validation covers 471 generator tests / 3,770 assertions and runtime 169 / 954. CI installs 42 independent generated SDKs at PHPStan maximum, with source/published-package HTTP smoke. File-backed 32 MiB input/output tests run under PHP 16 MiB; this does not promise arbitrary-client/RSS/streaming guarantees.

## Remaining boundaries

- Remote/dynamic/general reference scopes, arbitrary schema locations, IDs/anchors/dynamic references and general validation siblings of schema $ref remain incomplete. String inspection remains offline; local file loading is explicit.
- Native object/array type-set alternatives, general non-object allOf/intersections, general not/conditional vocabularies and complex const remain outside the bounded schema implementation. PHP numeric precision and existing composition limits still apply.
- Nested/unconstrained HTTP structures, structured/JSON cookies, advanced multipart headers/explode behavior, non-seekable inputs and general nullable collection-item support remain limited. See each serializer's contract.
- Wildcard MIME selection, incompatible representation negotiation, structured XML/CSV models and charset transcoding remain pending. Binary schemas retain their bounded rules. Binary error bodies remain buffered in HttpResponse; legacy custom transports materialize string bodies.
- Declared response header schemas are preserved but are not generally validated/exposed through a typed response wrapper. General cookie persistence, redirect policy and protocol edge cases remain pending.
- OAuth2/OIDC token acquisition, relative servers, named variable-binding APIs and dynamic server selection and live authenticated Casafari migration are not completed. No production migration or authenticated acceptance is claimed by mock/TCP tests.

Use [pendientes.md](../pendientes.md) for the remaining roadmap and the focused contracts: [schema rules](SCHEMA_RULES.md), [native types](NATIVE_SCHEMAS.md), [JSON bodies](REQUEST_BODIES.md), [aliases](SCHEMA_ALIASES.md), [errors](ERROR_PAYLOADS.md), [MIME](MIME_MEDIA.md), [forms](FORMS.md) and [parameter serialization](OBJECT_PARAMETERS.md).

## Historical delivery notes

The sections below record the limits of their original releases. Later milestones and the current coverage above supersede those historical limits; they are not a second current feature checklist.

## Polymorphic models (since 0.3.0)

Generator 0.3.0 ([PR #19](https://github.com/dmorenof/sdk-generator/pull/19)) is
coordinated with runtime 0.4.0 ([PR #6](https://github.com/dmorenof/sdk-runtime/pull/6)).
This historical milestone originally introduced stable runtime dependencies; current packages use `^0.31.0`;
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
is retained. Response headers with a scalar, scalar-list or enum schema are emitted as runtime
`responseHeaders` metadata (typed through `lastResponse()->headers`). This does **not**
execute encoding rules or type object/union/`content` headers; inspection still diagnoses those limitations. Recursive
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

HTTP Basic/Bearer and header/query API-key types execute; v0.26.0 also supports OpenAPI cookie API keys.
Unsupported target types and nonempty scopes remain diagnosed;
Reference Object siblings cannot turn OAuth into a supported HTTP scheme. Swagger
2.0 security-definition references and external file/network scheme imports remain
outside this release. The local aliases also work when other supported parts of
the document are loaded through the v0.7.0 file API; the file bundler does not
fetch external security definitions. Runtime stays at stable `^0.5.0`.

## Shared implementation (v0.14.0)

SDK Client and Configuration inherit common execution/options from runtime ^0.10.1. SDK classes declare API-specific metadata and defaults; public request signatures and DTO types remain unchanged. Guzzle PSR-7 constructs the query; the runtime adapts OpenAPI styles and validates/serializes DTOs. See [architecture review](ARCHITECTURE_REVIEW.md).

## Buffered OpenAPI multipart (v0.15.0)

Closed flat object bodies support text scalars, repeated primitive lists and binary string file properties. Guzzle PSR-7 MultipartStream handles MIME/boundaries; generated SDKs only declare field metadata. JSON takes precedence over URL-encoded forms, which take precedence over multipart. Binary files use basename(field) + `.bin`; their default type is application/octet-stream and one explicit encoding.contentType MIME type is supported. Text parts use text/plain.

Inline/local referenced schemas and request bodies, GetParams/PostParams, query/header/body separation, binary bytes, direction rules and mutation validation are verified. Multipart streams are preserved by the standard PSR-18 path in v0.18.0; legacy custom transports retain string bodies. PSR uploads add configurable filenames/MIME and seekable input streams in v0.17.0. Streaming PSR-18 uploads and the measured memory case are added in v0.18.0; OpenAPI binary arrays arrive in v0.19.0; nested objects, per-part headers and advanced encoding remain pending. Binary enum/pattern/Unicode length constraints are rejected; they cannot faithfully validate arbitrary file bytes. See [usage and exact limits](FORMS.md#openapi-multipart-v0150--runtime-v0111).

## Swagger multipart (v0.16.0)

Swagger type:file formData parameters become string binary fields in the same request-body contract. Require a single multipart/form-data consumes representation; operation consumes and field overrides take precedence. Primitive multipart lists require explicit collectionFormat: multi; default/explicit CSV remains rejected. Reusable declarations are checked independently of media selection, then each usage is checked in its operation context. URL-encoded files, body/formData mixtures, file-list/nested shapes and unsupported file constraints fail explicitly. SDK output is byte-identical to the equivalent OpenAPI fixture, uses the common runtime; v0.18.0 adds standard PSR-18 streaming with a compatible buffered legacy fallback. v0.17.0 extends inputs with PSR filenames/MIME and seekable streams; strings keep fixed fallback filenames. See [forms](FORMS.md#swagger-20-multipart-v0160).

## PSR upload inputs (v0.17.0)

Native binary model fields accept string|Psr\Http\Message\UploadedFileInterface, plus null when nullable. Reuse existing PSR/Guzzle uploaded files for filenames, MIME types and readable seekable input streams. Explicit OpenAPI part contentType is enforced; unspecified types accept the upload MIME or octet-stream fallback. Strings preserve existing behavior. Failed/unsafe uploads, unsupported streams and JSON serialization of uploaded objects fail before transport; repeat sends rewind through Guzzle. This requires stable runtime ^0.12.0. General schema-union support is unchanged. v0.18.0 preserves request streams through PSR-18; legacy transports keep buffering. See [usage and limits](FORMS.md#psr-uploaded-files-v0170--runtime-v0120).

## Streaming multipart uploads (v0.18.0)

Stable runtime ^0.13.0 passes MultipartStream directly through the existing PSR-18 adapter; seekable bodies rewind after middleware and on every send. No new transport interface is added. Legacy custom TransportInterface implementations keep string bodies. Two 32 MiB uploads with default Guzzle/cURL succeeded under a 16 MiB PHP limit with a 4 MiB PHP peak; installed OpenAPI/Swagger SDKs repeat the bounded-memory smoke in CI/publication. This measures file-backed upload requests, not arbitrary clients/middleware, strings, response buffering or RSS. Shared PSR stream cursors across parts are rejected; OpenAPI file lists arrive in v0.19.0; non-seekable/unknown-size guarantees, nesting and streaming downloads remain pending. [Details](FORMS.md#streaming-multipart-v0180--runtime-v0130).

## OpenAPI binary file lists (v0.19.0)

Multipart body properties with type:array and non-null string/binary items use native PHP lists of strings or UploadedFileInterface, validated for item types, bounds, required/nullable fields and mutations. Existing Guzzle multipart expansion repeats exact field names in order and preserves per-file filename/MIME; explicit contract MIME applies to every item. JSON-only collections keep their types; inherited native lists keep item validation. No file-list wrapper is generated. Runtime ^0.14.0 and an independently installed SDK each pass two 16 MiB-file HTTP requests under a 16 MiB PHP limit (4 MiB PHP peak), with independent MIME parsing. Seventeen SDKs pass maximum analysis. Swagger 2.0 file arrays, nullable/nested/constrained binary items and advanced encoding remain rejected. [Usage](FORMS.md#openapi-binary-file-lists-v0190).


## OpenAPI binary downloads (v0.20.0)

Successful application/octet-stream with inline nonnullable string/binary schemas
returns native PSR-7 ResponseInterface, including status, multi-value headers and
body stream. Local response aliases, JSON/YAML, OpenAPI 3.0/3.1, compatible 200/206
plus empty 204 and GET/POST JSON export requests are supported. JSON retains
representation priority. Runtime ^0.15.0 preserves the PSR response; Guzzle
copyToStream saves bytes without a full PHP string. JSON/typed errors and custom
legacy transports retain their buffered compatibility path.

Real HTTP copies/hashes 32 MiB per download under PHP memory_limit=16M; measured
PHP peaks are 8 MiB in the runtime and 6 MiB in the independent SDK (GET/POST
repeated). Eighteen independently installed SDKs pass PHPStan maximum. This is
not a promise of progressive network reads, no disk storage, universal RSS or
arbitrary client/middleware memory behavior. Swagger, other MIME types,
constraints/nullable binary schemas, binary errors, mixed success codecs,
negotiation and declared response-header schema validation remain pending.
See [downloads](DOWNLOADS.md) for usage and precise limits.


## Swagger binary downloads (v0.21.0)

Swagger 2.0 response schema type:file with one effective produces
application/octet-stream adapts to the same native PSR response contract.
Root/operation precedence, response aliases, JSON/YAML, explicit binary success
codes and empty responses are supported. GetParams/PostParams retain query,
headers and independent JSON request DTOs; mutations still validate before HTTP.
The generated SDK matches equivalent OpenAPI byte for byte. Stable runtime
^0.15.0 is unchanged, and the existing installed download smoke is reused.
Nineteen independent SDKs pass maximum PHPStan. Real GET/POST downloads of 32 MiB
under PHP memory_limit=16M measure an 8 MiB PHP peak with full hashes/headers.
Multiple produces, other MIME types, file constraints/nullability/errors, typed
JSON errors sharing octet-stream produces, and general streaming guarantees
remain outside this slice. [Usage and exact limits](DOWNLOADS.md#swagger-20-file-responses-v0210).


## JSON success selection by status (v0.22.0)

Swagger 2.0 and OpenAPI 3.0/3.1 JSON/YAML operations can declare several supported
JSON success types. Generate native unions, distinct inline names, and a status
map for shared ApiClient/JsonHydrator. Preserve parent/child classes, serialization,
validation and per-status nullability; never probe another schema if validation
fails. A bodyless status returns null; an empty JSON status still fails decoding.
Older SDK metadata retains fallback behavior. Stable runtime ^0.16.0 and twenty
independently installed SDKs are verified, including 24 real GET/POST scenarios.
No mixed JSON/binary status codecs, ranges, general property unions or new JSON
Schema keywords are implied. [Examples and compatibility](RESPONSES.md).


## Simple primitive path/header lists (v0.23.0)

OpenAPI simple (default/explicit, explode true/false) and Swagger CSV path/header
arrays reuse native PHP lists and the existing parameter resolver/validator.
Use PathParams DTOs for list paths; single scalar signatures and inherited
executeArguments stay compatible. Runtime ^0.17.1 encodes path items individually
and keeps raw header CSV; ambiguous commas, controls and outer header whitespace
fail before transport. Required/no-null path declarations match placeholders.
Twenty-two installed SDKs pass maximum PHPStan; both dialects reuse installation
smoke and real GET/POST HTTP checks with independent query/headers/JSON body.
Objects, nullable/nested items, other formats/styles and cookies remain pending.
[Usage, empty policy and boundaries](SIMPLE_PARAMETERS.md).


## Flat parameter objects (v0.24.0)

Complete form/query and simple/path-header flat objects with defaults and both
explode modes. Reuse DTO/Map schemas, JSON names, directions, validation and scalar
encoding; keep PathParams/GetParams/PostParams and body/query separation. Expanded
query collisions and ambiguous raw header delimiters fail before HTTP. DeepObject
behavior and constructor/executor compatibility remain intact. Runtime ^0.18.0 is
published first; twenty-three installed SDKs pass PHPStan maximum and an independent
HTTP consumer verifies GET/POST path/query/header/body bytes. Untyped/nested/null
values, compositions, cookies, label/matrix and content remain unsupported.
[Usage and exact boundaries](OBJECT_PARAMETERS.md).

## Label/matrix paths (v0.25.0)

OpenAPI 3.0/3.1 primitive scalars/lists and flat typed objects support both explode modes and defaults. Runtime ^0.19.0 preserves literal dot segments with the default Guzzle/cURL client. Existing query/header/body behavior and public executor signatures remain compatible. Nested/nullable/unconstrained shapes and structured cookies remain pending; scalar cookies arrive in v0.26.0. See [exact behavior and evidence](PATH_STYLES.md).

## Scalar cookies and API key cookies (v0.26.0)

OpenAPI scalar form cookies use existing Params DTOs/validation/native percent encoding. Cookie API key credentials remain raw RFC6265 values. Configured, raw-operation and security cookies share one header with explicit conflict checks. Runtime ^0.20.0 preserves executor signatures and query/body separation. Structured/nullable/content cookie schemas, cookie jars, persistence and Set-Cookie response access remain unsupported. See [behavior, rationale and tests](COOKIES.md).

## Parameter inheritance and duplicate rejection (v0.26.1)

Operations replace a Path Item parameter's complete declaration by exact location/name identity. Empty or absent operation lists preserve inheritance. Duplicate identities within one list fail in normal/strict generation; resolved aliases and masked parent duplicates retain source diagnostics. Reusable declaration maps can contain alternative definitions. Stable runtime ^0.20.0 is unchanged. See [rules and verification](PARAMETER_OVERRIDES.md).

## Reserved query expansion (v0.27.0)

OpenAPI query allowReserved supports the existing primitive scalar/list and flat DTO/Map forms. Safe reserved values and valid percent triples pass through; names, unflagged data and security credentials retain ordinary encoding. Explicit delimiter validation avoids changing query shape. Stable runtime ^0.21.0 preserves signatures and query/header/cookie/body separation. Application pre-encoding requirements, encoded list delimiters and unsupported contexts remain explicit. See [exact policy and tests](RESERVED_QUERY.md).

## Explicit empty-value policy (v0.28.0)

Supported OpenAPI/Swagger query and Swagger formData allowEmptyValue declarations reuse existing typed DTOs and serializers. Explicit empty strings remain name=; optional absent/null values are omitted; false/zero and empty collection/item distinctions remain intact. Schema required/constraints/types stay authoritative. Runtime ^0.21.0 is unchanged. Independent OpenAPI/Swagger SDK HTTP checks preserve GetParams/PostParams and query/form/JSON separation. See [exact policy](EMPTY_VALUES.md).

## Typed parameter content (v0.29.0)

OpenAPI supports one application/json representation for path/query/header and text/plain for path/query/header/cookie using existing native types/models/collections. Runtime ^0.22.1 serializes request-direction JSON once before URI encoding, preserves raw header/cookie bytes and maintains list shape after sparse mutations. Unsupported media/cardinality/style conflicts fail explicitly; nullable roots and other documented limits remain pending. See [contract and evidence](PARAMETER_CONTENT.md).
