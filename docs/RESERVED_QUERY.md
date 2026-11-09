# Reserved query expansion (v0.27.0 / runtime v0.21.0)

OpenAPI 3.0/3.1 schema-based query parameters support `allowReserved: true` for the already-supported primitive scalars/lists and flat typed DTO/Map objects. Form defaults/explode, CSV, space/pipe-delimited lists, form objects and deepObject retain their own shapes and constraints. Omitted/false flags produce the existing output. Swagger, other locations, content-based parameters and form-body encoding are outside this change.

The generator declares queryAllowReserved names in Operation metadata; the runtime reuses QuerySerializer, native percent encoding and Guzzle Query assembly. No URI-template engine or generated transport is added. Existing constructor positions and executor signatures are retained.

Safe reserved data `:/?@!$'()*,;` passes through. Valid percent triples keep their original bytes/case; other percent bytes, Unicode, spaces and unsafe bytes use native encoding. Parameter names always use ordinary encoding. Expanded object property names do too; compact object keys are data and use the selected value encoding. Null/absent parameters and empty supported collections retain their existing omission policy. Boolean false, numeric zero and explicit empty strings retain their meaning.

## Caller pre-encoding and delimiter limits

[OpenAPI 3.1.2 Appendix C](https://spec.openapis.org/oas/v3.1.2.html#appendix-c-using-rfc6570-based-serialization) requires applications to prepare data whose reserved characters have a structural meaning. The runtime rejects raw `#[]&=+` in opted-in values so they cannot create a fragment, extra parameters or form ambiguities. Provide their legal percent triples, for example `a%26b`, when the API expects an encoded ampersand. `a/b` stays `a/b`; an unflagged parameter stays `a%2Fb`. Literal percent text must be encoded deliberately: `%2F` passes through under reserved expansion, while `%252F` transmits an encoded percent sequence.

Raw commas in compact CSV/object members are rejected; pre-encoded `%2C` remains distinct from the structural comma. Space/tab/pipe-delimited lists reject their delimiter both as a raw item character and as a pre-encoded triple, because their wire separators are encoded and would be indistinguishable. Reserved expansion requires RFC3986, not the optional RFC1738 mode of the reusable query helper. These are explicit rejection policies for ambiguous inputs, not a guessed escaping convention.

Constraints validate the supplied DTO values; the runtime does not decode and rewrite application data first. GET and POST use their existing GetParams/PostParams DTOs. A query named body remains separate from the JSON payload. Header, cookie and payload serialization do not inherit this query flag. Security query credentials always use ordinary encoding, even when they override a parameter declared with allowReserved.

Validation covers the OpenAPI Appendix C.4.2 example, valid/invalid percent sequences, numeric names, Unicode, false/zero/empty values, supported list/object forms, ambiguity, mutation and credential precedence. Both transports and actual HTTP GET/POST targets are verified. An independently installed SDK runs without the generator in source CI and immutable-package smoke.
