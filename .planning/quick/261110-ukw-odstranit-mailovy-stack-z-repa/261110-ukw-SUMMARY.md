---
phase: quick-261110-ukw
plan: 01
status: complete
completed: 2026-11-10
requirements: [QUICK-261110-ukw]
---

# Quick 261110-ukw: Odstránenie mail stacku z repa

Mail stack (Stalwart, Roundcube, unbound, nginx web, mail-* skripty, compose.mail.yaml) je odstránený z repa; beží samostatne v /opt/mail a história je zachovaná tagom `mail-stack-final`.

## Tasks

| Task | Výsledok | Commit |
|------|----------|--------|
| 1 (checkpoint) | Splnený orchestrátorom na serveri (diff bez rozdielov, cron z /opt/mail, compose OK, siete mail/edge, runbook v /opt/mail/README.md) | n/a |
| 2 | Lokálny anotovaný tag `mail-stack-final` (nepushnutý), `git rm` compose.mail.yaml, docker/mail, scripts/mail-{backup,cert-renew,provision,selftest}.sh | b0b578a |
| 3 | docs/ops/mail.md nahradený 17-riadkovou poznámkou; opravené odkazy v docs/ops/server.md, komentáre v docker/edge/nginx.conf, .gitignore, compose.server.mail.yaml | viď git log (docs(quick-261110-ukw)) |

## Overenie

- grep na odkazy na zmazané súbory mimo .planning/ a mail.md: čisté (zvyšné zhody sú len odkazy na samotný mail.md).
- DeployGuardTest: 30 testov, 165 assertions, OK. Lokálne nie je vendor/, preto spustené cez `docker compose exec -T -u www-data php vendor/bin/phpunit` (dev kontajner).
- `docker compose -f compose.server.yaml -f compose.server.mail.yaml config -q` lokálne zlyhá na chýbajúcom COMPOSE_PROJECT_NAME v .env (produkčná premenná, mimo rozsahu; overlay nebol zmenený okrem komentára).

## Deviations from Plan

- Pri riadku 503 v docs/ops/server.md odkaz "podľa docs/ops/mail.md" (sprísnenie DMARC) zmenený na `/opt/mail/README.md`, lebo mail.md už toto neobsahuje.
- Nič iné; deploy.sh, ci.yml, DeployGuardTest, docker/nginx/* nezmenené.

## Poznámky

- Tag `mail-stack-final` je len lokálny; push vykoná používateľ.
- Server nebol dotknutý.

## Self-Check: PASSED
