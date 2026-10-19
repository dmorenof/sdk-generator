# Contributing

Use short-lived feature/fix branches based on `main`, open a PR, and let
GitHub Actions validate Composer, PHP 8.3/8.4/8.5, minimum dependencies and
security advisories. Never push directly to the protected `main` branch.

Keep responsibilities separate and reuse abstractions rather than creating
near-duplicates. Avoid introducing new public interfaces without a concrete
use case and tests.

## Local checks

```sh
composer validate --strict --no-check-lock
composer install --no-interaction --prefer-dist
bash ci/run-tests.sh
composer audit --no-interaction
```

No behavioral tests are currently present because implementation has not
started. The first functional contribution must include PHPUnit tests
and execute them through the common `ci/run-tests.sh` command.

Before merging a release PR, review API stability and PHP minimum requirements.
See [RELEASING.md](RELEASING.md). Do not commit secrets.
