#!/bin/sh
# Beh iba pri prazdnom datovom adresari (docker-entrypoint-initdb.d).
set -eu
for db in task_tracker task_tracker_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v dbname="$db" \
    -f /opt/provision/provision.sql
done
