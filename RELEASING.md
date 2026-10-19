# Release process

**Versioning:** root VERSION is the authoritative release request. The package's composer.json does **not** contain a version field; Composer package versions are derived from Git tags.

## Releasing 0.1.0 and later

1. Integrate the reviewed implementation and documentation into a **protected** main branch through a PR with all required CI checks green.
2. Add or update the VERSION file in that PR, containing a single semver X.Y.Z without leading v (first version: **0.1.0**).
3. Merge the release PR. The existing GitHub Actions workflow .github/workflows/release.yml responds to the change in VERSION on main.
4. The workflow independently validates Composer, installs dependencies, runs PHP lint and PHPUnit, audits advisories, and checks that any tag vX.Y.Z either does not exist or points to this exact commit.
5. If the checks pass, GitHub Actions pushes an immutable tag and creates the GitHub Release with generated notes. Re-running must never move an existing tag.
6. Verify the **tag**, **GitHub Release** and linked **Actions success**. A green PR is **not** confirmation the release exists until publishing finishes.
7. Packagist is **not registered by this workflow**. If distribution via Packagist is desired, register dmorenof/sdk-generator and connect GitHub updates separately; a GitHub tag alone does not prove Packagist availability.

## Post-release smoke verification

The generator and its output are independent Composer packages. Generate Accounts from tests/fixtures/accounts-openapi31.json, install its Composer dependencies in the output directory and run it with the fake Guzzle handler in CI; do not use API credentials for regression fixtures.

## Scope

0.1.0 is a deliberately limited JSON Swagger/OpenAPI feature set. The supported subset, technical debt and absent pre-send semantic validation are explicitly documented in docs/SUPPORTED_SCOPE.md and CHANGELOG.md.

Do not claim published versions unless both immutable Git tag and release have been verified. Never force-update a release tag. No release of dmorenof/sdk-runtime is implied by a release of dmorenof/sdk-generator.
