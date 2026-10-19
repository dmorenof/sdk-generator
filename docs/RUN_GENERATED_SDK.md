# Generate and run a sample PHP SDK

This is a **prototype** implementing only the documented JSON subset. It
currently supports the synthetic Accounts API's GET/POST endpoints and fake
HTTP transport. Never use it to claim general Swagger/OpenAPI compatibility.

From sdk-generator, after \`composer install\`:

\`\`\`sh
php bin/generate-sdk.php \
  tests/fixtures/accounts-openapi31.json \
  /tmp/accounts-php-sdk \
  'Example\AccountsSdk' \
  example/accounts-sdk
\`\`\`

The destination must not contain other files. The generator writes
\`composer.json\`, native PHP model classes, backed enums, typed collection
wrappers, an executable \`ApiClient\`, and an injected \`TransportInterface\`.

The generated package needs \`typedarray/typedarray:^1.0\` at runtime.
The SDK generator itself is **not** a runtime dependency.

A CI test creates a real output directory, loads the generated package in a
**separate PHP process**, supplies a fake HTTP transport and verifies a GET
response is hydrated into nested DTOs, enum and typed array. This test makes
no external network calls. Run the full verification with:

\`\`\`sh
bash ci/run-tests.sh --coverage
\`\`\`

Before calling real HTTP endpoints we still need supported authentication,
transport implementation/adapters, additionalProperties semantics, and
more complete OpenAPI contract coverage. Those are separate milestones.
