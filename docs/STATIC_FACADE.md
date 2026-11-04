# Static facade for generated SDKs (0.2.0 development)

The same operation is available through a generated, strongly typed static method or through an independent instance client.

~~~php
use Example\AccountsSdk\StaticClient;
use Example\AccountsSdk\StaticApi\Users\User;

StaticClient::connect('https://api.example.com/v1');
$user = User::getById(88, false); // a typed Responses\User
~~~

Static facade methods forward to the corresponding instance resource. They retain the exact argument types, optional parameters and return types. The facade fails clearly before the first configuration, then reuses its current client for every static operation. A later `StaticClient::configure($anotherClient)` or `StaticClient::connect(...)` explicitly replaces that connection; subsequent operations use the new host, credentials and middleware.

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

**Lifecycle boundary:** Under traditional PHP-FPM, static properties are reset at the end of the HTTP request, even when a worker serves later requests. In CLI scripts, the connection remains until process exit or explicit replacement. Long-lived servers such as RoadRunner/Swoole and concurrent fibers/coroutines have different lifetimes; a static property is not automatically isolated per fiber/request there. Use explicit instance clients for truly concurrent accounts, or reset/configure the SDK at the start of each sequential job. Replacing a connection never retroactively alters previously created instance clients.

This feature lives only in generated 0.2.0 code; it does not modify the separately versioned sdk-runtime package or the published 0.1.0 generator.
