# Scalar cookies and cookie authentication (v0.26.0 / runtime v0.20.0)

OpenAPI 3.0/3.1 `in: cookie` parameters support non-null string/integer/number/boolean schemas, constraints and supported enums. `style: form` is the default; explode has no effect on scalars. Optional uninitialized/null fields are omitted, while false, zero and empty strings are explicit. Required fields and mutations are checked by the existing Params DTO validation.

Cookie parameters live in the usual GetParams/PostParams DTOs and declare a cookie location. No transport code is generated. Query parameters (including one named body), headers and JSON/form payloads remain separate. Existing runtime executor and SDK constructor signatures are unchanged.

Schema-form names and values use existing RFC3986 percent encoding. For example `theme: "a/b,ñ"` becomes `theme=a%2Fb%2C%C3%B1`; false becomes `enabled=false`. The runtime joins cookie pairs with `; `, never query delimiters. It reuses QuerySerializer and Guzzle/PSR transport.

OpenAPI API key security schemes also support `in: cookie`. Supply the credential through the existing SecurityCredentials map. Its value is an already-wire-form cookie string, so `a+/b==` stays unchanged. No percent encoding or quoting is guessed for authentication. Empty credentials and illegal cookie octets fail before HTTP; raw cookie bytes follow RFC 6265.

Configured headers, operation Cookie headers and selected security cookies coexist in one Cookie field, regardless of header-name case. Cookie names themselves are case-sensitive. Identical repeated pairs coalesce; differing values for one name fail rather than silently changing authentication. Quoted valid raw values are preserved. The runtime does not echo credential values in cookie errors.

## Why structured cookies remain rejected

[OpenAPI 3.1.2 Appendix D](https://spec.openapis.org/oas/v3.1.2.html#appendix-d-serializing-headers-and-cookies) warns that form multi-value expansion uses delimiters incompatible with HTTP cookies. Compact CSV also uses comma, which RFC 6265 excludes from cookie values. This delivery does not invent array/object semantics or silently reinterpret query form rules. Use API-defined scalar cookie values; content-based parameters, nullable schemas, structured cookie expansion and Swagger cookies remain unsupported.

CookieHeader is a small request-header assembly/validation helper shared by execution, security and transport. Guzzle CookieJar manages storage, domain/path matching and Set-Cookie responses; its SetCookie parser handles response attributes, not merging request Cookie fields. Neither is a replacement for the OpenAPI encoding/merge contract here. No jar, persistence, automatic session refresh or Set-Cookie response access is added. A custom client with its own jar/middleware controls any subsequent changes it makes to the request.

Validation covers both transports, configured/raw/security coexistence, name case, zero/false/empty/Unicode/base64 data, conflicts, invalid bytes, mutations and actual HTTP GET/POST. The generated SDK installs without the generator and performs the HTTP smoke both in source CI and against the immutable published package.

## text/plain content cookies (v0.29.0)

A cookie may now use one content text/plain representation with a string schema. Its valid RFC6265 bytes are retained, so base64 a+/b== is not converted to query encoding. Configured, schema-form and API key cookies still coexist with conflict detection. JSON cookie and nullable root schemas remain unsupported. See [parameter content](PARAMETER_CONTENT.md).
