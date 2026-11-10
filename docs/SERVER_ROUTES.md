# Server defaults and coherent routes — v0.40.0

OpenAPI 3.0/3.1 server templates expand their declared defaults once during
normalization. Defaults must be strings; an enum must be a nonempty string list
containing its default. Every URL placeholder needs its own declaration. Root,
Path Item and operation scopes remain independent, and server order is retained.
The original server objects/variables remain in the canonical contract.

Expansion uses PHP strtr and the existing runtime URL policy. The result must be
an absolute HTTP(S) URL without credentials, query or fragment. No recursive
substitution, new transport or template engine is introduced. Guzzle URI handling
recognizes equivalent scheme/host/default-port spellings without collapsing
literal dot segments; a server equivalent to the root keeps the configured baseUrl.

Configuration remains unchanged: set baseUrl for the root environment and use
serverOverrides keyed by an expanded non-root URL for other environments. The
configuration snapshot applies to resources and static facades. There is no new
per-variable binding API; callers can supply complete environment URLs through
the existing options. Relative URLs and dynamic server-index selection remain
pending.

Route validation checks balanced nonempty placeholders, declared matching path
parameters, required boolean flags and extra/missing declarations after operation
overrides. Repeated placeholders and embedded forms such as {stem}.{ext} work.
Equivalent templated path hierarchies with different variable names are rejected,
as required by OpenAPI; other overlapping route patterns are not globally analyzed.
Query/fragment/control bytes cannot occur in path keys as literal URL syntax.

Paths Object x-... extensions remain data, including $ref-shaped values. They do
not become operations or reference fetches during string/file inspection. Their
values remain in canonical annotations.paths. Named components/pathItems beginning
with x- remain real reusable component names.

Stable runtime ^0.31.0 is reused without changes. JSON/YAML, default/enum errors,
URL precedence, configuration snapshots, GET/POST parameter separation, repeated/
embedded templates, mutations and installed-package HTTP are verified.
