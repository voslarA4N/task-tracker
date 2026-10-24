---
phase: 02-identita-a-skor-nasadenie
plan: 09
subsystem: infra
tags: [staging, deploy, cloudflare, letsencrypt, smoke]
requires: [02-02, 02-07, 02-08]
provides: [staging-live]
affects: [02-20]
key-files:
  modified:
    - docs/ops/staging.md
    - .github/workflows/ci.yml
    - docker/nginx/templates/default.conf.template
    - scripts/smoke-staging.sh
    - scripts/deploy.sh
    - scripts/cert-renew.sh
key-decisions:
  - "/sw.js sa servíruje s Cache-Control no-cache, no-store, inak ho Cloudflare cachuje (.js) a prepisuje na max-age"
  - "deploy.sh sa spúšťa cez sh a chmod +x po scp (scp bit nezachová)"
duration: 60min
completed: 2026-10-24
---

# Phase 02 Plan 09: Prvé nasadenie stagingu Summary

**Staging https://work-tracker.vostack.dev beží z GHCR obrazov nasadených z GitHub Actions: Let's Encrypt origin za Cloudflare, 302 na /login, hlavičky, cookies, skutočná IP a uzamknutý origin overené, pamäť v pokoji približne 57 MiB.**

## Commits
- fa90420: deploy skripty spustiteľné na serveri (fix)
- e9b2336: /sw.js bez cachovania v Cloudflare (fix)
- aa6bd99: záznam prvého nasadenia v docs/ops/staging.md, oprava pamäťovej kontroly v smoke
- (pushnutý SHA 9f712a2 = koniec 02-08)

## Results
GitHub run 40131835824 zelený (check + deploy), IMAGE_TAG sha-e9b2336..., `.last-good-tag` zhodný, pre-*.dump existuje. Certifikát Let's Encrypt (do 2027-01-22). `SMOKE OK` s ORIGIN_IP. nginx loguje skutočnú IP klienta. Pamäť: nginx 4,4 + php 21,5 + db 31,2 = približne 57 MiB. Overenie DEPLOY_SSH_KEY a GHCR pull: funguje.

## Deviations from Plan
1. [Rule 3] Prvý deploy zlyhal (exit 126): scp nezachová exec bit. Oprava v ci.yml (chmod, `sh ./deploy.sh`) a režim 755 v gite.
2. [Rule 1] Smoke zlyhal na /sw.js: Cloudflare prepísal Cache-Control. Oprava v nginx (`no-cache, no-store`). Pwa.php (no-cache) ostáva nezmenené, nginx ho skrýva.
3. [Rule 1] smoke-staging.sh pri zlyhaní ssh vyhodnotil pamäť 0 MiB ako PASS; teraz FAIL.
4. Sekcia v docs je číslovaná 13 (12 už obsadená správou používateľa).
5. Kvôli oprave 1 a 2 prebehli 3 CI behy; po finálnom docs commite pribudne ďalší.

## Skipped
Smoke krok zmeny session cookie (chýba SMOKE_EMAIL/SMOKE_PASSWORD, na stagingu ešte nie je používateľ; prvého admina vytvorí vlastník, runbook sekcia 10).

## Known Stubs
Žiadne.

## Self-Check: PASSED
