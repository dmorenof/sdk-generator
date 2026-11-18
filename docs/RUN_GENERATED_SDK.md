# Generate, install and use a PHP SDK (v0.1.0)

**Runtime prerequisites:** PHP 8.3+, Composer 2. The generated SDK is a distinct Composer package; it does not require the sdk-generator code at runtime.

## 1. Build from a supported JSON or YAML specification

From the sdk-generator repository (after composer install):

~~~sh
php bin/generate-sdk.php \
  tests/fixtures/accounts-openapi31.json \
  build/accounts-sdk \
  'Example\AccountsSdk' \
  example/accounts-sdk
~~~

The CLI requires four arguments: input specification, destination directory (must be absent or empty), valid root PHP namespace and vendor/package Composer name. A Composer-installed copy of this generator additionally exposes bin/generate-sdk.php via Composer's bin scripts.

YAML files with a `.yaml` or `.yml` extension use the same command and generation pipeline; for example, replace the input path with `contract.yaml`.

The output contains typed PHP model classes, backed enums, TypedArray collections, operation interfaces and a working API client, plus its own composer.json. It is deterministic for the supported contract subset.

## 2. Install the generated package independently

~~~sh
cd build/accounts-sdk
composer install
~~~

The generated package depends on typedarray/typedarray and a real Guzzle 7/PSR-18 HTTP transport. It does **not** depend on sdk-generator itself.

## 3. Use typed endpoint methods with no HTTP setup

~~~php
require '/path/to/build/accounts-sdk/vendor/autoload.php';

$client = \Example\AccountsSdk\Client::connect('https://api.example.com/v1');
$user = $client->resource(\Example\AccountsSdk\Api\Users\User::class)->getById(88); // Example contract's GET /users/{id}
echo $user->name;                 // Native PHP string property

$new = new \Example\AccountsSdk\Requests\NewUser();
$new->name = 'Grace';
$created = $client->resource(\Example\AccountsSdk\Api\Users::class)->post($new); // Example contract's POST /users
~~~

This example specification is **synthetic**. Replace the host with a real API compatible with the specification before performing live requests. In offline CI, a mock Guzzle handler is used instead of real HTTP.

## 4. Advanced: PSR-18 client and headers

~~~php
$config = new \Dmorenof\SdkRuntime\Configuration();
$config->baseUrl = 'https://api.example.com/v1';
$config->httpClient = $yourPsr18Client; // e.g. Guzzle with a configured HandlerStack
$config->securityCredentials = new \Dmorenof\SdkRuntime\Security\SecurityCredentials([
    'bearerAuth' => $token,
]);
$client = \Example\AccountsSdk\Client::connect($config);
~~~

The credential keys are security scheme names from the OpenAPI document. API keys use the same string form; HTTP Basic uses `new Core\BasicAuthCredentials($username, $password)`. The generated client applies credentials only to secured operations and honors OpenAPI's AND/OR requirement semantics. A missing complete credential alternative fails before sending the request. Query API keys become part of the URL and should be used with care in logs.

API operations are instance methods grouped by route. Every client has independent connection state. `resource(Type::class)` explicitly shares a client's transport, credentials and middleware; DTOs remain ordinary instances.

Route classes are under `Api`, mirror the route hierarchy, and extend `Client` to reuse request construction and the shared transport. HTTP errors identify the method and route (for example, `GET /users/{id}`), not an arbitrary OpenAPI `operationId`. Request-only schemas are under `Requests`, response-only schemas under `Responses`, and shared or neutral schemas under `DTOs`. Enums and typed collections are nested in folders derived from their owning schema. Schema identifiers flattened with `__` become folders under the documented [naming policy](SDK_DESIGN.md). Provider prefixes and numbered variants remain distinct; referenced schemas keep their identity even when used by one field. Simple unions with `null` map to PHP nullable types.

**Authentication:** `oauth2` and `openIdConnect` schemes are supported: pass a token (string) or a token provider (`OAuth2ClientCredentials`, `OAuth2AuthorizationCode`) under the scheme's name in `SecurityCredentials`; the runtime sends it as a Bearer token, renews it and replays a request once after a 401. **Limitations:** the generator does not read the scheme's endpoints or scopes into the SDK, so the provider is built by you with the URLs and scopes you need. Required fields and supported constraints are validated before sending, including modified nested DTOs. This is not complete JSON Schema validation. Consult [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md).

## Verify without local PHP

Open [Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml) for the real GitHub Actions steps and downloadable source-only ZIP artifacts of Accounts and Catalog. See [TRY_WITHOUT_LOCAL_PHP.md](TRY_WITHOUT_LOCAL_PHP.md).
