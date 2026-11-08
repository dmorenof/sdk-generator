# URL-encoded HTTP forms (v0.12.0 / runtime v0.9.0)

OpenAPI 3.0/3.1 request bodies declared as application/x-www-form-urlencoded can
use an object with explicit `additionalProperties: false`, scalar properties and
primitive lists. Inline objects generate request DTOs; named schemas reuse their
DTOs. Local requestBody references and JSON/YAML inputs are supported.

Fields support `encoding` with omitted/default `style: form`, omitted/true explode
(repeated array keys) or false explode (CSV). Only false allowReserved is accepted.
Without JSON representations, the form is selected automatically. JSON retains
its existing precedence when both are declared; selecting a media type per call
is still pending.

Using `forms-openapi31.json` with namespace `Example\Forms`:

```php
$get = new \Example\Forms\GetParams\Items((object) ['name' => 'query name']);
$items = $client->resource(\Example\Forms\Resources\Items::class);
$items->get($get);

$post = new \Example\Forms\PostParams\Items((object) [
    'name' => 'query name',
    'queryBody' => 'query-body',
    'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2]],
]);
$items->post($post);
```

The POST URL query is `body=query-body&name=query%20name`. Its Content-Type is
application/x-www-form-urlencoded and its body is
`codes=a%2Cb,x+y&ids=1&ids=2&name=body+name`. Headers, query and body fields are
independent even when names coincide. A query named body uses the generated PHP
field queryBody, while body remains the actual payload. Body-only operations
accept their form DTO directly; static facades use the same runtime executor.

Form spaces become +, literal + becomes %2B, tilde becomes %7E and percent data
is encoded once. Backed enums, JSON names and readOnly/writeOnly direction apply.
Null fields and empty lists are omitted; empty strings produce name=. A missing
optional body sends no Content-Type or bytes; a present empty form sends empty
bytes with the form Content-Type. Required fields and supported scalar/item
constraints are checked during hydration and after mutations before HTTP.

This completes the URL-encoded subset of the agreed getParameters/postParameters
review through the actual GetParams/PostParams API. Tests exercise GET query,
POST query + headers + form, coincident names, empty/null values and mutations.
A cloud-local HTTP server verifies real request bytes; release verification
installs the generated SDK separately from the generator.

Nested objects/lists, nullable list items, open/map bodies, other styles,
contentType/headers in encoding, allowReserved true, multipart and files remain
unsupported and diagnosed. Swagger 2.0 formData remains pending. The multipart
portion of the getParameters/postParameters review therefore remains open.
