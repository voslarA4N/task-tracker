#!/bin/sh
# D-24: zaloha DB (pg_dump --format=custom) vratane PDF dokladov (bytea). Vystup: task_tracker-RRRRMMDDTHHMMSS.dump + .sha256.
# Pouzitie: scripts/backup.sh [vystupny-adresar]   (BACKUP_DB, BACKUP_DB_USER prepisu predvolene hodnoty)
set -eu
umask 077
ROOT=$(cd "$(dirname "$0")/.." && pwd)

# Vystupny adresar: relativna cesta z argumentu plati voci adresaru volajuceho (aj z cronu), predvolene <repo>/backups.
if [ $# -ge 1 ] && [ -n "$1" ]; then
  mkdir -p "$1"
  OUT=$(cd "$1" && pwd)
else
  OUT="$ROOT/backups"
fi
cd "$ROOT"

DB=${BACKUP_DB:-task_tracker}
DB_USER=${BACKUP_DB_USER:-app_owner}
NAME="task_tracker-$(date -u +%Y%m%dT%H%M%S).dump"

mkdir -p "$OUT"
trap 'rm -f "$OUT/$NAME.partial"' EXIT

docker compose exec -T db pg_dump -U "$DB_USER" --format=custom --no-owner "$DB" > "$OUT/$NAME.partial"
# Overenie: pg_restore precita cely archiv vratane datovych blokov (aj bytea PDF), nielen obsah (--list).
docker compose exec -T db pg_restore -f /dev/null < "$OUT/$NAME.partial" > /dev/null
mv "$OUT/$NAME.partial" "$OUT/$NAME"
(cd "$OUT" && sha256sum "$NAME" > "$NAME.sha256")

echo "OK: $OUT/$NAME"
