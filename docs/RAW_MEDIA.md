# Raw HTTP media — v0.36.0

OpenAPI 3.0/3.1 and supported Swagger body schemas may use text/plain with a
non-nullable string schema, or application/octet-stream with a bounded
string/binary schema. JSON keeps its existing selection priority; existing forms
keep their supported selection. Conflicting raw alternatives remain unsupported.

Text bodies and responses use their original bytes rather than JSON quoting or
decoding. String constraints, aliases, required body and optional omission are
preserved. An optional omitted text body differs from an empty string; explicit
null is rejected because raw media does not define JSON null.

Binary bodies accept strings or readable seekable PSR-7 streams. The same stream
passes through the existing runtime transport, which rewinds it for sending.
Guzzle/PSR performs HTTP handling; no new stream wrapper or transport is emitted.
Combined parameter DTOs preserve path/query/header/body separation. Legacy custom
transports still receive a complete string and have no bounded-memory guarantee.
The source and installed-package smoke upload 32 MiB under a PHP 16 MiB limit.

Success responses may choose text, JSON, binary PSR ResponseInterface or no body
by status. Their codec follows the existing exact/range/default selection and
Accept lists the declared codecs. Text constraints still apply to raw response
strings, including an empty response where allowed.

Stable dependency: sdk-runtime ^0.29.0. Binary request constraints, non-seekable
input, arbitrary raw MIME codecs and negotiation among multiple schemas at one
status/representation remain pending. This release does not implement XML parsing
or CSV model decoding; raw text/plain returns a PHP string.
