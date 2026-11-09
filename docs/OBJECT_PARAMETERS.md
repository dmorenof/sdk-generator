# Flat HTTP parameter objects — v0.24.0 / runtime v0.18.0

OpenAPI 3.0/3.1 flat objects now share typed DTO/Map validation across query
`form`/`deepObject` and path/header `simple`. Reuse referenced object models,
inline closed objects and maps whose additional values have a primitive schema.
Scalar fields retain aliases, enums, required/value rules and request direction.
Swagger 2.0 does not define these object parameters and remains diagnosed.

| Location/style | explode false | explode true |
| --- | --- | --- |
| Query form | `filter=code,safe,active,false` | `code=safe&active=false` |
| Path simple | `code,safe,active,false` | `code=safe,active=false` |
| Header simple | `code,safe,active,false` | `code=safe,active=false` |

Query form defaults to explode true. Path/header simple default to false.
The existing explicit deepObject/explode true behavior remains unchanged.
Keys and values in URI expansions use the existing native percent encoder,
separately from structural comma/equals delimiters. False and zero retain their
text. Brackets are ordinary encoded key data in form/simple; deepObject keeps
its existing restriction on bracket-containing keys.

```php
$identifier = new \Example\ObjectSdk\PathParams\Items\BySelector((object) [
    'selector' => (object) ['code' => 'a,b/ñ', 'active' => false, 'count' => 0],
]);
$params = new \Example\ObjectSdk\PostParams\Items\BySelector((object) [
    'X-Filter' => (object) ['code' => 'safe', 'active' => false, 'count' => 0],
    'filter' => (object) ['code' => 'safe', 'active' => false, 'count' => 0],
    'labels' => (object) ['tag' => 'x y'],
    'queryBody' => 'query body',
    'body' => (object) ['name' => 'body name'],
]);
$client->resource(\Example\ObjectSdk\Resources\Items::class)->postBySelector($identifier, $params);
```

The fixture [object-parameters-openapi31.json](../tests/fixtures/object-parameters-openapi31.json)
uses compact path/query objects, an exploded header, an exploded typed query map
and a JSON request body in the same operation. A single object path parameter
uses the existing PathParams DTO, keeping identifier/payload signatures intact.
Objects with identical wire names in different locations retain separate types.
Optional null query/header objects are omitted; an empty query object emits no
pairs and an empty simple object produces an empty segment/header field, subject
to required fields and supported constraints.

Expanded query properties cannot overwrite an existing scalar, list or expanded
field; collisions fail before HTTP. A property matching its own container name
is valid when it does not collide with another emitted field. Headers retain raw
text and reject commas, outer SP/HTAB, invalid control bytes and equals signs in
exploded keys. Equals signs in values remain data. No quoting or escape syntax is
invented for ambiguous header values.

DTO request serialization removes readOnly fields before expansion. Constructor
hydration and execution validate fields and typed maps, including nested DTO
mutations. The runtime uses the same field extractor, scalar converter and query
builder as earlier releases. There is no new response/parameter wrapper hierarchy,
hydrator, HTTP client or per-SDK expansion switch. Existing constructor positions,
query-only emitter type access and executeArguments signatures remain compatible.

Verification covers JSON/YAML, OpenAPI 3.0/3.1, both explode modes, model/Parameter
Object references and aliases, typed maps, directions, false/zero, numeric names,
collisions and mutations. Twenty-three independently installed SDKs pass maximum
PHPStan. A separate consumer repeats real cloud-local Guzzle GET/POST requests,
checking path/query/header/body bytes together; publication runs that consumer
independently from the generator using stable runtime ^0.18.0.

Only flat nonnullable primitive properties and nonempty keys are supported.
Path/header object schemas themselves must be nonnullable. Free-form untyped maps,
compositions, nested values, cookie objects, content-based parameters,
allowReserved true and allowEmptyValue true remain outside this delivery. Nesting
is not assumed to have an OpenAPI serialization meaning where none is specified.
This completes the flat form/simple object families, not the entire HTTP roadmap.

Label/matrix paths for the supported scalar/list/flat-object subset are available in [v0.25.0](PATH_STYLES.md).

Query allowReserved for supported shapes is added in [v0.27.0](RESERVED_QUERY.md), with explicit application pre-encoding and delimiter rules. Other locations and form-body encoding do not inherit this option.

Query allowEmptyValue is accepted in [v0.28.0](EMPTY_VALUES.md) without coercing typed objects into strings or changing empty-object expansion.
