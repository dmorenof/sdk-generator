# SDK Generator — architecture proposal (review required)

Status: **PROPOSED, NOT ACCEPTED**. Documentation only; no PHP implementation,
new dependency, package tag or release.

## Codebase evidence

- `dmorenof/casafari-sdk` ships `src/Core/v1.json`, a Swagger 2.0
  snapshot of the **Casafari CRM API** (not the separate REST/Platform API).
- Its code contains concrete endpoint classes (`AgencyApi`, `PropertyApi`, etc.),
  DTO, request, response, backed enum and typed-collection classes.
- `Tests/SwaggerContractTest.php` maps **14** CRM operations to typed
  endpoint entrypoints and checks response model properties.
- `sdk-runtime` and `sdk-generator` contain scaffolds, not yet functional APIs.

## Strict separation of responsibilities

```text
Swagger 2.0 / OpenAPI 3.x
             |
         Parse/validate
             |
         Normalized IR
             |
   Validate names/types/refs
             |
        Deterministic emit
          /          \
generated SDK    golden fixtures
          |
  Runtime at execution time
```

Generator **execution** should not require loading the generated runtime.
Emitted packages may depend on SDK Runtime plus TypedArray if that architecture
is approved. Do not mix code generation into runtime classes.

## Initial intermediate representation (IR) concepts

- API: title, server/base URLs, operations, schemas, common security schemes.
- Operation: stable identifier, HTTP method, path, parameter locations
  (path/query/header/body), request/response body media types, response status.
- Model: named schema, properties, required/optional, nullable, defaults,
  references, array item type, enum values.
- Type: scalar / object reference / collection / backed enum; unsupported
  constructs must give a meaningful diagnostic rather than be silently dropped.

Do **not** immediately create generic frameworks for all OpenAPI features.
Model the minimum proven by concrete examples and extend by contract tests.

## First generator vertical slice (after Runtime semantics approval)

Input: a **small, owned synthetic Swagger 2.0 fixture** with one endpoint,
nested DTO, backed enum and a collection. The use of an owned fixture avoids
tying the generator implementation to one vendor's contract.

Output: reproducible PHP source for:
- One API operation with native parameter and return types.
- DTO model classes with exact JSON property names.
- A backed enum.
- A typed collection using the agreed TypedArray package.
- Composer autoload metadata and a smoke test.

Tests:
1. Parse the fixture and normalize it consistently into the IR.
2. Render exactly the same files on repeated runs (byte-for-byte), including
   deterministic ordering and stable names.
3. `php -l` every generated PHP file and validate generated Composer metadata.
4. Install the generated package into a **clean consumer fixture** (when
   dependency versions are published) and test hydration/serialization.
5. Ensure unsupported schemas produce explicit diagnostics.
6. Introduce the actual CRM Swagger as an **integration fixture** later and
   check all 14 operations against the existing Casafari SDK behavior, without
   copying Casafari-specific models into the runtime.
7. Benchmark generation time/output size/generated runtime speed and memory
   against a pinned OpenAPI Generator version and a fixed fixture. No
   speculative performance claims.

## Decisions and constraints

- Use the `sdk-runtime` architecture proposal in parallel. In particular,
  null/absent behavior, unknown properties, coercion and empty collections must
  be decided once, then reflected in generator output and tests.
- A package-name change is staged in the *unmerged* TypedArray PR #1.
  Do not put unreleased `php-typed-array/php-typed-array` into emitted Composer
  dependencies until it is available and an explicit migration is approved.
- Preserve transport independence of the IR. Authentication and HTTP error
  semantics belong to generated API configuration, not general JSON models.
- Swagger 2.0 is the first end-to-end target because it is the verified
  reference. Add OpenAPI 3.x with separate fixtures, rather than claiming
  support until tests prove it.
- No generator release before a representative generation flow, compiler
  checks, independent consumer tests and six-job CI are green.

## Review gate

Approve responsibilities, minimal IR and first fixture before a functional
implementation PR. Do not merge this design as an implicit authorization to
change the TypedArray package or its mutability semantics.
