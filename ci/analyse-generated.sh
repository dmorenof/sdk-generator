#!/usr/bin/env bash
set -euo pipefail

# Generate SDKs from the shipped fixtures, install them like a user would and
# analyse the result at PHPStan's strictest level. Generated code must be as
# well typed as hand-written code; any error here is a generator or runtime bug.
cd "$(dirname "$0")/.."
OUT="${1:-build/analysis}"
rm -rf "$OUT"

analyse() {
  local name="$1" spec="$2" namespace="$3"
  echo "== $name"
  php bin/generate-sdk.php "tests/fixtures/$spec" "$OUT/$name" "$namespace" "example/$name" > /dev/null
  composer install --working-dir="$OUT/$name" --no-interaction --prefer-dist --no-progress --quiet
  vendor/bin/phpstan analyse "$OUT/$name/src" --level=max \
    --autoload-file="$OUT/$name/vendor/autoload.php" --no-progress --memory-limit=2G
}

analyse accounts accounts-openapi31.json 'Example\AccountsSdk'
analyse catalog catalog-openapi31.json 'Example\CatalogSdk'
analyse casafari casafari-openapi31.json 'Example\CasafariSdk'
