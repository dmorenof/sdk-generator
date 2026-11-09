# Typed parameter content (v0.29.0 / runtime v0.22.1)

OpenAPI 3.0/3.1 parameters may declare one content representation instead of a root schema/style. This SDK supports application/json for path/query/header parameters and text/plain for path/query/header/cookie parameters. The media name is case-insensitive. Unsupported MIME variants/parameters, multiple representations and schema/style conflicts fail explicitly in normal and strict generation; no representation is silently chosen.

JSON parameters reuse native scalar types, existing DTOs, typed collections and Maps. Inline objects/arrays and references to supported object/collection models are reused rather than converted into a JSON-string wrapper type. Existing nested validation and request direction apply: readOnly fields are excluded, JSON names and numeric map keys are preserved. Serialization uses the common Serializer and native json_encode with the same flags as JSON bodies.

The runtime serializes a representation once, then applies ordinary percent encoding for path/query. JSON strings include their JSON quotes; false/zero remain valid JSON scalars. An empty JSON array is sent as encoded [] instead of disappearing like an empty style-expanded list. Parameter names retain their ordinary encoding. Headers contain the serialized bytes directly and must satisfy HTTP byte/whitespace rules. Per-parameter media does not set the request body's Content-Type.

For example a string path id with JSON content becomes %22Cat%22; the native method still takes string and validates enum/minLength before serialization. A query DTO becomes a single percent-encoded JSON value, not exploded properties. Optional uninitialized/null parameters are omitted. Nullable root schemas and literal top-level JSON null are not supported in this delivery; nullable fields within representable JSON models retain existing semantics.

## Cookies and method separation

A text/plain cookie is a string already in its RFC6265 wire form. Values such as a+/b== are retained rather than query-encoded; illegal octets or conflicting values fail before HTTP. Schema-form cookies keep their existing percent encoding. Configured cookies, parameter cookies and API key cookies coexist in one header. Cookie names must be valid HTTP tokens. Cookie JSON is outside scope because general JSON punctuation is not a legal cookie value.

GetParams/PostParams and PathParams retain their existing API. A query named body is distinct from the real JSON payload. Transport, execution and configuration remain inherited from the runtime. Operation only gains optional parameterContent metadata after existing constructor arguments; executor signatures are unchanged.

## Validation and limits

Require exactly one representation with a supported schema; schema, style, explode, allowReserved and collectionFormat cannot accompany content. allowEmptyValue retains its common query/schema-authoritative policy. text/plain requires an inline string schema. JSON uses already-emittable scalars, object/array models and supported inheritance; primitive schema aliases, nullable roots, general composition/mixed roots, binary/uploaded values and arbitrary custom objects are not added. Media-specific encoding directives and other media types remain diagnosed.

Runtime v0.22.1 also fixes typed arrays with removed/explicit keys: serialized collections remain JSON arrays at root and nested positions. Map keys remain object keys. Source and installed-package HTTP checks verify this mutation along with direction, empty containers, Unicode/percent/slash data, numeric keys, raw header/cookie bytes and logical constraints before encoding.

The independently installed SDK performs GET/POST with JSON and plaintext parameters, a scalar JSON path and a sparse collection mutation, without a generator dependency. Unit tests cover JSON/YAML, versions, aliases, invalid media/cardinality/options and mutations. See the [OpenAPI Parameter Object](https://spec.openapis.org/oas/v3.1.2.html#parameter-object).
