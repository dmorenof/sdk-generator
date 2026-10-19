#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
OUT=runtime-compare-output
mkdir -p "$OUT/logs" "$OUT/clients"
JAR="${RUNNER_TEMP:-/tmp}/openapi-generator-cli-7.26.0.jar"
if [[ ! -f "$JAR" ]]; then
  curl -fL --retry 3 --retry-delay 2 -sS "https://repo.maven.apache.org/maven2/org/openapitools/openapi-generator-cli/7.26.0/openapi-generator-cli-7.26.0.jar" -o "$JAR"
fi
java -jar "$JAR" version | grep -q '^7.26.0$'
for api in accounts catalog; do
  spec="tests/fixtures/$api-openapi30.json"
  php bin/generate-sdk.php "$spec" "$OUT/clients/$api/ours" "Benchmark\\${api^}Sdk" "benchmark/$api-sdk"
  java -Xms64m -Xmx1g -jar "$JAR" generate -i "$spec" -g php -o "$OUT/clients/$api/openapi" \
    --additional-properties="invokerPackage=OpenAPI\\Client,composerPackageName=benchmark/$api-sdk,library=guzzle,modern=true" \
    --global-property='modelDocs=false,apiDocs=false,modelTests=false,apiTests=false' >"$OUT/logs/generate-$api.log" 2>&1
  for impl in ours openapi; do
    pkg="$OUT/clients/$api/$impl"
    (cd "$pkg" && composer install --no-interaction --prefer-dist --no-progress --no-ansi)
    composer validate --working-dir="$pkg" --no-check-lock
    du -s -k "$pkg/vendor" | awk -v a="$api" -v m="$impl" '{printf "VENDOR_KiB %s %s %d\n",a,m,$1}' | tee -a "$OUT/logs/installed-size.log"
    (cd "$pkg" && composer show -i --no-ansi --no-interaction | wc -l) | awk -v a="$api" -v m="$impl" '{printf "INSTALLED_PACKAGES %s %s %d\n",a,m,$1}' | tee -a "$OUT/logs/installed-size.log"
  done
done
echo 'api,implementation,trial,wall_seconds,peak_rss_kib,startup_ms,ops_ms,us_per_request,php_peak_mib' > "$OUT/observations.csv"
for trial in 1 2 3 4 5; do
  for api in accounts catalog; do
    if ((trial % 2)); then implementations=(ours openapi); else implementations=(openapi ours); fi
    for impl in "${implementations[@]}"; do
      path="$OUT/clients/$api/$impl"
      timefile="$OUT/logs/$api-$impl-$trial.time"
      jsonfile="$OUT/logs/$api-$impl-$trial.json"
      echo "BENCHMARK $api $impl trial $trial"
      /usr/bin/time -f '%e,%M' -o "$timefile" php benchmark/runtime-probe.php "$path" "$impl" "$api" 1000 > "$jsonfile"
      python3 benchmark/runtime-report.py row "$jsonfile" "$timefile" "$trial" >> "$OUT/observations.csv"
    done
  done
done
python3 benchmark/runtime-report.py report "$OUT/observations.csv" "$OUT/logs/installed-size.log" "$OUT/REPORT.md"
cat "$OUT/REPORT.md"
