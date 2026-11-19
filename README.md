# SDK Generator

[![PHP package CI](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Turn an OpenAPI or Swagger file into a typed PHP SDK you can install with Composer.

```bash
php bin/generate-sdk.php api.json build/acme-sdk 'Acme\Sdk' acme/sdk
```

You get a Composer package with typed models, backed enums, typed collections and one method per operation. Your application then calls the API like this:

```php
$user = Users::getById(88);

$body = new NewUser();
$body->name = 'Grace';
$created = Users::post($body);
```

## Who it is for

PHP developers who consume a REST API that publishes an OpenAPI/Swagger contract and want a client that is typed, validated and cheap to maintain: when the contract changes, you regenerate.

It is a **deliberately limited subset** of OpenAPI, not a replacement for the full OpenAPI Generator. What it does not support is rejected with a clear message instead of being silently ignored (see [supported scope](docs/SUPPORTED_SCOPE.md)).

## How it fits together

| Piece | Role |
| --- | --- |
| **sdk-generator** (this repository) | Build-time tool. Reads the contract and writes the SDK. Not needed when the SDK runs. |
| [**sdk-runtime**](https://github.com/dmorenof/sdk-runtime) | Shared engine every generated SDK requires: HTTP (Guzzle/PSR-18), authentication, hydration, validation, errors, retries. Written and tested once. |
| **Your generated SDK** | Only what is specific to the API: models, operations, names. |

Because the engine is shared, a generated SDK is small and a fix in the runtime reaches every SDK with a `composer update`.

A real example: [casafari-sdk](https://github.com/dmorenof/casafari-sdk) is generated from the Casafari CRM contract with this tool.

## Requirements

PHP 8.4 or newer, Composer 2.2 or newer. Generated SDKs require PHP 8.4 and `dmorenof/sdk-runtime` `^1.7.0`.

```bash
composer require --dev dmorenof/sdk-generator
```

## Quick start

```bash
composer install
php bin/generate-sdk.php \
    tests/fixtures/accounts-openapi31.json \
    build/accounts-sdk \
    'Example\AccountsSdk' \
    example/accounts-sdk
cd build/accounts-sdk && composer install
```

Arguments: the specification (JSON, or YAML with `.yaml`/`.yml`), an output directory (empty or absent), the PHP root namespace and the Composer package name.

Using the generated SDK:

```php
use Example\AccountsSdk\{Configuration, ExampleAccountsSDK, Users};
use Example\AccountsSdk\DTOs\NewUser;

$config = new Configuration();
$config->baseUrl = 'https://api.example.com/v1';
ExampleAccountsSDK::configure($config);

$user = Users::getById(88);
$body = new NewUser();
$body->name = 'Grace';
$created = Users::post($body);
```

Static route classes (`Users::...`) mirror the URL structure and share one configuration. For several independent connections use `Client::connect($config)` and `$client->resource(Users::class)`. Endpoints above exist in the example Accounts specification; yours come from your contract.

## Options

| Option | Effect |
| --- | --- |
| `--strict` | Refuse anything the generator cannot execute. |
| `--validate-formats` | Assert declared formats (`date-time`, `uuid`, …). |
| `--inspect` / `--dry-run` | Report what would be generated without writing files. See [contract inspection](docs/CONTRACT_INSPECTION.md). |
| `--resolve-local-references`, `--reference-root=DIR` | Follow `$ref` to local files. See [file references](docs/FILE_REFERENCES.md). |

## Validation, in short

Every property that has rules gets a PHP 8.4 `set` hook in the generated model, so a value is checked where it enters the model, once: when you assign it or when the model is built from JSON. Nothing is validated again when a request is sent, and `json_encode($model)` only serializes. `$model->validate()` checks everything on demand. Two limits of PHP 8.4 hooks: a property with a hook cannot be unset, and native array properties get no hook (a hooked property cannot be edited in place), so lists with rules are checked when the model is built and by typed collections. PHP has no 32-bit integer, so the range of `int32` is always asserted; other formats only with `--validate-formats`. Details: the runtime's [validation guide](https://github.com/dmorenof/sdk-runtime/blob/main/docs/validation.md).

## What is supported

- Swagger 2.0, OpenAPI 3.0.x and 3.1.x, in JSON or YAML.
- Models, enums, typed collections, maps, discriminators, `allOf`/`oneOf`/`anyOf` within limits.
- Path, query, header and cookie parameters; JSON, forms, multipart, text and binary bodies; exact/range/default response selection.
- Security: API key, HTTP basic/bearer, OAuth2 and OpenID Connect (a token you supply, or a token provider such as `OAuth2ClientCredentials`) and, in OpenAPI 3.1, `mutualTLS` (a `ClientCertificate` the default Guzzle transport presents in the TLS handshake of the operations that require it), selected per operation. OAuth2 endpoints and scopes are not generated into the SDK.

Not supported (rejected, not ignored): remote `$ref`, structured XML/CSV models and the more exotic schema compositions. The exact list is in [SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md).

## Speed and honesty

Against OpenAPI Generator 7 on the same specifications, a call through our SDK is about 1.5× slower (about 92 µs against 62 µs on a mocked request), because it also validates, selects security, decodes errors and applies retry and redirect policies. We claim no speed advantage. See the [audit](https://github.com/dmorenof/sdk-runtime/blob/main/docs/AUDIT.md) and [benchmark/](benchmark/README.md).

## Documentation

[Supported scope](docs/SUPPORTED_SCOPE.md) · [Design and naming](docs/SDK_DESIGN.md) · [Public API](docs/PUBLIC_API.md) · [Ready-to-use client](docs/READY_TO_USE_CLIENT.md) · [Try without local PHP](docs/TRY_WITHOUT_LOCAL_PHP.md) · [Changelog](CHANGELOG.md) · [Releasing](RELEASING.md) · [Roadmap](pendientes.md)

One document per feature area lives in [docs/](docs/): forms, query arrays and objects, path styles, cookies, responses, error payloads, schema rules and more.

## Development

```bash
composer install
composer validate --strict --no-check-lock
bash ci/run-tests.sh --coverage
```

CI runs PHPUnit on PHP 8.4 and 8.5, minimum compatible dependencies, an advisory audit, and installs and smoke-tests generated SDKs.

## License

MIT — [LICENSE](LICENSE).
