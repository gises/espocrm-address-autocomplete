#!/usr/bin/env bash
#
# Alle Pruefungen: Syntax (PHP, JS, JSON) und die Mapping-Tests.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "== PHP-Lint =="
find "$ROOT/src" -name '*.php' -print0 | xargs -0 -n1 php -l

echo
echo "== JS-Syntax =="
find "$ROOT/src" "$ROOT/contrib" -name '*.js' -print0 | xargs -0 -n1 node --check
echo "ok"

echo
echo "== JSON =="
find "$ROOT/src" -name '*.json' -print0 | xargs -0 -n1 -I{} node -e 'JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"))' {}
echo "ok"

echo
echo "== Mapping-Tests =="
php "$ROOT/tests/mapper.test.php"
node "$ROOT/tests/mapping.test.js"
