# Changelog

This project follows semantic versioning for tagged releases. The v0.1.0 release is an **early functional milestone**, not a claim of complete OpenAPI conformity.

## Unreleased

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
