#!/bin/sh
# D-06: stiahne cely sifrovany restic repozitar zo servera na PC vlastnika (WSL, bezny pouzivatel, nie root).
# Server povoluje iba citanie: authorized_keys command="sudo -n /usr/bin/rrsync -ro <repozitar>" (docs/ops/backup.md).
# Bez --delete: pri zmazani repozitara na serveri ostane kopia na PC. Adresar locks sa nekopiruje.
# Premenne: PULL_SSH_TARGET, PULL_SSH_KEY, PULL_DEST, volitelne RESTIC_PASSWORD_FILE (overenie kopie).
set -eu
umask 077

PULL_SSH_TARGET=${PULL_SSH_TARGET:-backuppull@2.31.74.48}
PULL_SSH_KEY=${PULL_SSH_KEY:-$HOME/.ssh/work-tracker-backup-pull}
PULL_DEST=${PULL_DEST:-$HOME/backups/work-tracker-restic}

install -d -m 0700 "$PULL_DEST"

if ! rsync -a --partial --exclude '/locks/' \
  -e "ssh -i '$PULL_SSH_KEY' -o IdentitiesOnly=yes -o BatchMode=yes" \
  "$PULL_SSH_TARGET:" "$PULL_DEST/"; then
  echo "CHYBA: pull zlyhal" >&2
  exit 1
fi

if [ -n "${RESTIC_PASSWORD_FILE-}" ] && command -v restic > /dev/null 2>&1; then
  export RESTIC_PASSWORD_FILE
  restic -r "$PULL_DEST" snapshots --compact --no-lock
  restic -r "$PULL_DEST" check --no-lock
fi

SIZE=$(du -sh "$PULL_DEST" | cut -f1)
echo "OK: pull $(date +%F) $SIZE"
