# Generate, install and use a PHP SDK (v0.1.0)

**Runtime prerequisites:** PHP 8.3+, Composer 2. The generated SDK is a distinct Composer package; it does not require the sdk-generator code at runtime.

## 1. Build from a supported JSON specification

From the sdk-generator repository (after composer install):

~~~sh
php bin/generate-sdk.php \
  tests/fixtures/accounts-openapi31.json \
  build/accounts-sdk \
  'Example\AccountsSdk' \
  example/accounts-sdk
~~~

The CLI requires four arguments: input JSON file, destination directory (must be absent or empty), valid root PHP namespace and vendor/package Composer name. A Composer-installed copy of this generator additionally exposes bin/generate-sdk.php via Composer's bin scripts.

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

$api = \Example\AccountsSdk\Api\ApiClient::connect('https://api.example.com/v1');
$user = $api->getUser(88);        // Example contract's GET /users/{id}
echo $user->name;                 // Native PHP string property

$new = new \Example\AccountsSdk\Models\NewUser();
$new->name = 'Grace';
$created = $api->createUser($new); // Example contract's POST /users
~~~

This example specification is **synthetic**. Replace the host with a real API compatible with the specification before performing live requests. In offline CI, a mock Guzzle handler is used instead of real HTTP.

## 4. Advanced: PSR-18 client and headers

~~~php
$api = \Example\AccountsSdk\Api\ApiClient::connect(
    'https://api.example.com/v1',
    $yourPsr18Client, // e.g. Guzzle with a configured HandlerStack
    ['Authorization' => 'Bearer ' . $token]
);
~~~

The generated API keeps a transitional constructor taking an injected TransportInterface and base URL, useful in tests; ordinary users do not need this.

**Limitations:** the generator currently rejects specifications with declared security requirements rather than implementing auth flows. Native property type checks are not full pre-send DTO validation; required outbound fields are not yet comprehensively validated. Consult [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md).

## Verify without local PHP

Open [Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml) for the real GitHub Actions steps and downloadable source-only ZIP artifacts of Accounts and Catalog. See [TRY_WITHOUT_LOCAL_PHP.md](TRY_WITHOUT_LOCAL_PHP.md).
