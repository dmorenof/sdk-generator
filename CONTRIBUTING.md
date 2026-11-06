# Contributing

Complete each validated change through integration and stable publication in the same task. Prefer direct integration into `main` when protections allow it; use a PR only if GitHub requires that technical step, and finish it rather than leaving a draft. See [AGENTS.md](AGENTS.md) and [RELEASING.md](RELEASING.md).

Continue development on the existing `release/0.2.0` branch; do not create a separate branch for each feature. Keep it aligned with published code. Its historical name does not restrict future version numbers or permit replacing existing tags.

Keep responsibilities separate and reuse abstractions. Public API changes need concrete use cases and meaningful regression coverage. Validate PHP 8.3/8.4/8.5, minimum dependencies and advisories through GitHub Actions.

## Checks

```sh
composer validate --strict --no-check-lock
composer install --no-interaction --prefer-dist
bash ci/run-tests.sh
composer audit --no-interaction
```

Review API stability and PHP minimum requirements before publication. Never move release tags or commit secrets.
