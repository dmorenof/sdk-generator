# Release process

`VERSION` is the authoritative release request (`X.Y.Z`, without `v`). Composer versions come from immutable Git tags; do not add a version field to composer.json.

**Versions are not published piecemeal.** A version is published only when its line of work is finished and proven, and only when the owner confirms: one line of work, one release. Until then the work lives on its branch and its PR. Changing `VERSION` on `main` publishes, so that change is never merged without the owner's explicit confirmation. See [AGENTS.md](AGENTS.md).

Develop on a branch and open a PR; branches are not deleted unless the owner asks. The name of a branch is independent of `VERSION`.

1. Run Composer validation, appropriate tests/static analysis and advisory checks. Use cloud execution and remote GitHub state.
2. Update `VERSION`, versioned release notes and the actual supported scope. Open a PR and, only once the owner has confirmed the release, merge it into `main`.
3. The existing `.github/workflows/release.yml` runs on a `VERSION` change on `main`. It checks dependencies, PHP/tests and advisories, then creates the immutable tag and GitHub Release. Never bypass protections or force-update a tag.
4. Wait for the publish workflow and main CI; verify that the tag points to the integrated commit and the stable Release exists.
5. Verify a clean Composer installation and the appropriate consumer smoke. Update progress/release evidence; do not claim publication from green source checks alone.

For coordinated changes, publish and clean-install the runtime first, then replace temporary runtime branch dependencies with its stable version in the generator and generated packages before publishing the generator. The two repositories have separate versions and releases.

A GitHub release does not establish Packagist registration or availability. Keep VCS repository entries where needed. Do not commit secrets or use production credentials for synthetic regression fixtures.
