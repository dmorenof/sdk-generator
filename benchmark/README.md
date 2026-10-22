# First controlled comparison with OpenAPI Generator

Current DTO runtime measurements for the instance-client refactor are in [DTO_REFACTOR.md](DTO_REFACTOR.md), with reproducible probes and raw CPU/memory results. The historical comparisons below do not establish the performance of the current output.

Do not infer superior runtime efficiency from shorter source files.
The benchmark compares **generation only** first.

The GitHub Actions workflow Compare with OpenAPI Generator downloads the
**official pinned 7.26.0 CLI JAR** from Maven Central. The upstream target
is PHP with Guzzle and modern language mode; no custom templates are
used. Our generator is run with PHP 8.3. Both receive the **exact same
OpenAPI 3.0 JSON file** for each of two synthetic APIs (Accounts, Catalog).

Three clean-process generations per API and tool run on the same Ubuntu
runner with alternating order. The resulting artifact contains:
- Median wall-clock seconds including process startup.
- Peak process memory (maximum RSS) for *the generator*, not the SDK.
- PHP production source size, number of files/lines, full generated
  tree size (without installed dependencies) and direct Composer
  requirements.
- Raw observations, complete logs, and code output from both projects.

A later phase MUST install the outputs separately and execute equivalent
request/response cases with *both* SDKs to compare actual PHP runtime CPU,
peak memory, and cost of use. OpenAPI Generator produces a Guzzle-backed
transport; our SDK currently exposes an injectable interface with no
production transport implementation. This is a significant functional
difference and has to be counted when measuring usability and integration
effort. We should also compare coverage of real specifications before
presenting any overall winner.

**No local software is required**: open the workflow run in GitHub,
download the Actions artifact and inspect REPORT.md.

The baseline is exploratory: it uses synthetic specifications and only
three repeats on a shared runner. Published release-quality numbers need
wider fixtures, repeatability and equivalent functionality.
