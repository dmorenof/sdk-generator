# SDK Generator

[![PHP package CI](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml)
[![MIT license](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**v0.23.0: usable PHP SDK generator for a deliberately limited JSON/YAML OpenAPI/Swagger subset.** PHP 8.3 or newer and Composer 2.2 or newer. The same normalization and emitter path handles Swagger 2.0, OpenAPI 3.0.x, and OpenAPI 3.1.x contracts *within the supported subset*. It is not a drop-in replacement for the complete OpenAPI Generator tool.

The output is an **independently installable Composer library** with public typed PHP DTOs, backed enums, typed collections, executable operation methods and JSON response hydration. Transport (Guzzle 7 with PSR-18 injection), hydration, validation and security live once in the shared [dmorenof/sdk-runtime](https://github.com/dmorenof/sdk-runtime) package, which every generated SDK requires; the SDK itself contains only API-specific code. The generator is a **build-time tool**, not a dependency of the generated SDK. Its CLI applies PSR-12 formatting automatically with PHP-CS-Fixer; generated SDKs do not depend on that formatter.

Version 0.4.0 adds contract inspection (`--inspect` / `--dry-run`), opt-in strict generation (`--strict`), and operation/path server routing with per-client environment substitutions. See [contract inspection and operation servers](docs/CONTRACT_INSPECTION.md). Completed changes are integrated and published following [AGENTS.md](AGENTS.md).

Version 0.7.0 adds explicit local file graphs for supported JSON/YAML schema and HTTP fragments. Use `--resolve-local-references` and optionally `--reference-root=directory`; string inspection stays in memory and no HTTP references are fetched. See [local file references](docs/FILE_REFERENCES.md) for usage and limits.

Version 0.8.0 adds local OpenAPI security-scheme aliases while preserving their public credential names and the existing authentication requirements. External security-scheme imports and OAuth flows remain pending.

Version 0.9.0 adds native PHP query arrays: OpenAPI form repeated keys or CSV, and Swagger multi/CSV with its CSV default. Supported item constraints are checked again after mutations. See [query arrays](docs/QUERY_ARRAYS.md) for examples and limits.

Version 0.10.0 adds OpenAPI space/pipe-delimited arrays and Swagger ssv/tsv/pipes, with legal encoded delimiters and rejection of ambiguous item values. Undefined explode combinations remain diagnosed.

Version 0.11.0 adds typed flat `deepObject` query parameters in GetParams and PostParams, including POST query + JSON body and safe handling of a query parameter named `body`. See [query objects and public parameter DTOs](docs/QUERY_OBJECTS.md) for examples and limits.

Version 0.12.0 adds OpenAPI URL-encoded form bodies with scalar fields and primitive repeated-key/CSV lists. GetParams and PostParams keep query, headers and body separate. See [HTTP forms](docs/FORMS.md) for supported schemas, defaults and limits.

Version 0.13.0 adds Swagger 2.0 `formData` scalar/list fields through the same URL-encoded runtime. Its CSV default and explicit multi format are preserved, including reusable aliases and operation overrides. See [Swagger HTTP forms](docs/FORMS.md#swagger-20-formdata-v0130).

Version 0.23.0 adds primitive simple/CSV path and header lists, with native array PathParams, exact path item encoding, raw header rules and required placeholder validation. GetParams/PostParams keep query and JSON bodies independent; shared execution uses stable runtime ^0.17.0. See [simple parameters](docs/SIMPLE_PARAMETERS.md), [response selection](docs/RESPONSES.md), [downloads](docs/DOWNLOADS.md) and [the architecture review](docs/ARCHITECTURE_REVIEW.md).

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

**Arguments:** input JSON, YAML (`.yaml`/`.yml`) specification, output directory (must be empty or absent), PHP root namespace, Composer package name. The executable is also exposed to Composer as a binary when the generator is installed as a package.

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

use Example\AccountsSdk\Client;
use Example\AccountsSdk\Resources\Users;
use Example\AccountsSdk\GetParams\Users\ById as UserFilters;
use Example\AccountsSdk\DTOs\NewUser;

// Independent instance connection:
$client = Client::connect('https://api.example.com/v1');
$filters = new UserFilters();
$filters->verbose = false;
$user = $client->resource(Users::class)->getById(88, $filters);

$input = new NewUser();
$input->name = 'Grace';
$created = $client->resource(Users::class)->post($input);
~~~

These endpoints exist in the example **synthetic Accounts specification**, not in every API; generated endpoint names always come from the input contract.

Static route facades mirror OpenAPI URL segments. Their independent instance resources live under Resources and inherit from Client.
Reusable schema models are grouped in a **single hierarchical `DTOs` tree**.
Explicit hierarchy from dotted or `__` schema identifiers is preserved.
For flattened names, shared structural prefixes and common
`RequestSchema`/`ResponseSchema` suffixes are reconstructed into
namespaces. For example, `UserManagementJWTRefreshTokenResponseSchema`
becomes `DTOs\\UserManagement\\JWTRefreshToken\\ResponseSchema`.
Original schema identifiers and `$ref` links remain unchanged.
All generated data classes inherit a single runtime `DTO` base. The HTTP operation applies request/response direction-specific validation at the boundary. Enums and typed collections retain their
own namespace trees. See [naming policy](docs/SDK_DESIGN.md).

### Named SDK root and predictable route classes (0.2.0)

The abstract SDK root is derived from the complete OpenAPI info.title.
For example, Casafari API produces CasafariAPISDK. Public API classes
directly mirror the URL structure, while methods correspond to HTTP verbs.
A terminal path identifier adds ById or By{Placeholder} to the method.
GET query/header parameters use GetParams; equivalent POST parameters
use PostParams. Named body schema objects are reused from DTOs.
Inline operation-specific request objects live under `Requests`, and inline
response envelopes under `Responses`, each separated by route and HTTP verb.
These classes also extend `DTO` and can contain references to shared models.

~~~php
use Example\AccountsSdk\ExampleAccountsSDK;
use Example\AccountsSdk\Users;
use Example\AccountsSdk\Configuration;
use Example\AccountsSdk\DTOs\NewUser;

$config = new Configuration();
$config->baseUrl = 'https://api.example.com/v1';
ExampleAccountsSDK::configure($config);

$body = new NewUser();
$body->name = 'Grace';
$created = Users::post($body);
$user = Users::getById(88);
~~~

The old grouped UsersApi/ApiV1 facade and duplicate StaticApi tree are
replaced by this route structure. Client::connect() and Resources classes
remain for independent simultaneous connections. The shared runtime
handles HTTP transport, middleware, authentication and validation.
PHP identifiers are transliterated using ICU, preserving all original
wire names. See the complete design in docs/PUBLIC_API.md.

### HTTP and authentication

A default Guzzle client is constructed automatically by `Client::connect(server)`. No explicit HTTP handler, transport, middleware, or configuration object is required for ordinary use.

For advanced HTTP use, pass a **PSR-18 client** and optional headers. A configured Guzzle client can contain its own standard HandlerStack middlewares:

~~~php
use Example\AccountsSdk\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

$stack = HandlerStack::create();
$stack->push(Middleware::mapRequest(
    fn (RequestInterface $request): RequestInterface =>
        $request->withHeader('X-Request-Origin', 'example-app')
));
$http = new GuzzleClient(['handler' => $stack]);

$client = Client::connect(
    'https://api.example.com/v1',
    $http,
    ['Authorization' => 'Bearer ' . $accessToken],
);
~~~

Resources methods are instance methods; route facades (such as Users::getById()) are static. `Client::connect()` returns an independent client; `resource(Type::class)` shares its transport, credentials and middleware explicitly. A route class can also be instantiated directly or use its inherited `connect()` factory. OpenAPI security schemes are applied to secured operations; configure their credentials through `Dmorenof\SdkRuntime\Security\SecurityCredentials`. OAuth2/OpenID Connect token acquisition is not implemented, so provide an already-issued token when the scheme uses bearer authentication.

### Explore without PHP on your computer

Open [GitHub Actions → Downloadable SDK previews](https://github.com/dmorenof/sdk-generator/actions/workflows/sdk-preview.yml). Runs provide downloadable source ZIPs for **Accounts** and **Catalog** SDKs. GitHub Actions independently installs and smoke-tests each generated package with *mock HTTP*, so no API credentials or live services are needed. Details: [docs/TRY_WITHOUT_LOCAL_PHP.md](docs/TRY_WITHOUT_LOCAL_PHP.md).

## Supported features and deliberate boundaries

| Area | v0.23.0 (supported subset) |
|---|---|
| Input dialects | Swagger 2.0, OpenAPI 3.0.x and 3.1.x in **JSON or YAML**, tested common subset |
| Models | Object properties, common primitive types, local model references, backed enums, typed arrays, nested DTOs, compatible single-parent `allOf` inheritance, local discriminator mappings, and original-schema `anyOf`/`oneOf` validation |
| Endpoints | Path/query/header scalar parameters and native primitive query/simple path/header lists, flat typed deepObject query parameters, JSON and bounded URL-encoded/multipart requests, status-selected JSON responses and native return unions |
| Runtime | Typed operation signatures, Guzzle-by-default, optional PSR-18 injection, JSON hydration, binary PSR responses and HTTP status check |
| Testing | Synthetic independent Accounts and Catalog API contracts across three dialects, Composer installation + mock HTTP |
| Not included yet | External/general references, arbitrary native unions, `not`/`const` and unsupported composition branches, OAuth2/OpenID Connect token flows, cookie payloads, general streaming guarantees/non-seekable uploads and advanced form/multipart encoding, broad media types, all response shapes, full OpenAPI compliance |

The validator **rejects many unsupported constructs explicitly** instead of silently treating them as supported. Generated DTOs validate required fields and supported constraints on construction and before sending, including modified nested values. This is not complete JSON Schema validation. See [the SDK design criteria](docs/SDK_DESIGN.md) and the supported-scope document for the current boundaries.

See [pendientes.md](pendientes.md) for the audited OpenAPI compatibility roadmap, and [docs/SUPPORTED_SCOPE.md](docs/SUPPORTED_SCOPE.md) before generating a production-facing client. [CHANGELOG.md](CHANGELOG.md) lists versioned changes. [docs/READY_TO_USE_CLIENT.md](docs/READY_TO_USE_CLIENT.md) explains the HTTP factory; [docs/RUN_GENERATED_SDK.md](docs/RUN_GENERATED_SDK.md) covers the example.

## Development and verification

~~~bash
composer install
composer validate --strict --no-check-lock
bash ci/run-tests.sh --coverage
~~~

Required CI runs PHPUnit on PHP 8.3–8.5, minimum compatible dependencies, Composer/autoload validation and a security advisory check. The Accounts and Catalog previews are separately installed by Composer and exercised via mock Guzzle handlers. Generation and runtime comparisons against OpenAPI Generator are collected under [benchmark/](benchmark/README.md). The benchmarks use narrow synthetic workloads and **do not establish general feature parity**.

## Releases and dependencies

Release **[v0.23.0](https://github.com/dmorenof/sdk-generator/releases/tag/v0.23.0)** is specified by [VERSION](VERSION). The validated [Publish version workflow](.github/workflows/release.yml) creates its immutable tag and GitHub Release. Generated SDKs require stable `dmorenof/sdk-runtime` **^0.17.0**. Packagist registration is **not automatic**; see [RELEASING.md](RELEASING.md). The generated SDK needs its own Composer dependencies; the generator source is not required when running API requests.

## License

MIT — [LICENSE](LICENSE).
