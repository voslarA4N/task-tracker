#!/bin/sh
# Nájde mkcert.exe (Windows) pre WSL: env MKCERT, potom PATH, potom WinGet Packages.
# Nastaví premennú MKCERT (prázdnu, ak sa nenašiel). Linuxový /usr/bin/mkcert sa zámerne nepoužíva (nemá Windows CA).
if [ -z "${MKCERT:-}" ]; then
  MKCERT=$(command -v mkcert.exe 2>/dev/null || true)
fi
if [ -z "$MKCERT" ]; then
  for f in /mnt/c/Users/*/AppData/Local/Microsoft/WinGet/Packages/FiloSottile.mkcert*/mkcert.exe; do
    if [ -x "$f" ]; then MKCERT=$f; break; fi
  done
fi
export MKCERT
