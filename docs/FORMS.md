# HTTP forms and multipart (v0.17.0 / runtime v0.12.0)

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
contentType/headers in URL-encoded encoding and allowReserved true remain
unsupported and diagnosed. Swagger 2.0 URL-encoded formData and the bounded OpenAPI
multipart subset are described below.

## Swagger 2.0 formData (v0.13.0)

Swagger scalar and primitive-list formData Parameter Objects normalize to the same
form body contract. Declare exactly one `consumes: [application/x-www-form-urlencoded]`
at root or operation; operation consumes replaces root consumes. Parameters may be
inline or reusable local aliases. Operation parameters replace Path Item parameters
with the same in/name identity. Swagger body and formData parameters cannot coexist.

| Array declaration | Body for `[1, 2]` |
| --- | --- |
| OpenAPI form, omitted/true explode | `ids=1&ids=2` |
| OpenAPI form, false explode | `ids=1,2` |
| Swagger omitted collectionFormat or explicit csv | `ids=1,2` |
| Swagger collectionFormat multi | `ids=1&ids=2` |

The generator emits explicit metadata for Swagger's different CSV default.
Equivalent OpenAPI and Swagger fixtures produce byte-identical SDKs. This adapter introduced no new HTTP
serializer in v0.13.0; current generated SDKs require stable runtime ^0.12.0.

With `forms-swagger2.json` and namespace `Example\SwaggerForms`:

```php
$params = new \Example\SwaggerForms\PostParams\Items((object) [
    'name' => 'query name',
    'queryBody' => 'query-body',
    'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'codes' => ['a,b', 'x y'], 'ids' => [1, 2]],
]);
$client->resource(\Example\SwaggerForms\Resources\Items::class)->post($params);
```

Query and headers stay separate. The form body is
`codes=a%2Cb,x+y&ids=1&ids=2&name=body+name`. The typed body lives under Requests;
body-only operations accept that DTO directly. The aggregate body is required
when any form field is required; all-optional forms can omit the body entirely.
Scalar constraints, string/integer enums, descriptions/default metadata and the
supported nullable extensions are retained. Defaults are not materialized.

Files in URL-encoded bodies, objects/nested lists, nullable list items, ssv/tsv/pipes form
arrays, scalar collectionFormat, malformed flags/options and multiple consumes
representations remain unsupported and diagnosed. Query ssv/tsv/pipes support is
unchanged. Multipart support for both dialects is described below.


## OpenAPI multipart (v0.15.0 / runtime v0.11.1)

A multipart/form-data body accepts the same explicitly closed, flat object shape
as forms. Text scalars and primitive non-null list items become text/plain parts;
lists repeat the field name. Nullable fields are omitted when null, empty lists
produce no parts and empty strings produce empty parts. Binary fields declare
`type: string, format: binary` and accept raw bytes in their native PHP string
property, or a PSR UploadedFileInterface since v0.17.0 (see below). No file DTO or upload wrapper is added. Guzzle PSR-7 MultipartStream
constructs boundaries, headers and MIME bytes in the shared runtime.

For the shipped multipart-openapi31.json fixture, namespace Example\MultipartSdk:

```php
$params = new \Example\MultipartSdk\PostParams\Items((object) [
    'name' => 'query name',
    'queryBody' => 'query-body',
    'X-Trace' => 'trace',
    'body' => (object) ['name' => 'body name', 'active' => false, 'ids' => [1, 2]],
]);
$bytes = file_get_contents('/path/to/photo.png');
if ($bytes === false) {
    throw new \RuntimeException('Cannot read upload.');
}
$params->body->file = $bytes;
$client->resource(\Example\MultipartSdk\Resources\Items::class)->post($params);
```

The URL query is body=query-body&name=query%20name. The file part has wire name
file, filename file.bin and Content-Type image/png (from encoding.file.contentType).
Without an explicit contentType, string files use application/octet-stream; PSR
uploads can supply their MIME type. Strings use basename(wire field) + `.bin`
filenames; PSR uploads can supply their filename. MIME type remains independent
of the filename extension. Explicit contentType remains a contract restriction.
A single concrete MIME type without parameters or alternatives is accepted.
Text contentType may only be text/plain. Encoding style must be omitted/form,
explode omitted/true and allowReserved omitted/false. Headers and other encoding
options fail explicitly. Field names must be nonempty printable ASCII without
quotes/backslashes; path-like names ending in an empty/dot basename are rejected.

GetParams stays query-only. PostParams keeps query, headers and the actual typed
body separate, including coincident names and a query named body. Body-only
operations reuse the named DTO. Inline objects and local schema/requestBody
references work in JSON/YAML, OpenAPI 3.0/3.1. Required fields, enums, request
direction, constraints and mutations follow the existing DTO validation. A missing
optional body sends no Content-Type or bytes; a present empty body sends a closing
MIME boundary with the matching multipart Content-Type. JSON representations take
priority, then URL-encoded forms, then multipart; per-call negotiation is pending.

**Memory limit:** the existing public transport interface accepts a complete string.
Multipart bodies are buffered; there is no streaming or bounded-memory guarantee
for large files. Read only files whose size your application can hold in memory.
Readable, seekable streams are accepted through PSR UploadedFileInterface since
v0.17.0, with configurable filenames. Direct stream/resource properties, file lists,
nested objects, open maps, null list items, CSV multipart arrays and per-part
headers remain pending. Binary enum/pattern/minLength/maxLength constraints are rejected
because their current Unicode string semantics do not validate arbitrary bytes.
Binary DTOs are sent through multipart; JSON encoding arbitrary byte strings can
fail when they are not UTF-8.

Verification covers PSR-18 wire bytes and boundary agreement, a real cloud-local
HTTP POST parsed independently with Python's MIME parser, sixteen installed SDKs
at PHPStan maximum, mutations and an independent installed multipart SDK in the
immutable release workflow. The general HTTP/forms roadmap remains partial.


## Swagger 2.0 multipart (v0.16.0)

Declare `consumes: [multipart/form-data]` with formData fields. Scalar fields and
primitive lists use the same shared multipart serializer as OpenAPI. File
parameters declare `type: file` and become native PHP string properties with
binary format metadata; no upload wrapper is required. List fields must declare
`collectionFormat: multi`. Swagger's omitted collectionFormat means CSV, which
is rejected for multipart until its semantics are implemented. URL-encoded
forms retain CSV by default and explicit csv/multi support.

```yaml
consumes: [multipart/form-data]
parameters:
  - name: file
    in: formData
    type: file
    required: true
  - name: ids
    in: formData
    type: array
    items: { type: integer }
    collectionFormat: multi
```

The file's content is assigned as bytes or a PSR uploaded file to the body DTO,
exactly as in the OpenAPI example. String files use application/octet-stream and
basename(field) + `.bin` filenames. Swagger does not declare per-property
encoding; PSR uploads can supply their filename and MIME type since v0.17.0.

One consumes representation is required. Operation consumes overrides root
consumes; an operation declaring null/empty consumes is rejected. A GET with no
form fields stays query-only even when it inherits multipart consumes. Local
parameter aliases and operation-over-path overrides apply before body assembly;
a required file makes the aggregate body required. Reusable file declarations
are allowed even when only some operations select multipart, but each usage must
match its media type. Files in URL-encoded bodies and body/formData mixtures
are rejected with source diagnostics.

Required, optional, empty and nullable-extension files use the existing DTO
policies: required absence fails, empty strings send empty file parts, optional
absence/null is omitted, and mutations are checked before HTTP. File enum,
pattern/Unicode length/numeric/item constraints, default, explicit format and
collectionFormat are rejected rather than reinterpreted as file-size rules.
File lists and nested structures remain unsupported. The same buffering and
advanced-encoding limits documented above apply. Equivalent Swagger/OpenAPI
fixtures produce byte-identical SDK source and share the independent installed
SDK smoke in release verification.


## PSR uploaded files (v0.17.0 / runtime v0.12.0)

Binary DTO properties now declare `string|\Psr\Http\Message\UploadedFileInterface`,
with `|null` for nullable fields. Existing string assignments keep working. Callers
reading the property should account for both types. This explicit input union
is separate from pending general native OpenAPI oneOf/anyOf union support.

Use the already installed Guzzle PSR-7 implementation, or any compatible PSR
implementation; no generator-specific file class or factory is required:

```php
$params->body->file = new \GuzzleHttp\Psr7\UploadedFile(
    '/path/to/photo.png',
    null, // size may be unknown
    UPLOAD_ERR_OK,
    'original.png',
    'image/png',
);
$client->resource(\Example\MultipartSdk\Resources\Items::class)->post($params);
```

Guzzle accepts a local path, PHP resource or PSR stream in UploadedFile's first
argument. The body property itself accepts the PSR uploaded file, rather than a
bare stream/resource. Its readable, seekable stream is passed to Guzzle's existing
MultipartStream; Guzzle rewinds it for complete repeat sends. Changing the DTO's
file before the next request changes the transmitted content and metadata.

The upload's client filename takes precedence over the static field-derived
fallback; null keeps the fallback. Guzzle sends its basename. Names must be
printable ASCII without quotes/backslashes; empty names and filename `0` are
rejected. The latter avoids an ambiguity in older supported PSR-7 versions.

If OpenAPI declares encoding.contentType, the uploaded MIME must match it
(case-insensitively), or be absent. A mismatch fails before HTTP. Without an
explicit type, the client MIME is accepted, with application/octet-stream as the
fallback. This also permits MIME selection for Swagger uploads. Only a single
concrete MIME type without parameters is supported; no MIME sniffing or content
inspection is implied. Text part rules do not change.

Failed uploads, moved/unreadable streams, non-seekable streams and unsafe metadata
fail before transport. JSON and URL-encoded serialization reject uploaded objects
explicitly; they cannot silently turn into empty JSON objects. Request-direction
filtering, wire names, required/nullable fields and mutation validation still apply.

**Buffering remains:** accepting a file path or input stream does not mean the
HTTP transport streams the upload. The existing public transport receives the
complete body string. Streaming HTTP and bounded-memory uploads, file lists,
nested file data and advanced part encoding remain pending. Both OpenAPI and
Swagger installed-SDK publication smokes exercise string and PSR uploads.
