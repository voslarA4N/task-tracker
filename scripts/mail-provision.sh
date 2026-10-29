#!/bin/sh
# Idempotentne zalozenie a udrziavanie Stalwartu cez stalwart-cli (docs/ops/mail.md).
# Spusta sa v /opt/mail (server) aj z korena repozitara (scripts/mail-selftest.sh).
#
#   sh scripts/mail-provision.sh --init          # raz: zapise config.json do volume stalwart-etc
#   sh scripts/mail-provision.sh                 # aplikuje docker/mail/stalwart/plan.ndjson
#   sh scripts/mail-provision.sh --print-dns     # vypise DNS zonu domeny (DKIM selektory, _mta-sts id)
#   sh scripts/mail-provision.sh --cli query Domain   # lubovolny prikaz stalwart-cli ako sprava
#
# Tajomstva: SECRETS_DIR/admin_password, info_password, noreply_password (nikdy v gite).
# Pri prvom bootstrape sa autentifikuje docasnym STALWART_RECOVERY_ADMIN:
#   STALWART_BOOTSTRAP_USER=admin STALWART_BOOTSTRAP_PASSWORD=<to iste heslo> sh scripts/mail-provision.sh
# Inak sa pouzije principal stalwart-admin@vostack.dev s heslom z admin_password.
# Heslo ide do stalwart-cli iba cez premennu prostredia, nikdy cez argumenty prikazoveho riadku.
#
# Poznamka: `apply` pri kazdom behu hlasi "updated" (upsert prepise rovnake hodnoty, heslo sa znova
# zahashuje), ale nevytvara duplicity: pocet domen/uctov/aliasov/listenerov ostava rovnaky.
set -eu
umask 077
cd "$(dirname "$0")/.."

COMPOSE_PROJECT=${COMPOSE_PROJECT:-mail}
MAIL_NETWORK=${MAIL_NETWORK:-mail}
SECRETS_DIR=${SECRETS_DIR:-./secrets}
COMPOSE_FILES=${COMPOSE_FILES:--f compose.mail.yaml}
CLI_IMAGE=${CLI_IMAGE:-stalwartlabs/cli:1.0.14}
STALWART_URL=${STALWART_URL:-http://stalwart:8080}
PLAN=docker/mail/stalwart/plan.ndjson
CONFIG_JSON=docker/mail/stalwart/config.json
DOMAIN=vostack.dev

die() {
  echo "CHYBA: $*" >&2
  exit 1
}

# cli_run <argumenty stalwart-cli>; CLI_WORKDIR (ak je nastaveny) sa mountuje ako /work (iba na citanie).
# --user: docasny adresar je 0700 aktualneho pouzivatela, kontajner ho inak neprecita.
cli_run() {
  if [ -n "${CLI_WORKDIR:-}" ]; then
    docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp --network "$MAIL_NETWORK" \
      -v "$CLI_WORKDIR:/work:ro" -w /work \
      -e STALWART_URL -e STALWART_USER -e STALWART_PASSWORD "$CLI_IMAGE" "$@"
  else
    docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp --network "$MAIL_NETWORK" \
      -e STALWART_URL -e STALWART_USER -e STALWART_PASSWORD "$CLI_IMAGE" "$@"
  fi
}

read_secret() {
  f=$SECRETS_DIR/$1
  [ -s "$f" ] || die "chyba alebo prazdny subor $f"
  v=$(tr -d '\n' < "$f")
  # iba bezpecne znaky: heslo ide do JSON planu a do sed (bez uvodzoviek, lomitok, &, |)
  case "$v" in
    *[!A-Za-z0-9+/=_.@%^-]*) die "heslo v $f obsahuje nepovoleny znak (povolene: A-Z a-z 0-9 + / = _ . @ % ^ -)" ;;
  esac
  printf '%s' "$v"
}

set_auth() {
  if [ -n "${STALWART_BOOTSTRAP_PASSWORD:-}" ]; then
    STALWART_USER=${STALWART_BOOTSTRAP_USER:-admin}
    STALWART_PASSWORD=$STALWART_BOOTSTRAP_PASSWORD
  else
    STALWART_USER=stalwart-admin@$DOMAIN
    STALWART_PASSWORD=$(read_secret admin_password)
  fi
  export STALWART_URL STALWART_USER STALWART_PASSWORD
}

wait_ready() {
  i=0
  while [ "$i" -lt 60 ]; do
    if cli_run query Domain >/dev/null 2>&1; then
      return 0
    fi
    i=$((i + 1))
    sleep 2
  done
  die "Stalwart neodpoveda na $STALWART_URL (siet $MAIL_NETWORK) alebo zle prihlasenie"
}

MODE=apply
case "${1:-}" in
  --init) MODE=init ;;
  --print-dns) MODE=dns ;;
  --cli) MODE=cli; shift ;;
  "") ;;
  *) die "neznamy argument $1" ;;
esac

if [ "$MODE" = init ]; then
  VOL=${COMPOSE_PROJECT}_stalwart-etc
  # rovnake stitky ako compose, aby nehlasil "volume was not created by compose"
  docker volume create --label "com.docker.compose.project=$COMPOSE_PROJECT" \
    --label "com.docker.compose.volume=stalwart-etc" "$VOL" >/dev/null
  docker run --rm -v "$VOL:/e" -v "$PWD/$CONFIG_JSON:/src/config.json:ro" alpine:3.24 \
    sh -c 'if [ -s /e/config.json ]; then echo "config.json uz existuje, nechavam"; else cp /src/config.json /e/config.json; fi; chown -R 2000:2000 /e'
  echo "OK: config.json v $VOL"
  exit 0
fi

set_auth

if [ "$MODE" = cli ]; then
  cli_run "$@"
  exit 0
fi

wait_ready

if [ "$MODE" = dns ]; then
  ID=$(cli_run query Domain | awk -v d="$DOMAIN" '$2 == d { print $1 }')
  [ -n "$ID" ] || die "domena $DOMAIN este neexistuje (najprv spusti provisioning)"
  cli_run get Domain "$ID" --fields dnsZoneFile
  exit 0
fi

# apply: plan so substituovanymi heslami do docasneho adresara 0700, zmaze sa v trape
INFO_PW=$(read_secret info_password)
NOREPLY_PW=$(read_secret noreply_password)
ADMIN_PW=$(read_secret admin_password)
TMPD=$(mktemp -d)
chmod 700 "$TMPD"
trap 'rm -rf "$TMPD"' EXIT INT TERM
sed -e "s|<INFO_PASSWORD>|$INFO_PW|g" \
    -e "s|<NOREPLY_PASSWORD>|$NOREPLY_PW|g" \
    -e "s|<ADMIN_PASSWORD>|$ADMIN_PW|g" "$PLAN" > "$TMPD/plan.ndjson"
if grep -q '<[A-Z]*_PASSWORD>' "$TMPD/plan.ndjson"; then
  die "v plane ostal nenahradeny placeholder hesla"
fi

CLI_WORKDIR=$TMPD cli_run apply --file plan.ndjson --quiet
cli_run create Action/ReloadSettings --json '{}' >/dev/null
cli_run create Action/ReloadTlsCertificates --json '{}' >/dev/null
echo "OK: plan aplikovany. Novy listener (587) sa prejavi az po restarte stalwartu (docker compose up -d)."
