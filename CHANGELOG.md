# Changelog

This project follows semantic versioning for tagged releases. The v0.1.0 release is an **early functional milestone**, not a claim of complete OpenAPI conformity.

## Unreleased

No changes yet.

## [0.14.0] — 2026-11-08

- Stop emitting generic operationFields/executeArguments methods in every SDK Client; inherit the shared implementation from stable runtime ^0.10.1. Generated Configuration inherits shared connection options and supplies its API-specific baseUrl.
- Keep the public parameter DTOs, signatures, per-SDK connection isolation, middleware and JSON/form/query semantics. Accounts Client+Configuration decreases from 158 to 50 generated lines; generic behavior exists once in the runtime.
- Reuse Guzzle PSR-7 query assembly and native query/form percent-encoding through the shared runtime. Preserve the OpenAPI-specific style adaptation that generic indexed query/form_params options cannot replace.
- Record the code review, measured reduction, remaining contract adaptation and user reuse/inheritance rules in docs/ARCHITECTURE_REVIEW.md and AGENTS.md. Verify inherited runtime declarations alongside all existing generated-SDK behavior and installation smoke.

## [0.13.0] — 2026-11-08

- Normalize Swagger 2.0 formData parameters into the existing URL-encoded request-body contract. Aggregate scalar fields and primitive lists, required flags, constraints, descriptions and nullable extensions; reuse the stable runtime ^0.9.0 without changing it.
- Preserve Swagger's default CSV list format and explicit csv/multi options by lowering them to form/explode encoding. Resolve reusable parameter aliases and apply operation-over-path overrides before building the body; any required form field makes the aggregate body required.
- Keep GetParams query-only when consumes is inherited without form fields. PostParams separates query, headers and the typed form body, including coincident names and a query named body. Optional/body-only forms retain the existing absent/empty/null rules.
- Audit effective referenced parameters before media checks. Require one explicit URL-encoded consumes representation, with operation-over-root precedence; reject files, multipart, unsupported formats/options and body+formData conflicts with source diagnostics.
- Verify byte-identical SDK output for equivalent Swagger/OpenAPI forms, JSON/YAML, PSR-18 and real cloud-local HTTP requests, mutations, 14 SDKs at PHPStan max and an independent installed Swagger form SDK in release verification. Update the remaining HTTP/forms limits.

## [0.12.0] — 2026-11-08

- Generate OpenAPI 3.0/3.1 application/x-www-form-urlencoded request bodies for closed object schemas with scalar fields and primitive lists. Reuse named body DTOs, generate inline bodies and preserve local requestBody references. JSON retains precedence when both representations are declared; per-call media negotiation remains pending.
- Execute per-field form encoding with default/explicit form style and explode true (repeated keys) or false (CSV); require stable runtime ^0.9.0. Encode spaces as +, literal plus/tilde/percent as data, preserve backed enums and JSON names, apply request-direction fields and revalidate mutations.
- Verify GetParams remains query-only and PostParams separates query, headers and form body even for coincident names and a query named body. Distinguish absent body, present empty form, null fields, empty strings and lists. Body-only operations reuse the original DTO.
- Extend strict inspection to supported form schemas/encoding and reject unsupported options, nested fields, unconstrained objects, nullable list items, multipart and files. Swagger formData and other form styles remain pending.
- Validate 13 SDKs at PHPStan max, exact PSR-18 URL/header/body bytes, a real HTTP POST to a cloud-local server and a separately installed form SDK in immutable-release verification. Update the HTTP/forms roadmap and actual limits.

## [0.11.0] — 2026-11-08

- Generate typed query objects for OpenAPI `style: deepObject`, `explode: true`: inline or local referenced flat scalar object schemas with explicit `additionalProperties: false` or a supported scalar value schema. Reuse named DTOs and existing typed Maps; reject undefined nested/null properties, unconstrained extras and other object styles.
- Require published runtime `^0.8.0`. Serialize JSON wire names and backed enum values, omit readOnly properties, include writeOnly properties and revalidate object/map mutations before transport. Encode brackets and values once; reject ambiguous names and query-key collisions.
- Exercise GetParams and PostParams with GET and POST, including simultaneous query objects and JSON body. Add explicit operation-body metadata so a query parameter named `body` is not sent as JSON, and reserve the actual `body` constructor field when the query DTO uses `queryBody`.
- Verify JSON/YAML and OpenAPI 3.0/3.1, reusable parameters, static and instance APIs, exact PSR-18 URLs/body bytes, required fields, enums, validation and invalid mutations. Analyse twelve installed SDKs at PHPStan max and extend immutable-release installation smoke with GET/POST query-body separation.
- HTTP forms, multipart, nested deepObject data and other pending HTTP serialization remain outside this release; record the partial getParameters/postParameters review and its remaining form work in pendientes.md.

## [0.10.0] — 2026-11-08

- Execute primitive query lists using OpenAPI `spaceDelimited` and `pipeDelimited`, with omitted or false `explode`. Reject undefined `explode: true` combinations. Support Swagger query `collectionFormat: ssv`, `tsv` and `pipes` alongside existing CSV/multi.
- Require stable runtime `^0.7.0`. Use shared serialization with RFC3986-compatible `%20`, `%09` and `%7C` delimiters, while encoding item data once. Reject data containing the format's own delimiter before transport, including mutations, because percent-decoding makes such values ambiguous.
- Verify equivalent OpenAPI 3.0/3.1 and Swagger output, JSON/YAML, reusable parameters, exact URLs through PSR-18, omission/empty-item behavior and invalid values. Analyse eleven generated SDKs at PHPStan max and exercise an independently installed delimited SDK in release verification.
- Preserve scalar, repeated-key and CSV behavior. Objects, other parameter locations, content parameters, reserved/empty-value flags and the remaining styles remain pending; update `pendientes.md` and the supported scope accordingly.

## [0.9.0] — 2026-11-07

- Emit native PHP list properties for query arrays of non-null primitive items. Validate list shape, item types, supported item constraints and array bounds during construction and after mutations, before transport.
- Execute OpenAPI `style: form` with repeated keys (`explode: true`, the default) or CSV (`explode: false`). Execute Swagger 2.0 `collectionFormat: multi` and `csv`, preserving its different implicit CSV default. Encode individual values without confusing embedded commas with CSV separators.
- Require published stable runtime `^0.6.0`; serialization remains shared by all generated operations. Omit null/absent parameters and empty lists; preserve empty string items and scalar behavior, including API-key precedence.
- Verify exact URLs with fake transports and PSR-18, JSON/YAML input, OpenAPI/Swagger output equivalence, invalid values and mutations. Analyse ten generated SDKs at PHPStan max and verify a separately installed query SDK in the publication smoke.
- Other array locations, objects, nullable/nested items, content parameters and additional styles/flags remain unsupported and diagnosed. See `docs/QUERY_ARRAYS.md`; P0 serialization remains partial.

## [0.8.0] — 2026-11-06

- Resolve local OpenAPI 3.0/3.1 `components/securitySchemes` Reference Objects through the shared resolver, including alias chains and existing annotation/cycle/missing-target rules.
- Keep the declaring security-scheme name as the public credential key. Root/operation inheritance, empty overrides and AND/OR requirement selection remain unchanged; base-definition credentials do not replace credentials required under an alias.
- Audit the resolved authentication definition at its actual source pointer with the complete reference chain. Unsupported OAuth, scopes and cookie keys remain unsupported and cannot be enabled by sibling overrides.
- Verify Bearer/header-key AND, query-key OR fallback, Basic auth, missing credentials, split credential names, JSON/YAML and inline/reference SDK equivalence. Add a ninth PHPStan-max generated SDK and independently install/execute an alias-authenticated SDK in release smoke.
- Keep runtime `^0.5.0`. Swagger 2.0 security-definition references and file/network security-scheme imports are not added by this local-reference release.

## [0.7.0] — 2026-11-06

- Add opt-in local JSON/YAML file graphs through `--resolve-local-references`, `--reference-root=...`, `SpecificationNormalizer::fromFile()`, `DocumentInspector::inspectFile()` and acceptance file APIs. String-based APIs retain their in-memory behavior; HTTP(S), absolute paths and URI streams are not fetched.
- Bundle supported external schema and HTTP fragments into the existing canonical pipeline. Preserve public root schema names, collapse pure schema aliases, keep recursive schema identity and assign deterministic names to colliding imported definitions. Equivalent split/consolidated Accounts and explicitly mapped Pet models emit identical SDK code.
- Cache canonical source files and fingerprint the original root, each dependency and the materialized bundle. Preserve original file/JSON Pointer diagnostics and qualify cross-file reference chains. Acceptance root hash pins are checked before reading dependencies.
- Confine real paths and symlinks to a configurable root; bound source counts/bytes, decoded payloads, graph depth and alias chains. Reject unsupported resource scopes, mixed dialects and schema alias siblings rather than interpreting them silently.
- Keep initial limits explicit: multi-document discriminators require explicit mapping; HTTP fragments from another complete API document require future server/security namespace handling; external Path Items require OAS 3.1. Existing schema/HTTP subset limits remain. Runtime stays stable `^0.5.0`.
- Verify an eight-file SDK through independent Composer installation, mock HTTP, PHPStan max and the installed published CLI. Update the roadmap with the completed local file slice and remaining general-reference work.

## [0.6.0] — 2026-11-06

- Resolve local Path Item references to `#/paths/...` in Swagger/OpenAPI and named `components/pathItems` in OpenAPI 3.1. Generate and count their inherited operations, parameters and servers; retain existing operation ID collision checks.
- Treat Path Item `$ref` as a fixed field with independent siblings. Combine disjoint fields and reject overlapping definitions explicitly, including overlapping annotations; apply neither Reference Object sibling ignoring nor implicit operation/parameter overrides to this undefined case.
- Preserve per-field pointers and alias chains through mixed local/inherited operations and isolated acceptance. Report unresolved path counts instead of accepting unresolved routes as an empty SDK; bound alias and recursive HTTP-metadata graphs.
- Resolve local OpenAPI Header Object references in response and media-encoding metadata. Header exposure/typing in the runtime and media encoding execution remain pending and continue to fail strict acceptance when applicable. Invalid references in these newly normalized positions fail visibly.
- Verify inline/referenced contract and SDK equivalence, JSON/YAML, HTTP mock execution and installed-package checks. Release smoke now generates Accounts and server-routing SDKs from Path Item references and verifies resolved header metadata. Keep stable runtime `^0.5.0`; external documents, arbitrary target locations and general JSON Schema reference rules remain pending.

## [0.5.0] — 2026-11-06

- Resolve document-local named OpenAPI `components/parameters`, `requestBodies` and `responses`, including alias chains, path/operation parameter overrides and escaped/URI-encoded pointer tokens. Resolve Swagger 2.0 reusable parameters (including body parameters) and responses.
- Share resolution between normalization and inspection; preserve declaration pointers, operation identity and complete alias chains. Strict normalization reports unresolved/invalid/cyclic references before parsing the executable contract. Alias chains are bounded to 128 hops; recursive schema identity is preserved.
- Apply OpenAPI 3.0/Swagger Reference Object sibling rules and OpenAPI 3.1 description/summary overrides without replacing target validation or required fields. These rules apply to the supported HTTP Reference Objects, not arbitrary JSON Schema `$ref` siblings.
- Keep referenced schema dependencies during isolated operation acceptance and distinguish literal example/link payloads from schema references. Verify equivalent inline/referenced SDK output, JSON/YAML input, HTTP execution and a clean installed-package smoke; analyse a sixth generated SDK at PHPStan max.
- Keep stable runtime `^0.5.0`. Headers, Path Items, external documents and full JSON Schema/dialect reference resolution remain pending; supported references do not add new HTTP serializers or media types.

## [0.4.1] — 2026-11-06

- Close inspection gaps in reusable OpenAPI parameters, request bodies, responses, headers, links and Path Items, plus Swagger reusable parameters/responses and header schemas. Strict mode now rejects detected unsupported options in declarations even when they are not used by an operation.
- Audit nested media encoding headers and callback operations; localize unsupported security schemes, scopes and undefined requirements. Preserve escaped source pointers, operation identity and bounded local reference chains.
- Keep literal examples and link expression/value payloads separate from schemas and references. Inspection remains offline; these diagnostics do not implement general reference resolution, incoming callbacks, OAuth or additional HTTP serializers.
- Verify the nested audit from a clean installation of the published generator. Continue development on the existing `release/0.2.0` branch rather than creating feature branches.

## [0.4.0] — 2026-11-05

- Execute literal absolute HTTP(S) operation servers with operation > path > root precedence in the shared runtime. Preserve configured root URLs, declared server path prefixes and encoded parameters; support per-connection `serverOverrides` through instance clients and static configuration.
- All 46 pinned Casafari operations pass strict acceptance; seven HTTP mock regressions verify the actual generated methods route to platform.casafari.com with their declared authentication.
- Require stable runtime `^0.5.0`, replacing the temporary branch dependency. Relative/template servers, variable expansion and dynamic server selection remain pending.
- Add in-memory `--inspect` / `--dry-run` compatibility reporting and an opt-in `--strict` generation gate before output is created.
- Audit previously ignored HTTP options with deterministic source/operation JSON Pointer diagnostics and local reference chains. Compatibility generation now prints diagnostics instead of silently ignoring known unsupported options.
- Preserve explicit parameter serialization options, content representations/encoding, effective server metadata, response headers/links and annotations in the intermediate contract. Keep server preference order and the declared Swagger JSON media type.
- Apply the strict HTTP audit to acceptance reports (`compatibility_failed`) and expose structured strict normalization errors. Operation isolation retains Path Item metadata and discriminator subtype closures instead of hiding server overrides or removing valid subtypes.
- The completed P0 contract/diagnostic and routing features are published in this version; unsupported serializers, relative/template servers, full reference resolution and transport formats remain pending. See `docs/CONTRACT_INSPECTION.md`.

## [0.3.0] — 2026-11-05

- Fix the Composer-installed generator binary to use the consumer autoloader supplied by Composer, while retaining checkout execution.
- Generate native PHP inheritance for supported `allOf` models with a single referenced object parent and compatible inline object extensions. Keep inherited property types (including enums), required fields, validation, JSON names and directional metadata across multiple levels. Only classes used as parents lose `final`.
- Reject object `allOf` extensions that introduce properties forbidden by a branch's `additionalProperties: false` instead of silently flattening away that restriction.
- Parse local explicit/implicit discriminator mappings and emit shared runtime metadata for base-model and composition hydration. Scalar enum intersections refine inherited fields without redeclaring their PHP type.
- Evaluate original composition schemas before hydrating; discriminator selection cannot hide `oneOf` ambiguity. Preserve independent JSON snapshots and serialize/revalidate a selected variant after mutation.
- Require stable `sdk-runtime` `^0.4.0`, replacing the temporary development branch. This release is coordinated with runtime v0.4.0 through runtime PR #6 and generator PR #19.
- Native composition unions, `not`, `const`, arbitrary inherited-property refinements and general JSON Schema remain pending; see `pendientes.md` and `docs/SUPPORTED_SCOPE.md`.

## [0.2.0] — 2026-11-05

### Consolidation

- Require stable `dmorenof/sdk-runtime` ^0.3.1, replacing the temporary feature branch.
- Use a single DTO base class for generated data; keep operation-specific inline `Requests` and `Responses` separated by HTTP verb and route, preserving reusable named `DTOs`.
- Generate and verify PHP sources in PSR-12 via build-time PHP-CS-Fixer.
- Accept multiple advertised media types when compatible JSON representations share a schema.
- Verify the entire public FakeRESTApi OpenAPI specification can generate a Composer package and execute GET and POST calls against the live synthetic test service.
- Generate and validate 46 Casafari operations offline, without credentials; production migration remains separate.

### Changed

- Generated SDKs no longer embed their own `Core`, `Transport` and `Support` classes (about 1,300 lines each). They depend on the shared `dmorenof/sdk-runtime` package, which owns hydration, validation, security, PSR-18 transport and operation execution; the generated `Client` only declares its `SECURITY_SCHEMES`.
- Reconstruct schema hierarchy from `__` identifiers, retain numeric variants, and preserve original reference identity instead of replacing single-use components with field-derived names. Reject naming collisions explicitly.
- Replace the global generated `CasafariSDK` connection and static operations with independent instance clients, inherited construction, and explicit resource sharing through `Client::resource()`.
- Reuse equivalent collection classes; simplify finite enum `anyOf` unions; give generated ranges and inline HTTP errors readable names.
- Share type conversion across DTOs, maps, union branches and root responses; fix union items inside DTO collections.
- Cache reflection types by class/field, serialize after one recursive validation, and emit endpoint configuration as class constants. Add isolation, round-trip, mutation and CPU/memory regression probes.

### Added

- Bounded `allOf`, `anyOf`, and `oneOf` support: compatible object intersections, generated composition wrappers, and shared runtime matching.
- Preserve schema defaults as metadata, support type-less values as `mixed`, validate PCRE-compatible patterns, and filter `readOnly`/`writeOnly` properties by request/response direction.
- Generate HTTP Basic/Bearer and header/query API-key authentication from OpenAPI schemes, preserving inherited/overridden security requirements and their AND/OR semantics.
- Preserve supported `$ref` title/description annotations and accept single JSON-suffix media types such as `application/problem+json`.
- Support inline string/integer enums on primitive HTTP parameters and validate request values against their declared members.
- Generate operation-derived types for inline object/array success responses and inline object errors; support one body-bearing success response alongside no-content success statuses.

### Limitations

- Composition discriminators, external references, and unsupported branch/sibling forms remain rejected.

## [0.1.0] — 2026-10-20

### Added

- YAML input support for Swagger 2.0 and OpenAPI 3.0/3.1 through Symfony YAML, using the existing normalization and emission pipeline.
- One typed canonical contract model and equivalent synthetic Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1 normalization fixtures.
- Deterministic PHP emission of models/DTOs, native typed fields, backed enums, TypedArray-backed collections and executable typed operation methods.
- Standalone generated Composer packages, build-time CLI and generated JSON hydration.
- PSR-18 bridge with Guzzle HTTP default and an optional injected client/headers; ordinary API calls do not require manually configuring the HTTP transport.
- GitHub Actions CI for PHP 8.3, 8.4 and 8.5, preview packages downloadable without local PHP, and comparisons with OpenAPI Generator using equivalent synthetic inputs and fake HTTP.

### Known limitations

- A **subset** of JSON/YAML Swagger/OpenAPI only; no complete security schema or general composition/parameter/media-type support.
- Outbound required-field and schema-constraint validation is not complete; generated typed PHP does not replace full input validation.
- Synthetic fixtures and offline HTTP only: no production API compatibility claim or general performance superiority claim.
- Shared sdk-runtime prototype is not a released dependency of generated packages.

See docs/SUPPORTED_SCOPE.md and README.md before production evaluation.
