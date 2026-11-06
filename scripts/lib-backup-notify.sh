#!/bin/sh
# D-09: zdielane upozornenia pre backup-restic.sh, archive-yearly.sh a restore-test.sh (sourcovane).
# Kontrakt: check_env_file <cesta>, ping_ok <url>, notify_fail <predmet> <krok> <errlog> <ping_url>.
# Premenne z env suboru: BACKUP_ENV_NAME, ALERT_TO, ALERT_FROM, ALERT_SMTP_URL, ALERT_SMTP_USER, ALERT_SMTP_PASS_FILE.
# Tajomstva (heslo repozitara, URL pingu, SMTP heslo) sa nikdy nevypisuju na stdout/stderr ani do e-mailu.

# Env subor: existuje, patri aktualnemu pouzivatelovi a ma prava 600 alebo 400.
check_env_file() {
  if [ ! -f "$1" ]; then
    echo "CHYBA: env subor $1 neexistuje" >&2
    exit 1
  fi
  _mode=$(stat -c %a "$1")
  _owner=$(stat -c %u "$1")
  if [ "$_owner" != "$(id -u)" ]; then
    echo "CHYBA: env subor $1 nepatri aktualnemu pouzivatelovi" >&2
    exit 1
  fi
  if [ "$_mode" != 600 ] && [ "$_mode" != 400 ]; then
    echo "CHYBA: env subor $1 ma prava $_mode, vyzaduje sa 600 alebo 400" >&2
    exit 1
  fi
}

# Uspesny ping (dead man's switch). Prazdna URL = nic. Zlyhanie pingu sa len zapise bez URL.
ping_ok() {
  [ -n "${1-}" ] || return 0
  # URL je tajomstvo (UUID): ide cez konfiguraciu na stdin, nie cez argv (WR-05).
  printf 'url = "%s"\n' "$1" | curl -fsS -m 10 --retry 3 -o /dev/null -K - 2>/dev/null || echo "VAROVANIE: ping healthchecks zlyhal" >&2
  return 0
}

# Ping /fail a e-mail. Zlyhanie notifikacie sa len zapise na stderr.
notify_fail() {
  _subject=$1
  _step=$2
  _errlog=$3
  _ping=${4-}
  if [ -n "$_ping" ]; then
    printf 'url = "%s/fail"\n' "$_ping" \
      | curl -fsS -m 10 --retry 2 -o /dev/null --data-raw "FAIL ${BACKUP_ENV_NAME:-?} $_step" -K - 2>/dev/null \
      || echo "VAROVANIE: ping /fail zlyhal" >&2
  fi
  [ -n "${ALERT_TO-}" ] || return 0

  _mail=$(mktemp)
  _netrc=$(mktemp)
  {
    echo "From: ${ALERT_FROM-}"
    echo "To: $ALERT_TO"
    echo "Subject: [work-tracker] $_subject: ${BACKUP_ENV_NAME:-?} $(date +%F)"
    echo "MIME-Version: 1.0"
    echo "Content-Type: text/plain; charset=UTF-8"
    echo "Content-Transfer-Encoding: 8bit"
    echo
    case "$_subject" in
      *rch*) echo "Archiv sa nepodaril." ;;
      *obnov*) echo "Test obnovy sa nepodaril." ;;
      *) echo "Záloha databázy sa nepodarila." ;;
    esac
    echo "Krok: $_step"
    echo "Cas: $(date '+%F %T %Z'), prostredie: ${BACKUP_ENV_NAME:-?}"
    echo
    echo "Poslednych 20 riadkov chyboveho vystupu:"
    if [ -f "$_errlog" ]; then tail -n 20 "$_errlog"; fi
    echo
    echo "Postup obnovy a kontroly: docs/ops/backup.md"
  } > "$_mail"

  _host=${ALERT_SMTP_URL#*://}
  _host=${_host%%[:/]*}
  {
    printf 'machine %s login %s password ' "$_host" "${ALERT_SMTP_USER-}"
    if [ -n "${ALERT_SMTP_PASS_FILE-}" ] && [ -f "$ALERT_SMTP_PASS_FILE" ]; then tr -d '\n' < "$ALERT_SMTP_PASS_FILE"; fi
    echo
  } > "$_netrc"

  curl -sS --ssl-reqd -m 30 --url "$ALERT_SMTP_URL" --mail-from "${ALERT_FROM-}" --mail-rcpt "$ALERT_TO" \
    --netrc-file "$_netrc" --upload-file "$_mail" > /dev/null 2>&1 \
    || echo "VAROVANIE: odoslanie e-mailu s upozornenim zlyhalo" >&2
  rm -f "$_mail" "$_netrc"
  return 0
}
