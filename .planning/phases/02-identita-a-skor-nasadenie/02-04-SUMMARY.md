---
phase: 02-identita-a-skor-nasadenie
plan: 04
subsystem: infra
tags: [nginx, cloudflare, realip, limit_req, compose, staging]
requires: [02-01]
provides: [nginx-parametrised-cert, cf-realip, auth-rate-limit, nginx-image, compose-staging]
affects: [02-02, 02-07, 02-09, 02-13]
key-files:
  created:
    - docker/nginx/realip/cloudflare.conf
    - docker/nginx/Dockerfile
    - scripts/cf-ips-check.sh
    - compose.staging.yaml
  modified:
    - docker/nginx/templates/default.conf.template
    - compose.yaml
key-decisions:
  - "limit_req kľúč cez map na $request_uri (v /index.php je $uri vždy /index.php)"
  - "realip snippet zapečený do nginx obrazu v /etc/nginx/realip.d, v dev adresár neexistuje a glob include je tolerovaný"
duration: 20min
completed: 2026-10-24
---

# Phase 02 Plan 04: nginx a staging compose Summary

**Spoločná nginx šablóna pre dev aj staging (parametrizovaný certifikát, Cloudflare realip, limit 20/min na prihlasovacie URL, no-cache pre /sw.js a manifest), nginx obraz so zapečeným public/ a samostatný compose.staging.yaml.**

## Commits
- 8b30b96: nginx šablóna, realip snippet, cf-ips-check.sh, compose.yaml
- 0ab1fe0: nginx Dockerfile a compose.staging.yaml

## Verification
- Dev `nginx -t` OK, `/sw.js` vracia `cache-control: no-cache`, 40 požiadaviek na `/login` dalo 29x 429.
- `cf-ips-check.sh --write` vygeneroval 22 rozsahov, následná kontrola exit 0.
- nginx obraz sa zostavil, `nginx -t` v ňom prešiel, `app.css` je v obraze, snippet má 22 `set_real_ip_from`.
- `compose.staging.yaml config -q` OK, bez mailpit/build, bez publikovaného 5432.

## Deviations from Plan

**1. [Rule 3 - Blocking, prostredie] `make smoke` a overenie na 443 nebolo možné**
- Porty 80/443 na hostiteľovi drží cudzí kontajner `global-proxy` (nginx:alpine, mimo tohto projektu), preto dev nginx nemohol nabehnúť na štandardných portoch. Cudzí kontajner som nezastavoval.
- Overenie som urobil s dočasným override-om v scratchpade (porty 8080/8443, `curl --resolve -k` iba pre lokálny test). `scripts/smoke.sh` sa nedal spustiť (kontroluje 443). Odporúčam spustiť `make smoke` po uvoľnení portov 80/443.
- Dev nginx kontajner teraz beží na 127.0.0.1:8080/8443; návrat: `docker compose up -d --force-recreate nginx` po uvoľnení portov.

**2. `/sw.js` a `/manifest.webmanifest` vracajú 404** v dev, lebo kontrolér a manifest pridá 02-13; hlavička cache-control je správna.

## Known Stubs
Žiadne.

## Self-Check: PASSED
