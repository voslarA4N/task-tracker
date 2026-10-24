---
phase: 02-identita-a-skor-nasadenie
plan: 07
subsystem: infra
tags: [deploy, ghcr, github-actions, backup-gate, smoke, letsencrypt]
requires: [02-04]
provides: [deploy-script, cert-renew, smoke-staging, gha-deploy-job]
affects: [02-02, 02-09]
key-files:
  created:
    - scripts/deploy.sh
    - scripts/cert-renew.sh
    - scripts/smoke-staging.sh
    - tests/unit/Scripts/DeployGuardTest.php
  modified:
    - .github/workflows/ci.yml
    - Makefile
key-decisions:
  - "Rotácia záloh podľa názvu (UTC čas v názve), nie mtime"
  - "cert-renew.sh čítaj IMAGE_TAG z .last-good-tag (compose.staging.yaml ho vyžaduje, v .env nie je)"
  - "Deploy job posiela IMAGE_PREFIX z runnera, takže tag v GHCR a na serveri sú vždy zhodné"
  - "SSH konfigurácia (IdentityFile, StrictHostKeyChecking yes, known_hosts) v ~/.ssh/config, použije ju aj ssh v smoke skripte"
duration: 25min
completed: 2026-10-24
---

# Phase 02 Plan 07: Deploy stagingu Summary

**deploy.sh s bránou „overená záloha pred migráciou“ a rollbackom na `.last-good-tag`, cert-renew.sh, smoke-staging.sh a GHA job `deploy` (docker CLI, GHCR, SSH s pripnutým known_hosts).**

## Commits
- ace4a04: test (RED) DeployGuardTest
- 2eeae68: deploy.sh a cert-renew.sh (GREEN)
- 44524ff: smoke-staging.sh, ci.yml deploy job, make smoke-staging
- (nasleduje) štýlová oprava DeployGuardTest (php-cs-fixer)

## Verification
- DeployGuardTest 5/5 zelený; `composer check` zelený (177 testov).
- shellcheck čistý (deploy.sh, cert-renew.sh, smoke-staging.sh), actionlint čistý.
- Akceptačné počty: `needs: check` 1, `cancel-in-progress: false` 1, `packages: write` 1, cudzie akcie 0, `linux/amd64` 2, `migrate -g owner --all` 1 v ci.yml (krok prod obrazu), `-k ` v smoke-staging 0.
- `sh scripts/smoke-staging.sh work-tracker.loc` zbehol bez syntaktických chýb; proti dev stacku hlási FAIL na HTTPS kontrolách, lebo porty 80/443 drží cudzí `global-proxy` (známe z 02-04) a skript správne pozná nedostupný host. Skutočné overenie prebehne v 02-09.

## Deviations from Plan

**1. [Rule 2] cert-renew.sh načíta IMAGE_TAG z `.last-good-tag`** - compose.staging.yaml používa `${IMAGE_TAG:?}`, bez toho by cron obnova zlyhala.
**2. [Rule 2] smoke-staging.sh hlási FAIL, keď `/login` neodpovedá (HTTP 000)**, aby kontrola hlavičiek a cookies nepreskočila ticho.
**3. Skutočný beh deploy.sh a GHA jobu sa nerobil** (žiadny SSH, secrets nie sú nastavené, 02-02 je pozastavené); logika overená stub testom.

## Known Stubs
Žiadne.

## Notes for 02-09
- Server musí mať `docker` s oprávnením pre `deploy`, `crontab`, adresár `/opt/work-tracker/docker/` a `/var/backups/work-tracker` (deploy.sh ho vytvorí s 0700).
- Pred prvým deployom: GitHub secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`, `DEPLOY_USER` v environment `staging`, a `secrets/cloudflare.ini` na serveri.
- Prvý deploy pri `letsencrypt` vydá certifikát až po migrácii (nginx štartuje po ensure_cert).
- GHCR balíky musia byť pre pull prístupné (deploy.sh sa spolieha na `docker login` z CI).

## Self-Check: PASSED
