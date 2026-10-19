# Testing

The initial repository is a **scaffold**, not a working SDK package.
No production PHP classes or behavioral tests exist, so the CI test job
currently performs syntax, dependency and PSR-4 checks only.

The `ci/run-tests.sh` script explicitly reports this limitation.
**As soon as production PHP files are added to `src/`, a PHPUnit
`*Test.php` test is mandatory**; without one the CI fails.

Future tests should cover actual contracts, error handling and edge cases.
Coverage reports are generated with PCOV only once real tests exist.
