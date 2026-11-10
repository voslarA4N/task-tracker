# Mail stack (poznámka)

Od 2026-11-10 sa mail stack (Stalwart, Roundcube, unbound, nginx `web`, certbot) **nespravuje z tohto repa**. Beží samostatne na serveri v `/opt/mail` a slúži aj iným projektom. Samostatné repo pre mail stack sa rozhodne neskôr.

## Kde je runbook

- Na serveri: `/opt/mail/README.md` (inštalácia, DNS, zálohy, obnova, rollback).
- V git histórii: tag `mail-stack-final` (`git show mail-stack-final:docs/ops/mail.md`, ostatné súbory stacku rovnako cez `git show mail-stack-final:<cesta>`).

## Čo task-tracker od mailu očakáva

- **Externá docker sieť `mail`.** Pripája ju overlay `compose.server.mail.yaml`, iba produkcia cez `MAIL_NETWORK=mail` v `.env`. `scripts/deploy.sh` pred nasadením overí, že sieť na hoste existuje.
- **SMTP blok `email.*` v `app.env`** (a `migrate.env`): host `mail.vostack.dev`, port 465, odosielateľ `noreply@vostack.dev`. Detaily v [server.md](server.md), sekcia 20. Heslo je len na serveri v `/opt/mail/secrets/noreply_password`.
- **Edge nginx** (spravovaný samostatne, docs/ops/edge.md) smeruje hosty webmail, autoconfig, autodiscover a mta-sts na alias `mail-web:443` v sieti `edge`.
- **Spoločný zdroj nginx realip.** Kópia `docker/nginx/realip/edge.conf` a `docker/nginx/http.d/cf-peer.conf` v `/opt/mail/docker/nginx/` pochádza z tohto repa. Pri zmene týchto súborov treba aktualizovať aj kópiu v `/opt/mail`.

Zmenu siete `mail`, aliasu `mail-web` alebo SMTP hosta/portu treba zosúladiť s týmto repom.
