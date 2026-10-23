---
phase: 01-z-klad-a-infra-trukt-ra
plan: 01
subsystem: infra
tags: [docker, php-8.5, codeigniter-4.7, postgres-18, mailpit, phpunit]
requires: []
provides:
  - "Multi-stage Dockerfile (base/dev/vendor/prod) nad php:8.5-fpm-alpine"
  - "compose.yaml + compose.override.yaml (php, nginx placeholder, db, mailpit)"
  - "CI4 4.7.5 kostra, composer.lock, phpunit.dist.xml (unit/integration/feature)"
  - ".env.example s database.default/owner/tests/tests_owner skupinami"
affects: [01-02, 01-03, 01-04, 01-05, 01-06, 01-07, 01-08]
tech-stack:
  added: [codeigniter4/framework 4.7.5, codeigniter4/translations 4.7.4, mpdf/mpdf 8.3.1, phpunit 11.5.57, codeigniter4/devkit, phpstan-codeigniter, postgres 18.6, mailpit 1.32]
  patterns: ["vendor/ a writable/ v named volumes (WSL 9p)", "CI4 cita iba .env (clear_env=yes)", "dev porty iba na 127.0.0.1"]
key-files:
  created: [Dockerfile, compose.yaml, compose.override.yaml, docker/php/*, .env.example, .gitattributes, .editorconfig, .dockerignore, composer.json, composer.lock, phpunit.dist.xml, tests/unit/BootTest.php]
  modified: [.gitignore, app/Config/App.php, app/Config/Filters.php]
key-decisions:
  - "PHPUnit 11.5 namiesto pinu 10.5 z appstarter-a"
  - "phpunit.dist.xml bez coverage/logging blokov (bez drivera by failOnWarning zhodil suite)"
requirements-completed: [NFR-07]
duration: ~25 min
completed: 2026-10-23
---

# Phase 1 Plan 01: Docker runtime a CI4 kostra Summary

PHP 8.5.12 FPM (dev aj prod target z jedného Dockerfile) s CodeIgniter 4.7.5, PostgreSQL 18 (healthy) a Mailpit; `composer test:unit` je zelený a prod preload nezhadzuje PHP.

## Resolved checkpoints
- **Task 1 (human-action):** orchestrátor overil `PREREQ-OK` (docker 29.9.2, Compose v5.6.1, GNU Make 4.3, `work-tracker.loc` -> 127.0.0.1 vo WSL aj Windows hosts, `/usr/local/share/ca-certificates/mkcert-rootCA.crt`, `/etc/wsl.conf` s `[network] generateHosts=false`).
- **Task 2 (human-verify, package gate):** vlastník odpovedal "approved" na celý zoznam, bez vyradení.

## Tasks
1. Task 1 checkpoint: resolved (bez commitu)
2. Task 2 checkpoint: resolved (bez commitu)
3. Task 3 Docker runtime, repo hygiena: `1c9c47e`
4. Task 4 CI4 kostra, závislosti, phpunit, prod/preload: `d4d5652`

## Deviations from Plan

**1. [Rule 3 - Blocking] Povolený Composer plugin `phpstan/extension-installer`**
- Instalácia `codeigniter/phpstan-codeigniter` (schválený balík) ťahá tranzitívne plugin, ktorý Composer zablokoval. Nastavené `allow-plugins.phpstan/extension-installer = true` v composer.json (dev-only, len registruje PHPStan rozšírenia).

**2. [Rule 3 - Blocking] phpunit.dist.xml: odstránené `<coverage>` a `<logging>`**
- Bez coverage drivera PHPUnit hlási warning a `failOnWarning=true` zhodil `test:unit`. Plán 01-07 môže coverage pridať, keď bude driver.

**3. Verzie:** `codeigniter4/translations` sa nainštalovalo ako 4.7.4 (plán očakával 4.7.x, OK). `mlocati/php-extension-installer:2.12` existuje a je pripnutý. `postgresql18-client` (18.6) je v Alpine dostupný. OPcache je v 8.5 zostavený vždy (netreba `opcache`). `preload.php` z appstarter-a sa nemusel meniť (výstup `1`, `php-fpm -t` OK).

**4. Filters:** `toolbar` ostáva len v `$aliases` (zhoda s acceptance poznámkou), odstránený z `$required['after']`.

## Notes for later plans
- **mkcert:** `mkcert.exe` NIE je na WSL PATH. Použiť `/mnt/c/Users/Work/AppData/Local/Microsoft/WinGet/Packages/FiloSottile.mkcert_Microsoft.Winget.Source_8wekyb3d8bbwe/mkcert.exe`. Linuxový `/usr/bin/mkcert` existuje, ale nemá CA, nepoužívať. Certifikáty generovať do `docker/certs/` (gitignored).
- `.env` je vytvorený lokálne z `.env.example` s náhodnými heslami (gitignored). `.env.example` obsahuje `change-me-*`.
- nginx služba v compose.yaml existuje, ale konfigurácia (`docker/nginx/templates`) príde v pláne 01-02; nespúšťať `up nginx` skôr.
- Composer vypisuje "dubious ownership" varovanie z gitu v kontajneri (neškodné; `git config --global --add safe.directory /var/www/html` ak vadí).
- PHPUnit 11.5.57, composer skripty `test`, `test:unit`, `test:migrate` existujú.

## Known Stubs
None.

## Self-Check: PASSED
Commity `1c9c47e` a `d4d5652` existujú, Dockerfile, compose.yaml, composer.lock, phpunit.dist.xml, tests/unit/BootTest.php prítomné.
