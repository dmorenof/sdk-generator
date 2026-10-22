# Configuring a generated PHP SDK

Create a resource instance. Its inherited constructor supplies Guzzle automatically:

    $users = new \Example\ReadySdk\Api\Users\User('https://api.example.com/v1');
    $user = $users->getById(88);

The generated package uses Guzzle 7 by default. Advanced users can supply a configured PSR-18 client and default headers:

    $client = \Example\ReadySdk\Client::connect(
        'https://api.example.com',
        $customPsr18Client,
        ['Authorization' => 'Bearer ' . $token],
    );

Each client owns its configuration. `connect()` is a factory returning an independent instance, with no global state. For multiple resources, `$client->resource(\Example\ReadySdk\Api\Users\User::class)` explicitly shares that client's transport, credentials and middleware. Resources also inherit the constructor and `connect()` factory.

Guzzle HandlerStack middleware works through PSR-18 injection. SDK middleware registered with `withMiddleware()` is shared by resources using that client's transport.

The generated `Client` parent implements request construction, authentication, status handling and hydration once. Resource methods contain typed arguments and a call to the inherited executor with constant operation metadata. DTOs, requests and responses inherit conversion, validation and serialization from `Core\DTO`.

This development API replaces the prerelease `CasafariSDK::connect()` global connection and static route operations. Keep the returned instance and replace `User::getById(88)` with `$users->getById(88)`. The published 0.1.0 tag is unchanged. See [SDK_DESIGN.md](SDK_DESIGN.md) for design and acceptance criteria.

OpenAPI security schemes are applied to secured operations. Supply credentials through the generated `Core\SecurityCredentials` type; OAuth2 and OpenID Connect token acquisition are not implemented.

All tests run in GitHub Actions with mock PSR-18 responses; no real HTTP credentials or remote calls.
