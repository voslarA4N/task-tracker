#!/bin/sh
# Obnova Let's Encrypt certifikatu mail stacku (cron ako deploy v /opt/mail, docs/ops/mail.md):
# certbot renew -> kopia pre Stalwart (uid 2000) -> ReloadTlsCertificates -> reload nginx `web`.
#
#   sh scripts/mail-cert-renew.sh              # denne z cronu: renew + kopia + reload
#   sh scripts/mail-cert-renew.sh --copy-only  # prva instalacia po `certbot certonly`: iba kopia
#
# Pozor: Stalwart zmaze File-certifikat, ktory expiroval; vtedy treba objekt vytvorit znova
# (mail-provision.sh). Preto vystup z cronu (MAILTO alebo log) musi niekto citat.
set -eu
umask 077
cd "$(dirname "$0")/.."

COMPOSE_PROJECT=${COMPOSE_PROJECT:-mail}
COMPOSE_FILES=${COMPOSE_FILES:--f compose.mail.yaml}

COPY_ONLY=0
case "${1:-}" in
  --copy-only) COPY_ONLY=1 ;;
  "") ;;
  *) echo "CHYBA: neznamy argument $1" >&2; exit 1 ;;
esac

# shellcheck disable=SC2086
dc() { docker compose -p "$COMPOSE_PROJECT" $COMPOSE_FILES "$@"; }

if [ "$COPY_ONLY" = 0 ]; then
  dc run --rm -T certbot renew --quiet
fi

# live/ su symlinky do archive/ (privkey 0600 root), preto kopia robi root v kontajneri certbot
# s vlastnikom 2000 (uzivatel stalwart) a install ju dereferencuje.
# shellcheck disable=SC2016
dc run --rm -T --entrypoint sh certbot -c '
  set -e
  d=/etc/letsencrypt/live/mail.vostack.dev
  install -o 2000 -g 2000 -m 0444 "$d/fullchain.pem" /tls/fullchain.pem
  install -o 2000 -g 2000 -m 0400 "$d/privkey.pem" /tls/privkey.pem
'

if [ "$COPY_ONLY" = 0 ]; then
  sh scripts/mail-provision.sh --cli create Action/ReloadTlsCertificates --json '{}' >/dev/null
  dc exec -T web nginx -s reload
fi
echo "OK: certifikat skopirovany$([ "$COPY_ONLY" = 0 ] && echo ', Stalwart a nginx znovu nacitane')"
