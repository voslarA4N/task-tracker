#!/bin/sh
# Beh iba pri prazdnom datovom adresari (docker-entrypoint-initdb.d).
set -eu
for db in task_tracker task_tracker_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v owner_pw="$DB_OWNER_PASSWORD" -v rw_pw="$DB_RW_PASSWORD" -v dbname="$db" \
    -f /opt/provision/provision.sql
done
