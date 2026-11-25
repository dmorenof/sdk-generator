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

PHP 8.4 or newer, Composer 2.2 or newer. Generated SDKs require PHP 8.4 and `dmorenof/sdk-runtime` `^1.13.0`.

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
| `--strict` | Refuse anything the generator cannot execute, **and every defect it would otherwise repair** (see below). |
| `--skip-unsupported` | Leave out, and list, the operations the generator cannot execute (for example a response that is an image), instead of refusing the whole API. |
| `--server=https://host` | The server of a document that does not name one (Swagger without `host`, OpenAPI without `servers`). |
| `--validate-formats` | Assert declared formats (`date-time`, `uuid`, …). |
| `--inspect` / `--dry-run` | Report what would be generated without writing files. See [contract inspection](docs/CONTRACT_INSPECTION.md). |
| `--resolve-local-references`, `--reference-root=DIR` | Follow `$ref` to local files. See [file references](docs/FILE_REFERENCES.md). |

## Defective documents

Published contracts often break the specification in ways that have one obvious reading: a path placeholder with no declared parameter, a `type: string` beside a `oneOf` of objects, an answer declared as `*/*`. Without `--strict` the generator mends what it can **without guessing**, generates the SDK and reports each repair as a `repaired_defect` diagnostic (printed on generation, listed by `--inspect`). With `--strict` the same document is refused. The repairs are listed in the [CHANGELOG](CHANGELOG.md) (1.15.0). A defect with no obvious reading is still an error.

## Validation, in short

Every property that has rules gets a PHP 8.4 `set` hook in the generated model, so a value is checked where it enters the model, once: when you assign it or when you build the model from JSON. The parameter classes of an operation (query, path, header, cookie) get the same hooks. **A call only serializes, and an answer is trusted**: nothing is validated when a request is sent or when an answer is read (only PHP's own type errors refuse a value, wrapped in `InvalidResponseException`). `json_encode($model)` only serializes. `$model->validate()` checks everything on demand. Two limits of PHP 8.4 hooks: a property with a hook cannot be unset, and native array properties get no hook (a hooked property cannot be edited in place), so lists with rules are checked when the model is built and by typed collections. PHP has no 32-bit integer, so the range of `int32` is always asserted; other formats only with `--validate-formats`. Details: the runtime's [validation guide](https://github.com/dmorenof/sdk-runtime/blob/main/docs/validation.md).

## What is supported

- Swagger 2.0, OpenAPI 3.0.x and 3.1.x, in JSON or YAML.
- Models, enums, typed collections, maps, discriminators, `allOf`/`oneOf`/`anyOf` within limits.
- Path, query, header and cookie parameters; JSON, forms, multipart, text and binary bodies; exact/range/default response selection.
- Security: API key, HTTP basic/bearer, OAuth2 and OpenID Connect (a token you supply, or a token provider such as `OAuth2ClientCredentials`) an OpenID Connect scheme's `openIdConnectUrl` (the generated client offers `Client::openIdDiscovery()`, which fetches the provider's endpoints) and, in OpenAPI 3.1, `mutualTLS` (a `ClientCertificate` the default Guzzle transport presents in the TLS handshake of the operations that require it), selected per operation. OAuth2 endpoints and scopes are not generated into the SDK.

Not supported (rejected, not ignored): remote `$ref`, structured XML/CSV models and the more exotic schema compositions. The exact list is in [SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md).

## Why use it: the same API, both generators

The same published contract, generated by this tool and by OpenAPI Generator 7.26 (`php`, Guzzle, `modern`), on PHP 8.4, one clean process per measurement, the same real answer served to both, median of 7 (workflows `audit-casafari` and `audit-apis` of the [runtime](https://github.com/dmorenof/sdk-runtime); anyone can rerun them):

| API | Generated code | Everything installed | A call: time / peak memory |
| --- | ---: | ---: | ---: |
| **PokeAPI** (102 operations, OpenAPI 3.1) | **0.88 MB** vs 5.15 MB | **3.56 MB** vs 6.66 MB | **0.47×** / **0.68×** (a 350 KB answer); 0.76× / 0.53× (a list) |
| **Casafari** (46 operations, OpenAPI 3.1) | **1.10 MB** vs 4.74 MB | **3.96 MB** vs 6.25 MB | 0.74× to 1.01× / **0.25×** |
| **Wikimedia** (35 operations, Swagger 2.0) | **0.25 MB** vs 1.82 MB | **2.94 MB** vs 3.31 MB | **0.52×** / 0.89× |
| **met.no** (12 operations, Swagger 2.0 with no host) | **58 KB** vs 386 KB | 2.74 MB vs **1.85 MB** | **0.34×** / **0.48×** |

All 195 operations of the four contracts were generated, none left out. Less disk and memory because the engine lives once in the [runtime](https://github.com/dmorenof/sdk-runtime) and is not copied into every SDK; faster where answers are big because a model is built inline, per class, from what the contract says.

**Where it is not better**, so you can choose well: the runtime and its dependencies weigh 2.6 MB against Guzzle's 1.3 MB, so a *very* small API installs larger (met.no); the first call in a fresh process is about 1.4× slower (more files to load); it needs PHP 8.4; and it is a subset of OpenAPI that says what it refuses.

**What you write**, for the same call:

```php
// this generator                                       // OpenAPI Generator
PokeApiSDK::configure(new Configuration());             $api = new PokemonApi($guzzle, new Configuration());
$pokemon = Pokemon::getById('25');                      $pokemon = $api->pokemonRetrieve('25');
echo $pokemon->name;                                    echo $pokemon->getName();
$params->limit = 20;                                    $api->pokemonList(20);
```

- **A model is plain typed properties**, checked where you assign (a PHP 8.4 `set` hook): the PHP type and the limits the contract declares. OpenAPI Generator's models are getters and setters with untyped parameters: they check `null` and the declared lengths and ranges, not the PHP type of what you pass.
- **Authentication works**: the contract declares OAuth2 client credentials and you give a client id and secret; the token is fetched, kept, renewed and replayed after a `401`. OpenAPI Generator's client gives you `setAccessToken($token)`.
- **Errors are models**: an error body the contract describes arrives as a typed `ApiException::$error`, and identical bodies are one shared class instead of one per operation.
- **Policies are one object**: retries with `Retry-After`, redirects, timeouts (30 s by default), middleware, proxies and servers are one `Configuration`.
- **It says what it cannot do**: `--inspect` reports what a contract needs that is not supported, and `--skip-unsupported` leaves such an operation out and lists it instead of refusing the API.

Details, method and limits: [Why this runtime](https://github.com/dmorenof/sdk-runtime/blob/main/docs/why.md), the [audit](https://github.com/dmorenof/sdk-runtime/blob/main/docs/AUDIT.md) and [benchmark/](benchmark/README.md).

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
