#!/bin/sh
# D-07 az D-09: denna sifrovana zaloha DB (vratane PDF dokladov) do lokalneho restic repozitara.
# Cron (root, host): 10 3 * * * /usr/local/lib/work-tracker-backup/scripts/backup-restic.sh >> /var/log/work-tracker-backup.log 2>&1
# Tajomstva iba z ${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env} (0600), nikdy z app.env.
# Kroky: repo -> dump -> manifest -> restic -> forget -> check (nedela). Pri chybe ping /fail + e-mail.
set -eu
umask 077
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/lib-restic-policy.sh"
. "$HERE/lib-backup-notify.sh"

BACKUP_ENV_FILE=${BACKUP_ENV_FILE:-/root/.config/work-tracker-backup.env}
check_env_file "$BACKUP_ENV_FILE"
# shellcheck disable=SC1090
. "$BACKUP_ENV_FILE"
BACKUP_DB_USER=${BACKUP_DB_USER:-app_owner}
BACKUP_DB=${BACKUP_DB:-task_tracker}
export BACKUP_DB_USER BACKUP_DB
STACK_DIR=${STACK_DIR:-/opt/work-tracker}
STAGE_DIR=${STAGE_DIR:-/var/backups/work-tracker/restic-stage}
HC_URL=${HC_URL-}
BACKUP_ENV_NAME=${BACKUP_ENV_NAME:-prod}
BACKUP_COMPOSE_FILE=${BACKUP_COMPOSE_FILE-compose.server.yaml}

STEP=start
ERRLOG=$(mktemp)
FACTS1=
FACTS2=

cleanup() {
  rm -f "$STAGE_DIR"/task_tracker.dump* "$STAGE_DIR/manifest.txt" "$FACTS1" "$FACTS2"
}

on_exit() {
  _code=$1
  if [ "$_code" -ne 0 ]; then
    cat "$ERRLOG" >&3 2>/dev/null || true
    notify_fail 'Záloha zlyhala' "$STEP" "$ERRLOG" "$HC_URL"
  fi
  cleanup
  rm -f "$ERRLOG"
}

# stderr krokov ide do docasneho logu (posledne riadky pojdu do e-mailu), pri chybe sa vypise na pôvodny stderr.
exec 3>&2
exec 2>>"$ERRLOG"
trap 'on_exit $?' EXIT

STEP=env
# Od tejto chvili zlyhanie (aj chybajuca premenna v env subore) spusti upozornenie (WR-04).
: "${RESTIC_REPOSITORY:?RESTIC_REPOSITORY nie je nastavena v env subore}"
: "${RESTIC_PASSWORD_FILE:?RESTIC_PASSWORD_FILE nie je nastavena v env subore}"
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

STEP=repo
if ! restic cat config > /dev/null; then
  echo "repozitár nie je inicializovaný alebo nedostupný" >&2
  exit 1
fi

STEP=dump
install -d -m 0700 "$STAGE_DIR"
cleanup
if [ -n "$BACKUP_COMPOSE_FILE" ]; then
  COMPOSE_FILE=$BACKUP_COMPOSE_FILE
  export COMPOSE_FILE
fi
if [ -f "$STACK_DIR/.last-good-tag" ]; then
  IMAGE_TAG=$(cat "$STACK_DIR/.last-good-tag")
  export IMAGE_TAG
fi

q() {
  (cd "$STACK_DIR" && docker compose exec -T db psql -U "$BACKUP_DB_USER" -d "$BACKUP_DB" -At -c "$1" < /dev/null)
}

# Fakty o DB pre manifest. Kazdy dotaz sa priradi najprv do premennej, takze zlyhany dotaz ukonci zalohu (set -e)
# a prazdny pocet sa odmietne (WR-02). Poslednu fakturu a digest moze byt prazdny iba v prazdnej DB.
facts() {
  for _t in invoices invoice_lines invoice_documents invoice_counters invoice_sends invoice_send_voids payments payment_voids invoice_regen_windows clients entities tasks users; do
    _v=$(q "SELECT count(*) FROM $_t")
    case "$_v" in
      '' | *[!0-9]*)
        echo "neplatny pocet riadkov pre $_t: '$_v'" >&2
        exit 1
        ;;
    esac
    echo "rows.$_t=$_v"
  done
  _v=$(q "SELECT number FROM invoices WHERE origin = 'issued' ORDER BY id DESC LIMIT 1")
  echo "last_invoice_number=$_v"
  _v=$(q "SELECT md5(string_agg(sha256, ',' ORDER BY id)) FROM invoice_documents")
  echo "documents_sha256_digest=$_v"
  _v=$(q "SELECT count(*) FROM invoice_documents WHERE encode(sha256(content), 'hex') <> sha256")
  case "$_v" in
    '' | *[!0-9]*)
      echo "neplatny pocet poskodenych PDF: '$_v'" >&2
      exit 1
      ;;
  esac
  echo "documents_bad_sha256=$_v"
}

# Konzistencia dumpu a manifestu (WR-03): fakty sa zistia pred aj po dumpe. Ak sa lisia, DB sa menila pocas zalohy
# a dump aj manifest sa urobia znova (najviac 3 pokusy), takze manifest vzdy sedi na dump.
FACTS1=$(mktemp)
FACTS2=$(mktemp)
_try=0
while :; do
  _try=$((_try + 1))
  STEP=manifest
  facts > "$FACTS1"
  STEP=dump
  BACKUP_NAME=task_tracker.dump BACKUP_COMPOSE_DIR="$STACK_DIR" sh "$HERE/backup.sh" "$STAGE_DIR" > /dev/null
  STEP=manifest
  facts > "$FACTS2"
  if cmp -s "$FACTS1" "$FACTS2"; then
    break
  fi
  if [ "$_try" -ge 3 ]; then
    echo "DB sa menila pocas zalohy vo vsetkych $_try pokusoch, manifest by nesedel na dump" >&2
    exit 1
  fi
  echo "DB sa menila pocas zalohy, opakujem (pokus $_try)" >&2
done

MAN="$STAGE_DIR/manifest.txt"
{
  echo "created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "env=$BACKUP_ENV_NAME"
  cat "$FACTS2"
  echo "dump_sha256=$(cut -d' ' -f1 < "$STAGE_DIR/task_tracker.dump.sha256")"
} > "$MAN"
BAD=$(sed -n 's/^documents_bad_sha256=//p' "$FACTS2")
if [ "$BAD" != 0 ]; then
  echo "poškodené PDF dokladov (sha256 nesedí): $BAD" >&2
  exit 1
fi

STEP=restic
restic backup --tag db --host "$RESTIC_HOST" "$STAGE_DIR" > /dev/null

STEP=forget
# shellcheck disable=SC2086
restic forget $RESTIC_FORGET_POLICY --prune > /dev/null

if [ "$(date +%u)" = 7 ] || [ -n "${BACKUP_CHECK-}" ]; then
  STEP=check
  restic check --read-data-subset=5% > /dev/null
fi

STEP=ok
trap - EXIT
cleanup
rm -f "$ERRLOG"
exec 2>&3
ping_ok "$HC_URL"
echo "OK: snapshot db $BACKUP_ENV_NAME"
