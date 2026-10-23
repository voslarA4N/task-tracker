#!/bin/sh
# D-08: priprava testovacej DB (drop, provisioning, migracie ako app_owner cez tests_owner).
set -eu
cd "$(dirname "$0")/.."
docker compose exec -T db psql -U postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS task_tracker_test WITH (FORCE)'
docker compose exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U postgres -d postgres -v owner_pw="$DB_OWNER_PASSWORD" -v rw_pw="$DB_RW_PASSWORD" -v dbname=task_tracker_test -f /opt/provision/provision.sql'
docker compose exec -T -u www-data -e COMPOSER_HOME=/tmp/composer php composer test:migrate
