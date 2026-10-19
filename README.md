# SDK Generator

[![PHP package CI](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**v0.1.0: early, usable PHP SDK generator for a deliberately limited JSON OpenAPI/Swagger subset.** PHP 8.3 or newer. The same normalization and emitter path handles Swagger 2.0, OpenAPI 3.0.x, and OpenAPI 3.1.x contracts *within the supported subset*. It is not a drop-in replacement for the complete OpenAPI Generator tool.

The output is an **independently installable Composer library** with public typed PHP DTOs, backed enums, typed collections using [typedarray/typedarray](https://packagist.org/packages/typedarray/typedarray), executable operation methods, a default Guzzle 7 HTTP client with PSR-18 injection, and JSON response hydration. The generator is a **build-time tool**, not a dependency of the generated SDK.

## Quick start: generate Accounts SDK

From a checkout of this repository:

~~~bash
composer install
php bin/generate-sdk.php \
    tests/fixtures/accounts-openapi31.json \
    build/accounts-sdk \
    'Example\AccountsSdk' \
    example/accounts-sdk
~~~

**Arguments:** input JSON specification, output directory (must be empty or absent), PHP root namespace, Composer package name. The executable is also exposed to Composer as a binary when the generator is installed as a package.

The command generates a standalone Composer package at build/accounts-sdk. Install its own runtime dependencies:

~~~bash
cd build/accounts-sdk
composer install
~~~

A minimal application using the **generated package's** Composer autoloader:

~~~php
<?php
declare(strict_types=1);

require __DIR__ . '/build/accounts-sdk/vendor/autoload.php';

use Example\AccountsSdk\Api\ApiClient;
use Example\AccountsSdk\Models\NewUser;

$api = ApiClient::connect('https://api.example.com/v1');
// Demo fixture operations; real requests require a reachable compatible API.
$user = $api->getUser(88);
echo $user->name;

$input = new NewUser();
$input->name = 'Grace';
$created = $api->createUser($input);
~~~

These endpoints exist in the example **synthetic Accounts specification**, not in every API; generated endpoint names always come from the input contract.

### HTTP and authentication

A default Guzzle client is constructed automatically by ApiClient::connect(server). No explicit HTTP handler, transport, middleware, or configuration object is required for ordinary use.

For advanced HTTP use, pass a **PSR-18 client** and optional headers. A configured Guzzle client can contain its own standard HandlerStack middlewares:

~~~php
use Example\AccountsSdk\Api\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

$stack = HandlerStack::create();
$stack->push(Middleware::mapRequest(
    fn (RequestInterface $request): RequestInterface =>
        $request->withHeader('X-Request-Origin', 'example-app')
));
$http = new Client(['handler' => $stack]);

$api = ApiClient::connect(
    'https://api.example.com/v1',
    $http,
    ['Authorization' => 'Bearer ' . $accessToken],
);
~~~

**Important:** v0.1.0 does not generate authentication from OpenAPI security schemes: specs declaring security requirements are rejected. Only inject a header if it matches the real API's protocol. No Casafari-specific credentials, URL allowlist, response envelopes or middleware API are copied into this general generator.

### Explore without PHP on your computer

Open [GitHub Actions → Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml). Runs provide downloadable source ZIPs for **Accounts** and **Catalog** SDKs. GitHub Actions independently installs and smoke-tests each generated package with *mock HTTP*, so no API credentials or live services are needed. Details: [docs/TRY_WITHOUT_LOCAL_PHP.md](docs/TRY_WITHOUT_LOCAL_PHP.md).

## Supported features and deliberate boundaries

| Area | v0.1.0 |
|---|---|
| Input dialects | Swagger 2.0, OpenAPI 3.0.x and 3.1.x **JSON only**, tested common subset |
| Models | Object properties, common primitive types, local model references, backed enums, typed arrays and nested DTOs |
| Endpoints | Simple GET/POST fixtures, path/query/header parameters, JSON model request/response, single explicit success response |
| Runtime | Typed operation signatures, Guzzle-by-default, optional PSR-18 injection, JSON hydration and HTTP status check |
| Testing | Synthetic independent Accounts and Catalog API contracts across three dialects, Composer installation + mock HTTP |
| Not included yet | YAML, general references/compositions, broad media types, security schemes/auth generation, robust schema-constraint validation, all response shapes, full OpenAPI compliance |

The validator **rejects many unsupported constructs explicitly** instead of silently treating them as supported. In the current slice, required fields are checked on **incoming** response DTOs; outbound DTOs have typed properties and typed collections but **do not have complete pre-send required-field or JSON Schema validation**. Unknown response fields may be ignored. These limitations matter for production use.

See [docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md) before generating a production-facing client. [CHANGELOG.md](CHANGELOG.md) lists v0.1.0 changes. [docs/READY_TO_USE_CLIENT.md](docs/READY_TO_USE_CLIENT.md) explains the HTTP factory; [docs/RUN_GENERATED_SDK.md](docs/RUN_GENERATED_SDK.md) covers the example.

## Development and verification

~~~bash
composer install
composer validate --strict --no-check-lock
bash ci/run-tests.sh --coverage
~~~

Required CI runs PHPUnit on PHP 8.3–8.5, minimum compatible dependencies, Composer/autoload validation and a security advisory check. The Accounts and Catalog previews are separately installed by Composer and exercised via mock Guzzle handlers. Generation and runtime comparisons against OpenAPI Generator are collected under [benchmark/](benchmark/README.md). The benchmarks use narrow synthetic workloads and **do not establish general feature parity**.

## Releases and dependencies

The first reviewed release uses [VERSION](VERSION) = 0.1.0 and the [Publish version workflow](.github/workflows/release.yml) to create immutable Git tag **v0.1.0** and a GitHub Release *after validation*. Packagist registration is **not automatic**; see [RELEASING.md](RELEASING.md). The generated SDK needs its own Composer dependencies; the generator source is not required when running API requests.

## License

MIT — [LICENSE](LICENSE).
