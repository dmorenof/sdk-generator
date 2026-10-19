# First PHP emitter milestone

The `PhpContractEmitter` consumes the **format-independent** `ApiContract`
produced by `SpecificationNormalizer` and returns a deterministic
`array<string, string>` of relative generated files. It does not care which
supported Swagger/OpenAPI dialect produced the contract.

It currently emits:

- Native PHP 8.3 typed DTO declarations with `JsonSerializable` support.
- Backed PHP enum cases for enum properties.
- Typed collection declarations using the existing published
  `typedarray/typedarray` Composer package.
- An endpoint **interface** with native typed method signatures.
- An operation route/parameter/response catalog and Composer metadata.

**Scope boundary:** these are typed *contracts*, not yet executable HTTP
endpoint clients. The next slice adds JSON hydration, request encoding and
an injected transport, and evaluates which helpers belong in
`sdk-runtime`. Do not claim usable HTTP clients until that test passes.

Unsupported constructs currently fail with an exception; the emitter does
not silently erase required schema information. A future emitter may map
invalid wire identifiers to PHP-safe names with explicit serialization
metadata, but it must never change JSON keys by accident.

## Test fixtures and commands

The owned JSON fixtures in `tests/fixtures/` describe the same two
operations and three models in Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1.
Their generated output is byte-identical across all three formats.

```sh
composer install
bash ci/run-tests.sh --coverage
```

The test suite checks deterministic files, signatures and routes, strict
PHP lint of **every generated class**, backing values, Composer metadata,
serialization of synthetic models and explicit negative cases.

No generated package is published or tagged by this proof-of-concept.
