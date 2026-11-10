# JSON Schema rules — v0.33.0 / runtime v0.26.1

The generator emits existing declarative validation metadata and native PHP types.
The shared runtime reuses maintained opis/json-schema ^2.6.1 for numeric bounds,
multipleOf, JSON equality, uniqueItems, property counts and const. Guzzle remains
responsible for HTTP; no new DTO/transport/result wrapper is generated.

## Dialects and supported rules

- Swagger 2.0 and OpenAPI 3.0 exclusiveMinimum/exclusiveMaximum are booleans tied
  to minimum/maximum. True becomes a numeric exclusive bound; false retains the
  inclusive limit. A true flag without its limit fails normalization.
- OpenAPI 3.1 exclusive bounds are numbers. Booleans are rejected in that dialect.
- multipleOf must be a finite positive number. Exclusive bounds must be finite;
  property/item counts must be nonnegative integers. Inapplicable types fail.
- minProperties/maxProperties apply to DTOs and typed maps. Directional validation
  counts the fields that will be serialized, including present null values and
  excluding fields filtered by readOnly/writeOnly. Supported object allOf branches
  intersect their count bounds against the same whole object.
- uniqueItems compares JSON values, including numeric equality (1 equals 1.0),
  object key-order independence and distinctions among false, zero and strings.
- OpenAPI 3.1 const supports scalar/null literals on supported scalar/mixed types.
  It validates a present null as well as nonnull data; absent optional values
  remain absent. Complex const literals and other dialects are explicitly rejected.

Native string/integer enums retain only cases satisfying their numeric rules.
An enum with no valid cases is diagnosed rather than producing a misleading API.
Existing PHP numeric representations and precision limits still apply.

## Validation boundaries

DTO/model, map and collection metadata share the same runtime rule evaluation.
Constructor/execution/serialization checks retain their existing responsibilities;
collections may be mutated and are checked again at the owning JSON boundary.
Inherited ArrayObject mutations such as exchangeArray cannot bypass item validation
at those boundaries. Existing shared collection names are retained; property size
rules remain with their field/response context, while uniqueness can specialize
the collection type.

Root responses now carry responseValidation for one type, or optional rules in
the existing per-status responseTypes map. Scalars and arrays are therefore checked
after hydration as well as object DTOs. A selected invalid body does not fall back
to another status/schema. Older generated metadata and executor signatures remain
compatible; the new operation constructor argument is appended.

Unicode length validation already counted code points through UTF-8 PCRE. Tests
verify astral characters, newlines and combining sequences: a combined grapheme
can have several code points, while a multibyte character can have only one.

## Verification and limits

JSON/YAML, OpenAPI 3.0/3.1 and Swagger boolean-bound normalization, map/model counts,
scalar responses, constrained enums and mutations have regression coverage. The
35th independently installed SDK passes PHPStan maximum and actual HTTP requests
cover valid/invalid decimal responses, unique arrays, request/query constraints,
property counts, readOnly fields and Unicode. Publication runs the same consumer.

This block does not add complex const, boolean schemas, arbitrary type unions,
contains, conditional/dependent schemas, pattern/unevaluated properties or general
composition siblings. It does not turn annotation-only formats into assertions.
Only fixed generated keyword rules reach the validator; no API schema references
are fetched remotely at runtime.
