---
phase: 12-lokaliz-cia-a-produkcia
plan: 05
subsystem: ops
tags: [performance, p95, smoke, nginx, opcache, tests]
requires: []
provides:
  - "scripts/measure-p95.sh: p50/p95/max TTFB cez curl, cookie iba z P95_COOKIE, exit 0/1/2"
  - "smoke-server.sh sekcia 5b: immutable cache a kompresia statiky"
  - "ProdHardeningConfigTest: poistka http2, gzip, immutable, OPcache, classmap, CSP"
  - "docs/ops/performance.md: metodika, ladenie, prázdna tabuľka výsledkov pre 12-15"
affects: [12-15]
tech-stack:
  added: []
  patterns: ["falošný curl cez P95_CURL v PHPUnit"]
key-files:
  created:
    - scripts/measure-p95.sh
    - tests/unit/Scripts/MeasureP95ScriptTest.php
    - tests/unit/Ops/ProdHardeningConfigTest.php
    - docs/ops/performance.md
  modified:
    - scripts/smoke-server.sh
key-decisions:
  - "p95 metódou nearest-rank v celočíselnej aritmetike (index (95*n+99)/100), bez bc"
  - "Smoke dekóduje HTML-escapované adresy (&#x2F;) z /login, lebo CI4 escapuje URL v šablónach"
requirements-completed: []
duration: 20min
completed: 2026-11-04
---

# Phase 12 Plan 05: Meranie p95 a poistky výkonu Summary

Skript `measure-p95.sh` meria p50/p95/max TTFB bez externých nástrojov a bez úniku cookie, smoke kontroluje immutable cache a kompresiu statiky, statický test chráni produkčnú konfiguráciu výkonu a `docs/ops/performance.md` opisuje metodiku.

## Tasky

1. measure-p95.sh a test (7 testov): 5212863
2. smoke sekcia 5b, ProdHardeningConfigTest (5 testov), performance.md: 6f57349

## Overenie

- `phpunit tests/unit/Ops tests/unit/Scripts/MeasureP95ScriptTest.php`: 12 testov OK.
- Dev stack (smoke s curl shimom pre `work-tracker.loc`): CSS a JS majú `cache-control: public, max-age=31536000, immutable` a `content-encoding: gzip`; `SMOKE OK`.
- `default.conf.template` sa nemenila: `application/javascript` je gzipovaný, doplnenie typov nebolo treba.

## Deviations from Plan

None - plan executed exactly as written (sekcia je označená 5b, aby sa neprečíslovali existujúce sekcie).

## Known Stubs

Tabuľka Výsledky v `docs/ops/performance.md` je zámerne prázdna, vyplní ju 12-15 (meranie na VPS).

## Self-Check: PASSED
