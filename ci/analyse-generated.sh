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
  local -a options=()
  if [[ "${4:-}" == 'resolve-local' ]]; then
    options=(--resolve-local-references)
  fi
  echo "== $name"
  php bin/generate-sdk.php "${options[@]}" "tests/fixtures/$spec" "$OUT/$name" "$namespace" "example/$name" > /dev/null
  composer install --working-dir="$OUT/$name" --no-interaction --prefer-dist --no-progress --quiet
  vendor/bin/phpstan analyse "$OUT/$name/src" --level=max \
    --autoload-file="$OUT/$name/vendor/autoload.php" --no-progress --memory-limit=2G
}

analyse accounts accounts-openapi31.json 'Example\AccountsSdk'
analyse catalog catalog-openapi31.json 'Example\CatalogSdk'
analyse casafari casafari-openapi31.json 'Example\CasafariSdk'

analyse pets pets-discriminator-openapi30.json 'Example\PetsSdk'

analyse operation-servers operation-servers-openapi30.json 'Example\OperationServersSdk'

analyse referenced-accounts accounts-references-openapi31.json 'Example\ReferencedAccountsSdk'

analyse path-item-accounts accounts-path-items-openapi31.json 'Example\PathItemAccountsSdk'

analyse multifile-accounts multifile-accounts/api.json 'Example\MultifileAccountsSdk' resolve-local

analyse security-alias-accounts accounts-security-references-openapi31.json 'Example\SecurityAliasAccountsSdk'

analyse query-arrays query-arrays-openapi31.json 'Example\QueryArraySdk'

analyse delimited-query query-delimited-openapi31.json 'Example\DelimitedQuerySdk'

analyse query-objects query-objects-openapi31.json 'Example\QueryObjectSdk'
