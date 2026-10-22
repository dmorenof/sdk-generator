# Configuring a generated PHP SDK

Configure the shared connection once, then call static methods on the generated route classes:

    \Example\ReadySdk\CasafariSDK::connect('https://api.example.com');
    $user = \Example\ReadySdk\Users\User::getById(88);

The generated package uses Guzzle 7 by default. Advanced users can supply a configured PSR-18 client and default headers:

    \Example\ReadySdk\CasafariSDK::connect(
        'https://api.example.com',
        $customPsr18Client,
        ['Authorization' => 'Bearer ' . $token],
    );

Using an existing Guzzle HandlerStack with before/after middleware is possible by injecting that Guzzle client. Route classes share the configured connection; only the connection and DTOs are instantiated.

OpenAPI security schemes are applied to secured operations. Supply credentials through the generated `Core\SecurityCredentials` type; OAuth2 and OpenID Connect token acquisition are not implemented.

All tests run in GitHub Actions with mock PSR-18 responses; no real HTTP credentials or remote calls.
