#!/bin/sh
# Idempotentna instalacia zaloh na server (D-07, D-09). Spusta root z adresara s rozbalenym tarom skriptov:
#   sudo sh install-backup.sh            (ostro)
#   DRY_RUN=1 sh install-backup.sh       (iba vypise kroky s prefixom PLAN:, nic nemeni, nevyzaduje root)
# Skripty pre root cron sa kopiruju do root-owned adresara (T-12-43), restic sa overuje cez SHA256SUMS (T-12-44).
# Skript necita ani nemeni aplikacne env subory ani tajomstva aplikacie (T-12-46), cron sa iba vypise.
set -eu

DRY_RUN=${DRY_RUN:-0}
RESTIC_VERSION=${RESTIC_VERSION:-0.19.1}
INSTALL_PREFIX=${INSTALL_PREFIX:-/usr/local/lib/work-tracker-backup}
REPO_DIR=${REPO_DIR:-/var/backups/restic/work-tracker}
ENV_FILE=${ENV_FILE:-/root/.config/work-tracker-backup.env}
PULL_USER=${PULL_USER:-backuppull}
SRC_DIR=$(cd "$(dirname "$0")" && pwd)
SCRIPTS="backup.sh backup-restic.sh lib-restic-policy.sh lib-backup-notify.sh archive-yearly.sh restore-test.sh"
SUDOERS_FILE=/etc/sudoers.d/work-tracker-backuppull

if [ "$DRY_RUN" != "1" ] && [ "$(id -u)" -ne 0 ]; then
  echo "CHYBA: spusti ako root (sudo sh install-backup.sh)." >&2
  exit 1
fi

if [ -z "${RESTIC_ARCH:-}" ]; then
  case "$(uname -m)" in
    x86_64) RESTIC_ARCH=amd64 ;;
    aarch64) RESTIC_ARCH=arm64 ;;
    *) RESTIC_ARCH= ;;
  esac
fi
case "$RESTIC_ARCH" in
  amd64 | arm64) ;;
  *)
    echo "CHYBA: nepodporovana architektura (${RESTIC_ARCH:-neznama}), podporovane su amd64 a arm64." >&2
    exit 1
    ;;
esac

run() {
  if [ "$DRY_RUN" = "1" ]; then
    echo "PLAN: $*"
  else
    "$@"
  fi
}

# 2. restic
V=$RESTIC_VERSION
BASE="https://github.com/restic/restic/releases/download/v$V"
BZ="restic_${V}_linux_${RESTIC_ARCH}.bz2"
if command -v restic > /dev/null 2>&1 && restic version 2> /dev/null | grep -q "restic $V "; then
  echo "restic $V je uz nainstalovany, preskakujem."
elif [ "$DRY_RUN" = "1" ]; then
  echo "PLAN: stiahnut $BASE/$BZ a $BASE/SHA256SUMS do mktemp -d"
  echo "PLAN: sha256sum -c --ignore-missing SHA256SUMS (chyba = koniec)"
  echo "PLAN: bunzip2 $BZ"
  echo "PLAN: install -o root -g root -m 0755 restic_${V}_linux_${RESTIC_ARCH} /usr/local/bin/restic"
else
  if ! command -v bunzip2 > /dev/null 2>&1; then
    if command -v apt-get > /dev/null 2>&1; then
      echo "bunzip2 chyba, instalujem balik bzip2."
      DEBIAN_FRONTEND=noninteractive apt-get install -y -q bzip2 > /dev/null
    else
      echo "CHYBA: chyba bunzip2 (balik bzip2), nainstaluj ho a spusti znova." >&2
      exit 1
    fi
  fi
  TMP=$(mktemp -d)
  trap 'rm -rf "$TMP"' EXIT
  (
    cd "$TMP"
    curl -fsSLO "$BASE/$BZ"
    curl -fsSLO "$BASE/SHA256SUMS"
    if ! sha256sum -c --ignore-missing SHA256SUMS; then
      echo "CHYBA: kontrolny sucet restic nesedi, koniec." >&2
      exit 1
    fi
    bunzip2 "$BZ"
  )
  umask 022
  install -o root -g root -m 0755 "$TMP/restic_${V}_linux_${RESTIC_ARCH}" /usr/local/bin/restic
fi

# 3. skripty (root-owned, mimo adresara, ktory meni deploy)
umask 022
run install -d -o root -g root -m 0755 "$INSTALL_PREFIX/scripts"
for s in $SCRIPTS; do
  if [ "$DRY_RUN" != "1" ] && [ ! -f "$SRC_DIR/$s" ]; then
    echo "CHYBA: chyba $SRC_DIR/$s (rozbal tar so skriptmi)." >&2
    exit 1
  fi
  run install -o root -g root -m 0755 "$SRC_DIR/$s" "$INSTALL_PREFIX/scripts/$s"
done

# 4. adresare
umask 077
run install -d -m 0700 "$REPO_DIR"
run install -d -m 0700 /var/backups/work-tracker/restic-stage
run install -d -m 0700 /var/backups/work-tracker/archive-stage
run install -d -m 0700 /root/.config

# 5. sablona env suboru (existujuci sa nikdy neprepise)
ENV_TEMPLATE="# Zalohy work-tracker (root, 0600). Samostatny subor, vypln editorom.
BACKUP_ENV_NAME=prod
STACK_DIR=/opt/work-tracker
BACKUP_DB_USER=app_owner
BACKUP_DB=task_tracker
RESTIC_REPOSITORY=$REPO_DIR
RESTIC_PASSWORD_FILE=/root/.config/work-tracker-restic.pass
HC_URL=
HC_RESTORE_URL=
HC_ARCHIVE_URL=
ALERT_SMTP_URL=
ALERT_SMTP_USER=
ALERT_SMTP_PASS_FILE=
ALERT_FROM=
ALERT_TO="
if [ -e "$ENV_FILE" ]; then
  echo "$ENV_FILE uz existuje, nemenim."
elif [ "$DRY_RUN" = "1" ]; then
  echo "PLAN: install -m 0600 $ENV_FILE a zapisat sablonu:"
  printf '%s\n' "$ENV_TEMPLATE" | sed 's/^/PLAN:   /'
else
  install -m 0600 /dev/null "$ENV_FILE"
  printf '%s\n' "$ENV_TEMPLATE" > "$ENV_FILE"
fi

# 6. pull pouzivatel (iba citanie repozitara cez rrsync)
if [ "$DRY_RUN" = "1" ]; then
  echo "PLAN: useradd --system --create-home --shell /bin/sh $PULL_USER (ak neexistuje)"
elif ! id "$PULL_USER" > /dev/null 2>&1; then
  useradd --system --create-home --shell /bin/sh "$PULL_USER"
fi
PULL_HOME=/home/$PULL_USER
if [ "$DRY_RUN" != "1" ]; then
  PULL_HOME=$(getent passwd "$PULL_USER" | cut -d: -f6)
fi
run install -d -o "$PULL_USER" -m 0700 "$PULL_HOME/.ssh"
AK="$PULL_HOME/.ssh/authorized_keys"
if [ "$DRY_RUN" = "1" ]; then
  echo "PLAN: install -o $PULL_USER -m 0600 authorized_keys (ak neexistuje), prefix kluca: command=\"sudo -n /usr/bin/rrsync -ro $REPO_DIR\",restrict"
elif [ ! -e "$AK" ]; then
  install -o "$PULL_USER" -m 0600 /dev/null "$AK"
  {
    echo "# Vloz verejny kluc z PC vlastnika, kazdy riadok s prefixom:"
    echo "# command=\"sudo -n /usr/bin/rrsync -ro $REPO_DIR\",restrict ssh-ed25519 AAAA... backup-pull"
  } > "$AK"
fi

SUDOERS_RULE="Defaults!/usr/bin/rrsync env_keep += \"SSH_ORIGINAL_COMMAND\"
$PULL_USER ALL=(root) NOPASSWD: /usr/bin/rrsync -ro $REPO_DIR"
if [ "$DRY_RUN" = "1" ]; then
  echo "PLAN: zapisat $SUDOERS_FILE (0440), overit visudo pred presunom:"
  printf '%s\n' "$SUDOERS_RULE" | sed 's/^/PLAN:   /'
  echo "PLAN: overit command -v rrsync"
else
  STMP=$(mktemp)
  printf '%s\n' "$SUDOERS_RULE" > "$STMP"
  chmod 0440 "$STMP"
  if ! visudo -cf "$STMP"; then
    rm -f "$STMP"
    echo "CHYBA: sudoers pravidlo je neplatne, nenainstalovane." >&2
    exit 1
  fi
  install -o root -g root -m 0440 "$STMP" "$SUDOERS_FILE"
  rm -f "$STMP"
  if ! command -v rrsync > /dev/null 2>&1; then
    echo "UPOZORNENIE: chyba rrsync. Hladaj /usr/share/doc/rsync/scripts/rrsync* a nainstaluj ako /usr/bin/rrsync." >&2
  fi
fi

# 7. cron (nic sa nezapisuje automaticky)
echo
echo "Pridaj do root crontabu (editacia crontabu root, prepinac e):"
echo "10 3 * * * $INSTALL_PREFIX/scripts/backup-restic.sh >> /var/log/work-tracker-backup.log 2>&1"
echo "30 4 1 * * $INSTALL_PREFIX/scripts/restore-test.sh >> /var/log/work-tracker-restore-test.log 2>&1"
echo "0 4 2 1 * $INSTALL_PREFIX/scripts/archive-yearly.sh >> /var/log/work-tracker-archive.log 2>&1"

# 8. dalsie kroky
echo
echo "Dalsie kroky:"
echo "1. Heslo repozitara zapis do /root/.config/work-tracker-restic.pass (0600) a sucasne do spravcu hesiel."
echo "2. restic init (RESTIC_REPOSITORY a RESTIC_PASSWORD_FILE podla env suboru)."
echo "3. Vypln $ENV_FILE editorom (nano alebo vi)."
echo "4. Vloz verejny kluc do $AK s prefixom command=..., restrict."
