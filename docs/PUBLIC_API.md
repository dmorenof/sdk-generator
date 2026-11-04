# Public API — deterministic route namespaces (0.2.0)

The URL defines PHP namespaces; the HTTP method defines the PHP method name.
There is no separate group-name inference or hash suffix in API/parameter names.

## Mapping

| Contract endpoint | PHP facade |
| --- | --- |
| GET /users | NombreSDK\Users::get() |
| GET /users/{id} | NombreSDK\Users::getById(int $id) |
| DELETE /users/{id} | NombreSDK\Users::deleteById(int $id) |
| GET /v1/facturas with filters | NombreSDK\V1\Facturas::get(?GetParams\V1\Facturas $params = null) |
| POST /v1/facturas with an existing body schema | NombreSDK\V1\Facturas::post(DTOs\Factura $payload) |
| POST /v1/facturas with query and body | NombreSDK\V1\Facturas::post(PostParams\V1\Facturas $params) |
| GET /users/{id}/orders | NombreSDK\Users\ById\Orders::get(int $id) |
| GET /users/{id}/orders/{order_id} | NombreSDK\Users\ById\Orders::getByOrderId(PathParams\Users\ById\Orders\ByOrderId $identifier) |

PHP classes inside GetParams, PostParams, PutParams and DeleteParams mirror
the full literal route tree, including ById for terminal identifiers.
For operations with no query, header or other parameters, those classes are
omitted. More than one path identifier is represented by a PathParams DTO.

Each operation has at most two public arguments: an identifier and
a typed parameter/body object. An operation without inputs has no arguments.

## Source structure

For the sample API:

~~~text
src/
  ExampleAccountsSDK.php           abstract SDK root named from info.title
  Client.php                       reusable connection, shared executor
  Configuration.php
  Users.php                        static facade: getById, post
  Resources/Users.php              instance methods for independent connections
  GetParams/Users/ById.php         optional GET /users/{id} query/header filters
  DTOs/NewUser.php                 referenced OpenAPI body schema
  DTOs/User.php                    referenced OpenAPI response schema
  Collections/...                  runtime TypedArray subclasses
  Enums/...                        typed backed enums
~~~

Each URL literal becomes one PascalCase directory/class segment.
An intermediate URL placeholder becomes By{placeholder} in the namespace;
a terminal placeholder becomes By{placeholder} in the function name.
Existing named OpenAPI schemas preserve their declared or inferred model
hierarchy under DTOs, rather than being duplicated per operation.

The generated static facade delegates to its corresponding instance
Resource; the Resource extends generated Client and delegates to
sdk-runtime for validation, middleware, authentication, HTTP execution,
error handling and response hydration.

## Example

~~~php
use Example\AccountsSdk\Configuration;
use Example\AccountsSdk\ExampleAccountsSDK;
use Example\AccountsSdk\Users;
use Example\AccountsSdk\GetParams\Users\ById as UserFilters;
use Example\AccountsSdk\DTOs\NewUser;

$config = new Configuration();
$config->baseUrl = 'https://api.example.test/v1';
ExampleAccountsSDK::configure($config);

$params = new UserFilters();
$params->verbose = false;
$user = Users::getById(88, $params);

$body = new NewUser();
$body->name = 'Grace';
$created = Users::post($body);
~~~

With several active connections, construct independent Client objects and
use Client::resource(Resources\Users::class)->getById(...) instead.

## Collision policy

Reserved generated infrastructure class names cannot be used by endpoint
classes. Routes that transliterate to identical case-insensitive PHP class
paths or method names raise explicit generation errors. We do not silently
append numeric suffixes or hashes to hide a collision. DTO class names are
owned by their source schema; the route is not permitted to rename a named
schema referred to elsewhere in the document.

This naming policy is tested against the 46 Casafari operations and the
Accounts and Catalog fixtures (Swagger 2.0, OpenAPI 3.0 and 3.1).
