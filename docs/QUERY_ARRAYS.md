# Query arrays (generator v0.11.0 / runtime v0.8.0)

Query parameters with non-null primitive items generate native PHP `array`
properties, documented as `list<string>`, `list<int>`, `list<bool>` or
`list<int|float>`. No collection wrapper is required.

| Dialect | Options | Values `[1, 2]` |
| --- | --- | --- |
| OpenAPI 3.x | omitted, or `style: form`, `explode: true` | `ids=1&ids=2` |
| OpenAPI 3.x | `style: form`, `explode: false` | `ids=1,2` |
| Swagger 2.0 | `collectionFormat: multi` | `ids=1&ids=2` |
| Swagger 2.0 | omitted, or `collectionFormat: csv` | `ids=1,2` |
| OpenAPI 3.x | `style: spaceDelimited`, omitted/false `explode` | `ids=1%202` |
| OpenAPI 3.x | `style: pipeDelimited`, omitted/false `explode` | `ids=1%7C2` |
| Swagger 2.0 | `collectionFormat: ssv` | `ids=1%202` |
| Swagger 2.0 | `collectionFormat: tsv` | `ids=1%092` |
| Swagger 2.0 | `collectionFormat: pipes` | `ids=1%7C2` |

For the included `query-arrays-openapi31.json` fixture, generated with namespace
`Example\QueryLists`, callers can pass:

```php
$params = new \Example\QueryLists\GetParams\Items((object) [
    'ids' => [1, 2],
    'codes' => ['a,b', 'x y'],
]);
$client->resource(\Example\QueryLists\Resources\Items::class)->get($params);
```

The query is `codes=a%2Cb,x%20y&ids=1&ids=2`. Each item and parameter name is
RFC3986-encoded individually; CSV separators remain literal commas. Boolean
items use `true`/`false`. API-key query credentials retain scalar precedence.

Construction and sending validate list shape, item types and the supported
item enum, pattern, length and range rules, plus `minItems`/`maxItems`. Mutating a
public property does not bypass these checks. String/integer item enums are
supported. Null/absent parameters and empty lists are omitted; empty string
items produce `name=`. A required list with `minItems: 1` rejects an empty value.
A nullable outer array may be represented by the supported nullable schema forms;
items themselves must be non-null.

Other object styles (see [flat deepObject support](QUERY_OBJECTS.md)), nested arrays, nullable items, array-valued enums, other parameter
locations, content-based parameters, reserved/empty-value flags and other styles
remain unsupported and diagnosed. This release does not provide general HTTP
parameter or JSON Schema validation. Generated SDKs require stable runtime
`^0.8.0` and run independently of the generator.

## Delimiter limits

Space, tab and pipe separators are percent-encoded because they are not legal
literal RFC3986 query characters. These formats reject items containing their
own delimiter (space, tab or pipe respectively) before sending, including after
mutation: once percent-decoded, that data would be indistinguishable from a
separator. Literal percent sequences remain data (`%7C` becomes `%257C`). Other
characters retain normal item encoding. Repeated-key and CSV behavior is unchanged.

OpenAPI marks `explode: true` undefined for space/pipe-delimited arrays; strict
inspection rejects those combinations. This release covers primitive query lists
only, including local reusable Parameter Objects. Objects and other locations are
still pending. See [OpenAPI 3.1.2 style examples](https://spec.openapis.org/oas/v3.1.2.html#style-examples)
for the serialization table and URI-encoding rules.
