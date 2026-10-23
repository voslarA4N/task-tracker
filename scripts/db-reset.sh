#!/bin/sh
# D-09: reset vyvojovej DB (drop, provisioning, migracie ako app_owner, DevSeeder).
set -eu
cd "$(dirname "$0")/.."
ENV_VALUE=$(grep -E '^CI_ENVIRONMENT' .env 2>/dev/null | head -n1 | cut -d= -f2 | tr -d ' "' || true)
if [ "$ENV_VALUE" = "production" ]; then
  echo "REFUSED: make reset nesmie bezat s CI_ENVIRONMENT=production" >&2
  exit 1
fi
docker compose exec -T db psql -U postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS task_tracker WITH (FORCE)'
docker compose exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U postgres -d postgres -v owner_pw="$DB_OWNER_PASSWORD" -v rw_pw="$DB_RW_PASSWORD" -v dbname=task_tracker -f /opt/provision/provision.sql'
docker compose exec -T -u www-data php php spark migrate -g owner --all
docker compose exec -T -u www-data php php spark db:seed 'App\Database\Seeds\DevSeeder'
