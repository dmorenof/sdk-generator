# Binary downloads — v0.20.0 / runtime v0.15.0

OpenAPI 3.0/3.1 JSON or YAML operations can return a native
`Psr\Http\Message\ResponseInterface` for a successful
`application/octet-stream` representation with an inline
`type: string, format: binary` schema. Local `components/responses` aliases work.
JSON keeps priority when both representations exist. There is no custom file
DTO, download factory or per-SDK transport implementation.

```php
$response = $client->resource(\Example\DownloadSdk\Resources\Download::class)->get(
    new \Example\DownloadSdk\GetParams\Download((object) [
        'body' => 'query value', 'X-Trace' => 'trace',
    ]),
);
// PSR headers retain all values, including Content-Disposition and Set-Cookie.
$status = $response->getStatusCode();
$disposition = $response->getHeaderLine('Content-Disposition');
$destination = \GuzzleHttp\Psr7\Utils::streamFor(fopen('/tmp/download.bin', 'wb'));
\GuzzleHttp\Psr7\Utils::copyToStream($response->getBody(), $destination);
$destination->close();
$response->getBody()->close();
```

The fixture [downloads-openapi31.json](../tests/fixtures/downloads-openapi31.json)
also demonstrates `PostParams\Download`: query `body` becomes `queryBody`, while
`body` carries the JSON Export DTO. Query/header/request validation, including
mutations, remains inherited from the runtime and independent of response bytes.
Static façades and instance resources expose the same native return type.

Multiple explicit successful statuses may share the binary codec, including
200/206 and a bodyless 204. Every declared successful response returns a PSR
response, including empty bytes; this does not implement heterogeneous JSON
response dispatch. Unexpected statuses still throw ApiException and hydrate
supported JSON error DTOs; raw errors remain available on its HttpResponse.

The default PSR-18 adapter returns the original successful response after
middleware, rewinding seekable bodies. Middleware must leave non-seekable cursors
usable. Legacy custom TransportInterface implementations keep their public
contract and adapt their already-buffered HttpResponse via Guzzle Response.
Existing buffered Psr18Transport::send() callers remain compatible.

## Memory verification and limits

Runtime and an independently installed generated SDK copy/hash 32 MiB downloads
under PHP memory_limit=16M. Real cloud-local HTTP with default Guzzle/cURL measures
an 8 MiB PHP peak for the runtime and 6 MiB for the independent SDK. The SDK repeats GET and POST, checking all bytes, status,
Content-Length, Content-Disposition and independent query/header/JSON fields.
CI and publication repeat the file-backed response test in a separate process.

This measures the tested PHP heap, not universal RSS or progressive network
consumption. Guzzle may first spool the response into a temporary disk-backed
stream. Injected clients, middleware, `(string) $response->getBody()` and
`getContents()` can allocate full bodies. JSON and error responses retain their
buffered path. No HTTP Range request policy, resumable-download abstraction,
filename-derived writes or automatic file lifecycle is added.

Unsupported: other MIME types, nullable binary schemas,
byte constraints/enums/compositions/primitive schema references, binary error
schemas, per-call Accept negotiation and declared response-header schema validation.
OpenAPI status ranges and heterogeneous JSON/binary successes are available in
[v0.31.0](RESPONSES.md#response-selection-block--v0310). Header values
remain accessible through PSR, but their schemas are still diagnosed unsupported.
These limits keep the general response roadmap open.


## Swagger 2.0 file responses (v0.21.0)

Swagger responses with `schema: {type: file}` and one explicit
`produces: [application/octet-stream]` normalize to the same binary response
contract. Use root produces or override it on an operation. Local `#/responses/`
references and aliases resolve before selection; each use checks its effective
produces. Request consumes remains independent, so a POST can submit a JSON DTO
and download bytes while GetParams/PostParams preserve query and header values.

```yaml
swagger: '2.0'
info: {title: Download API, version: '1'}
host: api.example.test
schemes: [https]
produces: [application/octet-stream]
paths:
  /download:
    get:
      operationId: getDownload
      responses:
        '200':
          description: Binary file
          schema: {type: file}
        '204': {description: Empty}
```

The adapter emits the same SDK as equivalent OpenAPI, using the unchanged stable
runtime ^0.15.0 and the same PSR/Guzzle download path. No runtime, transport or
file-wrapper implementation is added. Multiple explicit successful binary codes
and empty responses preserve their status. Unexpected statuses still throw
ApiException with the raw buffered response.

Swagger produces applies to every response of an operation. This bounded slice
therefore rejects typed JSON error schemas alongside octet-stream file responses;
it does not invent per-status media metadata. Multiple produces, other MIME types,
implicit media defaults, file errors, nullable/constrained files, string/binary
as a Swagger file substitute, and schemas referenced as primitive file models
remain unsupported. Schemas without file bodies keep their existing JSON rules.

The fixture [downloads-swagger2.json](../tests/fixtures/downloads-swagger2.json)
is installed and analysed independently. The existing 32 MiB download smoke runs
for both dialects in CI and publication. Four real Guzzle/cURL HTTP requests
alternating GET and POST under PHP memory_limit=16M measured an 8 MiB PHP peak,
with full response hashes, headers and request values checked. The memory and
disk/client/middleware limits above still apply.
