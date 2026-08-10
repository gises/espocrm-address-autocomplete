#!/usr/bin/env bash
#
# Baut das installierbare Extension-Paket nach build/.
#
# Verwendung:  ./build.sh
# Ergebnis:    build/photon-address-autocomplete-<version>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/src"
OUT="$ROOT/build"

if [ ! -f "$SRC/manifest.json" ]; then
    echo "manifest.json nicht gefunden unter $SRC" >&2
    exit 1
fi

VERSION="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$SRC/manifest.json" 2>/dev/null \
    || node -e 'process.stdout.write(require(process.argv[1]).version)' "$SRC/manifest.json")"

if [ -z "$VERSION" ]; then
    echo "Version konnte nicht aus manifest.json gelesen werden." >&2
    exit 1
fi

NAME="photon-address-autocomplete-$VERSION"

rm -rf "$OUT"
mkdir -p "$OUT"

# Die ZIP muss manifest.json, files/ und scripts/ auf oberster Ebene tragen -
# der EspoCRM-Installer erwartet genau diese Struktur.
( cd "$SRC" && zip -rq "$OUT/$NAME.zip" manifest.json files scripts \
    -x '*.DS_Store' -x '__MACOSX/*' )

echo "gebaut: build/$NAME.zip"
