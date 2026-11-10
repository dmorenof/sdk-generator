# Native schema types — v0.34.0

OpenAPI 3.1 primitive type sets (`string`, `integer`, `number`, `boolean`,
`null`) become native PHP property and response unions. Their original type-set
schema and applicable constraints remain declarative runtime validation. Hydration
prefers the incoming JSON kind; it does not turn numeric strings into numbers
when the string branch applies. Integral finite JSON numbers also hydrate PHP
integers and integer-backed enums.

Boolean schemas preserve their meaning: `true` allows any JSON value and `false`
rejects every present value, including null. They work in models, properties,
array items and JSON responses. A false optional property may remain absent.
Arrays of primitive unions or unrestricted values use native arrays rather than
specialized wrapper classes; item guards are rechecked after mutations.

Local scalar alias chains reuse these native types, including path/query/header/
cookie parameters, supported content parameters and existing form field shapes.
No scalar DTO classes are emitted. Required nullable HTTP parameters which would
otherwise disappear as null fail before transport; optional null parameters
remain omitted. JSON properties retain their own null semantics.

Runtime dependency: stable `dmorenof/sdk-runtime ^0.27.0`. Transport remains in
the runtime and uses existing Guzzle/PSR facilities. Generated SDKs only declare
types and operation/schema metadata.

This release does not replace existing object composition wrappers, enable
object/array alternatives in type sets, add arbitrary named array models, or
accept primitive root request bodies. General oneOf/anyOf/not, complex const,
and the remaining JSON Schema vocabulary retain their documented limits.
