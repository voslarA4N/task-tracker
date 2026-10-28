#!/bin/sh
# Serverový deploy (prod aj staging; spúšťa sa ako <adresár stacku>/deploy.sh,
# /opt/work-tracker alebo /opt/work-tracker-staging).
# Poradie: pull -> db -> záloha -> overenie zálohy -> rotácia -> [staging: reset DB] ->
# migrácia -> [staging: seed] -> certifikát -> up -> .last-good-tag. Migrácia sa NIKDY
# nespustí bez overenej zálohy (D-08). Pri zlyhaní zostáva bežať predošlý stack.
# Voliteľne STAGING_RESEED=1 v .env (iba staging, sekcia 19 v docs/ops/server.md): po zálohe sa
# DB zahodí, znovu založí, zmigruje a naplní `php spark staging:seed`. Produkcia to odmieta
# pred prvým volaním dockeru.
# Povinné: IMAGE_TAG; súbory app.env, migrate.env, secrets/db_*_password (sekcia 15
# v docs/ops/server.md); v .env COMPOSE_PROJECT_NAME a EDGE_ALIAS. Voliteľné: BACKUP_DIR
# (default /var/backups/<názov adresára>), COMPOSE_FILE_NAME, DOCKER_CONFIG, SKIP_PULL=1
# (obrazy sú už stiahnuté, napr. pri prechode produkcie na edge).
set -eu
cd "$(dirname "$0")"

: "${IMAGE_TAG:?Chýba IMAGE_TAG (napr. sha-abc123)}"
export IMAGE_TAG
HERE=$(pwd -P)
BACKUP_DIR=${BACKUP_DIR:-/var/backups/$(basename "$HERE")}
COMPOSE_FILE_NAME=${COMPOSE_FILE_NAME:-compose.server.yaml}
# Každý stack má vlastný docker config: login a logout jedného deployu nezasiahne druhý.
DOCKER_CONFIG=${DOCKER_CONFIG:-$HERE/.docker}
export DOCKER_CONFIG

# Token z CI nesmie zostať na disku servera.
trap 'docker logout ghcr.io >/dev/null 2>&1 || true' EXIT

dc() { docker compose -f "$COMPOSE_FILE_NAME" "$@"; }

# Hodnoty z .env sa čítajú po kľúčoch (formát CI4 s bodkami sa nedá `source`-ovať).
env_get() { sed -n "s/^$1 *= *//p" .env | head -n1 | tr -d "'\"\r"; }

# Rozdelene tajomstva (WR-06, docs/ops/server.md sekcia 15). Kontrola este pred prvym
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
    echo "Stare rozlozenie tajomstiev. Postup: docs/ops/server.md, sekcia 15. Nic sa nenasadilo." >&2
    exit 1
  fi
}
preflight_secrets

# Projekt a alias stacku z .env; bez nich by sa stack mohol potichu pomiešať s druhým.
for key in COMPOSE_PROJECT_NAME EDGE_ALIAS; do
  if [ -z "$(env_get "$key")" ]; then
    echo "Chýba $key v .env (docs/ops/server.md, sekcia 6). Nic sa nenasadilo." >&2
    exit 1
  fi
done
COMPOSE_PROJECT_NAME=$(env_get COMPOSE_PROJECT_NAME)
export COMPOSE_PROJECT_NAME

# Reset a seed stagingu (D-01). Tvrdé poistky ešte pred prvým volaním dockeru: produkcia (projekt,
# doména alebo adresár) nesmie mať STAGING_RESEED ani `staging.seed = true` v app.env.
RESEED=$(env_get STAGING_RESEED)
APP_SEED=$(sed -n 's/^staging\.seed *= *//p' app.env | head -n1 | tr -d "'\"\r ")
preflight_reseed() {
  is_prod=0
  if [ "$COMPOSE_PROJECT_NAME" = task-tracker ] || [ "$(env_get APP_DOMAIN)" = work-tracker.vostack.dev ] || [ "$HERE" = /opt/work-tracker ]; then
    is_prod=1
  fi
  if [ "$is_prod" = 1 ] && { [ "$APP_SEED" = true ] || { [ -n "$RESEED" ] && [ "$RESEED" != 0 ]; }; }; then
    echo "Produkcia nesmie mať STAGING_RESEED ani staging.seed = true v app.env. Nic sa nenasadilo." >&2
    exit 1
  fi
  case "$RESEED" in
    ''|0) return 0 ;;
    1) ;;
    *)
      echo "STAGING_RESEED musí byť 1 alebo prázdne/0 (je '$RESEED'). Nic sa nenasadilo." >&2
      exit 1
      ;;
  esac
  if [ "$APP_SEED" != true ]; then
    echo "STAGING_RESEED=1 vyžaduje 'staging.seed = true' v app.env. Nic sa nenasadilo." >&2
    exit 1
  fi
  if [ ! -s secrets/seed_admin.env ]; then
    echo "STAGING_RESEED=1 vyžaduje neprázdny secrets/seed_admin.env (docs/ops/server.md, sekcia 19). Nic sa nenasadilo." >&2
    exit 1
  fi
  if [ -n "$(find secrets/seed_admin.env -perm /077 2>/dev/null)" ]; then
    echo "secrets/seed_admin.env musí mať práva 0600 (je čitateľný pre skupinu alebo ostatných). Nic sa nenasadilo." >&2
    exit 1
  fi
}
preflight_reseed

# Host: externá sieť edge a kontrola, že projekt nepatrí inému adresáru.
preflight_host() {
  if ! docker network inspect edge >/dev/null 2>&1; then
    echo "Chýba docker sieť edge (docs/ops/server.md, sekcia Edge proxy). Nic sa nenasadilo." >&2
    exit 1
  fi
  # V `docker ps` je .Labels reťazec, jeden label vracia iba {{.Label "..."}}. Chyba šablóny
  # nesmie kontrolu potichu preskočiť, preto sa výstup a kód dockeru kontrolujú zvlášť.
  if ! dirs=$(docker ps -a --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" \
    --format '{{.Label "com.docker.compose.project.working_dir"}}'); then
    echo "Nepodarilo sa overiť adresár projektu $COMPOSE_PROJECT_NAME (docker ps). Nic sa nenasadilo." >&2
    exit 1
  fi
  dirs=$(printf '%s\n' "$dirs" | sort -u)
  for d in $dirs; do
    if [ -n "$d" ] && [ "$d" != "$HERE" ]; then
      echo "Projekt $COMPOSE_PROJECT_NAME už beží z iného adresára ($d), tento deploy je v $HERE. Nic sa nenasadilo." >&2
      exit 1
    fi
  done
}
preflight_host

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

if [ "${SKIP_PULL:-}" = 1 ]; then
  PREFIX=${IMAGE_PREFIX:-$(env_get IMAGE_PREFIX)}
  echo "Preskakujem pull, kontrolujem lokálne obrazy ($IMAGE_TAG)"
  docker image inspect "$PREFIX-php:$IMAGE_TAG" "$PREFIX-nginx:$IMAGE_TAG" >/dev/null
else
  echo "Sťahujem obrazy ($IMAGE_TAG)"
  dc pull php nginx
fi
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

# Staging: po zálohe sa DB zahodí a znovu založí (faktúry odmietajú DELETE aj TRUNCATE triggerom, preto DROP).
# Heslá rolí číta až kontajner db zo svojich secret súborov, v argumentoch nie sú.
reseed_db() {
  started=$(date +%s)
  echo "Staging: reset DB (DROP + provision)"
  # shellcheck disable=SC2016  # $(cat ...) sa zamerne rozbali az v kontajneri db (heslá nie sú v hostiteľskom shelli)
  if ! dc exec -T db psql -U postgres -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS task_tracker WITH (FORCE)' \
    || ! dc exec -T db sh -c 'DB_OWNER_PASSWORD=$(cat "$DB_OWNER_PASSWORD_FILE") DB_RW_PASSWORD=$(cat "$DB_RW_PASSWORD_FILE") psql -v ON_ERROR_STOP=1 -U postgres -d postgres -v dbname=task_tracker -f /opt/provision/provision.sql'; then
    echo "Reset staging DB zlyhal, stará verzia beží ďalej. Záloha: $DUMP" >&2
    exit 1
  fi
  echo "Staging: reset DB hotový za $(( $(date +%s) - started )) s"
}
if [ "$RESEED" = 1 ]; then
  reseed_db
fi

# Migrácia (stará verzia beží ďalej, `up` sa pri zlyhaní nevolá). Jednorazový kontajner
# `migrate` má migrate.env s app_owner, bežiaci php-fpm ho nevidí (WR-06).
if ! dc run --rm --no-deps -T migrate php spark migrate -g owner --all; then
  echo "Migrácia zlyhala, stará verzia beží ďalej. Záloha: $DUMP" >&2
  exit 1
fi

# Staging: seed ako app_rw v jednorazovom kontajneri `seed` (iba ten vidí secrets/seed_admin.env). Po behu sa
# vlastník writable/ vráti www-data, aby php-fpm mohol zapisovať (kontajner beží ako root).
if [ "$RESEED" = 1 ]; then
  seed_started=$(date +%s)
  echo "Staging: seed (php spark staging:seed)"
  # shellcheck disable=SC2016  # $? sa rozbali v kontajneri seed
  if ! dc run --rm --no-deps -T seed sh -c 'php spark staging:seed; rc=$?; chown -R www-data:www-data writable; exit $rc'; then
    echo "Seed zlyhal, staging DB je čiastočná; stará verzia beží ďalej, ďalší deploy ju znovu vytvorí. Záloha: $DUMP" >&2
    exit 1
  fi
  echo "Staging: seed hotový za $(( $(date +%s) - seed_started )) s"
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
  # Jeden riadok na stack (podľa cesty); riadky iných stackov ostávajú.
  CRON_LINE="17 3 * * * $HERE/cert-renew.sh >> $HERE/cert-renew.log 2>&1"
  { crontab -l 2>/dev/null | grep -vF "$HERE/cert-renew.sh" || true; echo "$CRON_LINE"; } | crontab -
fi

docker image prune -f --filter until=168h > /dev/null || true
echo "Deploy hotový: $IMAGE_TAG"
