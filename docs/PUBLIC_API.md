# Public static SDK API — 0.2.0 development

The generated SDK provides one public **Api** class with strongly typed static
methods. Names come from OpenAPI **operationId**; when missing, a mechanical
verb/path fallback is used. Path variables retain their literal names, without
guessing that \`num\` or \`code\` means an identifier.

~~~php
use Example\AccountsSdk\Api;
use Example\AccountsSdk\Client;
use Example\AccountsSdk\Configuration;
use Example\AccountsSdk\Requests\NewUser;

$config = new Configuration();
$config->baseUrl = 'https://api.example.com/v1';
$config->headers = ['Authorization' => 'Bearer ...'];
Client::configure($config);

$newUser = new NewUser();
$newUser->name = 'Grace';

$created = Api::createUser($newUser);
$user = Api::getUser(88);

Client::configure($anotherConfiguration); // replaces the active connection
$user = Api::getUser(88);
~~~

Configuration has typed properties \`baseUrl\`, \`headers\`, \`httpClient\`,
\`securityCredentials\`, \`transport\` and \`middleware\`.
Use OpenAPI-defined security credentials; the generator does not invent
\`client_id\`/\`secret_token\` fields that the API specification never declared.
The client captures configuration values on configure; changes to the
Configuration object take effect only after another configure call.
The `headers` property configures the default PSR-18 transport; a custom
`transport` implementation manages its own default headers.

Methods delegate to the shared runtime through the generated instance resource
classes; the runtime handles HTTP, validation, serialization and hydration.
The older StaticClient/StaticApi façade and instance clients remain available
during 0.2.0 development, with no impact on published 0.1.0.

**Remaining simplification:** the executable emitter currently categorizes some
schema models as Requests/Responses based on usage. The planned final shape
unifies these under DTOs and retains separate operation parameter objects only
when required by the contract. The example above uses the current intermediate
model names, not a claim that that unification is already complete.

**Lifecycle:** Static state is per PHP-FPM request. In long-running concurrent
workers it is not fiber- or coroutine-local; use instance clients or an explicit
request-scoped mechanism in those environments.
