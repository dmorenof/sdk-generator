# SDK Generator

[![PHP package CI](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-generator/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Initial scaffold for `dmorenof/sdk-generator`, a PHP 8.3+ library.

No generation engine, CLI, schema format, or dependency on SDK Runtime is implemented yet.
Those architecture decisions belong in subsequent implementation pull requests.
No release has been published by this setup.

## Development

Use PHP 8.3+ and Composer 2:

```sh
composer install
composer validate --strict --no-check-lock
composer dump-autoload --optimize --strict-psr --strict-ambiguous
```

Source code belongs in `src/` under `Dmorenof\SdkGenerator\`; tests belong in `tests/`.
There are no behavior tests yet. PHPUnit and the `test` job are configured, but the job explicitly reports scaffold-only checks until production code is added. The first implementation PR must include real tests, or CI will fail.

## GitHub Flow

Create short-lived `feature/*`, `fix/*`, or `chore/*` branches from `main`.
Open a pull request back to `main`, keep it up to date, resolve review conversations,
and wait for required CI checks. No external approving reviewer is required.
Merge commits remain allowed. Do not use a permanent `develop` branch.

CI validates Composer metadata, PHP 8.3–8.5, minimum compatible dependencies, security advisories, PSR-4 and PHP syntax. It will execute PHPUnit when there is real code. No functional coverage is claimed for this scaffold.

Future releases are automated: once real code and tests exist, introduce `VERSION` in a reviewed PR. GitHub Actions verifies the package and publishes an immutable tag and release. **No tag or release is created for this scaffolding change.**

## License

MIT; see [LICENSE](LICENSE).

## Quality and releases

Use `bash ci/run-tests.sh` locally. CI reports explicitly when it has no
behavior tests; once `src/` contains PHP, missing `*Test.php` tests fail CI.
See [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md)
for the quality gates and versioning workflow.
