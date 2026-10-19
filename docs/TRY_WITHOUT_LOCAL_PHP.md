# Download and review generated SDKs without local PHP

GitHub Actions generates two PHP SDKs from **synthetic** specifications:
Accounts and Catalog. Each is installed by Composer on the runner, smoke-
tested with simulated GET/POST traffic and published as a ZIP artifact.

1. Open **Actions → Downloadable SDK previews**, or use the workflow run
   from PR #9 (the PR includes the new workflow).
2. Wait for **Preview accounts** and **Preview catalog** to pass.
3. Under **Artifacts**, download one of the ZIPs named
   `sdk-preview-accounts-php83` or
   `sdk-preview-catalog-php83`.
4. Inspect the PHP files and composer.json; no software installation is
   needed to download or read them.

The artifacts expire in 14 days. The workflow can be rerun manually after
its introduction is merged to main (via the **Run workflow** button).
PR runs are automatic when relevant generator inputs change.

This is an experimental PHP generator and these artifacts are **not
production SDK releases**. End-to-end tests use fake HTTP transports and
do not access actual accounts or APIs. Swagger 2.0 and OpenAPI 3.0/3.1
are tested on the supported subset, not as fully conformant generators.
