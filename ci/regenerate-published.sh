#!/usr/bin/env bash
set -euo pipefail

# Regenerates the published SDKs from the contract each one carries and compares the result with the code it publishes.
# A change to the generator that is meant to leave the output alone (a refactor) must leave every SDK identical; a change that is
# meant to alter it shows here which SDKs it alters and how.
#
# usage: ci/regenerate-published.sh [sdk-name ...]     (default: all of them)
# needs: php 8.4, `composer install` done, git and network access to github.com/dmorenof

owner="${SDK_OWNER:-dmorenof}"
# name[:extra generator flags]
all=(
  trustpilot-sdk catastro-sdk floorfy-sdk neutrinoapi-sdk aplazame-sdk restb-sdk deepl-sdk sendbird-sdk
  'uda-sdk:--server=https://api.urbandataanalytics.com'
  gocardless-sdk mailjet-sdk marketo-sdk infobip-sdk sendgrid-sdk stripe-sdk
)

selected=("$@")
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
root="$(cd "$(dirname "$0")/.." && pwd)"
failed=0

for entry in "${all[@]}"; do
  name="${entry%%:*}"
  flags=""
  [[ "$entry" == *:* ]] && flags="${entry#*:}"
  if [[ ${#selected[@]} -gt 0 ]] && [[ ! " ${selected[*]} " =~ " $name " ]]; then
    continue
  fi
  git clone --quiet --depth 1 "https://github.com/$owner/$name.git" "$work/$name"
  contract="$(ls "$work/$name"/contract/* | head -n1)"
  namespace="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo rtrim((string) array_key_first($c["autoload"]["psr-4"]), "\\");' "$work/$name/composer.json")"
  package="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo $c["name"];' "$work/$name/composer.json")"
  # shellcheck disable=SC2086
  php -d memory_limit=-1 "$root/bin/generate-sdk.php" $flags "$contract" "$work/out/$name" "$namespace" "$package" > "$work/$name.log" 2>&1 || { echo "FAIL $name: generation stopped"; tail -5 "$work/$name.log"; failed=1; continue; }
  if diff -rq "$work/out/$name/src" "$work/$name/src" > "$work/$name.diff"; then
    echo "same $name ($(find "$work/out/$name/src" -name '*.php' | wc -l) files)"
  else
    echo "DIFFERENT $name: $(wc -l < "$work/$name.diff") files differ"
    head -5 "$work/$name.diff"
    failed=1
  fi
done

exit "$failed"
