#!/bin/sh
# Kontrola predpokladov dev prostredia. Exit 1 pri akomkoľvek FAIL.
cd "$(dirname "$0")/.." || exit 1
rc=0
DOMAIN=work-tracker.loc
. ./scripts/lib-mkcert.sh

check() {
  name=$1; shift
  if "$@" >/dev/null 2>&1; then echo "OK    $name"; else echo "FAIL  $name"; rc=1; fi
}

check "docker info" docker info
check "docker compose" docker compose version
check "make" make --version
check "mkcert.exe -CAROOT" sh -c ". ./scripts/lib-mkcert.sh && [ -n \"\$MKCERT\" ] && \"\$MKCERT\" -CAROOT"
check "$DOMAIN -> 127.0.0.1" sh -c "getent hosts $DOMAIN | grep -q '^127\.0\.0\.1'"
check "WSL CA /usr/local/share/ca-certificates/mkcert-rootCA.crt" test -f /usr/local/share/ca-certificates/mkcert-rootCA.crt
check ".env" test -f .env
check "docker/certs/work-tracker.loc.pem" test -f docker/certs/work-tracker.loc.pem

exit $rc
