# Changelog

This project follows semantic versioning for tagged releases. The v0.1.0 release is an **early functional milestone**, not a claim of complete OpenAPI conformity.

## [0.1.0] — 2026-10-20

### Added

- One typed canonical contract model and equivalent synthetic Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1 normalization fixtures.
- Deterministic PHP emission of models/DTOs, native typed fields, backed enums, TypedArray-backed collections and executable typed operation methods.
- Standalone generated Composer packages, build-time CLI and generated JSON hydration.
- PSR-18 bridge with Guzzle HTTP default and an optional injected client/headers; ordinary API calls do not require manually configuring the HTTP transport.
- GitHub Actions CI for PHP 8.3, 8.4 and 8.5, preview packages downloadable without local PHP, and comparisons with OpenAPI Generator using equivalent synthetic inputs and fake HTTP.

### Known limitations

- A **subset** of JSON Swagger/OpenAPI only; no YAML, complete security schema, or general composition/parameter/media-type support.
- Outbound required-field and schema-constraint validation is not complete; generated typed PHP does not replace full input validation.
- Synthetic fixtures and offline HTTP only: no production API compatibility claim or general performance superiority claim.
- Shared sdk-runtime prototype is not a released dependency of generated packages.

See docs/SUPPORTED_SCOPE.md and README.md before production evaluation.
