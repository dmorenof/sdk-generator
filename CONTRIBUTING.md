# Contributing

Develop on a branch and open a PR. A version is published only when its work is finished and proven and the owner confirms; versions are not published piecemeal, and a branch is not deleted unless the owner asks. See [AGENTS.md](AGENTS.md) and [RELEASING.md](RELEASING.md).

Never replace or move an existing tag; the name of a branch does not restrict version numbers.

Keep responsibilities separate and reuse abstractions. Public API changes need concrete use cases and meaningful regression coverage. Validate PHP 8.3/8.4/8.5, minimum dependencies and advisories through GitHub Actions.

## Checks

```sh
composer validate --strict --no-check-lock
composer install --no-interaction --prefer-dist
bash ci/run-tests.sh
composer audit --no-interaction
```

Review API stability and PHP minimum requirements before publication. Never move release tags or commit secrets.
