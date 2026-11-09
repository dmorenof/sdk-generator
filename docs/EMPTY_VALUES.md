# Empty query and form values (v0.28.0)

The generator accepts boolean allowEmptyValue for supported OpenAPI 3.0/3.1 and Swagger 2.0 query parameters, and for supported Swagger formData fields. It reuses the existing typed DTOs, constraints and query/form serializers. No runtime metadata, union, wrapper or transport code is needed; stable runtime ^0.21.0 already preserves these distinctions.

## Schema-authoritative policy

OpenAPI 3.0.4/3.1.2 explicitly leaves interactions with the schema to the implementation. This SDK keeps the schema authoritative: allowEmptyValue does not bypass required, minLength, enum, pattern, item rules or PHP types. It does not coerce a number, boolean, list or object into an empty string.

| Supplied parameter | Query / URL-encoded form |
| --- | --- |
| Optional uninitialized property | Omitted |
| Optional null | Omitted; not a literal null string |
| String `""` allowed by its schema | `name=` |
| False / zero | `name=false` / `name=0` |
| Empty supported list / object | Existing empty expansion, normally omitted |
| List containing one empty string | Existing single empty item, e.g. `name=` for form/multi or CSV |
| Required property missing or null | Validation error |
| Empty string violating minLength/enum/pattern/type | Validation error before HTTP |

An explicit empty string is data and is not interchangeable with null or an absent field. Empty strings allowed by the schema already worked with the flag omitted/false; those contracts keep identical output. The flag does not automatically create a parameter for a missing value or change an undefined style expansion. The SDK chooses the valid empty-value representation `name=`, without a new API for bare names.

Swagger formData uses the same policy through the existing URL-encoded/multipart support. The flag does not relax supported file/list schemas or change multipart bytes. Its explicit value must be boolean. For OpenAPI bodies use the field schema; allowEmptyValue is a parameter option, not an additional JSON Schema keyword or form encoding directive.

## Method and body separation

GetParams/PostParams keep query parameters independent from the body, including a query named body. An empty query/form field stays present. A nullable query/form field is omitted, while an initialized nullable field inside a JSON payload remains JSON null. Headers/cookies retain their existing rules; allowEmptyValue is rejected for those locations and path parameters in both normal and strict generation.

Tests cover JSON/YAML, OpenAPI 3.0/3.1, Swagger reusable parameters, scalar/list/flat-object acceptance, unchanged multipart generation, false/zero/empty/null/absent values, required constraints and mutations. Independently installed OpenAPI and Swagger SDKs perform GET, POST URL-encoded and POST JSON HTTP requests, asserting exact targets, headers and body content. The publishing workflow repeats those installed-package smokes.

See [OpenAPI 3.1.2 Parameter Object](https://spec.openapis.org/oas/v3.1.2.html#parameter-object), [OpenAPI 3.0.4](https://spec.openapis.org/oas/v3.0.4.html#parameter-object) and [Swagger 2.0](https://spec.openapis.org/oas/v2.0.html#parameter-object). This policy does not add schema unions, nested parameter serialization, content-based parameters or new collection styles.
