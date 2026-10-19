# Releases

This repository currently contains only project scaffolding. **No package
version is published and no Git tag should be created until real, tested PHP
code exists.**

## First release (when implementation is ready)

1. Implement the first meaningful feature under `src/` with PHPUnit
   `*Test.php` tests in `tests/`.
2. Ensure all GitHub Actions checks pass on a PR to the protected `main`
   branch. In particular, the `test` job must execute genuine behavioral
   tests rather than the scaffold-only validation path.
3. Include a new root `VERSION` file containing `0.1.0` (without leading
   `v`) in the approved release PR. There is **no VERSION file now**.
4. Merge the PR. `.github/workflows/release.yml` runs Composer validation,
   dependency installation, syntax checks, PHPUnit and security audit.
5. If those checks pass, the workflow creates immutable tag `v0.1.0` at
   the merge commit and publishes a GitHub Release.
6. Register the package in Packagist (once) and configure repository
   synchronization. Packagist gets its version from the Git tag; never
   write a `version` field in `composer.json`.
7. Confirm clean installation with Composer and publish only verified claims
   about supported features.

For future versions, change `VERSION` in a PR and repeat. Existing tags
are never force-moved. A duplicate version pointing to a different commit
causes the release workflow to fail safely.

This is package release automation, not the SDK code generation engine.
