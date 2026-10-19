# Review generated SDKs without local PHP

GitHub Actions creates **two complete, standalone PHP Composer SDK packages** from independent **synthetic** API contracts: Accounts and Catalog. Both are smoke-tested after independent Composer installation, with a Guzzle mock handler. The tests verify real GET/POST serialization and nested DTO/enum/typed collection hydration; they also exercise PSR-18/Guzzle injection and request/response middleware hooks.

1. Open [Actions → Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml).
2. Select the newest successful workflow run (the workflow also supports **Run workflow** from the default branch).
3. Under **Artifacts**, download sdk-preview-accounts-php83 or sdk-preview-catalog-php83.
4. Inspect the PHP source and generated composer.json directly in the ZIP. You **do not need PHP or Composer locally** to review it.

These are **source-only previews**, not signed or production-tested API releases. The artifact excludes vendor packages to keep downloads small. Each generated SDK is separately installable using its own composer.json.

Workflow artifacts expire 14 days after upload; rerun the workflow to get fresh copies. CI does not use real API accounts, credentials, or live HTTP traffic.

For code running under PHP 8.3+, a generated SDK connects with the simple static factory:

~~~php
$api = \Preview\AccountsSdk\Api\ApiClient::connect('https://api.example.com/v1');
$user = $api->getUser(88);
~~~

A real Guzzle 7 implementation is selected by default; custom PSR-18 client and default headers are optional.

**Scope:** limited JSON Swagger/OpenAPI subset; security schemes and outbound full required-field validation are not implemented. See [SUPPORTED_SCOPE.md](SUPPORTED_SCOPE.md) and [README.md](../README.md).
