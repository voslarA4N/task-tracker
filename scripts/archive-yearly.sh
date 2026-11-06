#!/bin/sh
# D-10, D-09: rocny archiv dokladov (spark archive:year) ulozeny do restic s tagom archive.
# Cron (root, host, 2. januara): 0 4 2 1 * /usr/local/lib/work-tracker-backup/scripts/archive-yearly.sh >> /var/log/work-tracker-archive.log 2>&1
# Pouzitie: archive-yearly.sh [rok]  (bez argumentu predchadzajuci rok).
# Retencia 10 rokov = snapshoty s tagom archive sa nemazu automaticky (--keep-tag archive v lib-restic-policy.sh),
# tento skript preto nikdy nevola restic forget. Starsie ako 10 rokov sa mazu rucne podla runbooku docs/ops/backup.md.
# Tajomstva iba z ${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env} (0600), volitelne HC_ARCHIVE_URL.
# Kroky: spark -> copy -> verify -> restic -> cleanup. Pri chybe ping /fail + e-mail.
set -eu
umask 077
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/lib-restic-policy.sh"
. "$HERE/lib-backup-notify.sh"

ROK=${1-}
if [ -z "$ROK" ]; then
  ROK=$(($(date +%Y) - 1))
  if [ -n "${ARCHIVE_YEAR_NOW-}" ]; then
    ROK=$((ARCHIVE_YEAR_NOW - 1))
  fi
fi
case "$ROK" in
  [0-9][0-9][0-9][0-9]) ;;
  *)
    echo "CHYBA: neplatný rok '$ROK', očakáva sa RRRR" >&2
    exit 1
    ;;
esac

BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env}
check_env_file "$BACKUP_ENV_FILE"
# shellcheck disable=SC1090
. "$BACKUP_ENV_FILE"
STACK_DIR=${STACK_DIR:-/opt/work-tracker}
ARCHIVE_STAGE=${ARCHIVE_STAGE:-/var/backups/work-tracker/archive-stage}
HC_ARCHIVE_URL=${HC_ARCHIVE_URL-}
BACKUP_ENV_NAME=${BACKUP_ENV_NAME:-prod}
BACKUP_COMPOSE_FILE=${BACKUP_COMPOSE_FILE-compose.server.yaml}
ZIP_NAME="work-tracker-archiv-$ROK.zip"
STAGE_YEAR="$ARCHIVE_STAGE/$ROK"

STEP=start
ERRLOG=$(mktemp)

cleanup() {
  rm -rf "$STAGE_YEAR"
}

on_exit() {
  _code=$1
  if [ "$_code" -ne 0 ]; then
    cat "$ERRLOG" >&3 2>/dev/null || true
    notify_fail 'Archív zlyhal' "$STEP" "$ERRLOG" "$HC_ARCHIVE_URL"
  fi
  cleanup
  rm -f "$ERRLOG"
}

dc() {
  (cd "$STACK_DIR" && docker compose "$@")
}

# stderr krokov ide do docasneho logu (posledne riadky pojdu do e-mailu), pri chybe sa vypise na povodny stderr.
exec 3>&2
exec 2>>"$ERRLOG"
trap 'on_exit $?' EXIT

STEP=env
# Od tejto chvili zlyhanie (aj chybajuca premenna v env subore) spusti upozornenie (WR-04).
: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY nie je nastavena v env subore}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE nie je nastavena v env subore}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

if [ -n "$BACKUP_COMPOSE_FILE" ]; then
  COMPOSE_FILE=$BACKUP_COMPOSE_FILE
  export COMPOSE_FILE
fi
if [ -f "$STACK_DIR/.last-good-tag" ]; then
  IMAGE_TAG=$(cat "$STACK_DIR/.last-good-tag")
  export IMAGE_TAG
fi

STEP=spark
OUT=$(dc exec -T php php spark archive:year "$ROK")
CPATH=$(printf '%s\n' "$OUT" | sed -n 's/^PATH=//p' | tail -n 1)
case "$CPATH" in
  /*/archive/"$ROK"/"$ZIP_NAME") ;;
  *)
    echo "príkaz archive:year nevrátil cestu k archívu" >&2
    exit 1
    ;;
esac

STEP=copy
install -d -m 0700 "$ARCHIVE_STAGE"
cleanup
install -d -m 0700 "$STAGE_YEAR"
dc cp "php:$CPATH" "$STAGE_YEAR/" > /dev/null
dc cp "php:$CPATH.sha256" "$STAGE_YEAR/" > /dev/null

STEP=verify
(cd "$STAGE_YEAR" && sha256sum -c "$ZIP_NAME.sha256" > /dev/null)

STEP=restic
restic backup --tag archive --tag "year-$ROK" --host "$RESTIC_HOST" "$STAGE_YEAR" > /dev/null

STEP=cleanup
cleanup
dc exec -T php rm -f "$CPATH" "$CPATH.sha256"

STEP=ok
trap - EXIT
rm -f "$ERRLOG"
exec 2>&3
ping_ok "$HC_ARCHIVE_URL"
echo "OK: archive $ROK"
