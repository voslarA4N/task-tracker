#!/bin/sh
# Serverový deploy stagingu (spúšťa sa ako /opt/work-tracker/deploy.sh).
# Poradie: pull -> db -> záloha -> overenie zálohy -> rotácia -> migrácia ->
# certifikát -> up -> .last-good-tag. Migrácia sa NIKDY nespustí bez overenej
# zálohy (D-08). Pri zlyhaní zostáva bežať predošlý stack.
# Povinné: IMAGE_TAG; súbory app.env, migrate.env, secrets/db_*_password (sekcia 15
# v docs/ops/staging.md). Voliteľné: BACKUP_DIR, COMPOSE_FILE_NAME.
set -eu
cd "$(dirname "$0")"

: "${IMAGE_TAG:?Chýba IMAGE_TAG (napr. sha-abc123)}"
export IMAGE_TAG
BACKUP_DIR=${BACKUP_DIR:-/var/backups/work-tracker}
COMPOSE_FILE_NAME=${COMPOSE_FILE_NAME:-compose.staging.yaml}

# Token z CI nesmie zostať na disku servera.
trap 'docker logout ghcr.io >/dev/null 2>&1 || true' EXIT

dc() { docker compose -f "$COMPOSE_FILE_NAME" "$@"; }

# Hodnoty z .env sa čítajú po kľúčoch (formát CI4 s bodkami sa nedá `source`-ovať).
env_get() { sed -n "s/^$1 *= *//p" .env | head -n1 | tr -d "'\"\r"; }

# Rozdelene tajomstva (WR-06, docs/ops/staging.md sekcia 15). Kontrola este pred prvym
# volanim dockeru: pri starom rozlozeni deploy skonci a bezi predosly stack.
preflight_secrets() {
  bad=0
  for f in app.env migrate.env secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password; do
    if [ ! -s "$f" ]; then
      echo "Chyba alebo je prazdny $f" >&2
      bad=1
    elif [ -n "$(find "$f" -perm -004 2>/dev/null)" ]; then
      echo "$f je citatelny pre vsetkych (ocakavane 0640/0600)" >&2
      bad=1
    fi
  done
  # .env cita iba compose a tento skript, hesla do neho nepatria.
  if grep -Eq '^(DB_[A-Z_]*PASSWORD|database\.|encryption\.|auth\.backupCodePepper)' .env 2>/dev/null; then
    echo ".env obsahuje hesla alebo CI4 kluce (DB_*_PASSWORD, database.*, encryption.*, auth.*); patria do app.env / migrate.env / secrets/" >&2
    bad=1
  fi
  # php-fpm nesmie vidiet superuser ani app_owner.
  if [ -f app.env ] && grep -Eq '^(DB_[A-Z_]*PASSWORD|database\.(owner|tests|tests_owner)\.)' app.env; then
    echo "app.env obsahuje DB_*_PASSWORD alebo database.owner/tests*; smie mat iba database.default.* (app_rw)" >&2
    bad=1
  fi
  if [ -f migrate.env ] && ! grep -Eq '^database\.owner\.password *= *[^ ]' migrate.env; then
    echo "migrate.env nema database.owner.password" >&2
    bad=1
  fi
  if [ "$bad" -ne 0 ]; then
    echo "Stare rozlozenie tajomstiev. Postup: docs/ops/staging.md, sekcia 15. Nic sa nenasadilo." >&2
    exit 1
  fi
}
preflight_secrets

TLS_MODE=$(env_get TLS_MODE)
APP_DOMAIN=$(env_get APP_DOMAIN)
LE_EMAIL=$(env_get LE_EMAIL)

ensure_cert() {
  case "$TLS_MODE" in
    letsencrypt)
      if [ ! -f "letsencrypt/live/$APP_DOMAIN/fullchain.pem" ]; then
        echo "Vydávam certifikát pre $APP_DOMAIN (DNS-01)"
        dc run --rm certbot certonly --dns-cloudflare \
          --dns-cloudflare-credentials /secrets/cloudflare.ini \
          --dns-cloudflare-propagation-seconds 30 \
          -d "$APP_DOMAIN" -m "$LE_EMAIL" --agree-tos --non-interactive --keep-until-expiring
      fi
      ;;
    *)
      if [ ! -f certs/origin.pem ]; then
        echo "Chýba certs/origin.pem (Cloudflare Origin CA)" >&2
        exit 1
      fi
      ;;
  esac
}

echo "Sťahujem obrazy ($IMAGE_TAG)"
dc pull php nginx
dc up -d --wait db

# Záloha pred migráciou
umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
DUMP="$BACKUP_DIR/pre-$(date -u +%Y%m%dT%H%M%SZ).dump"

if ! dc exec -T db pg_dump -U postgres -Fc task_tracker > "$DUMP" \
  || [ ! -s "$DUMP" ] \
  || ! dc exec -T db pg_restore -l < "$DUMP" > /dev/null; then
  rm -f "$DUMP"
  echo 'Záloha zlyhala, migrácia sa nespustí' >&2
  exit 1
fi
chmod 600 "$DUMP"
echo "Záloha overená: $DUMP"

# Rotácia: ponechať 14 najnovších (názov obsahuje UTC čas)
# shellcheck disable=SC2012
ls -1 "$BACKUP_DIR"/pre-*.dump 2>/dev/null | sort -r | tail -n +15 | while read -r old; do
  rm -f "$old"
done

# Migrácia (stará verzia beží ďalej, `up` sa pri zlyhaní nevolá). Jednorazový kontajner
# `migrate` má migrate.env s app_owner, bežiaci php-fpm ho nevidí (WR-06).
if ! dc run --rm --no-deps -T migrate php spark migrate -g owner --all; then
  echo "Migrácia zlyhala, stará verzia beží ďalej. Záloha: $DUMP" >&2
  exit 1
fi

ensure_cert

if ! dc up -d --wait --remove-orphans; then
  echo 'Nový stack sa nespustil' >&2
  if [ -s .last-good-tag ]; then
    IMAGE_TAG=$(cat .last-good-tag)
    export IMAGE_TAG
    echo "Vraciam posledný dobrý tag: $IMAGE_TAG" >&2
    dc up -d --wait || true
  fi
  exit 1
fi

printf '%s\n' "$IMAGE_TAG" > .last-good-tag

if [ "$TLS_MODE" = "letsencrypt" ]; then
  CRON_LINE='17 3 * * * /opt/work-tracker/cert-renew.sh >> /opt/work-tracker/cert-renew.log 2>&1'
  { crontab -l 2>/dev/null | grep -vF 'cert-renew.sh' || true; echo "$CRON_LINE"; } | crontab -
fi

docker image prune -f --filter until=168h > /dev/null || true
echo "Deploy hotový: $IMAGE_TAG"
