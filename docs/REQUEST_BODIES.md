# JSON request bodies — v0.35.0

JSON bodies may be DTOs, maps, typed collections, native arrays, primitive values
or null. Local named array schemas and their alias chains reuse the same array
or collection representation used by inline schemas. Scalar aliases keep native
PHP signatures. Existing forms and multipart retain their supported DTO shapes.

Body presence is independent of its value. For an optional direct body, calling
`post()` omits the body and Content-Type; `post(null)` sends JSON `null` only if
the schema permits it. In a combined PostParams/GetParams DTO, an uninitialized
`body` property is absent and an initialized null is explicitly present. Required
body, nullability, constraints and nested mutations are checked before transport.
Static facades retain the same distinction.

A query parameter named `body` stays separate from the actual request body.
Path/query/header/cookie parameters reuse existing mapping. Request-direction
serialization filters readOnly fields and retains JSON names through the existing
Serializer, DTO, Map and TypedArray runtime classes. Declared JSON and +json media
types are retained; all HTTP execution still uses the shared Guzzle/PSR transport.

The runtime adds one protected native-body entry point while retaining the
existing executeOperation/executeArguments signatures. New requestBody operation
metadata is optional and appended to the constructor; previously emitted SDKs
continue to use their established DTO executor contract.

Stable dependency: `dmorenof/sdk-runtime ^0.28.0`. This does not add text/binary
request bodies, negotiation among incompatible request representations, arbitrary
JSON Schema composition or general nullable collection-item support. Those limits
remain in pendientes.md.
