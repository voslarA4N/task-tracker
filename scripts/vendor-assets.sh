#!/bin/sh
# Stiahne frontend kniznice (len npm pack, bez install skriptov) do public/assets/vendor
# s verziou v nazve. Idempotentny. Pozri CLAUDE.md (ziadny CDN, ziadny build).
set -eu

HTMX=2.0.11
ALPINE=3.17.5
BOOTSTRAP=5.3.8

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT="$ROOT/public/assets/vendor"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$OUT/LICENSES" "$TMP/h" "$TMP/a" "$TMP/b"

(cd "$TMP" && npm pack "htmx.org@$HTMX" "@alpinejs/csp@$ALPINE" "bootstrap@$BOOTSTRAP" --silent >/dev/null)

tar -xzf "$TMP/htmx.org-$HTMX.tgz" -C "$TMP/h"
tar -xzf "$TMP/alpinejs-csp-$ALPINE.tgz" -C "$TMP/a"
tar -xzf "$TMP/bootstrap-$BOOTSTRAP.tgz" -C "$TMP/b"

strip_map() { sed -e '/sourceMappingURL/d' "$1" > "$2"; }

strip_map "$TMP/h/package/dist/htmx.min.js" "$OUT/htmx-$HTMX.min.js"
strip_map "$TMP/a/package/dist/cdn.min.js" "$OUT/alpine-csp-$ALPINE.min.js"
strip_map "$TMP/b/package/dist/css/bootstrap.min.css" "$OUT/bootstrap-$BOOTSTRAP.min.css"
strip_map "$TMP/b/package/dist/js/bootstrap.bundle.min.js" "$OUT/bootstrap.bundle-$BOOTSTRAP.min.js"

cp "$TMP/h/package/LICENSE" "$OUT/LICENSES/htmx.txt"
# balik @alpinejs/csp neobsahuje subor LICENSE; licencia MIT je uvedena v package.json
if [ -f "$TMP/a/package/LICENSE.md" ]; then cp "$TMP/a/package/LICENSE.md" "$OUT/LICENSES/alpinejs.txt"; else
  printf 'Alpine.js (@alpinejs/csp %s)\nLicense: %s (podla package.json balika)\nCopyright (c) Caleb Porzio and contributors\n' "$ALPINE" "$(sed -n 's/.*"license": *"\([^"]*\)".*/\1/p' "$TMP/a/package/package.json")" > "$OUT/LICENSES/alpinejs.txt"
fi
cp "$TMP/b/package/LICENSE" "$OUT/LICENSES/bootstrap.txt"

(cd "$OUT" && sha256sum *.js *.css > SHA256SUMS)
echo "vendor assets OK"
