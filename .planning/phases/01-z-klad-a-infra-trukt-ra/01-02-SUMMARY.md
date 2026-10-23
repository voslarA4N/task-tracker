---
phase: 01-z-klad-a-infra-trukt-ra
plan: 02
subsystem: infra
tags: [nginx, mkcert, http2, csp, makefile, mailpit]
requires: ["01-01"]
provides:
  - "https://work-tracker.loc cez nginx HTTP/2 s mkcert certifikatom"
  - "Makefile so vsetkymi cielmi D-01 (help, up, down, spark, composer, test, check, certs, smoke ...)"
  - "scripts/doctor.sh, certs.sh, smoke.sh, lib-mkcert.sh"
  - "spark mail:test"
affects: [01-03, 01-04, 01-05, 01-06, 01-07]
tech-stack:
  added: []
  patterns: ["bezpecnostne hlavicky iba v nginx (snippet vkladany do servera aj kazdej location s add_header)", "spark/testy ako www-data, composer/cs-fix ako root"]
key-files:
  created: [Makefile, README.md, scripts/doctor.sh, scripts/certs.sh, scripts/smoke.sh, scripts/lib-mkcert.sh, docker/nginx/templates/default.conf.template, docker/nginx/templates/snippets/security-headers.conf.template, app/Commands/MailTest.php]
  modified: []
key-decisions:
  - "mkcert.exe sa hlada cez env MKCERT, PATH, potom WinGet Packages (lib-mkcert.sh)"
requirements-completed: [NFR-07]
duration: ~15 min
completed: 2026-10-23
---

# Phase 1 Plan 02: nginx HTTPS, Makefile a smoke Summary

Aplikacia bezi na https://work-tracker.loc (HTTP/2, gzip, CSP bez `unsafe-*`, HSTS max-age=300, no-store na HTML), Makefile je jediny vstup pre bezne prikazy a `make smoke` konci `SMOKE OK` vratane dorucenia e-mailu do Mailpitu.

## Tasks
1. Makefile, skripty, README: `8f7de21` (cerveny smoke zamerne nespusteny samostatne, nginx bez konfiguracie)
2. nginx sablony, mail:test, certifikat, zeleny smoke: `9cdd909`

## Deviations from Plan
**1. [Rule 3 - Blocking] Pridany `scripts/lib-mkcert.sh`**
- `mkcert.exe` nie je na WSL PATH (z 01-01); spolocny helper pre doctor.sh a certs.sh (MKCERT env, PATH, WinGet).
- Cerveny stav smoke pred nginx konfiguraciou nebol samostatne spusteny.

## Notes for later plans
- `make smoke` hlasi `SKIP vendor-cache`, kym plan 01-04 nedoda `public/assets/vendor/*.js`.
- Makefile cielmi `reset`, `test-db` (01-05), `spike-pdf` (01-06), `vendor-assets` (01-04), `cs-fix`/`stan`/`cs` (skripty v composer.json, 01-07) potrebuju skripty z tych planov.
- Hlavicky pridavat iba v nginx; kazda nova `location` s `add_header` musi znova includovat snippet.
- `docker/certs/` je gitignored; `make certs` ho vytvori.

## Known Stubs
None.

## Self-Check: PASSED
Commity `8f7de21` a `9cdd909` existuju, `make smoke` = SMOKE OK, ziadne root-owned subory v `writable/`.
