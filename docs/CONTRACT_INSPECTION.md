# Contract inspection (v0.6.0)

SDK Generator v0.4.0 adds an HTTP-contract audit before generation and executes literal
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
numbers, external URI resolution and complete structural/dialect validation remain
pending. JSON Pointer inspection does not expand the normalizer's reference support.
Literal Casafari path server overrides execute through stable
[runtime v0.5.0](https://github.com/dmorenof/sdk-runtime/releases/tag/v0.5.0).
The generator and generated packages require `^0.5.0`; installation does not
depend on a development branch.

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

Relative/template servers, variable expansion, explicit `servers: []` overrides and server URLs with
credentials, query or fragment remain explicitly unsupported. This work does not
choose dynamically between several declared servers. [Generator v0.4.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.4.0)
and runtime v0.5.0 contain the completed implementation. Future roadmap items
remain tracked separately from these published capabilities.

## Nested declaration audit (v0.4.1)

Inspection now applies the same HTTP audit to reusable OpenAPI parameters, request
bodies, responses, headers, links and Path Items, and to Swagger root parameters
and responses. It checks declarations even when no operation uses them. For
example, `allowReserved: true` on `components.parameters.Filter` now produces
`parameter_allowReserved` at `/components/parameters/Filter/allowReserved` and
blocks strict acceptance; non-strict normalization retains its compatibility API.

Encoding headers and header content schemas are inspected recursively. Callback
operations are audited and explicitly reported as unsupported incoming contracts.
Unsupported security types, HTTP schemes, API-key locations, nonempty scopes and
undefined security requirements receive precise source pointers. Declaration
issues have no operation ID unless they belong to an actual operation; a reused
Path Item's declared operation IDs remain available. Reference chains terminate
on cycles without fetching external documents.

Example payloads and link parameter/request-body expressions or literal values
are not traversed as schemas or references. A literal `$ref` in those payloads
never triggers a fetch or unresolved-reference error. General reference execution,
OAuth, typed response headers and callback generation remain separate roadmap
work. Unlocalized legacy normalization/emission errors still use the root pointer.


## Executable local HTTP references (v0.5.0)

Inspection and normalization now share the resolver for named local OpenAPI
parameters, request bodies and responses, plus Swagger reusable parameters and
responses. Diagnostics inside a resolved object point to its declaration while
retaining the using operation and alias chain. Distinct alias provenance is kept
and sorted deterministically. Missing references report the usage `$ref` pointer
and the attempted chain; strict normalization returns structured compatibility
errors before trying to parse an unresolved executable object.

The original JSON/YAML remains unchanged. Only HTTP Reference Object positions
are expanded; schemas, defaults, examples and literal link values are not
expanded. Inspection remains offline. A supported reference is not a promise
that every option inside its target can execute: for example a referenced
`content` parameter still fails strict acceptance until its serializer exists.

See [supported scope](SUPPORTED_SCOPE.md#local-http-reference-objects-050) for
sibling rules, the 128-alias limit and the reference categories still pending.


## Path Item and Header Object resolution (v0.6.0)

The normalizer, inspector and acceptance analyzer now resolve local Path Items
and local OpenAPI headers. Path Item fields carry their own source pointer and
reference chain, including disjoint siblings beside `$ref`. Overlaps are errors
rather than implicit overrides. This distinction also applies to descriptions:
Path Items do not use Reference Object annotation override rules.

The acceptance report includes `unresolvedPathCount` and counts operations after
Path Item resolution. Derived operation subsets materialize supported Path Items
in memory and carry original field origins into the audit, preserving actionable
diagnostics and the untouched full-source acceptance gate. No external references
are fetched and the report hash remains that of the original input.

Resolving response headers and encoding headers preserves metadata; strict mode
continues to reject unimplemented header exposure or media encoding. Alias cycles,
invalid targets and unsupported recursive HTTP-metadata graphs are explicit
failures. See [supported scope](SUPPORTED_SCOPE.md#path-item-and-header-references-060)
for exact target locations and dialect limits.
