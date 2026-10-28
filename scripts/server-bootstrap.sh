#!/bin/sh
# Pripravenie servera od nuly (prod + staging + edge; spusta sa ako root, idempotentne).
# Na zivej produkcii sa nespusta, tam plati cut-over postup v docs/ops/server.md.
# Pouzitie: ssh root@2.31.74.48 "DEPLOY_PUBKEY='<verejny kluc>' sh -s" < scripts/server-bootstrap.sh
# Skript negeneruje ani nevypisuje ziadne aplikacne tajomstva.
set -eu

if [ -z "${DEPLOY_PUBKEY:-}" ]; then
  echo "CHYBA: premenna DEPLOY_PUBKEY je povinna (verejny SSH kluc pre pouzivatela deploy)." >&2
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
  echo "CHYBA: skript sa musi spustit ako root." >&2
  exit 1
fi

# 1. Kontrola distribucie
if [ ! -f /etc/os-release ]; then
  echo "CHYBA: chyba /etc/os-release." >&2
  exit 1
fi
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
  ubuntu | debian) ;;
  *)
    echo "CHYBA: podporovany je iba ubuntu alebo debian (zistene: ${ID:-neznamy})." >&2
    exit 1
    ;;
esac

# 2. Zakladne balicky
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl gnupg unattended-upgrades

# 3. Docker Engine z oficialneho apt repozitara
install -m 0755 -d /etc/apt/keyrings
if [ ! -f /etc/apt/keyrings/docker.asc ]; then
  curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
fi
ARCH=$(dpkg --print-architecture)
CODENAME=${VERSION_CODENAME:-}
echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${CODENAME} stable" \
  >/etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# 4. Docker log rotacia, restart iba pri zmene
mkdir -p /etc/docker
DAEMON_TMP=$(mktemp)
cat >"$DAEMON_TMP" <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
JSON
if [ ! -f /etc/docker/daemon.json ] || ! cmp -s "$DAEMON_TMP" /etc/docker/daemon.json; then
  cp "$DAEMON_TMP" /etc/docker/daemon.json
  systemctl restart docker
fi
rm -f "$DAEMON_TMP"
systemctl enable docker

# 5. Pouzivatel deploy
if ! id deploy >/dev/null 2>&1; then
  useradd -m -s /bin/bash deploy
fi
usermod -aG docker deploy
install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
AK=/home/deploy/.ssh/authorized_keys
touch "$AK"
if ! grep -qxF "$DEPLOY_PUBKEY" "$AK"; then
  printf '%s\n' "$DEPLOY_PUBKEY" >>"$AK"
fi
chown deploy:deploy "$AK"
chmod 0600 "$AK"

# 6. SSH hardening
cat >/etc/ssh/sshd_config.d/10-work-tracker.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
CONF
sshd -t
systemctl reload ssh 2>/dev/null || systemctl reload sshd

# 7. Adresare pre oba stacky (prod a staging) a edge
for d in /opt/work-tracker /opt/work-tracker-staging; do
  install -d -m 0750 -o deploy -g deploy "$d"
  install -d -m 0700 -o deploy -g deploy "$d/secrets"
  # GID 82 (www-data v alpine php obraze) na hostitelovi nema meno; uutils install -g ho neprijme, chown ano
  install -d -m 0750 -o deploy "$d/certs"
  chown deploy:82 "$d/certs"
  install -d -m 0750 -o deploy -g deploy "$d/letsencrypt"
  install -d -m 0750 -o deploy -g deploy "$d/docker"
done
install -d -m 0700 -o deploy -g deploy /var/backups/work-tracker
install -d -m 0700 -o deploy -g deploy /var/backups/work-tracker-staging
install -d -m 0750 -o deploy -g deploy /opt/edge

# Siet edge s pevnou podsietou (realip v stack nginx dovery prave jej)
if ! docker network inspect edge >/dev/null 2>&1; then
  docker network create --driver bridge --subnet 10.250.250.0/24 edge
fi

# 8. Odtlacok host kluca a dalsie kroky
echo
echo "Hotovo. Odtlacok SSH host kluca servera (posli ho spat na overenie known_hosts):"
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
echo
echo "Dalsie kroky: docs/ops/server.md, sekcia 6 (.env, app.env, migrate.env, secrets/db_*_password) a sekcia 7 (zaloha tajomstiev)."
