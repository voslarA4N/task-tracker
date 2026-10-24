#!/bin/sh
# Obnova Let's Encrypt certifikátu (cron z deploy.sh) a reload nginx.
set -eu
cd "$(dirname "$0")"

# compose.staging.yaml vyžaduje IMAGE_TAG (nie je v .env)
IMAGE_TAG=$(cat .last-good-tag)
export IMAGE_TAG

docker compose -f compose.staging.yaml run --rm certbot renew --quiet
docker compose -f compose.staging.yaml exec -T nginx nginx -s reload
