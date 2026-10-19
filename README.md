# SDK Generator

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
There are no behavior tests yet. Add a test framework, real tests, and an obligatory CI test job with the first implementation.

## GitHub Flow

Create short-lived `feature/*`, `fix/*`, or `chore/*` branches from `main`.
Open a pull request back to `main`, keep it up to date, resolve review conversations,
and wait for required CI checks. No external approving reviewer is required.
Merge commits remain allowed. Do not use a permanent `develop` branch.

CI validates Composer metadata, installation, autoload generation, and the syntax of PHP files in `src/` and `tests/` when present.
Passing these checks on this scaffold does not imply functional test coverage.

Publication is a separate, explicit step using a reviewed version tag. This workflow does not publish packages.

## License

MIT; see [LICENSE](LICENSE).
