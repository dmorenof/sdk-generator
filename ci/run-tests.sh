#!/usr/bin/env bash
set -euo pipefail

# A new repository has no behavior to test yet. Never report invented coverage.
find src tests -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l
composer dump-autoload --optimize --strict-psr --strict-ambiguous

if ! find src -type f -name '*.php' -print -quit | grep -q .; then
  echo "SCAFFOLD ONLY: no production PHP code; no behavioral tests or coverage claimed."
  exit 0
fi

if ! find tests -type f -name '*Test.php' -print -quit | grep -q .; then
  echo "Production PHP code exists but no PHPUnit *Test.php tests were found." >&2
  exit 1
fi

if [[ "${1:-}" == "--coverage" ]]; then
  mkdir -p coverage
  vendor/bin/phpunit -c phpunit.xml --coverage-text --coverage-clover coverage/clover.xml
else
  vendor/bin/phpunit -c phpunit.xml
fi
