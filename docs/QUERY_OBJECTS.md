# Flat query objects (v0.11.0 / runtime v0.8.0)

OpenAPI query parameters with `style: deepObject` and explicit `explode: true`
serialize an object such as `{ "city": "Madrid", "active": false }` as
`filter%5Bcity%5D=Madrid&filter%5Bactive%5D=false`. Parameter/property names and
values are encoded once. The same behavior applies to GET and POST.

The supported schema is a flat object with non-null string, integer, number or
boolean values and either explicit `additionalProperties: false` or a supported
scalar schema for additional properties. Inline objects generate operation-derived
DTOs; named local object schemas reuse their DTO; typed maps reuse Map classes.
String/integer backed enums and existing scalar validation rules are supported.

Using the `query-objects-openapi31.json` fixture with namespace `Example\DeepQuery`:

```php
$get = new \Example\DeepQuery\GetParams\Items((object) [
    'filter' => (object) ['city-name' => 'Madrid', 'active' => false],
]);
$items = $client->resource(\Example\DeepQuery\Resources\Items::class);
$items->get($get);

$post = new \Example\DeepQuery\PostParams\Items((object) [
    'filter' => (object) ['city-name' => 'Madrid'],
    'queryBody' => (object) ['city-name' => 'Barcelona'],
    'body' => (object) ['filter' => 'json-body'],
]);
$items->post($post);
```

The POST sends query `body[city-name]=Barcelona&filter[city-name]=Madrid` (with
encoded brackets) and JSON body `{"filter":"json-body"}`. `queryBody` is the
collision-safe generated PHP field for the wire query name `body`; `body` remains
the actual JSON payload field. A GET query named body never becomes a JSON body.
Static facades delegate to the same instance executor.

This implements part of the agreed getParameters/postParameters review through
the current GetParams/PostParams API. Method and parameter location are distinct:
POST may carry query, headers and body simultaneously. There are no literal
getParameters/postParameters symbols in the current emitter. Form-urlencoded and
multipart bodies, and their equivalent mixed-parameter tests, remain pending.

DTO JSON names and directional readOnly/writeOnly rules apply. Construction and
sending validate required properties, enums and supported constraints. Mutations
are checked again; null/absent outer parameters and empty maps are omitted, while
empty scalar strings produce explicit empty values.

Nested objects/arrays, null properties, unconstrained additional properties,
composition schemas, other locations/styles, and omitted/false explode are outside
this bounded slice. Empty/bracketed names and collisions between expanded keys
and explicit query parameters are rejected before transport. This does not define
a non-standard encoding for nested deepObject values.
