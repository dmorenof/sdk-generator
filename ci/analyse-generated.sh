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

analyse forms forms-openapi31.json 'Example\FormSdk'

analyse swagger-forms forms-swagger2.json 'Example\SwaggerFormSdk'

analyse multipart multipart-openapi31.json 'Example\MultipartSdk'

analyse swagger-multipart multipart-swagger2.json 'Example\MultipartSdk'

analyse file-lists multipart-file-lists-openapi31.json 'Example\MultipartSdk'

php -d memory_limit=16M tests/streaming-smoke.php "$OUT/multipart"
php -d memory_limit=16M tests/streaming-smoke.php "$OUT/swagger-multipart"
php -d memory_limit=16M tests/streaming-smoke.php "$OUT/file-lists" '' list

analyse downloads downloads-openapi31.json 'Example\DownloadSdk'
php -d memory_limit=16M tests/download-smoke.php "$OUT/downloads"

analyse swagger-downloads downloads-swagger2.json 'Example\DownloadSdk'
php -d memory_limit=16M tests/download-smoke.php "$OUT/swagger-downloads"

analyse status-responses status-responses-openapi31.json 'Example\StatusSdk'
php tests/status-response-smoke.php "$OUT/status-responses"

analyse simple-parameters simple-parameters-openapi31.json 'Example\SimpleSdk'
analyse swagger-simple-parameters simple-parameters-swagger2.json 'Example\SimpleSdk'
php tests/simple-parameters-smoke.php "$OUT/simple-parameters"
php tests/simple-parameters-smoke.php "$OUT/swagger-simple-parameters"

analyse object-parameters object-parameters-openapi31.json 'Example\ObjectSdk'
php tests/object-parameters-smoke.php "$OUT/object-parameters"

analyse path-styles path-styles-openapi31.json 'Example\PathSdk'
php tests/path-styles-smoke.php "$OUT/path-styles"

analyse cookies cookies-openapi31.json 'Example\CookieSdk'
php tests/cookies-smoke.php "$OUT/cookies"

analyse parameter-overrides parameter-overrides-openapi31.json 'Example\OverrideSdk'
php tests/parameter-overrides-smoke.php "$OUT/parameter-overrides"

analyse reserved-query reserved-query-openapi31.json 'Example\ReservedSdk'
php tests/reserved-query-smoke.php "$OUT/reserved-query"

analyse empty-values empty-values-openapi30.json 'Example\EmptySdk'
analyse swagger-empty-values empty-values-swagger2.json 'Example\EmptySdk'
php tests/empty-values-smoke.php "$OUT/empty-values"
php tests/empty-values-smoke.php "$OUT/swagger-empty-values"

analyse parameter-content parameter-content-openapi31.json 'Example\ContentSdk'
php tests/parameter-content-smoke.php "$OUT/parameter-content"

analyse delimited-parameters delimited-parameters-swagger2.json 'Example\DelimitedSdk'
php tests/delimited-parameters-smoke.php "$OUT/delimited-parameters"

analyse response-selection response-selection-openapi31.json 'Example\SelectionSdk'
php tests/response-selection-smoke.php "$OUT/response-selection"

analyse form-block form-block-openapi31.json 'Example\FormBlockSdk'
analyse swagger-form-block form-block-swagger2.json 'Example\SwaggerFormBlockSdk'
php tests/form-block-smoke.php "$OUT/form-block" "$OUT/swagger-form-block"

analyse schema-rules schema-rules-openapi31.json 'Example\SchemaRulesSdk'
php tests/schema-rules-smoke.php "$OUT/schema-rules"

analyse native-schemas native-schemas-openapi31.json 'Example\NativeSdk'
php tests/native-schemas-smoke.php "$OUT/native-schemas"

analyse request-bodies request-bodies-openapi31.json 'Example\BodySdk'
php tests/request-bodies-smoke.php "$OUT/request-bodies"

analyse raw-media raw-media-openapi31.json 'Example\RawSdk'
php -d memory_limit=16M tests/raw-media-smoke.php "$OUT/raw-media"

analyse schema-aliases schema-aliases-openapi31.json 'Example\AliasSdk'
php tests/schema-aliases-smoke.php "$OUT/schema-aliases"

analyse error-payloads error-payloads-openapi31.json 'Example\ErrorPayloadSdk'
php tests/error-payloads-smoke.php "$OUT/error-payloads"

analyse mime-media mime-media-openapi31.json 'Example\MimeMediaSdk'
php -d memory_limit=16M tests/mime-media-smoke.php "$OUT/mime-media"

analyse server-routes server-routes-openapi31.json 'Example\ServerRoutesSdk' resolve-local
php tests/server-routes-smoke.php "$OUT/server-routes"
