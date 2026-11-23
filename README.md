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

A real example: [casafari-sdk](https://github.com/dmorenof/casafari-sdk) is generated with this tool from Casafari's published OpenAPI contract (46 operations).

## Requirements

PHP 8.4 or newer, Composer 2.2 or newer. Generated SDKs require PHP 8.4 and `dmorenof/sdk-runtime` `^1.11.1`.

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
| `--skip-unsupported` | Leave out, and list, the operations the generator cannot execute (for example a response that is an image), instead of refusing the whole API. |
| `--server=https://host` | The server of a document that does not name one (Swagger without `host`, OpenAPI without `servers`). |
| `--validate-formats` | Assert declared formats (`date-time`, `uuid`, …). |
| `--inspect` / `--dry-run` | Report what would be generated without writing files. See [contract inspection](docs/CONTRACT_INSPECTION.md). |
| `--resolve-local-references`, `--reference-root=DIR` | Follow `$ref` to local files. See [file references](docs/FILE_REFERENCES.md). |

## Validation, in short

Every property that has rules gets a PHP 8.4 `set` hook in the generated model, so a value is checked where it enters the model, once: when you assign it or when you build the model from JSON. The parameter classes of an operation (query, path, header, cookie) get the same hooks. **A call only serializes, and an answer is trusted**: nothing is validated when a request is sent or when an answer is read (only PHP's own type errors refuse a value, wrapped in `InvalidResponseException`). `json_encode($model)` only serializes. `$model->validate()` checks everything on demand. Two limits of PHP 8.4 hooks: a property with a hook cannot be unset, and native array properties get no hook (a hooked property cannot be edited in place), so lists with rules are checked when the model is built and by typed collections. PHP has no 32-bit integer, so the range of `int32` is always asserted; other formats only with `--validate-formats`. Details: the runtime's [validation guide](https://github.com/dmorenof/sdk-runtime/blob/main/docs/validation.md).

## What is supported

- Swagger 2.0, OpenAPI 3.0.x and 3.1.x, in JSON or YAML.
- Models, enums, typed collections, maps, discriminators, `allOf`/`oneOf`/`anyOf` within limits.
- Path, query, header and cookie parameters; JSON, forms, multipart, text and binary bodies; exact/range/default response selection.
- Security: API key, HTTP basic/bearer, OAuth2 and OpenID Connect (a token you supply, or a token provider such as `OAuth2ClientCredentials`) an OpenID Connect scheme's `openIdConnectUrl` (the generated client offers `Client::openIdDiscovery()`, which fetches the provider's endpoints) and, in OpenAPI 3.1, `mutualTLS` (a `ClientCertificate` the default Guzzle transport presents in the TLS handshake of the operations that require it), selected per operation. OAuth2 endpoints and scopes are not generated into the SDK.

Not supported (rejected, not ignored): remote `$ref`, structured XML/CSV models and the more exotic schema compositions. The exact list is in [SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md).

## Why use it

Measured on the Casafari contract against OpenAPI Generator 7.26 (GitHub Actions, PHP 8.4, same mocked answer, clean process per measurement):

| | This generator + runtime | OpenAPI Generator |
| --- | ---: | ---: |
| Generated code | **1.10 MB** | 4.74 MB |
| Everything installed | **3.96 MB** | 6.2 MB |
| PHP peak memory, a call building nested models | **2.5 MB** | 10 MB |
| Memory growth over 5,000 calls | **4 KiB** | 163 KiB |
| Starting the client | **0.57–0.96×** the time | 1× |
| Time of a call | **0.74×** (flat model) to 1.01× (nested) | 1× |

Less disk and memory because the engine lives once in the runtime and is not copied into every SDK. On PokeAPI (102 operations, a 350 KB real answer) a call takes 0.46× the time and 0.62× the peak memory of OpenAPI Generator's, and the generated code is 0.88 MB against 5.15 MB. On Casafari the call is faster on a flat model and level on nested ones (one run on a shared runner; an earlier run gave 0.96× and 1.07×), even though it also selects security, decodes errors and applies retry and redirect policies. The first call in a fresh process is about 1.4× slower (more files to load), and the dependencies alone weigh more (2.6 MB against 1.3 MB).

What you gain most is simplicity: OAuth2 tokens fetched and renewed for you, models that are plain typed properties (no getters and setters), validation where a value enters a model, one `Configuration` for retries, redirects and proxies. Details, method and the honest limits (PHP 8.4 only, a subset of OpenAPI, answers trusted as sent) are in [Why this runtime](https://github.com/dmorenof/sdk-runtime/blob/main/docs/why.md); the history of what was tried is in the [audit](https://github.com/dmorenof/sdk-runtime/blob/main/docs/AUDIT.md) and [benchmark/](benchmark/README.md).

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
