#!/bin/sh
# Kontrola driftu rozsahov Cloudflare voci docker/nginx/http.d/cf-peer.conf.
# Bez prepinaca: porovna a pri rozdiele exit 1. S --write: prepise snippet.
# shellcheck disable=SC2016
set -eu
cd "$(dirname "$0")/.."

TARGET=docker/nginx/http.d/cf-peer.conf
TMP=$(mktemp)
trap 'rm -f "$TMP" "$TMP.new" "$TMP.old"' EXIT

V4=$(curl -fsS https://www.cloudflare.com/ips-v4)
V6=$(curl -fsS https://www.cloudflare.com/ips-v6)

{
  echo "# Generované scripts/cf-ips-check.sh --write, $(date -u +%Y-%m-%d)"
  echo "# Zdroj: https://www.cloudflare.com/ips-v4 a https://www.cloudflare.com/ips-v6"
  echo "# Povoli CF-Connecting-IP iba ak PROXY-protocol zdroj (edge preposiela peera) je rozsah Cloudflare."
  echo 'geo $proxy_protocol_addr $cf_peer {'
  echo "    default 0;"
  printf '%s\n%s\n' "$V4" "$V6" | grep -v '^$' | tr -d '\r' | sort | while read -r r; do
    echo "    $r 1;"
  done
  echo "}"
} > "$TMP"

if [ "${1:-}" = "--write" ]; then
  cp "$TMP" "$TARGET"
  echo "Zapísané $TARGET"
  exit 0
fi

grep -v '^# Generované' "$TMP" > "$TMP.new"
grep -v '^# Generované' "$TARGET" > "$TMP.old"
if diff -u "$TMP.old" "$TMP.new"; then
  echo "OK    rozsahy Cloudflare sedia"
else
  echo "FAIL  rozsahy Cloudflare sa zmenili, spusti: sh scripts/cf-ips-check.sh --write"
  exit 1
fi
