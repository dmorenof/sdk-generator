# Integration and UX comparison — scenarios and evidence

Compare the **same Accounts or Catalog OpenAPI 3.0** against OpenAPI Generator 7.26.0 PHP Guzzle and our PR #11 PSR-18/Guzzle emitted SDK. These are deliberately small *synthetic* contracts and do not prove full OpenAPI parity.

## Scenario A: basic GET with no custom HTTP setup

Our generated package after Composer installation:

```php
$api = \Benchmark\AccountsSdk\Api\ApiClient::connect('https://api.example.test/v1');
$user = $api->getUser(88);
```

OpenAPI Generator PHP/Guzzle (verify default host and generated `DefaultApi` class on actual output):

```php
$config = \OpenAPI\Client\Configuration::getDefaultConfiguration()
    ->setHost('https://api.example.test/v1');
$api = new \OpenAPI\Client\Api\DefaultApi(null, $config);
$user = $api->getUser(88);
```

These snippets are **not yet an objective ease-of-use score**. Both require the generated package to be installed and loaded. Calling with a default host can require fewer lines in both. API class layout and naming differences should be judged with user feedback.

## Scenario B: request/response middleware

Both clients accept a configured Guzzle instance, so a standard `HandlerStack` with Guzzle `Middleware::mapRequest` or `Middleware::mapResponse` is reusable. Our API uses `ApiClient::connect($url, $httpClient)`; upstream uses `new DefaultApi($httpClient, $configuration)`.

## Scenario C: request authentication

The source synthetic specs have **no declared security scheme**. Our current generator does *not* generate automatic auth. It accepts an optional header map; OpenAPI Generator has a configuration/auth implementation for declared schemes. This is a **feature gap** for our project, not an efficiency win. Benchmark auth separately when supported.

## Scenario D: error handling and schemas

The experimental SDK throws for an unexpected HTTP status and rejects mismatched scalar DTO types. OpenAPI Generator has richer error classes, models, status and specification coverage. This is another capability difference that must be measured rather than inferred from lines of code.

## What the runtime benchmark does

- Installs each generated package independently with Composer.
- Uses a Guzzle Client with exactly the same in-memory PSR-7 mock handler and successful JSON response for both.
- Asserts that returned typed object exposes the expected ID and name, and that requested HTTP method/path matches.
- Repeats 1000 GET+DTO calls in each of five separate processes per API and implementation; startup and memory are separate observations.

## What it does not prove

- Complete functional parity; security schemes, complex schemas, general response statuses and different transports remain.
- Usability ranking. Count concrete setup steps and user actions, solicit users, then evaluate intuitive APIs.
- Production throughput or network performance; no real network traffic.

This is an experiment-only PR. Do not merge or publish a release based solely on microbenchmarks.
