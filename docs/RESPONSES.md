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

Only explicit numeric successful statuses select these JSON types. Status ranges,
JSON/binary success mixtures, non-JSON codecs/negotiation, declared response-header
schema validation, and general HEAD/no-content policies remain pending. Existing
JSON Schema validation limits still apply; this change does not add ignored schema
keywords or promise complete JSON Schema compliance. JSON/errors remain buffered.
