# Contract inspection (P0 development)

P0 development adds an HTTP-contract audit before generation and executes literal
absolute HTTP(S) operation servers. General serializers, relative/template server
resolution, references and additional transports remain pending.

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
Literal Casafari path server overrides are executed through the coordinated
runtime branch in [runtime PR #7](https://github.com/dmorenof/sdk-runtime/pull/7).
Development packages require `dev-feature/p0-contract-fidelity` until the
coordinated delivery; the published baseline remains runtime v0.4.0.

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


## Programmatic and acceptance gates

Programmatic normalization accepts `strict: true` and `sourceName:` in both
`SpecificationNormalizer::fromJson()` and `fromYaml()`. Unsupported HTTP semantics
raise `Diagnostics\CompatibilityException` with structured diagnostics. The
existing default remains compatibility normalization for callers that inspect or
transform the preserved contract themselves.

`check-openapi-acceptance.php` always uses the strict compatibility gate. Its report
adds `compatibility` and a `compatibility_failed` operation/summary status. Merely
emitting PHP is insufficient for acceptance. Isolated operations retain Path Item
metadata and the explicit/implicit discriminator subtype closure; isolation must
not hide unsupported servers or remove valid subtype definitions.

For the pinned Casafari fixture, all 46 operations now pass strict acceptance.
Seven HTTP mock tests invoke the actual affected generated methods and assert their
exact platform.casafari.com URL, encoded identifiers/query values and authentication
header. The tests use synthetic PlatformApiKey credentials and mock 401 responses;
no live/authenticated Casafari calls are made. Problem JSON error representations
remain compatible with success-content negotiation.

## Executing operation servers

The generator selects the first effective server with precedence **operation >
Path Item > root**. It emits `serverUrl` only for an override differing from the
root default. Root operations and equivalent root declarations keep using the
configured client `baseUrl`. The shared runtime preserves the selected server's
path prefix before adding the operation path and encoded parameters.

Environment substitutions are per connection and are copied to shared resources:

```php
$client = Client::connect('https://root.staging.test', serverOverrides: [
    'https://platform.casafari.com' => 'https://platform.staging.test',
]);
```

Static facades support the same mapping through `Configuration::$serverOverrides`.
`Client::configure()` takes a snapshot, so later configuration edits do not change
an existing connection. URL keys ignore trailing slashes.

Relative/template servers, variable expansion, empty overrides and server URLs with
credentials, query or fragment remain explicitly unsupported. This work does not
choose dynamically between several declared servers. Generator PR #20 and runtime
PR #7 remain a single coordinated development delivery, without an independent
release or merge.
