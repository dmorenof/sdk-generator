# Simple path/header primitive lists — v0.23.0 / runtime v0.17.0

OpenAPI 3.0/3.1 arrays of nonnullable primitive items can use `style: simple` in
path and header parameters, including the location default. For arrays, explode
true and false have the same comma-separated expansion. Swagger 2.0 arrays in
these locations support collectionFormat csv, explicit or default. References to
local Parameter Objects and aliases work; schemas themselves remain inline.

```yaml
parameters:
  - name: ids
    in: path
    required: true
    schema:
      type: array
      minItems: 1
      items: {type: string}
  - name: X-Codes
    in: header
    schema:
      type: array
      items: {type: string}
```

A path list `['a,b', 'c/d', '%2F', 'ñ']` expands to
`a%2Cb,c%2Fd,%252F,%C3%B1`. The runtime uses native rawurlencode for each item
before joining, preserving delimiter commas separately from encoded data.
Header `['alpha', 'x y']` becomes `alpha,x y` without percent encoding. Header
items containing commas, invalid HTTP control bytes or outer SP/HTAB fail before
transport; there is no invented quoting/escaping convention or silent trimming.
Finite numbers and booleans use the existing scalar conversion.

## Public API and validation

Even a single path list uses a generated `PathParams\Items\ByIds` DTO with a
native array and list PHPDoc. Scalar single-path parameters keep their scalar
signature. Composite paths use the existing PathParams DTO. Both resource methods
and façades retain at most two arguments: identifier and payload.

```php
$path = new \Example\SimpleSdk\PathParams\Items\ByIds((object) [
    'ids' => ['a,b', 'c/d', '%2F', 'ñ'],
]);
$params = new \Example\SimpleSdk\PostParams\Items\ByIds((object) [
    'X-Codes' => ['alpha', 'x y'],
    'ids' => [2, 3],                  // Query ids remain independent of path ids.
    'queryBody' => 'query body',      // Query named body remains query.
    'body' => (object) ['name' => 'body name'],
]);
$client->resource(\Example\SimpleSdk\Resources\Items::class)->postByIds($path, $params);
```

Native arrays reuse the existing parameter resolver and declarative item rules,
including supported bounds, enums, patterns and numeric constraints. Constructor
hydration and every execution validate DTOs again, including mutations to path,
header, query and body fields. Required/nonnullable path declarations must match
the URL placeholders; missing, unused, optional or nullable path declarations
fail generation. Missing/null runtime path inputs fail before HTTP.

Optional header fields absent or null are omitted. Explicit empty arrays expand
to an empty path segment or empty header field, subject to minItems. Empty item
strings retain empty CSV tokens if schema constraints permit them. The wire
format is not a lossless inverse for every empty-list/empty-item combination.
No query, request-body or response behavior is inferred from parameter location.

## Reuse, verification and limits

Operation declares pathSerialization/headerSerialization maps only for lists.
ApiClient inherits DTO argument extraction and calls QuerySerializer::simpleList,
which reuses the existing scalar-list validator and native encoding. Guzzle/PSR
still owns request construction and transport. No new parameter wrapper hierarchy,
URI-template interpreter or HTTP serializer class is added. executeArguments keeps
its native signature for compatibility with older generated overrides; new
constructor options are appended. Lists without metadata and unknown formats fail.

Equivalent Swagger/OpenAPI JSON/YAML emit identical SDKs. Twenty-two independently
installed SDKs pass PHPStan maximum, and publication reuses the independent simple
parameter consumer for both dialects. Eight real HTTP requests alternate GET/POST
across both SDKs, checking encoded path/query, raw header values and JSON DTO bytes;
mutations fail before transport. The fixture is
[simple-parameters-openapi31.json](../tests/fixtures/simple-parameters-openapi31.json).

Objects, nested/null list items, nullable list schemas, label/matrix, cookies,
header/path ssv/tsv/pipes/multi, content-based parameters, arbitrary reserved/empty
flags and primitive schema references remain pending. Reject unsupported list-level
enum constraints, including empty enum, rather than dropping their semantics.
This is a bounded simple-list feature, not a claim that all parameter styles or
all OpenAPI HTTP behavior are implemented.
