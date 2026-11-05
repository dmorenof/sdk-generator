# Configuring a generated PHP SDK (0.2.0 development)

The generator emits instantiable typed DTOs, typed inputs and responses, and two ways of calling HTTP operations. Both use the same shared transport, serialization, validation, security and middleware implementation from `dmorenof/sdk-runtime ^0.3`.

## Static operations — one current connection per PHP execution context

~~~php
use Example\ReadySdk\StaticClient;
use Example\ReadySdk\StaticApi\Users\User;
use Example\ReadySdk\StaticApi\Users;
use Example\ReadySdk\Requests\NewUser;

StaticClient::connect('https://api.example.com/v1');
$input = new NewUser();
$input->name = 'Grace';

$created = Users::post($input); // typed response
$user = User::getById(88);    // typed response
~~~

The static route classes have generated PHP signatures, so IDE completion and PHPStan can see input and output types without dynamic dispatch. The connection is set once and reused across every static route. Explicitly calling `StaticClient::connect(...)` or `StaticClient::configure($client)` again replaces the current connection.

Use `StaticClient::configure(Client::connect(...))` when attaching middleware, credentials, a configured PSR-18 client or an alternative transport before registering the SDK connection. See [STATIC_FACADE.md](STATIC_FACADE.md) for examples.

In ordinary PHP-FPM requests, static state has request lifetime. In long-lived worker servers, static properties remain alive between jobs and are shared within the process; concurrent multi-account use should instead use separate instance clients.

## Independent clients — explicit connection ownership

The generated package uses Guzzle 7 by default, with optional PSR-18 dependency injection:

~~~php
use Example\ReadySdk\Client;
use Example\ReadySdk\Api\Users\User;

$client = Client::connect(
    'https://api.example.com',
    $customPsr18Client,
    ['Authorization' => 'Bearer ' . $token],
);

$user = $client->resource(User::class)->getById(88);
~~~

Resources can also be instantiated directly. Every independent `Client::connect()` object owns its base URL and credentials; `resource(Type::class)` shares only that client's transport, credentials and middleware. Switching the static connection does not mutate existing instance clients.

## HTTP middleware and DTO behavior

Attach custom PSR-18/Guzzle HandlerStack middlewares, or register SDK middleware with `$client->withMiddleware($middleware)`. SDK middleware offers `beforeRequest(RequestInterface $request)` and `afterResponse(RequestInterface $request, ResponseInterface $response)` hooks; middleware is reused by the resource classes sharing that client. Logs should not record authorization secrets or sensitive payloads by default.

Resource methods contain generated typed argument and return declarations; HTTP execution lives in `Dmorenof\SdkRuntime\ApiClient`. DTOs, requests and responses inherit hydration, validation and JSON serialization from the shared `Dmorenof\SdkRuntime\DTO` hierarchy. The generator is required only at build time; generated packages depend on the separate versioned runtime package.

OpenAPI security schemes are applied to secured operations. Supply already-issued credentials through `Dmorenof\SdkRuntime\Security\SecurityCredentials`; OAuth2 and OpenID Connect token acquisition are not included.

Automated tests simulate HTTP without real API credentials. The published 0.1.0 tag remains unchanged.
