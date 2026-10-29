#!/bin/sh
# Nocna zaloha mail stacku (docs/ops/mail.md): stop stalwart + roundcube, tar volumov, start.
# RocksDB nema online zalohu, kopia beziaceho adresara by bola nekonzistentna. Vypadok je rad sekund,
# odosielajuce MTA opakuju. Archiv obsahuje aj hesla a DKIM kluce, preto 0600; off-site neskor cez restic.
#
# cron (deploy): 30 3 * * * cd /opt/mail && sh scripts/mail-backup.sh >> /opt/mail/backup.log 2>&1
set -eu
umask 077

MAIL_DIR=${MAIL_DIR:-/opt/mail}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/mail}
COMPOSE_PROJECT=${COMPOSE_PROJECT:-mail}
COMPOSE_FILES=${COMPOSE_FILES:--f compose.mail.yaml}
TLS_DIR=${MAIL_TLS_DIR:-$MAIL_DIR/tls}
SECRETS_DIR=${SECRETS_DIR:-$MAIL_DIR/secrets}
ENV_FILE=${BACKUP_ENV_FILE:-$MAIL_DIR/.env}
KEEP_DAYS=${KEEP_DAYS:-14}

cd "$MAIL_DIR"
install -d -m 0700 "$BACKUP_DIR"

# shellcheck disable=SC2086
dc() { docker compose -p "$COMPOSE_PROJECT" $COMPOSE_FILES "$@"; }

STAMP=$(date +%Y%m%dT%H%M%S)
NAME=mail-$STAMP.tgz
OUT=$BACKUP_DIR/$NAME

# sluzby musia ist znova aj pri chybe
start_services() { dc start stalwart roundcube >/dev/null 2>&1 || true; }
trap start_services EXIT INT TERM

for v in stalwart-data stalwart-etc roundcube-db; do
  docker volume inspect "${COMPOSE_PROJECT}_$v" >/dev/null || { echo "CHYBA: chyba volume ${COMPOSE_PROJECT}_$v" >&2; exit 1; }
done

dc stop stalwart roundcube >/dev/null

# volume a doplnky sa mountuju do /src/<meno>, archiv je tar tych adresarov
set -- -v "${COMPOSE_PROJECT}_stalwart-data:/src/stalwart-data:ro" \
       -v "${COMPOSE_PROJECT}_stalwart-etc:/src/stalwart-etc:ro" \
       -v "${COMPOSE_PROJECT}_roundcube-db:/src/roundcube-db:ro"
[ -d "$TLS_DIR" ] && set -- "$@" -v "$TLS_DIR:/src/tls:ro"
[ -d "$SECRETS_DIR" ] && set -- "$@" -v "$SECRETS_DIR:/src/secrets:ro"
[ -f "$ENV_FILE" ] && set -- "$@" -v "$ENV_FILE:/src/env:ro"

docker run --rm "$@" -v "$BACKUP_DIR:/b" -e OWNER="$(id -u):$(id -g)" -e NAME="$NAME" alpine:3.24 \
  sh -c 'tar czf "/b/$NAME.partial" -C /src . && chown "$OWNER" "/b/$NAME.partial" && chmod 0600 "/b/$NAME.partial"'

start_services

tar tzf "$OUT.partial" >/dev/null
tar tzf "$OUT.partial" | grep -q '^\./stalwart-data/' || { echo "CHYBA: archiv neobsahuje stalwart-data" >&2; rm -f "$OUT.partial"; exit 1; }
mv "$OUT.partial" "$OUT"
(cd "$BACKUP_DIR" && sha256sum "$NAME" > "$NAME.sha256")
chmod 0600 "$OUT" "$OUT.sha256"

# rotacia: archivy starsie ako KEEP_DAYS
find "$BACKUP_DIR" -maxdepth 1 -name 'mail-*.tgz*' -mtime "+$((KEEP_DAYS - 1))" -delete

echo "OK: $OUT"
