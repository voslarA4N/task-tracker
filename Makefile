.DEFAULT_GOAL := help

DC        := docker compose
EXEC      := $(DC) exec -T -u www-data -e COMPOSER_HOME=/tmp/composer php
EXEC_ROOT := $(DC) exec -T php

.PHONY: help doctor up down ps logs shell composer spark migrate reset test-db perms test test-unit stan cs cs-fix check certs smoke spike-pdf vendor-assets

help: ## Vypíše dostupné príkazy
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

doctor: ## Skontroluje predpoklady vývojového prostredia
	sh scripts/doctor.sh

up: ## Zostaví a spustí celé prostredie
	$(DC) up -d --build --wait

down: ## Zastaví prostredie
	$(DC) down

ps: ## Stav služieb
	$(DC) ps

logs: ## Sleduje logy služieb
	$(DC) logs -f --tail=100

shell: ## Root shell v kontajneri php
	$(DC) exec php sh

composer: ## Composer v kontajneri (ARGS="install")
	$(EXEC_ROOT) composer $(ARGS)

spark: ## CI4 spark ako www-data (ARGS="routes")
	$(EXEC) php spark $(ARGS)

migrate: ## Migrácie ako app_owner
	$(EXEC) php spark migrate -g owner --all

reset: ## Reset vývojovej DB (skript z plánu 01-05)
	sh scripts/db-reset.sh

test-db: ## Príprava testovacej DB (skript z plánu 01-05)
	sh scripts/test-db.sh

perms: ## Oprava vlastníka writable/ na www-data
	$(EXEC_ROOT) chown -R www-data:www-data writable

test: perms test-db ## Všetky testy
	$(EXEC) composer test

test-unit: ## Unit testy
	$(EXEC) composer test:unit

stan: ## Statická analýza (PHPStan)
	$(EXEC) composer stan

cs: ## Kontrola štýlu kódu
	$(EXEC) composer cs

cs-fix: ## Oprava štýlu kódu
	$(EXEC_ROOT) composer cs-fix

check: stan cs test ## stan + cs + test (pred pushom)

certs: ## Vygeneruje mkcert certifikát a skontroluje dôveru CA
	sh scripts/certs.sh

smoke: perms ## End-to-end kontrola stacku (HTTPS, hlavičky, Mailpit)
	sh scripts/smoke.sh

spike-pdf: perms ## Spike mPDF (studený štart, opakovanie, FPM)
	$(EXEC) php scripts/spikes/mpdf.php --cold && $(EXEC) php scripts/spikes/mpdf.php && $(EXEC) sh scripts/spikes/mpdf-fpm.sh

vendor-assets: ## Stiahne frontend knižnice do public/assets/vendor
	sh scripts/vendor-assets.sh
