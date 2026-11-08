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

Unsupported: Swagger file responses, other MIME types, nullable binary schemas,
byte constraints/enums/compositions/primitive schema references, binary error
schemas, heterogeneous binary/JSON successes, per-call Accept negotiation,
status ranges, and declared response-header schema validation. Header values
remain accessible through PSR, but their schemas are still diagnosed unsupported.
These limits keep the general response roadmap open.
