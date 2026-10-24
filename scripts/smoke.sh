#!/bin/sh
# End-to-end kontrola dev stacku. Bez curl -k: certifikát musí byť dôveryhodný.
set -eu
cd "$(dirname "$0")/.."

DOMAIN=work-tracker.loc
if [ -f .env ]; then
  d=$(grep -E '^APP_DOMAIN *=' .env | head -n1 | sed 's/^[^=]*= *//' | tr -d '\r "'"'" || true)
  [ -n "$d" ] && DOMAIN=$d
fi

# Voliteľné porty (hostiteľ s obsadeným 80/443): HTTPS_PORT=8443 HTTP_PORT=8080 sh scripts/smoke.sh
BASE="https://$DOMAIN${HTTPS_PORT:+:$HTTPS_PORT}"
HBASE="http://$DOMAIN${HTTP_PORT:+:$HTTP_PORT}"

FAILS=0
pass() { echo "OK    $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
skip() { echo "$1"; }

# 1. služby
PS=$(docker compose ps --format '{{.Service}} {{.State}} {{.Health}}' 2>/dev/null || true)
for s in php nginx db mailpit; do
  if echo "$PS" | grep -q "^$s running"; then pass "service $s running"; else fail "service $s running"; fi
done
if echo "$PS" | grep -q '^db running healthy'; then pass "db healthy"; else fail "db healthy"; fi

# 2. HTTPS + HTTP/2
R=$(curl -sS -o /dev/null -w '%{http_code} %{http_version}' "$BASE/" 2>&1 || true)
if [ "$R" = "302 2" ]; then pass "https 302 HTTP/2 (brana)"; else fail "https 302 HTTP/2 (got: $R)"; fi
LH=$(curl -sS -o /dev/null -D - "$BASE/" | tr -d '\r' | grep -i '^location:' || true)
if echo "$LH" | grep -Eiq "^location: *https://$DOMAIN(:[0-9]+)?/login$"; then pass "/ -> /login"; else fail "/ -> /login (got: $LH)"; fi

# 3. redirect
H=$(curl -sS -o /dev/null -D - "$HBASE/" 2>&1 || true)
if echo "$H" | head -n1 | grep -q ' 301' && echo "$H" | grep -i '^location: https://' >/dev/null; then
  pass "http -> https 301"
else fail "http -> https 301"; fi

# 4/5. hlavičky
check_headers() {
  url=$1
  hdr=$(curl -sSI "$url" 2>&1 | tr -d '\r' || true)
  has() { echo "$hdr" | grep -i "$1" >/dev/null; }
  csp=$(echo "$hdr" | grep -i '^content-security-policy:' || true)
  ok=1
  for p in "default-src 'self'" "script-src 'self'" "frame-ancestors 'none'"; do
    echo "$csp" | grep -F "$p" >/dev/null || ok=0
  done
  echo "$csp" | grep -F 'unsafe-' >/dev/null && ok=0
  has '^strict-transport-security:.*max-age=' || ok=0
  has '^x-content-type-options: *nosniff' || ok=0
  has '^referrer-policy: *strict-origin-when-cross-origin' || ok=0
  has '^permissions-policy:' || ok=0
  has '^x-powered-by:' && ok=0
  has '^server: *nginx/' && ok=0
  if [ "$2" = "html" ]; then has '^cache-control:.*no-store' || ok=0; fi
  if [ "$ok" = 1 ]; then pass "headers $url"; else fail "headers $url"; fi
}
check_headers "$BASE/" html
check_headers "$BASE/smoke-nonexistent-xyz" html
check_headers "$BASE/x.php" other

# 6. gzip (na statickom assete, `/` je za branou len presmerovanie bez tela)
GZF=$(ls public/assets/vendor/*.css 2>/dev/null | head -n1 || true)
if [ -z "$GZF" ]; then GZF=public/assets/css/app.css; fi
if curl -sS -H 'Accept-Encoding: gzip' -o /dev/null -D - "$BASE/assets/${GZF#public/assets/}" | tr -d '\r' | grep -i '^content-encoding: *gzip' >/dev/null; then
  pass "gzip"; else fail "gzip"; fi

# 7. vendor cache
VF=$(ls public/assets/vendor/*.js 2>/dev/null | head -n1 || true)
if [ -n "$VF" ]; then
  VH=$(curl -sSI "$BASE/assets/vendor/$(basename "$VF")" | tr -d '\r' | grep -i '^cache-control:' || true)
  if echo "$VH" | grep -q 'max-age=31536000' && echo "$VH" | grep -q immutable; then pass "vendor-cache"; else fail "vendor-cache"; fi
else
  skip "SKIP vendor-cache"
fi
NH=$(curl -sSI "$BASE/assets/vendor/smoke-missing-xyz.js" | tr -d '\r' || true)
if echo "$NH" | head -n1 | grep -q ' 404' && ! echo "$NH" | grep -i '^cache-control:.*immutable' >/dev/null; then
  pass "asset 404 not immutable"; else fail "asset 404 not immutable"; fi

# 8. Mailpit
SUBJ="smoke-$(cat /proc/sys/kernel/random/uuid)"
if docker compose exec -T -u www-data php php spark mail:test "$SUBJ" >/dev/null 2>&1; then
  sleep 1
  CNT=$(curl -fsS "http://127.0.0.1:8025/api/v1/search?query=subject:$SUBJ" 2>/dev/null | grep -o '"messages_count": *[0-9]*' | grep -o '[0-9]*$' || echo 0)
  if [ "${CNT:-0}" -ge 1 ]; then pass "mailpit received $SUBJ"; else fail "mailpit received $SUBJ"; fi
else
  fail "spark mail:test"
fi

# 9. /demo za branou: anonymne 302 na /login; cookies Secure/HttpOnly/SameSite=Lax (D-04), POST bez tokenu = 403
DH=$(curl -sS -D - -o /dev/null "$BASE/demo" 2>&1 | tr -d '\r' || true)
if echo "$DH" | head -n1 | grep -q ' 302'; then pass "demo 302 (brana)"; else fail "demo 302 (brana)"; fi
CK=$(echo "$DH" | grep -i '^set-cookie:' || true)
COK=1
if [ -n "$CK" ]; then
  echo "$CK" | while read -r line; do
    for a in 'Secure' 'HttpOnly' 'SameSite=Lax'; do
      echo "$line" | grep -qi "$a" || exit 1
    done
  done || COK=0
fi
if [ "$COK" = 1 ]; then pass "cookie flags"; else fail "cookie flags"; fi
PH=$(curl -sS -o /dev/null -D - -X POST "$BASE/demo/calculate" -H 'HX-Request: true' -d 'hours=1&rate=1' | tr -d '\r' || true)
if echo "$PH" | head -n1 | grep -q ' 403' && echo "$PH" | grep -i '^hx-retarget: *#app-alert' >/dev/null; then
  pass "csrf 403 without token"; else fail "csrf 403 without token"; fi

# 10. app.js: CSRF fragment v #app-alert je viditelny a neprepisany (CR-01)
if command -v node >/dev/null 2>&1; then
  if node --test tests/js/*.test.js >/dev/null 2>&1; then pass "js app-alert"; else fail "js app-alert (node --test tests/js/*.test.js)"; fi
else
  skip "SKIP js app-alert (chyba node)"
fi

if [ "$FAILS" -eq 0 ]; then echo "SMOKE OK"; exit 0; fi
echo "SMOKE FAILED ($FAILS)"
exit 1
