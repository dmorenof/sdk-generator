# OpenAPI label and matrix paths (v0.25.0 / runtime v0.19.0)

OpenAPI 3.0/3.1 path parameters support `style: label` and `style: matrix` for non-null primitive scalars, primitive lists and flat typed DTO/Map objects. Both explode modes are supported; omitted explode defaults to false. Existing required-path validation and model constraints run before transport, including after mutations.

| Value | label | label, explode | matrix (`id`) | matrix, explode |
| --- | --- | --- | --- | --- |
| `blue` | `.blue` | `.blue` | `;id=blue` | `;id=blue` |
| `[blue,black]` | `.blue,black` | `.blue.black` | `;id=blue,black` | `;id=blue;id=black` |
| `{a:blue,b:black}` | `.a,blue,b,black` | `.a=blue.b=black` | `;id=a,blue,b,black` | `;a=blue;b=black` |

Data keys/items are percent-encoded before structural separators are joined. Unicode, slash, percent, false, zero and numeric object keys retain their meaning. Schema metadata distinguishes an object with key `0` from a list. An empty scalar expands to `.` or `;id`; an empty list/object expands to an empty string. Empty matrix members omit `=`. Schema minLength/minItems/required constraints still apply; null path values fail.

Generated methods use the existing PathParams and GetParams/PostParams types. Query parameters, including one named body, headers and JSON body remain independent. The shared runtime performs expansion; generated resources declare only metadata. Constructor/executor signatures remain compatible.

The default Guzzle/cURL client enables CURLOPT_PATH_AS_IS so an empty label used as an entire dot segment survives HTTP. The stream fallback also preserves it. Applications injecting their own client must preserve the request target themselves.

Reference examples are tested against [RFC 6570 sections 3.2.5 and 3.2.7](https://www.rfc-editor.org/rfc/rfc6570). Unit tests, independently installed generated SDKs and real cloud-local GET/POST requests check exact targets and body separation.

Limits: no nullable/nested/unconstrained object fields, general compositions, arbitrary URI-template syntax, prefix modifiers or cookies. Swagger 2.0 does not define style/explode and rejects those flags. Existing simple path/header and query serialization retain their own rules. See [flat objects](OBJECT_PARAMETERS.md).
