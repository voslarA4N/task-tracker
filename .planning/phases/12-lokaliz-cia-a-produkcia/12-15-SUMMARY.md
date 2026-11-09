---
phase: 12-lokaliz-cia-a-produkcia
plan: 15
subsystem: performance
tags: [performance, p95, postgres, php-fpm, intl]
requires: [12-05, 12-14]
provides: [nfr-01-measured]
key-files:
  created: []
  modified:
    - app/Config/Database.php
    - app/Libraries/Platform/MoneyFormat.php
    - docs/ops/performance.md
    - CLAUDE.md
key-decisions:
  - "Persistentné PG spojenie iba pre PHP-FPM (pConnect pri PHP_SAPI fpm-fcgi), CLI a testy bez neho; schválené vlastníkom, CLAUDE.md upravený"
  - "NumberFormatter cache v MoneyFormat podľa lokality a meny počas požiadavky"
  - "Cieľ NFR-01 sa meria ako odozva servera (app-only, FastCGI na php-fpm); cez Cloudflare sa zapisuje informatívne, rozdiel je sieť"
  - "zz-www.conf a compose.server.yaml bez zmeny (pm=ondemand nebol príčinou)"
requirements-completed: [NFR-01]
completed: 2026-11-09
---

# Plan 12-15: meranie výkonu na VPS a ladenie

Odozva servera na produkcii je p95 51 ms (cieľ < 100 ms), PDF 230 ms (cieľ < 2 s) a prod stack v pokoji 97 MiB (cieľ < 512 MiB). Ladenie odstránilo nové PG spojenie na každú požiadavku a opakované vytváranie ICU formátovačov.

## Task 1: staging
- p95 cez Cloudflare SK 241 ms, PL 228 ms (FAIL), app-only 96 až 152 ms; PDF p95 166 ms; `phpunit --group performance` OK (vystavenie 353 ms, 66 MB). Commit 8afa5c8.

## Task 3 (ladenie, pred prod meraním)
- Profilovanie (excimer v jednorazovom kontajneri): 73 % času `/` boli DB dotazy, z toho 22 % pripojenie a zvyšok studený backend. Konfigurácia (OPcache, preload, classmap, CI_ENVIRONMENT) bola v poriadku.
- bbd31ca perzistentné spojenie pre PHP-FPM, be3dc26 cache NumberFormatter, fc75b94 výsledky stagingu, 4bdf3c7 CLAUDE.md po schválení vlastníkom.
- Staging app-only p95: `/` 152 -> 76, `/tasks` 123 -> 53, `/invoices` 148 -> 66, `/reports/month` 108 -> 34 ms.

## Task 2: produkcia (vlastník, cookie iba na serveri)
- Pred nasadením PR #14: app-only p95 ALL 138 ms, cez Cloudflare 245 ms.
- Po nasadení PR #14: app-only p95 ALL 51 ms (`/` 61, `/tasks` 33, `/invoices` 38, `/reports/month` 30), cez Cloudflare 176 ms.
- PDF `/invoices/4/pdf` p95 230 ms. Pamäť v pokoji: task-tracker 97.3 MiB (nginx 5.6, php 45.2, db 46.5), staging 97.8, mail 151.3, edge 2.6 MiB.

## Deviations
- Poradie taskov: ladenie (Task 3) prebehlo pred prod meraním, aby vlastník meral produkciu iba raz s ladením; prvé prod meranie omylom bežalo pred nasadením (deploy čakal na schválenie prostredia) a slúži ako základná línia.
- Cieľ 100 ms cez Cloudflare nie je dosiahnuteľný (sieť 56 až 96 ms); NFR-01 hodnotený ako odozva servera.
- Session cookie vlastníka sa objavila na snímke v chate; relácia zneplatnená odhlásením.

## Self-Check: PASSED
