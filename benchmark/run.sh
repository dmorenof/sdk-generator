#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=7.26.0
JAR="${RUNNER_TEMP:-/tmp}/openapi-generator-cli-$VERSION.jar"
OUT=benchmark-output
mkdir -p "$OUT/logs" "$OUT/comparison"
if [[ ! -f "$JAR" ]]; then
  curl -fLsS --retry 3 --retry-delay 2 \
    "https://repo.maven.apache.org/maven2/org/openapitools/openapi-generator-cli/$VERSION/openapi-generator-cli-$VERSION.jar" -o "$JAR"
fi
[[ "$(java -jar "$JAR" version 2>/dev/null | tail -1)" == "$VERSION" ]] || { echo "Unexpected generator version" >&2; exit 1; }
echo 'api,implementation,trial,wall_seconds,user_seconds,sys_seconds,peak_rss_kib,php_files,php_source_bytes,php_source_lines,package_total_bytes,composer_require_count' > "$OUT/raw.csv"
measure() {
  local api="$1" impl="$2" trial="$3"
  local spec="tests/fixtures/$api-openapi30.json"
  local dest="$OUT/work/$api/$impl/$trial"
  local log="$OUT/logs/$api-$impl-$trial.log"
  local tim="$OUT/logs/$api-$impl-$trial.time"
  mkdir -p "$(dirname "$dest")"
  if [[ "$impl" == ours ]]; then
    /usr/bin/time -f '%e,%U,%S,%M' -o "$tim" php bin/generate-sdk.php "$spec" "$dest" \
      "Benchmark\${api}Sdk" "benchmark/$api-sdk" > "$log" 2>&1
  else
    /usr/bin/time -f '%e,%U,%S,%M' -o "$tim" java -Xms64m -Xmx1g -jar "$JAR" generate \
      -g php -i "$spec" -o "$dest" \
      --additional-properties="invokerPackage=OpenAPI\Client,composerPackageName=benchmark/$api-sdk,library=guzzle,modern=true" \
      --global-property='modelDocs=false,apiDocs=false,modelTests=false,apiTests=false' > "$log" 2>&1
  fi
  [[ -f "$dest/composer.json" ]] || { cat "$log"; exit 1; }
  for dir in "$dest/src" "$dest/lib"; do
    if [[ -d "$dir" ]]; then
      find "$dir" -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l >/dev/null
    fi
  done
  if [[ "$trial" == 1 ]]; then
    mkdir -p "$OUT/comparison/$api"
    cp -a "$dest" "$OUT/comparison/$api/$impl"
  fi
  python3 benchmark/collect.py "$api" "$impl" "$trial" "$dest" "$tim" >> "$OUT/raw.csv"
}
for trial in 1 2 3; do
  for api in accounts catalog; do
    if (( trial % 2 )); then
      measure "$api" ours "$trial"
      measure "$api" openapi "$trial"
    else
      measure "$api" openapi "$trial"
      measure "$api" ours "$trial"
    fi
  done
done
python3 benchmark/report.py "$OUT/raw.csv" "$OUT/REPORT.md"
cat "$OUT/REPORT.md"
