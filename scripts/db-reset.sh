#!/bin/sh
# D-09: reset vyvojovej DB (drop, provisioning, migracie ako app_owner, DevSeeder).
# `--staging` (make reset-staging): namiesto DevSeeder spusti `spark staging:seed` (vymyslena historia od
# 2026-01-01, prihlasovacie udaje SEED_ADMIN_* z dev .env). Rovnaka ochrana prostredia ako pri beznom reset.
set -eu
MODE=${1:-dev}
case "$MODE" in
  dev|--staging) ;;
  *) echo "Pouzitie: db-reset.sh [--staging]" >&2; exit 2 ;;
esac
cd "$(dirname "$0")/.."
. scripts/lib-env-guard.sh
# Allow-list (development|testing), fail closed: hostitel (.env + procesne prostredie)...
require_dev_env .env || exit 1
# ...aj prostredie kontajnera php, ak tam je CI_ENVIRONMENT nastavene (nedostupny kontajner = odmietnut).
PHP_ENV=$(docker compose exec -T php sh -c 'printf "%s" "${CI_ENVIRONMENT-__unset__}"') || {
  echo "REFUSED: kontajner php nie je dostupny, prostredie sa neda overit" >&2
  exit 1
}
if [ "$PHP_ENV" != "__unset__" ]; then
  require_dev_env_value "$(env_guard_normalize "$PHP_ENV")" "kontajner php" || exit 1
fi
docker compose exec -T db psql -U postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS task_tracker WITH (FORCE)'
docker compose exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -U postgres -d postgres -v dbname=task_tracker -f /opt/provision/provision.sql'
docker compose exec -T -u www-data php php spark migrate -g owner --all
if [ "$MODE" = "--staging" ]; then
  docker compose exec -T -u www-data -e staging.seed=true php php spark staging:seed
else
  docker compose exec -T -u www-data php php spark db:seed 'App\Database\Seeds\DevSeeder'
fi
