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
