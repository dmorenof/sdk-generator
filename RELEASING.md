# Release process

`VERSION` is the authoritative release request (`X.Y.Z`, without `v`). Composer versions come from immutable Git tags; do not add a version field to composer.json.

The agreed workflow is to publish each completed, validated increment. Do not stop at a draft PR or wait for the entire roadmap. See [AGENTS.md](AGENTS.md).

1. Run Composer validation, appropriate tests/static analysis and advisory checks. Use cloud execution and remote GitHub state.
2. Update `VERSION`, versioned release notes and the actual supported scope. Integrate into `main` directly when protections permit it. If GitHub requires a PR, complete that technical step and continue through publication in the same task.
3. The existing `.github/workflows/release.yml` runs on a `VERSION` change on `main`. It checks dependencies, PHP/tests and advisories, then creates the immutable tag and GitHub Release. Never bypass protections or force-update a tag.
4. Wait for the publish workflow and main CI; verify that the tag points to the integrated commit and the stable Release exists.
5. Verify a clean Composer installation and the appropriate consumer smoke. Update progress/release evidence; do not claim publication from green source checks alone.

For coordinated changes, publish and clean-install the runtime first, then replace temporary runtime branch dependencies with its stable version in the generator and generated packages before publishing the generator. The two repositories have separate versions and releases.

A GitHub release does not establish Packagist registration or availability. Keep VCS repository entries where needed. Do not commit secrets or use production credentials for synthetic regression fixtures.
