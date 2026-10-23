#!/bin/sh
# Vygeneruje leaf certifikát cez Windows mkcert.exe (WSL interop) a overí dôveru CA vo WSL.
set -eu
cd "$(dirname "$0")/.."
. ./scripts/lib-mkcert.sh

if [ -z "$MKCERT" ]; then
  echo "mkcert.exe sa nenašiel (PATH ani WinGet). Nainštaluj: winget install FiloSottile.mkcert, alebo nastav MKCERT=/cesta/k/mkcert.exe" >&2
  exit 1
fi

mkdir -p docker/certs
CERT=docker/certs/work-tracker.loc.pem
KEY=docker/certs/work-tracker.loc-key.pem

if ! "$MKCERT" -cert-file "$(wslpath -w "$CERT")" -key-file "$(wslpath -w "$KEY")" work-tracker.loc localhost 127.0.0.1; then
  echo "WSL interop zlyhal. Vo Windows PowerShell spusti v adresári projektu:" >&2
  echo "  mkcert -cert-file docker\\certs\\work-tracker.loc.pem -key-file docker\\certs\\work-tracker.loc-key.pem work-tracker.loc localhost 127.0.0.1" >&2
  exit 1
fi

# Dôvera CA vo WSL
CAROOT=$("$MKCERT" -CAROOT | tr -d '\r')
SRC="$(wslpath -u "$CAROOT")/rootCA.pem"
DST=/usr/local/share/ca-certificates/mkcert-rootCA.crt
if ! cmp -s "$SRC" "$DST"; then
  if sudo -n true 2>/dev/null; then
    sudo cp "$SRC" "$DST" && sudo update-ca-certificates
  else
    echo "CA vo WSL chýba alebo je iná. Spusti:" >&2
    echo "  sudo cp '$SRC' $DST" >&2
    echo "  sudo update-ca-certificates" >&2
    exit 1
  fi
fi

# /etc/hosts
if ! getent hosts work-tracker.loc | grep -q '^127\.0\.0\.1'; then
  echo "Pridaj do /etc/hosts vo WSL (a do C:\\Windows\\System32\\drivers\\etc\\hosts vo Windows): 127.0.0.1 work-tracker.loc" >&2
fi

if [ -n "$(docker compose ps -q nginx 2>/dev/null)" ]; then
  docker compose restart nginx
fi
echo "CERTS OK"
