# SDK Generator — contract-first, format-neutral PHP code generator

**Status:** historical architecture proposal; read README.md and docs/SUPPORTED_SCOPE.md for the actually implemented v0.1.0 subset. Corrects the earlier
Casafari-first/DTO-first sequence. No PHP source, dependencies or tags changed.

## Goal: one path from any supported API specification to a PHP SDK

This project's primary product is a **source-code generator**, analogous in
purpose to OpenAPI Generator, with our own small reusable architecture and
typed PHP output. A vendor integration such as Casafari is a regression
**fixture**, not the template from which design decisions are derived.

```text
                  Specification sources
       Swagger 2.0   OpenAPI 3.0   OpenAPI 3.1
             \           |            /
              Versioned format adapters
                         |
                 Canonical contract IR
          Models/types + operations + security
                         |
          Semantic validation + name resolution
                         |
               PHP source emitter
                         |
          Typed, self-contained SDK source
            DTOs | enums | typed collections
            endpoints | requests | responses
                         |
           small, shared sdk-runtime package
```

The generator is a **build-time dependency only**; generated SDKs do not call
the parser/normalizer during ordinary HTTP requests.

## Scope: specification-neutral but not protocol-magical

Swagger 2.0, OpenAPI 3.0 and 3.1 are different *dialects of HTTP API
contracts*. Other well-specified HTTP formats can be supported by additional
input adapters. A completely arbitrary proprietary API description cannot
be supported without defining and implementing an adapter. Non-HTTP protocols
are not silently considered supported.

## Canonical intermediate representation (IR)

Make the IR a **typed, explicit set of objects**, not an unstructured bag of
Swagger-specific arrays. Model at least:

- `ApiContract`: source/provenance, title, servers, operations, schemas,
  reusable components and security declarations.
- `Operation`: semantic operation identity, HTTP method, templated path,
  operation tags/grouping hints, parameters, request content and named/status-
  keyed responses, security requirements, errors.
- `Parameter`: wire name, location (path/query/header/cookie), required,
  schema, serialization style/explode and defaults when present.
- `RequestBody` and `Response`: status, media types, headers, body schemas.
- `Schema`/`Type`: primitive+format, object, array, map, reference, enums,
  required vs optional, nullable, read/write mode, constraints, composed
  schemas, discriminator, additionalProperties.
- `SecurityScheme`: API key, HTTP auth, OAuth2, OpenID Connect (where the
  input dialect supports it). Preserve the *operation requirement*, not only
  a global list of schemes.
- `SourceLocation`: enough source pointer for actionable diagnostics when
  input is invalid or a construct is unsupported.

This list is the intended **semantic surface**, not permission to claim every
construct is implemented. Start with a deliberately small tested subset and
extend it with cases.

Crucial property: **semantically equivalent Swagger 2.0 and OpenAPI 3.x
contracts normalize to equivalent IR** for the common supported subset.
Syntax differences are resolved *before* PHP generation. Do not put
`if swagger2`/`if openapi3` logic in DTO or endpoint emitters.

## Single generation route

1. Parse JSON/YAML with explicit dialect detection and informative errors.
2. Resolve references / paths / components while preserving source locations.
3. Normalize `definitions`/`parameters` and `components/schemas`,
   `basePath+schemes+host` versus `servers`, `body` parameters versus
   `requestBody`, MIME types, form/query/path headers and response forms.
4. Validate the IR: stable identifiers, collisions, reserved PHP keywords,
   ambiguous `operationId`, cycles, unsupported schema combinations.
5. Emit *native PHP types* and small classes deterministically:
   model DTOs, backed enums, typed collections, request and response types,
   endpoint facades, Composer metadata, runtime adapters/tests/docs as needed.
6. Validate generated output via PHP lint, static analysis, Composer, PHPUnit
   and smoke installation in a clean consumer.

## Separation from sdk-runtime

Generator knows **how a contract becomes PHP code**; runtime knows only how
the resulting SDK performs common work (serialization/hydration, transport
and error context). Runtime must never import OpenAPI parsers, Swagger
semantics, vendor DTOs, endpoint names or templates.

TypedArray stays a separate library; do not duplicate member validation.
Its package rename proposed in unmerged `dmorenof/TypedArray` PR #1 must
not be assumed published.

## Test-first milestones, in the right order

**M1 — Cross-format equivalence (first implementation PR):**
Create two **owned synthetic fixtures**, one Swagger 2.0 and one OpenAPI 3.0,
representing the same tiny API: one GET operation with a required path id,
optional query parameter, JSON response, scalar fields, nested object, enum,
typed list and required/optional distinction. Parse and normalize *both*.
Assert equality of canonical semantic IR and actionable errors for an
unrecognized input format. Add OpenAPI 3.1 equivalent fixture as its own
explicitly tested dialect. This is the architecture's first executable proof.

**M2 — Deterministic PHP generation:**
Emit a small SDK for that IR using one code-generation path. Golden tests
verify byte-for-byte stable output, exact wire field names, valid PHP and
typed endpoint signatures. No hand-written Casafari classes inside the
generator.

**M3 — Execute the generated SDK:**
Provide the minimal runtime support needed by output from M2. Test
serialization, hydration, endpoint method/path/parameter transmission and
errors using an injected fake HTTP transport.

**M4 — Real contract regression:**
Normalize the Casafari CRM Swagger 2.0 and another unrelated OpenAPI 3.x
contract. Check the documented CRM's 14 operations as one regression suite,
not a set of hand-coded parsing exceptions. Check generated package
installation independently.

**M5 — Measure and extend:**
Benchmark generation time, generated lines/size, Composer dependencies,
memory and request execution against a **pinned OpenAPI Generator** version,
on reproducible fixtures. Claim only measured improvements.

## Policy originates in the contract, not a universal DTO switch

For extra properties (`additionalProperties`), nullability, required vs
absent, scalar formats/coercion, array vs object, content type, aliases and
request/response validation, keep **schema semantics** in the IR and
generated adapters. When a specification is silent or ambiguous, document
the generator option and test its default. Do **not** ask users to choose a
single global “reject/ignore unknown JSON fields” policy as a prerequisite
for building the cross-format pipeline.

## Acceptance criteria

- One normalized type/operation representation consumed by one PHP emitter.
- Semantically equivalent inputs yield equivalent IR and generated output.
- No vendor-specific mapping in the generic adapters or emitter.
- Every claimed supported feature is covered by positive and negative
  fixtures; unsupported features fail visibly.
- Generated SDK needs no generator at runtime.
- All six required GitHub Actions checks pass, PR reviewed before merge.
- No tags or releases until actual end-to-end generation works.
