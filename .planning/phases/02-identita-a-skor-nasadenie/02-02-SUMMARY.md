---
phase: 02-identita-a-skor-nasadenie
plan: 02
subsystem: ops
tags: [staging, server, cloudflare, hetzner, github-secrets]
requires: [02-01]
provides: [staging-server-ready, deploy-key, github-staging-environment]
affects: [02-07, 02-09, 02-20]
key-files:
  created: [scripts/server-bootstrap.sh, docs/ops/staging.md]
  modified: []
key-decisions:
  - "Bootstrap spustil Claude cez ssh my-server-agent (aiagent + sudo) namiesto root SSH od vlastníka"
  - "Tajomstvá (Cloudflare token, .env, DEPLOY_SSH_KEY) nastavil vlastník; Claude overoval iba práva, formát a platnosť, nikdy hodnoty"
duration: ~2h (vrátane čakania na vlastníka)
completed: 2026-10-24
---

# Phase 02 Plan 02: Príprava stagingu Summary

**Server 2.31.74.48 (Ubuntu 26.04) je pripravený na deploy: Docker 29.9.2 + Compose v5.6.1, používateľ `deploy` s deploy kľúčom, SSH bez hesiel, Cloudflare DNS (proxy) + DNS token, Hetzner Cloud Firewall, serverový `.env` a GitHub environment `staging` so 4 secrets.**

## Tasks

| Task | Názov | Commit |
|------|-------|--------|
| 1 | Bootstrap skript, runbook, deploy kľúč | 87f38ba |
| 2 | Kroky vlastníka (Cloudflare, firewall, server, .env) | 23eed51 (oprava skriptu), vlastník: DNS, firewall, token, .env, DEPLOY_SSH_KEY |
| 3 | GitHub environment a secrets, overenie | dab7eba, df8b2da, 8c4b360, ad1aa2a, a2f6a76 + tento commit |

## Overenie (Task 3)

- Host kľúč `SHA256:LslaUrQDpTdX38kT4AUjvq1CLyLA92Qt2yZifDTScUQ` (ED25519) z výstupu bootstrapu = `ssh-keyscan` z dev stroja.
- `deploy` sa prihlási kľúčom, heslom `Permission denied (publickey)`; `PermitRootLogin prohibit-password`.
- DNS `work-tracker.vostack.dev` vracia iba IP Cloudflare; edge vracia 521 (nie 522), Cloudflare sa na origin dostane.
- Firewall: z dev stroja porty 80, 443, 5432 timeout; SSH a ICMP fungujú.
- `secrets/cloudflare.ini` 600 deploy, token aktívny, vidí iba zónu `vostack.dev`.
- `.env` 640 deploy:82, 30 kľúčov, bez prázdnych hodnôt a nenahradených premenných; Compose formát prečíta.
- `gh secret list --env staging` = 4 DEPLOY_* secrets.

## Deviations from Plan

- **[Rule 3] uutils coreutils:** Ubuntu 26.04 dodáva `install` z uutils, ktorý neprijme `-g 82` (GID bez mena). Opravené na `install -o deploy` + `chown deploy:82` (23eed51).
- **Spôsob prístupu:** vlastník neposkytol root SSH, ale používateľa `aiagent` so sudo (`ssh my-server-agent`); bootstrap bežal cez `sudo`.
- **Delenie práce:** vlastník chcel nastaviť všetky tajomstvá sám. Claude nastavil iba netajné `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS` a environment `staging`; `DEPLOY_SSH_KEY` nastavil vlastník (obsah sa overí až pri prvom deployi).
- Runbook doplnený o sekciu 12 (staging `spark user:password` a `user:reset-2fa`, poznámky z 02-18).

## Not verified

- Či `DEPLOY_SSH_KEY` obsahuje správny kľúč (GitHub secrets sa nedajú čítať) a Cloudflare SSL Full (strict) — oboje overí prvý deploy v 02-09.

## Self-Check: PASSED
