#!/bin/sh
# Kontrola driftu rozsahov Cloudflare voci docker/nginx/realip/cloudflare.conf.
# Bez prepinaca: porovna a pri rozdiele exit 1. S --write: prepise snippet.
set -eu
cd "$(dirname "$0")/.."

TARGET=docker/nginx/realip/cloudflare.conf
TMP=$(mktemp)
trap 'rm -f "$TMP" "$TMP.new" "$TMP.old"' EXIT

V4=$(curl -fsS https://www.cloudflare.com/ips-v4)
V6=$(curl -fsS https://www.cloudflare.com/ips-v6)

{
  echo "# Generované scripts/cf-ips-check.sh --write, $(date -u +%Y-%m-%d)"
  echo "# Zdroj: https://www.cloudflare.com/ips-v4 a https://www.cloudflare.com/ips-v6"
  printf '%s\n%s\n' "$V4" "$V6" | grep -v '^$' | tr -d '\r' | sort | while read -r r; do
    echo "set_real_ip_from $r;"
  done
  echo "real_ip_header CF-Connecting-IP;"
  echo "real_ip_recursive off;"
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
