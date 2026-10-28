#!/bin/sh
# Smoke kontrola servera (prod alebo staging) cez Cloudflare: sh scripts/smoke-server.sh <domena>
# Voliteľné env: SSH_TARGET (pamäť cez docker stats), SMOKE_PROJECT (compose projekt, ktorého
# kontajnery sa sčítajú; bez neho všetky), ORIGIN_IP (origin mimo CF musí byť nedostupný),
# SMOKE_EXPECT_NOINDEX=1 (staging: /login musí mať X-Robots-Tag noindex; inak ho nesmie mať),
# SMOKE_EMAIL + SMOKE_PASSWORD (zmena session cookie po hesle).
set -eu

D=${1:?Použitie: smoke-server.sh <domena>}
BASE="https://$D"

FAILS=0
pass() { echo "OK    $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
skip() { echo "SKIP  $1"; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# 1. HTTPS, HTTP/2 alebo 3, presmerovanie na /login
R=$(curl -sS -o /dev/null -D "$TMP/root.h" -w '%{http_code} %{http_version}' "$BASE/" 2>&1 || true)
CODE=${R%% *}
VER=${R##* }
LOC=$(tr -d '\r' < "$TMP/root.h" 2>/dev/null | grep -i '^location:' || true)
if [ "$CODE" = 302 ] && echo "$LOC" | grep -Eiq '/login$'; then pass "/ -> 302 /login"; else fail "/ -> 302 /login (got: $R $LOC)"; fi
if [ "$VER" = 2 ] || [ "$VER" = 3 ]; then pass "HTTP/$VER"; else fail "HTTP/2 alebo 3 (got: $VER)"; fi

# 2. HTTP -> HTTPS
HC=$(curl -sS -o /dev/null -D "$TMP/http.h" -w '%{http_code}' "http://$D/" 2>&1 || true)
if { [ "$HC" = 301 ] || [ "$HC" = 308 ]; } && tr -d '\r' < "$TMP/http.h" | grep -i '^location: *https://' >/dev/null; then
  pass "http -> https ($HC)"; else fail "http -> https (got: $HC)"; fi

# 3. hlavičky na /login
LC=$(curl -sS -o /dev/null -D "$TMP/login.h" -w '%{http_code}' "$BASE/login" 2>&1 || true)
if [ "$LC" = 000 ]; then
  fail "GET $BASE/login nedostupný"
elif [ "$LC" = 404 ]; then
  skip "hlavičky /login (login ešte nie je nasadený)"
else
  hdr=$(tr -d '\r' < "$TMP/login.h")
  has() { echo "$hdr" | grep -i "$1" >/dev/null; }
  ok=1
  csp=$(echo "$hdr" | grep -i '^content-security-policy:' || true)
  [ -n "$csp" ] || ok=0
  echo "$csp" | grep -F 'unsafe-' >/dev/null && ok=0
  has '^strict-transport-security:.*max-age=' || ok=0
  has '^x-content-type-options: *nosniff' || ok=0
  has '^referrer-policy:' || ok=0
  has '^permissions-policy:' || ok=0
  has '^x-powered-by:' && ok=0
  has '^cache-control:.*no-store' || ok=0
  has '^vary:.*HX-Request' || ok=0
  has '^cf-cache-status: *HIT' && ok=0
  if [ "$ok" = 1 ]; then pass "hlavičky $BASE/login"; else fail "hlavičky $BASE/login"; fi

  # X-Robots-Tag: staging noindex, prod bez hlavičky
  if [ "${SMOKE_EXPECT_NOINDEX:-0}" = 1 ]; then
    if has '^x-robots-tag:.*noindex'; then pass "X-Robots-Tag noindex"; else fail "X-Robots-Tag noindex chýba"; fi
  else
    if has '^x-robots-tag:'; then fail "X-Robots-Tag nemá byť na produkcii"; else pass "bez X-Robots-Tag"; fi
  fi

  # 4. cookies
  CK=$(echo "$hdr" | grep -i '^set-cookie:' || true)
  COK=1
  if [ -n "$CK" ]; then
    echo "$CK" > "$TMP/cookies.txt"
    while read -r line; do
      echo "$line" | grep -qi '^set-cookie: *__Host-' || COK=0
      for a in 'Secure' 'HttpOnly' 'SameSite=Lax'; do
        echo "$line" | grep -qi "$a" || COK=0
      done
      [ "$COK" = 1 ] || break
    done < "$TMP/cookies.txt"
  fi
  if [ "$COK" = 1 ]; then pass "cookies __Host-, Secure, HttpOnly, SameSite=Lax"; else fail "cookies __Host-, Secure, HttpOnly, SameSite=Lax"; fi
fi

# 5. service worker a manifest
SH=$(curl -sS -o /dev/null -D - "$BASE/sw.js" 2>&1 | tr -d '\r' || true)
SC=$(echo "$SH" | head -n1)
if echo "$SC" | grep -q ' 404'; then
  skip "/sw.js (ešte nie je nasadený)"
elif echo "$SH" | grep -i '^cache-control:.*no-cache' >/dev/null && ! echo "$SH" | grep -i '^cf-cache-status: *HIT' >/dev/null; then
  pass "/sw.js no-cache"; else fail "/sw.js no-cache a nie HIT v CF"; fi
MH=$(curl -sS -o /dev/null -D - "$BASE/manifest.webmanifest" 2>&1 | tr -d '\r' || true)
if echo "$MH" | head -n1 | grep -q ' 200'; then
  if echo "$MH" | grep -i '^content-type: *application/manifest+json' >/dev/null; then pass "manifest content-type"; else fail "manifest content-type"; fi
else
  skip "manifest (nie je 200)"
fi

# 6. zmena session cookie po hesle (Pitfall 3)
if [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ]; then
  JAR="$TMP/jar"
  PAGE=$(curl -sS -c "$JAR" "$BASE/login" 2>&1 || true)
  TOKEN=$(echo "$PAGE" | sed -n 's/.*name="csrf_test_name"[^>]*value="\([^"]*\)".*/\1/p' | head -n1)
  BEFORE=$(grep '__Host-ci_session' "$JAR" | awk '{print $7}' || true)
  if [ -n "$TOKEN" ]; then
    PH=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -D - -X POST "$BASE/login" \
      --data-urlencode "csrf_test_name=$TOKEN" \
      --data-urlencode "email=$SMOKE_EMAIL" \
      --data-urlencode "password=$SMOKE_PASSWORD" 2>&1 | tr -d '\r' || true)
    AFTER=$(grep '__Host-ci_session' "$JAR" | awk '{print $7}' || true)
    if echo "$PH" | head -n1 | grep -qE ' 30[23]' && echo "$PH" | grep -iq '^location:.*/2fa' && [ -n "$AFTER" ] && [ "$BEFORE" != "$AFTER" ]; then
      pass "po hesle 302/303 /2fa a nová session cookie"; else fail "po hesle 302/303 /2fa a nová session cookie"; fi
  else
    fail "csrf token vo formulári /login nenájdený"
  fi
else
  skip "zmena session cookie (chýba SMOKE_EMAIL/SMOKE_PASSWORD)"
fi

# 7. pamäť kontajnerov (NFR-02); so SMOKE_PROJECT iba tento stack
if [ -n "${SSH_TARGET:-}" ]; then
  if [ -n "${SMOKE_PROJECT:-}" ]; then
    REMOTE="ids=\$(docker ps -q --filter label=com.docker.compose.project=$SMOKE_PROJECT); [ -n \"\$ids\" ] && docker stats --no-stream --format '{{.MemUsage}}' \$ids"
    LABEL="pamäť ($SMOKE_PROJECT)"
  else
    REMOTE="docker stats --no-stream --format '{{.MemUsage}}'"
    LABEL="pamäť"
  fi
  # shellcheck disable=SC2029  # $REMOTE sa zamerne expanduje na klientovi (SMOKE_PROJECT)
  MEM=$(ssh "$SSH_TARGET" "$REMOTE" 2>/dev/null \
    | awk '{ v=$1; n=v+0; if (v ~ /GiB/) n*=1024; else if (v ~ /KiB/) n/=1024; else if (v ~ /MiB/) n=n; s+=n } END { printf "%.0f", s }' || true)
  if [ -n "$MEM" ] && [ "$MEM" -gt 0 ] && [ "$MEM" -lt 512 ]; then pass "$LABEL ${MEM} MiB < 512"; else fail "$LABEL (${MEM:-?} MiB, limit 512)"; fi
else
  skip "pamäť (chýba SSH_TARGET)"
fi

# 8. origin nesmie byť dostupný mimo Cloudflare
if [ -n "${ORIGIN_IP:-}" ]; then
  if curl -sS -m 8 -o /dev/null "http://$ORIGIN_IP/" >/dev/null 2>&1; then
    fail "origin $ORIGIN_IP je dostupný priamo"; else pass "origin $ORIGIN_IP nedostupný mimo CF"; fi
else
  skip "origin (chýba ORIGIN_IP)"
fi

if [ "$FAILS" -eq 0 ]; then echo "SMOKE OK"; exit 0; fi
echo "SMOKE FAILED ($FAILS)"
exit 1
