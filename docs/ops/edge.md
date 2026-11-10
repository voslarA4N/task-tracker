# Edge proxy (poznámka)

Edge proxy (nginx `stream` podľa SNI, TLS passthrough, PROXY protocol, kontajner `edge-edge-1`, `/opt/edge`) je jediný vlastník portov 80 a 443 na serveri a slúži všetkým projektom (task-tracker prod a staging, mail stack, ďalšie). Preto sa od 2026-11-10 **nespravuje z tohto repa**, ale samostatne (na serveri v `/opt/edge` a v samostatnom repe). Mapa hostov (vrátane `webmail` a `admin-webmail` na `mail-web:443`) sa mení tam.

## História

V git histórii pod tagom `edge-final`:

- `git show edge-final:docker/edge/nginx.conf`
- `git show edge-final:compose.edge.yaml`
- `git show edge-final:scripts/edge-selftest.sh`
- `git show edge-final:docs/ops/server.md` (pôvodné sekcie 17 a 18, inštalácia a cut-over)

## Čo task-tracker od edge očakáva

- **Externá docker sieť `edge`** s podsieťou `10.250.250.0/24`. Vytvára ju `scripts/server-bootstrap.sh`; `docker/nginx/realip/edge.conf` dôveruje práve tejto podsieti.
- **Alias nginx kontajnera** v sieti `edge` z `EDGE_ALIAS` v `.env` (`wt-prod-nginx`, `wt-stage-nginx`; `compose.server.yaml`).
- Edge smeruje `work-tracker.vostack.dev` a `work-tracker-stage.vostack.dev` na tieto aliasy, port 443, bez rozbalenia TLS, a posiela PROXY protocol (stack nginx má `listen 443 ssl proxy_protocol`). Port 80 presmeruje na https.
- `scripts/deploy.sh` odmietne beh bez siete `edge` a bez `EDGE_ALIAS`.
- `docker/nginx/http.d/cf-peer.conf` vráti `444`, ak PROXY zdroj nie je rozsah Cloudflare.

Pri zmene aliasu, podsiete alebo PROXY protocolu treba zosúladiť obe strany (edge repo aj tento repo).
