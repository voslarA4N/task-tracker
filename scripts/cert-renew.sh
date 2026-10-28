#!/bin/sh
# Obnova Let's Encrypt certifikátu (cron z deploy.sh, jeden riadok na stack) a reload nginx.
set -eu
cd "$(dirname "$0")"

# compose.server.yaml vyžaduje IMAGE_TAG (nie je v .env)
IMAGE_TAG=$(cat .last-good-tag)
export IMAGE_TAG

docker compose -f compose.server.yaml run --rm certbot renew --quiet
docker compose -f compose.server.yaml exec -T nginx nginx -s reload
