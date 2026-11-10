# Declared HTTP error payloads — v0.38.0

ApiException continues to expose the raw HTTP response/status and its existing
DTO-only `error` property. Its new readonly `payload` exposes declared scalar,
null, array/collection, map, enum, composition or DTO errors. DTO errors share
the same object in both properties; existing exception consumers stay compatible.

The generator emits errorResponses metadata using existing type descriptors.
The runtime reuses JsonHydrator, DTO/Map/TypedArray/composition validation and raw
text/binary handling. Exact status overrides a range, which overrides default.
A descriptor with null type declares a bodyless error and suppresses fallback
decoding. JSON null remains a distinct schema/value. Declared error media join
Accept; path/query/header parameter mapping is unchanged.

Malformed JSON or a payload that violates its declared schema never replaces
the HTTP failure. ApiException keeps the status and raw bytes, sets payload to
null and exposes the decoding/validation cause through getPrevious(). A valid
nullable error also has payload null and has no decoding cause. Existing model
serialization checks remain active after a caller mutates a decoded error DTO.

Text/plain errors retain string constraints. Binary errors expose buffered bytes,
consistent with the existing HttpResponse exception API; binary success responses
retain their existing PSR behavior. No error-response streaming, arbitrary MIME
codecs or negotiation among incompatible representations is added here.

Stable dependency: sdk-runtime ^0.30.0. Existing constructor positions and legacy
DTO error metadata remain supported. No exception subclass per endpoint, response
wrapper or new transport is emitted.
