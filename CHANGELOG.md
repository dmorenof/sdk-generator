# Changelog

This project follows semantic versioning for tagged releases. The v0.1.0 release is an **early functional milestone**, not a claim of complete OpenAPI conformity.

## Unreleased

No changes yet.

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
