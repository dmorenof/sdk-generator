# Local file references (v0.7.0)

Read a supported JSON/YAML API split into local schema and HTTP fragments:

```sh
php bin/generate-sdk.php --strict --resolve-local-references \
  tests/fixtures/multifile-accounts/api.json build/accounts \
  'Example\AccountsSdk' example/accounts

php bin/generate-sdk.php --inspect --resolve-local-references api.yaml
php bin/check-openapi-acceptance.php api.yaml --resolve-local-references
```

References resolve relative to the file containing them, including nested JSON
Pointer fragments and URI-encoded filenames. JSON and YAML may be mixed.
For a specification below a shared directory, explicitly allow that directory:

```sh
php bin/generate-sdk.php --inspect --resolve-local-references \
  --reference-root=/workspace/specifications /workspace/specifications/api/root.yaml
```

The default reference root is the canonical root file's directory. Only readable
regular `.json`, `.yaml` and `.yml` files within that root are opened. Canonical
paths and symlinks are checked; HTTP(S), URI streams, absolute references and URI
queries are rejected. Source count, byte, node, alias and depth limits are listed
in [supported scope](SUPPORTED_SCOPE.md#explicit-local-file-graphs-070).

Programmatic equivalents are `SpecificationNormalizer::fromFile($file, strict:
true, referenceRoot: $directory)`, `DocumentInspector::inspectFile()` and
`OpenApiAcceptanceAnalyzer::analyzeFile()`. `LocalFileBundler::bundle()` exposes the
materialized document and source hashes for callers needing a single snapshot;
inspect/analyze bundle methods reuse that snapshot. String APIs never load files.

## Representation and provenance

A root model such as `User: {$ref: './models/User.yaml'}` retains the public `User`
name. The schema file may contain a concrete supported schema or a pure `$ref`
alias chain. Schema references remain symbolic when recursive; cycles consisting
only of aliases are errors. Different imported schemas sharing a leaf name receive
distinct stable names; public root aliases disambiguate intended public identities.

Supported external HTTP fragments are parameters, request bodies, responses,
header metadata and OAS 3.1 Path Items. Existing sibling rules and strict HTTP
validation still apply after bundling. Root aliases with extra schema siblings,
multiple public root names for one target, unsupported schema resource scopes,
custom/mixed dialects and ambiguous contexts fail explicitly.

Multi-document discriminators currently require explicit mapping. HTTP objects
inside another complete OpenAPI/Swagger document are not imported: their server
and security namespaces require separate work. Compatible complete API documents
may supply supported schema fragments. External Path Items in Swagger/OAS 3.0,
network references, anchors, dynamic references and other unsupported categories
remain pending. Loading files does not widen the emitter's model/type subset.

Successful inspection and acceptance reports preserve the root hash and list all
canonical source hashes. `bundleSha256` fingerprints the materialized JSON; it is
not a claim of JSON canonicalization. Diagnostics identify original files and JSON
Pointers, with qualified imported reference chains. Some legacy parser/emitter
errors remain unlocalized root diagnostics.

The acceptance CLI's existing `--sha256` pins the original root before dependency
reads; the dependency manifest records all additional source snapshots. No files
are fetched over the network or written by inspection. SDK generation is completed
in memory before writing the normal SDK output. The generated Composer package
runs independently of these source documents and the generator itself.
