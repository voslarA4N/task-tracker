#!/bin/sh
# D-15, NFR-05: test obnovy. Posledny snapshot db z restic sa obnovi do docasnej DB a porovna s manifestom zo zalohy
# (pocty riadkov, posledne cislo faktury, digest sha256 dokumentov, kontrola obsahu PDF).
# Cron (root, host, serverovy rezim): 30 4 1 * * /usr/local/lib/work-tracker-backup/scripts/restore-test.sh >> /var/log/work-tracker-restore-test.log 2>&1
# Serverovy rezim: tajomstva z ${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env} (0600), DB iba restore_check_<cas>.
# Lokalny rezim (RESTORE_LOCAL=1, WSL): bez env suboru a bez upozorneni, RESTIC_REPOSITORY a RESTIC_PASSWORD_FILE z prostredia,
#   docker compose v aktualnom adresari. RESTORE_TARGET_DB=<nazov>, RESTORE_KEEP=1 ponecha DB (najprv ju zmaze a vytvori znova).
set -eu
umask 077
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/lib-restic-policy.sh"

RESTORE_LOCAL=${RESTORE_LOCAL:-0}
RESTORE_KEEP=${RESTORE_KEEP:-0}
RESTORE_DB_USER=${RESTORE_DB_USER:-app_owner}
HC_RESTORE_URL=
ALERT_TO=
STEP=start
ERRLOG=
TMP=
CREATED=0
TARGET_DB=${RESTORE_TARGET_DB-}

if [ "$RESTORE_LOCAL" != 1 ]; then
  . "$HERE/lib-backup-notify.sh"
  BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env}
  check_env_file "$BACKUP_ENV_FILE"
  # shellcheck disable=SC1090
  . "$BACKUP_ENV_FILE"
  HC_RESTORE_URL=${HC_RESTORE_URL-}
  STACK_DIR=${STACK_DIR:-/opt/work-tracker}
  COMPOSE_DIR=$STACK_DIR
  COMPOSE_FILE=${BACKUP_COMPOSE_FILE-compose.server.yaml}
  if [ -n "$COMPOSE_FILE" ]; then export COMPOSE_FILE; fi
  if [ -f "$STACK_DIR/.last-good-tag" ]; then
    IMAGE_TAG=$(cat "$STACK_DIR/.last-good-tag")
    export IMAGE_TAG
  fi
  # Na serveri sa nikdy neponecha DB ani nepouzije nazov ine ako restore_check_*.
  RESTORE_KEEP=0
  case "$TARGET_DB" in
    '' | restore_check_*) ;;
    *)
      echo "CHYBA: na serveri je povolena iba docasna DB restore_check_*" >&2
      exit 1
      ;;
  esac
else
  COMPOSE_DIR=.
fi
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

if [ -z "$TARGET_DB" ]; then
  TARGET_DB="restore_check_$(date -u +%Y%m%d_%H%M%S)"
fi
if ! printf '%s' "$TARGET_DB" | grep -Eq '^[a-z_][a-z0-9_]{0,62}$'; then
  echo "CHYBA: neplatny nazov DB" >&2
  exit 1
fi

ERRLOG=$(mktemp)
TMP=$(mktemp -d)

dc() {
  (cd "$COMPOSE_DIR" && docker compose "$@")
}

on_exit() {
  _code=$1
  if [ "$CREATED" = 1 ] && [ "$RESTORE_KEEP" != 1 ]; then
    dc exec -T db dropdb -U postgres --if-exists "$TARGET_DB" > /dev/null 2>&1 || echo "VAROVANIE: dropdb $TARGET_DB zlyhal" >&2
  fi
  if [ "$_code" -ne 0 ]; then
    cat "$ERRLOG" >&3 2>/dev/null || true
    if [ "$RESTORE_LOCAL" != 1 ]; then
      notify_fail 'Test obnovy zlyhal' "$STEP" "$ERRLOG" "$HC_RESTORE_URL"
    fi
  fi
  rm -rf "$TMP"
  rm -f "$ERRLOG"
}

# stderr krokov ide do docasneho logu, pri chybe sa vypise na povodny stderr.
exec 3>&2
exec 2>>"$ERRLOG"
trap 'on_exit $?' EXIT

STEP=restore
restic restore latest --tag db --host "$RESTIC_HOST" --target "$TMP" > "$TMP/restore.out"
SNAP=$(sed -n 's/^restoring <Snapshot \([0-9a-f]*\).*/\1/p' "$TMP/restore.out" | head -n 1)
SNAP=${SNAP:-latest}
DUMP=$(find "$TMP" -name task_tracker.dump -type f | head -n 1)
MAN=$(find "$TMP" -name manifest.txt -type f | head -n 1)
if [ -z "$DUMP" ] || [ -z "$MAN" ] || [ ! -f "$DUMP.sha256" ]; then
  echo "snapshot neobsahuje task_tracker.dump, .sha256 alebo manifest.txt" >&2
  exit 1
fi

STEP=checksum
(cd "$(dirname "$DUMP")" && sha256sum -c task_tracker.dump.sha256 >&2)

STEP=createdb
if [ "$RESTORE_KEEP" = 1 ]; then
  dc exec -T db dropdb -U postgres --if-exists "$TARGET_DB" >&2 < /dev/null
fi
dc exec -T db createdb -U postgres -O app_owner "$TARGET_DB" >&2 < /dev/null
CREATED=1

STEP=pg_restore
dc exec -T db pg_restore -U "$RESTORE_DB_USER" --no-owner --exit-on-error -d "$TARGET_DB" < "$DUMP" >&2

STEP=compare
q() {
  # < /dev/null: `docker compose exec -T` inak precita zvysok manifestu zo stdin cyklu (CR-01).
  dc exec -T db psql -U "$RESTORE_DB_USER" -d "$TARGET_DB" -At -c "$1" < /dev/null
}
BAD=0
while IFS= read -r line || [ -n "$line" ]; do
  key=${line%%=*}
  want=${line#*=}
  case "$key" in
    created_at | env | dump_sha256 | '') continue ;;
    rows.*) got=$(q "SELECT count(*) FROM ${key#rows.}") ;;
    last_invoice_number) got=$(q "SELECT number FROM invoices WHERE origin = 'issued' ORDER BY id DESC LIMIT 1") ;;
    documents_sha256_digest) got=$(q "SELECT md5(string_agg(sha256, ',' ORDER BY id)) FROM invoice_documents") ;;
    documents_bad_sha256)
      got=$(q "SELECT count(*) FROM invoice_documents WHERE encode(sha256(content), 'hex') <> sha256")
      want=0
      ;;
    *) continue ;;
  esac
  if [ "$got" != "$want" ]; then
    echo "nezhoda $key: zaloha=$want obnovena=$got" >&2
    BAD=1
  fi
done < "$MAN"
if [ "$BAD" != 0 ]; then
  exit 1
fi
ROWS=$(sed -n 's/^rows\.invoices=//p' "$MAN")
LAST=$(sed -n 's/^last_invoice_number=//p' "$MAN")

STEP=ok
trap - EXIT
if [ "$CREATED" = 1 ] && [ "$RESTORE_KEEP" != 1 ]; then
  dc exec -T db dropdb -U postgres --if-exists "$TARGET_DB" > /dev/null 2>&1 || echo "VAROVANIE: dropdb $TARGET_DB zlyhal" >&2
fi
rm -rf "$TMP"
rm -f "$ERRLOG"
exec 2>&3
if [ "$RESTORE_LOCAL" != 1 ]; then ping_ok "$HC_RESTORE_URL"; fi
echo "OK: obnova $SNAP $TARGET_DB rows.invoices=$ROWS last_invoice_number=$LAST"
