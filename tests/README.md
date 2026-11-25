# Testing

The suite has four kinds of test. All but the last run on every pull request (`bash ci/run-tests.sh`).

| What | Where | What it proves |
| --- | --- | --- |
| Generation tests | `tests/*Test.php` (PHPUnit, about 690 tests) | A contract in `tests/fixtures/` generates the expected code, and the repairs and refusals are the documented ones. Named after the feature (`FormGenerationTest`, `RepairedDefectsTest`, ...). |
| Dialect variants | `tests/DialectVariantsTest.php` | Every OpenAPI 3.1 fixture, with only its version changed to 3.2, gives the same SDK; as 3.0 it gives the same or refuses the one construct only 3.1 has (listed in the test). It covers a dialect without a fixture per feature. |
| Dialect equivalence | `tests/SwaggerEquivalenceTest.php` | The same API written in Swagger 2.0 and in OpenAPI 3.1 (accounts, catalog, query arrays, delimited queries, simple parameters, status responses) gives the same SDK, file by file. The other Swagger fixtures are written differently on purpose and have their own tests. |
| Specification fixtures | `tests/fixtures/` | The same API written as Swagger 2.0, OpenAPI 3.0 and OpenAPI 3.1 (`accounts-*`, `catalog-*`, `casafari-*`), plus one document per feature. |
| Smoke scripts | `tests/*-smoke.php`, `tests/*-http-server.php` | A generated SDK is run against a local server and the bytes on the wire are checked (query styles, forms, multipart, cookies, ...). |
| Live scripts | `tests/live/` | A generated SDK calls a real public API (Petstore, Frankfurter, httpbin, FakeRESTApi). They run only in the `Live public APIs` workflow, weekly or by hand, and never gate a merge: those servers are not ours. |

## Adding a feature

1. Add or extend a fixture in `tests/fixtures/` that states the case in the smallest contract that shows it.
2. Add a `*GenerationTest.php` that generates from it and asserts the code and the diagnostics; if the feature changes bytes on the wire, add a smoke script that runs the generated SDK.
3. If the generator repairs a defect of the document, add the case to `RepairedDefectsTest` and make sure `--strict` refuses it.

`ci/run-tests.sh` also lints every PHP file, checks that the autoloader is strict PSR-4 and fails if `src/` has code and `tests/` has no `*Test.php`.
