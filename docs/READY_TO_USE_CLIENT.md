# Zero-configuration generated PHP client (prototype)

Ordinary user code needs only the server URL and a typed method:

    $api = \Example\ReadySdk\Api\ApiClient::connect('https://api.example.com');
    $user = $api->getUser(88);

The generated package uses Guzzle 7 by default. Advanced users can supply a configured PSR-18 client and default headers:

    $api = \Example\ReadySdk\Api\ApiClient::connect(
        'https://api.example.com',
        $customPsr18Client,
        ['Authorization' => 'Bearer ' . $token],
    );

Using an existing Guzzle HandlerStack with before/after middleware is possible by injecting that Guzzle client. The generator does not prescribe a separate custom middleware protocol.

The legacy proof-of-concept constructor (custom transport and base URL) is retained to keep existing fake transports and tests operational.

Constraints: authentication behavior must eventually derive from the specification security schema; this stage does not infer Bearer, API key or anything else. The SDK still contains a transitional transport interface; the shared sdk-runtime package has not been integrated or released. Casafari SDK and its existing middleware API are untouched. The new default Guzzle dependency changes runtime resource and integration measurements, so do not reuse old source-size numbers for this revision.

All tests run in GitHub Actions with mock PSR-18 responses; no real HTTP credentials or remote calls.
