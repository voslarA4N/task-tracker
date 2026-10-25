#!/bin/sh
# Beh iba pri prazdnom datovom adresari (docker-entrypoint-initdb.d).
set -eu

# Staging/produkcia: hesla roli ako Docker secrets (*_FILE, WR-06); dev ich ma priamo v prostredi.
if [ -n "${DB_OWNER_PASSWORD_FILE:-}" ]; then
  DB_OWNER_PASSWORD=$(cat "$DB_OWNER_PASSWORD_FILE")
  export DB_OWNER_PASSWORD
fi
if [ -n "${DB_RW_PASSWORD_FILE:-}" ]; then
  DB_RW_PASSWORD=$(cat "$DB_RW_PASSWORD_FILE")
  export DB_RW_PASSWORD
fi
: "${DB_OWNER_PASSWORD:?chyba DB_OWNER_PASSWORD alebo DB_OWNER_PASSWORD_FILE}"
: "${DB_RW_PASSWORD:?chyba DB_RW_PASSWORD alebo DB_RW_PASSWORD_FILE}"

for db in task_tracker task_tracker_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v dbname="$db" \
    -f /opt/provision/provision.sql
done
