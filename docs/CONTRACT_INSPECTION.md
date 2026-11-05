# Contract inspection (P0 development)

The first P0 increment adds an HTTP-contract audit before generation. It does not
implement the missing serializers, server routing, reference resolver or transports.

```sh
php bin/generate-sdk.php --inspect api.json
php bin/generate-sdk.php --dry-run api.yaml
php bin/generate-sdk.php --strict api.json build/sdk 'Example\Sdk' example/sdk
```

Inspection builds the canonical contract and attempts emission **in memory**. It
never creates an output directory, formats/writes generated files, fetches remote
references or changes the source. It reports `supported`, `partial` or `unsupported`,
the original source SHA-256, normalization/emission results and `strictlyAccepted`.
The inspection command exits 1 if the document cannot pass the strict gate.

Diagnostics identify the source file, escaped RFC 6901 JSON Pointer, stable code,
severity, operation when applicable and local reference chain. Known HTTP options
which the executable pipeline would ignore are reported explicitly: parameter
styles/flags, structured/content parameters, effective server overrides, response
headers/links and media encoding. Vendor extensions and unselected non-JSON media
alternatives are reported as warnings. Annotation fields are distinguished from
execution options. A schema/parser/emitter failure not yet localized by the audit
is reported at the document root (`""`) with the original error; this is not a claim
of precise source coordinates for every existing error.

`--strict` rejects compatibility errors before touching the destination. To preserve
the existing generation API during P0 development, generation without `--strict`
prints diagnostics and retains the previous executable subset. It must not be
interpreted as full contract acceptance. Programmatic users can call
`Diagnostics\DocumentInspector::inspect()` and check `strictlyAccepted` before
calling the emitter.

Current audit coverage is bounded. General schema/dialect validation, source line
numbers, external URI resolution and complete auditing of nested components remain
pending. JSON Pointer inspection does not expand the normalizer's reference support.
In particular, current Casafari path server overrides are detected; they are not
executed by this increment. The stable runtime dependency remains `^0.4.0`.

## Preserved canonical metadata

The normalizer now retains `ParameterSerialization` options (including explicit
false values), content-based parameter representations, effective operation
servers with root/path/operation provenance and their original preference order,
all request/response media definitions, encoding, examples, response headers/links
and document/operation/parameter annotations. Swagger collection formats are kept
outside the schema rather than misparsed as schema keywords.

`MediaTypeDefinition::schema` is normalized when supported. An unsupported,
unselected representation keeps its original schema in `unparsedSchema`; it is not
an executable canonical schema. Response headers/links retain their source objects
until their dedicated normalization and runtime work. Preserving these fields does
not implement them. In particular, vendor JSON media types are diagnosed when the
runtime's fixed `Content-Type`/`Accept: application/json` does not match any declared
representation. Server preference is no longer changed by alphabetical sorting.

The audit uses the normalizer's schema keyword inventory to localize unsupported
schema keywords. Literal `$ref` values inside example payloads are not interpreted
as OpenAPI references. Overridden path parameters and servers are audited according
to their effective operation value.
