# JSON response types by HTTP status — v0.22.0 / runtime v0.16.0

Operations with several explicit JSON success responses now return native PHP
unions. The runtime chooses the existing hydrator for the actual response status;
it does not guess a schema from the body or wrap successes in a new result type.

```yaml
responses:
  '200':
    description: Existing user
    content:
      application/json:
        schema: {$ref: '#/components/schemas/User'}
  '201':
    description: Created user
    content:
      application/json:
        schema: {$ref: '#/components/schemas/CreatedUser'}
  '204': {description: No content}
```

The public operation returns `User|CreatedUser|null`. Both resource methods and
static façades expose the union. If CreatedUser extends User through supported
allOf inheritance, the returned object preserves that inheritance and its exact
class. Model names and existing single-body response names remain stable.
Multiple inline response objects get status-specific names, such as
`Responses\Users\GetStatus200` and `Responses\Users\GetStatus201`, to avoid collisions.
Duplicate native types collapse, while the status map still retains separate
nullability rules. Representations continue to use the existing supported JSON
response schemas, typed collections and hydration/validation limits.

Generated operations declare only responseTypes metadata. Each status has its
own type and schemaNullable flag; a null type means no body. Execution, JSON
parsing, validation, security and transport remain inherited from ApiClient.
There is no second hydrator, response factory, result wrapper or per-SDK status
switch. The union is a method return type; it does not implement arbitrary native
unions in model properties or change oneOf/anyOf validity.

## No content and invalid bodies

A declared no-body 204 returns null. If 200 declares a nonnullable JSON body,
empty bytes and JSON null are invalid even when 204 is another successful status.
A nullable 202 can return JSON null but still requires a valid JSON document;
empty bytes are not JSON null. Invalid required/type/value constraints in the
selected DTO fail; the runtime never falls back to another status's schema.

Newly generated multi-status SDKs use this stricter status-specific behavior.
Older SDKs keep the runtime's responseType/schemaNullable/noContentAllowed fallback
and their existing public metadata/constructor compatibility. JSON error DTOs
and raw ApiException responses retain their behavior. Binary-only operations
continue returning their native PSR responses.

## Verification and boundaries

JSON/YAML Swagger 2.0 and OpenAPI 3.0/3.1 produce equivalent maps/signatures.
The fixture [status-responses-openapi31.json](../tests/fixtures/status-responses-openapi31.json)
covers a parent User, CreatedUser child, nullable inline 202, bodyless 204 and typed
400. GET/POST keep query and headers independent of JSON request DTOs, including
query named body. Response serialization, inherited readOnly fields, request and
response mutations retain their validation rules.

Twenty independently Composer-installed SDKs pass maximum PHPStan. An independent
SDK exercises 24 real Guzzle HTTP GET/POST scenarios on a cloud-local server,
including invalid bodies and errors. CI and immutable-publication installation
run the same status-response consumer independently of the generator. Runtime
v0.16.0 is published and installed first; generated SDKs require stable ^0.16.0.

The original v0.22.0 subset used explicit numeric successful statuses. v0.31.0
adds ranges and JSON/binary success mixtures below. Other codecs/negotiation and declared response-header
schema validation, and general HEAD/no-content policies remain pending. Existing
JSON Schema validation limits still apply; this change does not add ignored schema
keywords or promise complete JSON Schema compliance. JSON/errors remain buffered.


## Response selection block — v0.31.0

The generator and emitted SDKs require stable runtime ^0.24.0. The existing
success, responseTypes and errors maps carry selectors; execution, JSON hydration
and PSR transport remain shared. No result wrapper, factory or decoder is generated.

OpenAPI 3.0/3.1 supports uppercase 1XX, 2XX, 3XX, 4XX and 5XX in addition to numeric
HTTP codes and default. An exact entry wins over its range, which wins over default.
Swagger 2.0 supports exact codes/default; ranges are rejected for that dialect.
Malformed selectors such as 2xx, 20X and 6XX fail.

2XX describes successful states not listed explicitly. default can describe an
unmatched 2xx, including operations with only default declared; it does not make
non-2xx successful. When 2XX already covers all successes, the default schema does
not widen the success return type. Redirect policy stays in transport configuration.

~~~yaml
responses:
  '200':
    description: Exact JSON
    content: {application/json: {schema: {$ref: '#/components/schemas/Exact'}}}
  '201':
    description: Binary
    content: {application/octet-stream: {schema: {type: string, format: binary}}}
  '204': {description: No body}
  '2XX':
    description: Other successful JSON
    content: {application/json: {schema: {$ref: '#/components/schemas/Range'}}}
  '400': {description: Empty error}
  '4XX':
    description: Other client errors
    content: {application/json: {schema: {$ref: '#/components/schemas/Problem'}}}
  default:
    description: Other errors
    content: {application/json: {schema: {$ref: '#/components/schemas/Fallback'}}}
~~~

This operation returns Exact|ResponseInterface|Range|null. 200 uses Exact, 201
returns the original PSR response and unread binary stream, 204 returns null, and
299 uses Range. Accept lists application/json and application/octet-stream.
Selection uses the HTTP status, not schema guessing. JSON schema nullability and
response-direction constraints apply; invalid data does not try a broader selector.

The explicit bodyless 400 suppresses 4XX/default hydration; 404 uses Problem and
500 uses Fallback. ApiException preserves raw status, headers and body when typed
hydration fails, exposing that failure as its previous exception. An explicit
unsupported/untyped error also blocks a broader decoder. Error DTOs retain the
existing object/reference subset; scalar/collection/binary error codecs remain pending.

Binary-only SDKs preserve their PSR return behavior, including bodyless successes.
Mixed operations return null for declared bodyless successes. PSR-18 binary streams
return without materialization; the legacy string transport remains buffered.
Native return unions do not add general oneOf/anyOf model validation.

Both transports, JSON/YAML, local references, empty/invalid bodies and mutations are
verified. The 32nd independent SDK passes PHPStan maximum and nine actual GET/POST
HTTP scenarios: query named body, headers, JSON request body, binary bytes/headers,
typed errors and default-only success. Publication runs the same installed consumer.
General HEAD/205 rules, same-status MIME negotiation, arbitrary binary MIME and
declared response-header schema validation remain separate work.
