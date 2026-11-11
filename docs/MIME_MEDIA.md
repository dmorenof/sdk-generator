# Concrete MIME media — v0.39.0

The generator reuses three existing codecs: JSON values, opaque strings and PSR
binary streams. Concrete declared MIME types choose headers; the normalized
schema chooses the codec. No XML/CSV model converter or new transport is emitted.

String schemas can carry CSV, XML or another concrete raw MIME. Values retain
their bytes and string constraints. Binary string schemas retain their bounded
rules and PSR streaming representation for PNG, PDF, SVG and other concrete MIME
types. Local binary aliases work in requests and responses. Swagger file responses
reuse the same binary representation with a single explicit produces MIME.

Vendor/problem +json keeps JSON hydration and its actual Accept/Content-Type.
JSON selection priority remains unchanged. Response codecs follow status selection;
error payloads use their own declared media/codec and retain HTTP failure causes.
Opaque XML/CSV errors remain strings; structured XML/CSV model parsing is not added.

Optional responseEncoding and per-status encoding metadata distinguish the codec
from the MIME name, retaining old text/plain and octet-stream SDK behavior.
The shared Guzzle/PSR transport preserves streams and rewinding. Source and installed
smoke transfer a 32 MiB PNG body and 32 MiB PDF response under PHP 16 MiB, alongside
raw text, vendor JSON, mixed statuses and general error payloads.

Stable dependency: sdk-runtime ^1.0.0. Wildcard media selection, incompatible
request representations, structured XML/CSV models and charset transcoding remain
pending. Declared MIME parameters are preserved; opaque string bytes are supplied
by the caller. Binary constraints retain their prior bounded schema restrictions,
and error bodies retain their existing buffered HttpResponse representation.
