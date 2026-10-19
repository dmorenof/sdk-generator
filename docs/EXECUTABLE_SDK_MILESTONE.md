# Executable end-to-end SDK prototype

**Status:** experimental milestone, not a released or fully conformant API
client.

The `PhpExecutableEmitter` consumes the exact same typed canonical contract
produced from Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1 in PR #6. It reuses
the deterministic DTO, enum, collection and operation interface emission from
PR #7. It additionally emits a real `ApiClient` implementing those interfaces.

The generated SDK is independent of `sdk-generator` at runtime. A tiny
`TransportInterface` makes HTTP injectable; no network credentials are used
in the fixture. Tests verify full **GET and POST** serialization, routing,
query values, HTTP status handling and nested typed response hydration.

The JSON hydrator and transport interface currently live in generated
support files as a transitional prototype. When we have a second generated
API proving reuse, the common implementation can move into `sdk-runtime`.
This is not a finished public runtime API and does not claim full OpenAPI
support. In particular, security, cookies, composed schema types, general
wire-name aliases, content types other than JSON and additionalProperties
handling need dedicated contract IR and test coverage. Unknown JSON keys
are ignored by this prototype; schema-based policies are future work.

The generated package depends on the **published**
`typedarray/typedarray` package; the generator's own Composer development
dependencies include it so the real collection class participates in tests.
No unsupported future TypedArray package name is assumed.

Run:

```sh
composer install
bash ci/run-tests.sh --coverage
```

CI verifies PHP 8.3, 8.4, 8.5, minimum dependencies, security advisories
and Composer validation. A green run confirms the fixture-based round trip
without pretending that an actual external API was contacted.
