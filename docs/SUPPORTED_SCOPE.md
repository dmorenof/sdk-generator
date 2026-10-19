# v0.1.0 supported scope and known limitations

**Status: initial release, not fully conformant Swagger/OpenAPI generation.**

## What is verified

- The normalizer accepts the tested **JSON** subsets of Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x. The owned Accounts and Catalog specs in tests/fixtures/ have equivalent semantics across the three versions.
- The canonical contract uses immutable typed classes: API, operation, schema, parameters, body, model and response. One PHP emitter path writes the output, independent of source dialect.
- For the small supported subset, emits distinct DTO/model classes with native PHP property types, scalar-backed enums, collections that subclass the existing typedarray/typedarray library, and typed operation methods.
- Generates an installable Composer package and a minimal runnable API client. GET/POST path, query, headers, JSON body and typed response hydration are verified with **offline mock HTTP**. Nested object, enum and collection hydration have tests.
- The convenience factory ApiClient::connect(baseUrl) constructs Guzzle 7 automatically, while ApiClient::connect(baseUrl, psr18Client, headers) accepts a user-supplied PSR-18 client. The transitional constructor additionally allows direct TransportInterface injection.
- Rejects a variety of unsupported schema keywords, media types, missing/ambiguous operation IDs, unresolved local references and unsupported input dialects instead of knowingly emitting incomplete code.

## What is deliberately NOT guaranteed

- No YAML inputs; a JSON file is required.
- Not a general OpenAPI 3.1 / JSON Schema implementation; no allOf/oneOf/anyOf, discriminator, advanced formats, external references, broad additionalProperties or cookie/form/multipart support.
- **Authentication is not inferred or implemented from securitySchemes**. Specs declaring security requirements are rejected. Custom headers supplied by the application can support API-specific integrations manually, but this is **not** contract-driven security support.
- Not every HTTP method, response schema or content type is supported; the runnable emitter currently expects **one successful response**, generally an explicitly typed model or no content. Rich error-body typing and all response codes are not implemented.
- Required fields are checked during JSON **response hydration**, but required input fields are **not yet validated before HTTP transmission**. A noninitialized required property may be left out by jsonSerialize. Native PHP types constrain assigned values but do not establish semantic compliance with pattern, range, format or length constraints.
- Unknown incoming properties may be ignored; no configurable strict unknown-property policy. Input JSON property names must be representable by supported generated PHP identifiers.
- The generated SDK defaults to Guzzle 7; a PSR-18 client is optionally injectable. It is not yet wired to the separate experimental sdk-runtime package, which should not be assumed released.
- No tested live integration with Casafari or a production external API; only owned synthetic fixtures. No general latency, memory or security superiority claim versus OpenAPI Generator.
- Existing Casafari SDK middleware that uses by-reference arguments and bool callbacks **is not** an interchangeable instance of Guzzle HandlerStack middleware. This release does not change Casafari SDK.

## Roadmap (not promises about 0.1.0)

Contract-driven authentication, complete pre-send DTO validation, deeper schema support, more realistic public API fixtures, stable shared runtime extraction, and feature-equivalent performance/usability comparisons.

Please report supported-schema failures through GitHub Issues with a small **redacted** reproducible JSON specification (no keys or tokens).
