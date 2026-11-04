# Static facade for generated SDKs (0.2.0 development)

The same operation is available through a generated, strongly typed static method or through an independent instance client.

~~~php
use Example\AccountsSdk\StaticClient;
use Example\AccountsSdk\StaticApi\Users\User;

StaticClient::connect('https://api.example.com/v1');
$user = User::getById(88, false); // a typed Responses\User
~~~

Static facade methods forward to the corresponding instance resource. They retain the exact argument types, optional parameters and return types. The connection is configured once: the facade fails clearly before configuration and rejects attempts to replace the connection, avoiding accidental cross-account credential changes.

For customizable debugging, attach an HTTP middleware to the shared client before registering it:

~~~php
use Example\AccountsSdk\Client;
use Example\AccountsSdk\StaticClient;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$logger = new class implements HttpMiddlewareInterface {
    public function beforeRequest(RequestInterface $request): RequestInterface
    {
        error_log('SDK request: ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
        return $request;
    }

    public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        error_log('SDK status: ' . $response->getStatusCode());
        return $response;
    }
};

StaticClient::configure(
    Client::connect('https://api.example.com/v1')->withMiddleware($logger),
);
$user = User::getById(88);
~~~

Request and response middleware are optional, ordered and reusable across resources. Do not log tokens, Authorization headers or sensitive bodies by default. Error HTTP responses pass through the response middleware; transport exceptions retain their original identity.

**Isolation boundary:** Static state belongs to one PHP execution context, not to an account or individual fiber. Use static operations for an isolated CLI command or standard isolated PHP request. For persistent workers, parallel coroutines and multi-account integrations, use separate instance clients; do not share the static facade between tenants.

This feature lives only in generated 0.2.0 code; it does not modify the separately versioned sdk-runtime package or the published 0.1.0 generator.
