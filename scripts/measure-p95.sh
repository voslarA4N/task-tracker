#!/bin/sh
# Meranie odozvy servera (TTFB) bez externych nastrojov: sh scripts/measure-p95.sh <base-url> <cesta> [<cesta> ...]
# Env: P95_COOKIE (hodnota hlavicky Cookie, iba z prostredia, nikdy sa nevypise), P95_ALLOW_ANON=1 (meranie bez cookie),
# P95_N (merania na cestu, 50), P95_WARMUP (nemerane, 3), P95_LIMIT_MS (100),
# P95_RESOLVE (host:port:ip pre curl --resolve, meranie na origin), P95_CURL (curl).
# Vystup: <cesta> n= p50= p95= max= status=, nakoniec ALL ... OK|FAIL. Exit 0 OK, 1 p95 > limit, 2 status != 200.
# Iba GET, jedna poziadavka naraz (T-12-21).
set -eu

BASE=${1:?Použitie: measure-p95.sh <base-url> <cesta> [<cesta> ...]}
shift
[ "$#" -ge 1 ] || { echo "chýba aspoň jedna cesta" >&2; exit 64; }

N=${P95_N:-50}
WARMUP=${P95_WARMUP:-3}
LIMIT=${P95_LIMIT_MS:-100}
CURL=${P95_CURL:-curl}

if [ -z "${P95_COOKIE:-}" ] && [ "${P95_ALLOW_ANON:-0}" != 1 ]; then
  echo "chýba P95_COOKIE (anonymné meranie len s P95_ALLOW_ANON=1)" >&2
  exit 64
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
: > "$TMP/all"

# percentil metodou nearest-rank zo zoradeneho suboru: pct(subor, n, p)
pct() {
  idx=$(( ($3 * $2 + 99) / 100 ))
  [ "$idx" -ge 1 ] || idx=1
  sed -n "${idx}p" "$1"
}

one() {
  # $1 = url; vypise "<kod> <ms>"
  set -- "$1"
  if [ -n "${P95_COOKIE:-}" ]; then
    if [ -n "${P95_RESOLVE:-}" ]; then
      r=$($CURL -sS -o /dev/null -H "Cookie: $P95_COOKIE" --resolve "$P95_RESOLVE" -w '%{http_code} %{time_starttransfer}\n' "$1" 2>/dev/null || echo "000 0")
    else
      r=$($CURL -sS -o /dev/null -H "Cookie: $P95_COOKIE" -w '%{http_code} %{time_starttransfer}\n' "$1" 2>/dev/null || echo "000 0")
    fi
  else
    if [ -n "${P95_RESOLVE:-}" ]; then
      r=$($CURL -sS -o /dev/null --resolve "$P95_RESOLVE" -w '%{http_code} %{time_starttransfer}\n' "$1" 2>/dev/null || echo "000 0")
    else
      r=$($CURL -sS -o /dev/null -w '%{http_code} %{time_starttransfer}\n' "$1" 2>/dev/null || echo "000 0")
    fi
  fi
  echo "$r" | awk '{ printf "%s %d\n", $1, $2 * 1000 + 0.5 }'
}

BAD=0
for P in "$@"; do
  URL="$BASE$P"
  i=0
  while [ "$i" -lt "$WARMUP" ]; do
    one "$URL" > /dev/null
    i=$((i + 1))
  done
  : > "$TMP/s"
  : > "$TMP/codes"
  i=0
  while [ "$i" -lt "$N" ]; do
    r=$(one "$URL")
    echo "${r%% *}" >> "$TMP/codes"
    echo "${r##* }" >> "$TMP/s"
    i=$((i + 1))
  done
  sort -n "$TMP/s" > "$TMP/sorted"
  cat "$TMP/s" >> "$TMP/all"
  CODES=$(sort -u "$TMP/codes" | tr '\n' ',' | sed 's/,$//')
  echo "$P n=$N p50=$(pct "$TMP/sorted" "$N" 50) p95=$(pct "$TMP/sorted" "$N" 95) max=$(tail -n1 "$TMP/sorted") status=$CODES"
  if [ "$CODES" != 200 ]; then
    BAD=1
    echo "stránka $P nevrátila 200 (cookie neplatná alebo expirovaná?)" >&2
  fi
done

if [ "$BAD" = 1 ]; then
  echo "cookie neplatná alebo expirovaná, alebo stránka nie je dostupná (status != 200)" >&2
  exit 2
fi

TOTAL=$(wc -l < "$TMP/all" | tr -d ' ')
sort -n "$TMP/all" > "$TMP/allsorted"
P95=$(pct "$TMP/allsorted" "$TOTAL" 95)
if [ "$P95" -gt "$LIMIT" ]; then V=FAIL; else V=OK; fi
echo "ALL n=$TOTAL p95=$P95 limit=$LIMIT $V"
[ "$V" = OK ] || exit 1
