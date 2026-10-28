# Server runbook: produkcia a staging

## 1. Prehľad

Jeden server Hetzner Cloud #172176757 (IP `2.31.74.48`, amd64), dva nezávislé stacky a spoločný edge proxy. Obrazy z GHCR (`ghcr.io/voslara4n/task-tracker-php`, `-nginx`), rovnaký tag `sha-<commit>` pre oba stacky.

| | Produkcia | Staging | Edge |
|---|---|---|---|
| Doména | `work-tracker.vostack.dev` | `work-tracker-stage.vostack.dev` | |
| Adresár | `/opt/work-tracker` | `/opt/work-tracker-staging` | `/opt/edge` |
| Compose projekt (`COMPOSE_PROJECT_NAME`) | `task-tracker` (nemeniť, drží volumes `task-tracker_pgdata`, `task-tracker_writable`) | `task-tracker-staging` | `edge` |
| Alias nginx v sieti `edge` (`EDGE_ALIAS`) | `wt-prod-nginx` | `wt-stage-nginx` | |
| Compose súbor | `compose.server.yaml` | `compose.server.yaml` | `compose.edge.yaml` |
| GitHub environment | `production` (povinné schválenie vlastníka) | `staging` | |
| Vetva | `main` | `develop` | |
| Zálohy pred migráciou | `/var/backups/work-tracker` | `/var/backups/work-tracker-staging` | |
| `NGINX_ROBOTS_TAG` | prázdne | `noindex, nofollow` | |
| `STAGING_RESEED` (`.env`) | **chýba vždy** (deploy ho odmietne) | `1`: každý deploy zahodí DB a naplní ju seedom (sekcia 19) | |

- Docker sieť `edge` (podsieť `10.250.250.0/24`, mimo predvolených poolov Dockeru) spája edge s nginx oboch stackov. Realip v stack nginx dôveruje presne tejto podsieti.
- Pôvodné rozhodnutie fázy 2 (D-03 "staging je zároveň budúca produkcia") je prekonané: `/opt/work-tracker` je produkcia od go-live.
- Architektúra amd64, obrazy z GHCR (D-04 fázy 2).
- Vlastný mailový server (Stalwart + Roundcube) pre `vostack.dev` beží ako samostatný compose projekt `mail` v `/opt/mail`; inštalácia, DNS, zálohy a rollback sú v [docs/ops/mail.md](mail.md).

## Vetvový model

1. Práca na `feature/<nazov>`; push a PR spúšťajú iba job `check`.
2. PR `feature/*` -> `develop`; po merge sa `develop` nasadí na **staging** (`work-tracker-stage.vostack.dev`).
3. Overenie na stagingu, potom PR `develop` -> `main`.
4. Po merge do `main` čaká deploy na schválenie vlastníka v environmente `production`, potom nasadí tú istú verziu na **produkciu**.
5. Hotfix: `hotfix/<nazov>` z `main`, PR do `main`, potom `main` zlúčiť späť do `develop`.

Environment `production` má deployment branch policy iba `main`, `staging` iba `develop`. Oba majú vlastné secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`, `DEPLOY_USER` (rovnaké hodnoty).

## 2. Cloudflare

V Cloudflare -> `vostack.dev`:

- DNS -> Records: `A` záznamy `work-tracker` a `work-tracker-stage` -> `2.31.74.48`, obe **Proxied** (oranžový obláčik).
- SSL/TLS: režim **Full (strict)**, Always Use HTTPS zapnuté, Minimum TLS Version 1.2.
- Speed -> Optimization: **Rocket Loader OFF**.
- Scrape Shield: **Email Obfuscation OFF**.
- Security -> Bots: **Bot Fight Mode OFF** (inak výzvy rozbijú htmx a service worker, Pitfall 8).
- HSTS v Cloudflare nechať vypnuté, hlavičku posiela nginx (`NGINX_HSTS_VALUE`).
- Nastavenia sú na úrovni zóny, platia pre oba hostname rovnako.

## 3. TLS podľa rozhodnutia O-4 (02-01-SUMMARY.md): `letsencrypt`

### Vetva `letsencrypt` (zvolená)

- Cloudflare -> My Profile -> API Tokens -> Create Token. Oprávnenie iba `Zone:DNS:Edit`, zdroje: iba zóna `vostack.dev`.
- Na serveri (po bootstrape, sekcia 5) vytvor `<adresár stacku>/secrets/cloudflare.ini` (staging môže mať kópiu tokenu produkcie, token je viazaný na zónu) s právami 0600 a jediným riadkom:

```
dns_cloudflare_api_token = <token>
```

- Prvé vydanie a obnovu robí `scripts/deploy.sh` / `scripts/cert-renew.sh` z plánu 02-07 (kontajner `certbot` s profilom `certbot`, adresár `/opt/work-tracker/letsencrypt`).
- V `.env`: `TLS_MODE = letsencrypt`, `SSL_CERT = /etc/letsencrypt/live/work-tracker.vostack.dev/fullchain.pem`, `SSL_KEY = /etc/letsencrypt/live/work-tracker.vostack.dev/privkey.pem`, `LE_EMAIL = <tvoj e-mail>`.

### Vetva `origin-ca` (nepoužitá, záloha)

- Cloudflare -> SSL/TLS -> Origin Server -> Create Certificate, ulož `origin.pem` a `origin-key.pem` do `/opt/work-tracker/certs` (0640, vlastník deploy, skupina 82).
- V `.env`: `TLS_MODE = origin-ca`, `SSL_CERT = /etc/nginx/certs/origin.pem`, `SSL_KEY = /etc/nginx/certs/origin-key.pem`.

## 4. Hetzner Cloud Firewall

Použi Cloud Firewall, nie ufw (porty publikované Dockerom obchádzajú ufw, Pitfall 10). Hetzner Cloud Console -> Firewalls -> vytvoriť a priradiť k serveru #172176757.

Inbound:

| Protokol | Port | Zdroj |
|----------|------|-------|
| TCP | 22 | 0.0.0.0/0 a ::/0 (GitHub hosted runnery majú dynamické IP, ochranou je iba kľúč) |
| TCP | 80, 443 | iba rozsahy Cloudflare |
| ICMP | | povolené |

Rozsahy Cloudflare (vlož do pravidla 80 a 443, IPv4 aj IPv6):

```
curl -s https://www.cloudflare.com/ips-v4
curl -s https://www.cloudflare.com/ips-v6
```

Outbound ponechať povolený.

Porty 80 a 443 publikuje iba kontajner edge (sekcia Edge proxy); stack nginx nemá žiadne publikované porty.

## 5. Bootstrap servera

Z koreňa repozitára na vývojovom stroji (verejný kľúč je `~/.ssh/work_tracker_deploy.pub`):

```
ssh root@2.31.74.48 "DEPLOY_PUBKEY='$(cat ~/.ssh/work_tracker_deploy.pub)' sh -s" < scripts/server-bootstrap.sh
```

Skript je idempotentný. Nainštaluje Docker Engine a compose plugin z oficiálneho apt repozitára, vytvorí používateľa `deploy`, vypne SSH heslá, vytvorí adresáre a na konci vypíše odtlačok host kľúča `SHA256:...`. Ten pošli späť, použije sa na overenie `known_hosts`.

## 6. Serverové `.env`, `app.env`, `migrate.env` a `secrets/`

Tajomstvá sú rozdelené podľa toho, kto ich potrebuje (WR-06). Bežiaci php-fpm vidí iba heslo `app_rw`, heslo `app_owner` má iba jednorazový kontajner `migrate` a heslo superusera iba DB pri inicializácii.

| Súbor v `<adresár stacku>` | Obsah | Kto ho číta | Práva |
|------------------------------|-------|-------------|-------|
| `.env` | interpolácia compose a `deploy.sh`: `COMPOSE_PROJECT_NAME`, `EDGE_ALIAS`, `NGINX_ROBOTS_TAG`, domény, TLS, `IMAGE_PREFIX`, **žiadne heslá** | `docker compose`, `deploy.sh`, `cert-renew.sh` | 0640 `deploy` |
| `app.env` | CI4 runtime: `CI_ENVIRONMENT`, `app.baseURL`, `database.default.*` (app_rw), `email.*`, `encryption.key`, `auth.backupCodePepper` | php-fpm (mount ako `/var/www/html/.env`) | 0640 `deploy:82` |
| `migrate.env` | obsah `app.env` + `database.owner.*` (app_owner) | iba `docker compose run migrate` v `deploy.sh` | 0600 `deploy` |
| `secrets/seed_admin.env` (iba staging) | `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` | iba jednorazový kontajner `seed` pri `STAGING_RESEED=1` (sekcia 19) | 0600 `deploy` |
| `secrets/db_superuser_password`, `secrets/db_owner_password`, `secrets/db_rw_password` | jedno heslo bez nového riadku | kontajner `db` (`*_PASSWORD_FILE`), použité iba pri inicializácii prázdneho volume | 0640 `deploy:70` |

`deploy.sh` pred prvým volaním dockeru overí, že súbory existujú, nie sú čitateľné pre všetkých, `.env` neobsahuje heslá ani CI4 kľúče a `app.env` neobsahuje `database.owner.*`. Inak skončí so správou a predošlý stack beží ďalej.

Nová inštalácia: vytvor súbory ako `deploy` priamo na serveri, tajomstvá generuj tam (nikdy ich nepíš do gitu ani chatu). Blok je parametrizovaný: produkcia `DIR=/opt/work-tracker DOMAIN=work-tracker.vostack.dev PROJECT=task-tracker ALIAS=wt-prod-nginx ROBOTS=`, staging `DIR=/opt/work-tracker-staging DOMAIN=work-tracker-stage.vostack.dev PROJECT=task-tracker-staging ALIAS=wt-stage-nginx ROBOTS='noindex, nofollow'`. **Staging dostane vlastné čerstvé heslá, `encryption.key` a pepper, nikdy nekopíruj tajomstvá produkcie.**

```
DIR=/opt/work-tracker-staging DOMAIN=work-tracker-stage.vostack.dev PROJECT=task-tracker-staging ALIAS=wt-stage-nginx ROBOTS='noindex, nofollow'
cd "$DIR"
umask 077
DBPW_SU=$(openssl rand -hex 24)
DBPW_OWNER=$(openssl rand -hex 24)
DBPW_RW=$(openssl rand -hex 24)
ENC=$(openssl rand -hex 32)
PEPPER=$(openssl rand -hex 32)
printf '%s' "$DBPW_SU" > secrets/db_superuser_password
printf '%s' "$DBPW_OWNER" > secrets/db_owner_password
printf '%s' "$DBPW_RW" > secrets/db_rw_password
cat > .env <<ENV
COMPOSE_PROJECT_NAME = $PROJECT
EDGE_ALIAS = $ALIAS
NGINX_ROBOTS_TAG = $ROBOTS

APP_DOMAIN = $DOMAIN
NGINX_HSTS_VALUE = max-age=31536000

TLS_MODE = letsencrypt
SSL_CERT = /etc/letsencrypt/live/$DOMAIN/fullchain.pem
SSL_KEY = /etc/letsencrypt/live/$DOMAIN/privkey.pem
LE_EMAIL = <tvoj e-mail>

IMAGE_PREFIX = ghcr.io/voslara4n/task-tracker
ENV
cat > app.env <<ENV
CI_ENVIRONMENT = production
app.baseURL = 'https://$DOMAIN/'

database.default.hostname = db
database.default.database = task_tracker
database.default.username = app_rw
database.default.password = $DBPW_RW
database.default.DBDriver = Postgre
database.default.port = 5432

email.protocol = smtp
email.transport = log
email.fromEmail = noreply@$DOMAIN
email.fromName = 'Work Tracker'

encryption.key = hex2bin:$ENC
auth.backupCodePepper = $PEPPER
ENV
{ cat app.env; cat <<ENV

database.owner.hostname = db
database.owner.database = task_tracker
database.owner.username = app_owner
database.owner.password = $DBPW_OWNER
database.owner.DBDriver = Postgre
database.owner.port = 5432
ENV
} > migrate.env
unset DBPW_SU DBPW_OWNER DBPW_RW ENC PEPPER
chmod 0640 .env
chmod 0600 migrate.env
# ako root (deploy nie je v skupinach 82 ani 70):
chown deploy:82 app.env && chmod 0640 app.env
chown deploy:70 secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password
chmod 0640 secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password
```

Staging navyše dostane (sekcia 19): `STAGING_RESEED = 1` do `.env`, `staging.seed = true` do `app.env` (práva `app.env` ostávajú 0640 `deploy:82`) a súbor `secrets/seed_admin.env`. **Produkcia tieto tri veci nemá nikdy**, `deploy.sh` ju s nimi odmietne.

Pri produkcii je `NGINX_ROBOTS_TAG` prázdne alebo chýba. Poznámky: `IMAGE_TAG` nastavuje `scripts/deploy.sh`, nepatrí do `.env`. GID 82 je `www-data` v alpine php obraze, GID 70 je `postgres` v `postgres:18-alpine` (init skript číta heslá rolí ako používateľ `postgres`). Staging má vždy `email.transport = log` a `app.baseURL = 'https://work-tracker-stage.vostack.dev/'`. `email.transport = log` znamená, že e-maily sa neodosielajú, do logu idú iba metadáta. Pri zmene SMTP alebo kľúča uprav `app.env` **aj** `migrate.env` (migrácie kľúče nepotrebujú, ale CI4 sa s nimi musí spustiť rovnako).

## 7. Tajomstvá mimo servera

Ulož do správcu hesiel: `encryption.key`, `auth.backupCodePepper` (z `app.env`), tri DB heslá (`secrets/db_*_password`, čitateľné ako root) a Cloudflare API token. Strata `encryption.key` znamená, že TOTP tajomstvá sa nedajú dešifrovať, obnova je cez `spark user:reset-2fa` (plán 02-18). Strata `auth.backupCodePepper` zneplatní záložné kódy.

## 8. GitHub

Environmenty `production` a `staging` v `voslarA4N/task-tracker`, každý so štyrmi secrets (nastavuje Claude cez `gh`): `DEPLOY_SSH_KEY` (privátny ed25519 kľúč `~/.ssh/work_tracker_deploy`), `DEPLOY_KNOWN_HOSTS` (overený riadok z `ssh-keyscan`), `DEPLOY_HOST` (`2.31.74.48`), `DEPLOY_USER` (`deploy`). Používateľ `deploy` je v skupine `docker`, čo je ekvivalent roota (akceptované riziko, jediný používateľ a server, kľúč iba v GitHub secrets a na dev stroji).

## 9. Nasadenie a rollback

- Nasadenie robí `scripts/deploy.sh` cez SSH z GitHub Actions (`develop` -> staging, `main` -> produkcia). Skript odmietne beh, ak chýba `COMPOSE_PROJECT_NAME`/`EDGE_ALIAS`, sieť `edge`, alebo projekt už beží z iného adresára. Každý stack má vlastný riadok cron `cert-renew.sh`, vlastný `DOCKER_CONFIG` (`<adresár>/.docker`) a vlastný `BACKUP_DIR`.
- Posledný fungujúci tag je v `<adresár stacku>/.last-good-tag`.
- Pred migráciou sa robí `pg_dump` do `/var/backups/<názov adresára>`.
- Obnova: `pg_restore --clean --if-exists -d task_tracker <súbor>` v kontajneri `db`.

## 10. Prvý admin

Produkcia (pre staging rovnako v `/opt/work-tracker-staging`):

```
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.server.yaml exec php php spark user:create <e-mail> --admin'
```

Heslo sa zadáva iba interaktívne (D-09), pre interaktívny prompt použi `ssh -t`.

## 11. Stav prípravy

Stav k 2026-10-24 (overil Claude):

- Bootstrap: hotový cez `ssh my-server-agent` (používateľ `aiagent` so sudo), Ubuntu 26.04 `resolute`, Docker Engine 29.9.2, Docker Compose v5.6.1. Ubuntu 26.04 má uutils coreutils, `install -g 82` nefunguje, skript preto používa `chown deploy:82`.
- Odtlačok host kľúča: `SHA256:LslaUrQDpTdX38kT4AUjvq1CLyLA92Qt2yZifDTScUQ` (ED25519), overený cez `ssh-keyscan` z vývojového stroja.
- SSH: `deploy` sa prihlási deploy kľúčom, prihlásenie heslom je vypnuté (`Permission denied (publickey)`), `PermitRootLogin prohibit-password`.
- Adresáre `/opt/work-tracker/{secrets,certs,letsencrypt,docker}` a `/var/backups/work-tracker` existujú so správnymi právami.
- GitHub environment `staging` existuje, nastavené sú `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS`.
- TLS_MODE: `letsencrypt` (O-4).
- DNS: `work-tracker.vostack.dev` je proxovaný cez Cloudflare (A 188.114.96.11/97.11, AAAA 2a06:98c1:3120::b/3121::b), origin IP sa nezverejňuje. Cloudflare edge vracia 521 (origin odmietol spojenie, nginx ešte nebeží), nie 522, takže firewall Cloudflare na port 443 púšťa.
- Firewall: Hetzner Cloud Firewall nastavený (2026-10-24). Z vývojového stroja porty 80, 443 a 5432 končia timeoutom (zahodené), SSH (deploy aj aiagent) a ICMP fungujú. Prístup z Cloudflare na 80/443 sa overí pri prvom deployi (02-09).
- Cloudflare token: `secrets/cloudflare.ini` existuje (600, deploy), jediný riadok `dns_cloudflare_api_token`, token je podľa `/user/tokens/verify` aktívny a vidí iba zónu `vostack.dev`.
- `.env`: existuje (640, deploy, GID 82), obsahuje všetkých 30 kľúčov zo sekcie 6 bez duplicít a prázdnych hodnôt, heslá a kľúče majú očakávanú dĺžku, `database.*.password` sa zhoduje s `DB_*_PASSWORD`. Docker Compose formát s medzerami okolo `=` prečíta (overené na kópii s falošnými hodnotami).
- GitHub environment `staging`: všetky štyri secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`, `DEPLOY_USER` nastavené (DEPLOY_SSH_KEY vlastník 2026-10-24).
- Príprava je hotová, ďalší krok je prvý deploy (plán 02-09).

## 12. Správa používateľa

Na serveri je `email.transport = log` (O-3), reset hesla e-mailom sa preto nedá dokončiť. Náhrada:

```
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.server.yaml exec php php spark user:password <e-mail>'
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.server.yaml exec php php spark user:reset-2fa <e-mail>'
```

- `user:password`: skrytý prompt, minimálne 12 znakov, heslo nesmie obsahovať časť e-mailu pred `@`. Odvolá všetky dôveryhodné zariadenia.
- `user:reset-2fa`: zruší TOTP aj záložné kódy a príznak odmietnutia, pri ďalšom prihlásení sa zobrazí ponuka 2FA (2FA je od 2026-10-28 voliteľná) (`--yes` preskočí potvrdenie). Zmaže aj nepoužité tokeny obnovy hesla.
- Reset hesla e-mailom (`/password/forgot`) funguje pre každý aktívny účet, s 2FA aj bez nej (od 2026-10-28, rozhodnutie vlastníka; pôvodné obmedzenie WR-04 je zrušené). Obmedzenia počtu žiadostí a rovnaká odpoveď stránky ostávajú. Po resete sa účet s 2FA prihlasuje heslom aj kódom, účet bez 2FA iba heslom.
- Limit obnovy hesla: najviac 3 e-maily za hodinu na účet a 5 na IP (WR-03), ďalšie žiadosti sa ticho zahodia (log `password_reset.throttled`).

## 13. Prvé nasadenie

Dátum: 2026-10-24. Prvé automatické nasadenie z `main` (D-07), GitHub Actions run 40131835824 (`check` aj `deploy` zelené), `IMAGE_TAG` = `sha-e9b2336137f05e62e79f6f22bc1b2473b0e0b6c1`, na serveri `/opt/work-tracker/.last-good-tag` má rovnakú hodnotu. Pred migráciou vznikla overená záloha `/var/backups/work-tracker/pre-*.dump`.

Priebeh: prvý beh (9f712a2) skončil chybou 126, lebo `scp` neprenesie bit spustiteľnosti (oprava: `chmod +x` po kopírovaní, `sh ./deploy.sh`, režim 755 v gite). Druhý beh nasadil, ale smoke zlyhal na `/sw.js`: Cloudflare cachoval `.js` a prepísal `Cache-Control` na `max-age=14400` (oprava: `no-cache, no-store` v nginx). Tretí beh zelený.

Výsledky:
- Certifikát originu: issuer `Let's Encrypt` (CN=YE1), platný do 2027-01-22 (O-4 letsencrypt).
- `https://work-tracker.vostack.dev/` = 302 na `/login`, `/login` = 200, HTTP/2, `http` presmerované 301.
- Hlavičky (CSP bez `unsafe-`, HSTS, nosniff, Referrer-Policy, Permissions-Policy) a cookies `__Host-` Secure, HttpOnly, SameSite=Lax: OK. `cf-cache-status` na `/login` = DYNAMIC, v tele nie je `rocket-loader` ani `email-decode`.
- Skutočná IP: nginx log obsahuje verejnú IP vývojového stroja (podľa `/cdn-cgi/trace`), nie adresu Cloudflare.
- Origin 2.31.74.48:443 mimo Cloudflare nedostupný (smoke s `ORIGIN_IP`).
- Pamäť v pokoji (`docker stats --no-stream`, 4+ minút po nasadení): nginx 4,4 MiB, php 21,5 MiB, db 31,2 MiB, spolu približne 57 MiB (< 512 MiB).
- `SMOKE OK`; krok zmeny session cookie je `SKIP` (na stagingu ešte nie je používateľ, SMOKE_EMAIL/SMOKE_PASSWORD chýbajú).

## 14. Nasadenie fázy 2 (plán 02-20)

Dátum: 2026-10-24. Celá fáza 2 je nasadená automaticky z `main` (D-07): HEAD `ba9b92f`, GitHub Actions run 40132767378 (`check` aj `deploy` zelené), `IMAGE_TAG` = `sha-ba9b92f689e80dff486c4d57865e3b48860d5dcf`, zhodné s `/opt/work-tracker/.last-good-tag`. V tomto pláne sa nepushovalo, nasadenie už bolo hotové. Lokálne `make check` (335 testov) a `node --test tests/js/*.test.js` (26 testov) zelené.

Smoke s dočasným účtom (skupina `accountant`, náhodné heslo iba v premennej shellu): `SMOKE OK`, vrátane riadku `po hesle 302/303 /2fa a nová session cookie` = OK (nie skip). Prvý beh tohto riadku zlyhal, lebo staging vracia `HTTP/2 303`, nie 302; regenerácia session bola správna (cookie `__Host-ci_session` sa po hesle zmenila, `Location: /2fa`). Opravené uvoľnením kontroly v `scripts/smoke-server.sh` na 302 alebo 303.

Pamäť v pokoji (`docker stats --no-stream`, 5 minút po poslednej aktivite): nginx 4,5 MiB, php 22,3 MiB, db 31,6 MiB, spolu približne 58 MiB (< 512 MiB, NFR-02).

Dočasné smoke účty `smoke+*@work-tracker.invalid` boli po každom behu zmazané (`DELETE FROM users`), overené: `SELECT count(*) FROM auth_identities WHERE secret LIKE 'smoke+%'` = 0.

Poznámka k príkazom: `docker compose -f compose.server.yaml` (do cut-overu `compose.staging.yaml`) na serveri vyžaduje `IMAGE_TAG` (napr. `export IMAGE_TAG=$(cat /opt/work-tracker/.last-good-tag)`), inak zlyhá na interpolácii.

Historické sekcie 11 až 15 popisujú stav pred zavedením edge a stagingu (v nich `staging` znamená dnešnú produkciu).

Ďalší krok: UAT na iPhone podľa `docs/ops/iphone-uat.md`.

## 15. Oddelenie tajomstiev na existujúcom serveri (WR-06, vykoná vlastník)

Dôvod: do 2026-10-25 bol jediný `.env` (0640 `deploy:82`) mountovaný do php kontajnera, takže každý PHP-FPM worker videl heslo superusera `postgres` aj `app_owner` (vlastník schémy môže meniť insert-only `auth_events`). Od commitu s opravou WR-06 compose súbor mountuje do php iba `app.env`, migrácie bežia v kontajneri `migrate` s `migrate.env` a DB dostáva heslá ako súbory. **Kým tieto kroky nie sú hotové, `deploy.sh` skončí hneď na začiatku** (`Stare rozlozenie tajomstiev ...`), nič nenasadí a bežiaci stack sa nezmení. Obnova certifikátu (`cert-renew.sh`) funguje aj medzitým.

Kroky ako `deploy` na serveri, nič z nich nevypisuje hodnoty. Heslá sa nemenia, iba presúvajú, preto netreba meniť roly v DB.

```
cd /opt/work-tracker
umask 077
cp -p .env .env.pre-wr06

# 1. Heslá rolí do secrets/ (bez nového riadku, bez úvodzoviek)
for k in SUPERUSER OWNER RW; do
  f=secrets/db_$(echo "$k" | tr 'A-Z' 'a-z')_password
  sed -n "s/^DB_${k}_PASSWORD *= *//p" .env.pre-wr06 | head -n1 | tr -d "'\"\r\n" > "$f"
done

# 2. app.env: CI4 kľúče bez database.owner.* a bez DB_*_PASSWORD
grep -E '^(CI_ENVIRONMENT|app\.|database\.default\.|email\.|encryption\.|auth\.)' .env.pre-wr06 > app.env

# 3. migrate.env: app.env + database.owner.*
{ cat app.env; grep -E '^database\.owner\.' .env.pre-wr06; } > migrate.env

# 4. .env: iba kľúče pre compose a deploy.sh
grep -Ev '^(DB_[A-Z_]*PASSWORD|database\.|encryption\.|auth\.|email\.|CI_ENVIRONMENT|app\.)' .env.pre-wr06 > .env

# 5. Kontrola bez výpisu hodnôt: názvy kľúčov, dĺžky súborov a zhoda hesiel
for f in .env app.env migrate.env; do echo "== $f"; grep -Ev '^(#|$)' "$f" | sed 's/ *=.*//'; done
wc -c secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password   # po 48 bajtov
v() { sed -n "s/^$1 *= *//p" "$2" | head -n1 | tr -d "'\"\r"; }
[ "$(v database.default.password app.env)" = "$(cat secrets/db_rw_password)" ] && echo 'rw OK'
[ "$(v database.owner.password migrate.env)" = "$(cat secrets/db_owner_password)" ] && echo 'owner OK'
grep -c 'database\.owner\.' app.env   # musí byť 0

# 6. Práva (ako root)
chmod 0640 .env
chmod 0600 migrate.env
chown deploy:82 app.env && chmod 0640 app.env
chown deploy:70 secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password
chmod 0640 secrets/db_superuser_password secrets/db_owner_password secrets/db_rw_password
```

Očakávané kľúče po kroku 5:

- `.env`: `APP_DOMAIN`, `NGINX_HSTS_VALUE`, `TLS_MODE`, `SSL_CERT`, `SSL_KEY`, `LE_EMAIL`, `IMAGE_PREFIX`.
- `app.env`: `CI_ENVIRONMENT`, `app.baseURL`, šesť `database.default.*`, štyri `email.*`, `encryption.key`, `auth.backupCodePepper`.
- `migrate.env`: to isté ako `app.env` + šesť `database.owner.*`.

Potom spusti nasadenie (push na `main` alebo opätovné spustenie workflow). `deploy.sh` prejde kontrolou, migrácia pobeží v `migrate`, php sa reštartuje s `app.env`. Overenie po nasadení (vypíše iba počty):

```
export IMAGE_TAG=$(cat .last-good-tag)
docker compose -f compose.server.yaml exec -T php sh -c "grep -c 'owner\|SUPERUSER' /var/www/html/.env; env | grep -ci password"   # 0 a 0
docker compose -f compose.server.yaml exec -T db sh -c 'env | grep -c "^POSTGRES_PASSWORD="'                                      # 0
```

Keď je server po nasadení v poriadku (smoke OK, prihlásenie funguje), zmaž zálohu `rm .env.pre-wr06`. Návrat pred dokončením: `mv .env.pre-wr06 .env` a nasadiť commit pred WR-06 (`app.env`, `migrate.env` a `secrets/db_*` môžu zostať, starý compose ich nepoužíva).

Na DB volume sa nič nemení: `POSTGRES_PASSWORD_FILE` a `DB_*_PASSWORD_FILE` sa použijú iba pri inicializácii prázdneho volume. Pri obnove servera od nuly stačí sekcia 6.

## 16. Nasadenie fázy 6 (dokumenty)

Postup pre vlastníka po nasadení fázy 6 na produkciu (najprv ho overiť na stagingu: merge do `develop`, skontrolovať, až potom do `main`; faktúry a výkazy v PDF, zákonné texty). Vzorové PDF slúžia účtovníčke a na ručné overenie QR (D-23).

1. **Rebuild obrazu kvôli `xz`** (PAY by square QR ho potrebuje): `docker compose build php`, potom kontrola `docker compose exec php xz --version` (musí vypísať verziu).
2. **Migrácie** bežia automaticky pri deployi (`spark migrate -g owner --all`). Nové sú `RequireReportDocument` a `AddLegalTextsRevision`; existujúce faktúry sa nedotknú (produkcia má len externú FA 208-2026, vlastné faktúry `inv-1` neexistujú).
3. **Vzorové PDF**: `docker compose exec -u www-data php php spark documents:samples` vytvorí súbory `writable/samples/{profil}-{jazyk}-faktura.pdf` a `...-vykaz.pdf` (profily sk, cz, pl, pl_eu_b2b, pl_non_eu, každý v jazyku profilu a v `en`). Príkaz len číta aktuálne znenia zákonných textov, nič nezapisuje do DB, všetky strany majú štítok VZOR. Voľby `--profile`, `--language`, `--out <podadresár>`. Súbory skopíruj z kontajnera (`docker compose cp php:/var/www/html/writable/samples ./samples`). Meranie výkonu (nepatrí do `make test`): `docker compose exec -T -u www-data php vendor/bin/phpunit --group performance` (skupina `performance`, každé PDF pod 2 s, peak pamäte pod 128 MB); spúšťa sa pred `/gsd-verify-work`.
4. **Účtovníčka**: pošli vzory, prejdite otvorené otázky 1 až 7 z `06-CONTEXT.md`, po potvrdení uprav znenia v Nastavenia → Zákonné texty a zaškrtni „Potvrdené účtovníčkou“.
5. **Ručný UAT QR**: SK vzor naskenuj aspoň dvoma slovenskými bankovými aplikáciami (IBAN, suma, VS, splatnosť), CZ vzor českou a PL vzor poľskou aplikáciou. Vystav aj testovaciu PL faktúru so sumou nad 9 999,99 PLN. Zapíš aplikáciu, verziu a výsledok (OK/chyba) a pri SK hodnotu `PayBySquare::VERSION`; chyby zapíš ako gap pre `/gsd-plan-phase 6 --gaps`. Výsledok 2026-10-28: ČSOB SK rozpozná iba PAY by square 1.0.0 (nie 1.1.0 ani 1.2.0) a hlási chybu pri vyplnenej ulici alebo meste príjemcu; aplikácia preto posiela hlavičku 1.0.0 a z príjemcu iba meno.
6. **Núdzový postup, ak `xz` chýba**: SK faktúry sa nevystavia (chyba `qr_failed`), v DB sa nič nezapíše (vystavenie je transakčné). Oprava: `docker compose build php` a nové nasadenie, potom vystavenie zopakovať.

## 17. Edge proxy

Jediný kontajner, ktorý publikuje porty 80 a 443 (D-02). Súbory: `docker/edge/nginx.conf`, `compose.edge.yaml`; na serveri v `/opt/edge/` ako `nginx.conf` a `compose.edge.yaml` (CI ich nenasadzuje, kopírujú sa ručne).

- Port 443: `stream` s `ssl_preread`, smerovanie podľa SNI na `wt-prod-nginx:443` alebo `wt-stage-nginx:443` bez rozbalenia TLS, takže každý stack má vlastný certifikát (Let's Encrypt DNS-01), hlavičky aj CSP a HTTP/2 sa dohaduje priamo so stackom. Neznámy alebo chýbajúci SNI sa zatvorí. Mená sa rozlišujú za behu, zastavený stack nezhodí edge.
- Port 80: `301` na `https://$host$request_uri`.

Jednorazová inštalácia (ako `deploy`, po kontrole kolízií podsiete cez `docker network ls` a `ip route`):

```
docker network create --driver bridge --subnet 10.250.250.0/24 edge
# súbory skopírovať do /opt/edge: nginx.conf (docker/edge/nginx.conf) a compose.edge.yaml
cd /opt/edge && docker compose -f compose.edge.yaml up -d
```

Mail stack (webmail, autoconfig, autodiscover, mta-sts) má v `map` štyri riadky na `mail-web:443` (nginx `web` stacku `mail`, v sieti `edge` s aliasom `mail-web`), pozri [mail.md](mail.md).

Nový hostname: pridať riadok do `map` v `nginx.conf`, potom `docker compose -f compose.edge.yaml exec edge nginx -s reload`.

Skutočná IP klienta: Cloudflare -> edge (`proxy_protocol on`) -> stack nginx (`listen 443 ssl proxy_protocol`). `docker/nginx/realip/edge.conf` prijíma `CF-Connecting-IP` iba z `10.250.250.0/24` (edge) a `docker/nginx/http.d/cf-peer.conf` (`geo $proxy_protocol_addr $cf_peer`) vráti `444`, ak PROXY zdroj nie je rozsah Cloudflare (ochrana pred podvrhnutím hlavičky). Vďaka tomu zostáva `$binary_remote_addr` (limit prihlasovania AUTH-04) adresou klienta. Rozsahy Cloudflare udržiava `sh scripts/cf-ips-check.sh [--write]` (zapisuje `docker/nginx/http.d/cf-peer.conf`).

Lokálny test celého reťazca (HTTP/2 cez edge, realip, zatvorenie neznámeho SNI, presmerovanie 80, noindex, zahodenie PROXY zdroja mimo Cloudflare): `sh scripts/edge-selftest.sh`.

## 18. Prechod produkcie na edge (cut-over)

Jednorazový postup, výpadok produkcie menej ako 1 minúta (Cloudflare medzitým ukazuje 52x). `X` = celý SHA commitu s edge zmenami, ktorý už nasadil staging (obrazy `sha-X` sú na serveri stiahnuté).

Predpoklady: sieť `edge` vytvorená, súbory v `/opt/edge` skopírované, edge **nespustený**, staging nasadený a overený, DNS `work-tracker-stage` existuje.

Ako `deploy` v `/opt/work-tracker`:

```
cd /opt/work-tracker
cp compose.staging.yaml compose.staging.yaml.pre-edge
cp deploy.sh deploy.sh.pre-edge
cp cert-renew.sh cert-renew.sh.pre-edge
cp .env .env.pre-edge
cp .last-good-tag .last-good-tag.pre-edge
# z repozitára na tagu X: compose.server.yaml, scripts/deploy.sh, scripts/cert-renew.sh do /opt/work-tracker (scp)
printf 'COMPOSE_PROJECT_NAME = task-tracker\nEDGE_ALIAS = wt-prod-nginx\n' >> .env
IMAGE_TAG=sha-X docker compose -f compose.server.yaml config | grep -E '^name: task-tracker$'
docker volume ls | grep task-tracker_pgdata
IMAGE_TAG=sha-X SKIP_PULL=1 sh ./deploy.sh && (cd /opt/edge && docker compose -f compose.edge.yaml up -d)
```

`deploy.sh` s `SKIP_PULL=1` urobí overenú zálohu, no-op migráciu a prevytvorí nginx bez host portov (tu začína výpadok); edge ho ukončí. Príkaz sa píše na jeden riadok, aby výpadok trval čo najkratšie.

Overenie: `SSH_TARGET=deploy@2.31.74.48 SMOKE_PROJECT=task-tracker ORIGIN_IP=2.31.74.48 sh scripts/smoke-server.sh work-tracker.vostack.dev` a to isté pre `work-tracker-stage.vostack.dev` so `SMOKE_PROJECT=task-tracker-staging SMOKE_EXPECT_NOINDEX=1`; prihlásenie na produkcii; `crontab -l` má jeden riadok `cert-renew.sh` na stack.

### Rollback

```
cd /opt/edge && docker compose -f compose.edge.yaml down
cd /opt/work-tracker
cp deploy.sh.pre-edge deploy.sh
cp cert-renew.sh.pre-edge cert-renew.sh
cp .last-good-tag.pre-edge .last-good-tag
IMAGE_TAG=$(cat .last-good-tag) docker compose -f compose.staging.yaml up -d --wait --remove-orphans
```

Starý `compose.staging.yaml` má projekt `task-tracker`, znova publikuje 80/443 a používa tie isté volumes. Ak sa zmenil riadok cron, obnov ho. Nepushovať `main`, kým sa problém nevyrieší. `compose.staging.yaml` a súbory `*.pre-edge` na serveri ostávajú týždeň stabilnej prevádzky, potom sa zmažú.

## 19. Staging seed (reset pri každom deployi)

Staging sa pri každom deployi vráti do rovnakého stavu s vymyslenou, ale realistickou históriou od 2026-01-01 do dňa deployu: 3 subjekty (SK, CZ, PL), 8 klientov, cca 150 úloh vrátane úloh rozdelených na časti cez viac mesiacov a cca 40 faktúr vystavených skutočným `InvoiceIssuer` (bezmedzerové čísla, PDF faktúry a výkazu, QR). Aktuálny mesiac ostáva nevyfakturovaný, jeden klient má nevyfakturovaný posledný celý mesiac (scenár neskorej fakturácie). Dáta sú deterministické (pevný seed, bez Fakeru): rovnaký deň dá rovnaké čísla a sumy. Všetko beží cez aplikačné služby, nikdy cez surové INSERT do biznis tabuliek.

### Čo sa deje pri deployi so `STAGING_RESEED = 1`

1. Bežný postup: pull, `db`, **overená záloha** (ostáva aj na stagingu, rotácia 14; záloha syntetických dát je lacná a ak by poistka zlyhala, existuje overený dump), rotácia.
2. `DROP DATABASE task_tracker WITH (FORCE)` a `provision.sql` (role a databáza). Tabuľky faktúr odmietajú DELETE aj TRUNCATE triggerom, preto sa maže celá DB. Heslá rolí číta kontajner `db` zo svojich secret súborov, v argumentoch ani v hostiteľskom shelli nie sú.
3. Migrácie ako `app_owner` v kontajneri `migrate` (nezmenené).
4. `php spark staging:seed` v jednorazovom kontajneri `seed` ako **app_rw** (`app.env`). Heslo `app_owner` ostáva len v `migrate.env`; ak by seed potreboval oprávnenie, ktoré UI nemá, vyjde to na stagingu rovnako ako by vyšlo v produkcii. Po behu sa vlastník `writable/` vráti `www-data`.
5. `up -d --wait`, `.last-good-tag`.

Čas: reset + seed pridá k deployu rádovo 20 až 30 s (seed samotný cca 15 s lokálne, limit je 60 s). `deploy.sh` vypíše trvanie resetu a seedu a `spark staging:seed` trvanie modulov a počty riadkov.

### Poistky proti seedovaniu produkcie

Navzájom nezávislé vrstvy:

| Vrstva | Pravidlo |
|--------|----------|
| `deploy.sh`, pred akýmkoľvek volaním dockeru | `STAGING_RESEED` musí byť presne `1` (iné hodnoty než prázdne/`0` odmietne); odmietne sa pri `COMPOSE_PROJECT_NAME = task-tracker`, `APP_DOMAIN = work-tracker.vostack.dev` alebo adresári `/opt/work-tracker` |
| `deploy.sh` | produkcia s `staging.seed = true` v `app.env` sa odmietne aj bez `STAGING_RESEED` (skopírovaný staging `app.env`) |
| `deploy.sh` | vyžaduje `staging.seed = true` v `app.env` a neprázdny `secrets/seed_admin.env` s právami 0600 |
| `spark staging:seed` | odmietne bez `staging.seed = true` (predvolene vypnuté), pri `app.baseURL` s hostom `work-tracker.vostack.dev`, pri neprázdnej DB (faktúry, subjekty, klienti, úlohy alebo používatelia) a v produkcii bez súboru s údajmi admina |

Pri akomkoľvek odmietnutí deploy skončí so správou `Nic sa nenasadilo` a predošlý stack beží ďalej.

### Zlyhanie seedu

Seed nie je jedna veľká transakcia (každá služba má vlastnú, ako v UI). Pri chybe príkaz skončí nenulovým kódom a `deploy.sh` sa zastaví **pred** `up`: staré kontajnery bežia ďalej proti čiastočne naplnenej DB a `.last-good-tag` sa nemení. Ďalší deploy DB znovu vytvorí. Ručne: `docker compose -f compose.server.yaml run --rm --no-deps seed php spark staging:seed` na čerstvo vytvorenej DB (vyžaduje prázdnu DB, inak odmietne).

### Jednorazové založenie `secrets/seed_admin.env` na serveri

Admin na stagingu má pevný e-mail a heslo, ktoré prežijú každý reset. Admin je bez 2FA. Súbor vzniká iba na serveri ako `deploy` v `/opt/work-tracker-staging`, nikdy nie v gite ani v chate.

```
cd /opt/work-tracker-staging
umask 077
SEED_EMAIL='<tvoj e-mail pre staging>'
SEED_PW=$(openssl rand -base64 24 | tr -d '/+=')
printf 'SEED_ADMIN_EMAIL = %s\nSEED_ADMIN_PASSWORD = %s\n' "$SEED_EMAIL" "$SEED_PW" > secrets/seed_admin.env
chmod 600 secrets/seed_admin.env
unset SEED_EMAIL SEED_PW
echo 'STAGING_RESEED = 1' >> .env
echo 'staging.seed = true' >> app.env
```

Súbor obsahuje iba e-mail a heslo. Starší riadok `SEED_ADMIN_TOTP_SECRET` sa od 2026-10-28 ignoruje a môžeš ho zmazať. Po každom resete sa admin prihlási heslom a uvidí ponuku 2FA: zvoľ "Nechcem nastavovať 2FA" (alebo si 2FA na ten deploy cyklus nastav, po ďalšom resete zmizne).

Heslo si prečítaj zo súboru rovnako len na svojom termináli. Rotácia: uprav súbor, ďalší deploy ho použije. Súbor číta iba kontajner `seed`; php-fpm ani `migrate` ho nemontujú. Záložné kódy 2FA seed nevytvára.

### Lokálne

`make reset-staging` zahodí **vývojovú** DB, znovu ju založí, zmigruje a naplní stagingovým seedom (rovnaká ochrana prostredia ako `make reset`, povolené len pre `development`/`testing`). Údaje admina sa berú z `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` v dev `.env` (vzor v `.env.example`). `php spark staging:seed --profile=small` je rýchly profil (3 klienti) pre testy.

### Rozšírenie o nový modul

Seed je poradie modulov (`App\Libraries\StagingSeed\StagingSeeder::MODULES`: `AdminUser`, `MasterData`, `Tasks`, `Invoices`). Nová fáza pridá svoj modul takto:

1. Vytvor `app/Libraries/StagingSeed/Modules/<Nazov>.php` implementujúci `SeedModule` (`name()`, `run(SeedContext $ctx)`).
2. Ak modul potrebuje plánované dáta, rozšír `HistoryPlan` a `HistoryPlanner` (zachovaj determinizmus: iba seedovaný `Randomizer`, žiadny Faker ani reálny čas, všetky dátumy najviac do `today`).
3. Zapisuj iba cez aplikačné služby (nie surové SQL do biznis tabuliek) a pred každou udalosťou nastav `$ctx->clock->at('YYYY-MM-DDTHH:MM:SS')`, aby `created_at`, audit a dokumenty nesli fiktívny čas. ID z predchádzajúcich modulov získaš cez `$ctx->id('client'|'entity'|'task'|'part'|'user', $kluc)`.
4. Pridaj triedu na koniec `StagingSeeder::MODULES`, za moduly, na ktorých závisí.
5. Doplň `tests/unit/StagingSeed/HistoryPlannerTest.php` a tvrdenia v `tests/integration/StagingSeed/StagingSeederTest.php` (počty, súčty, dátumy).
6. Celkový čas seedu drž v rozpočte (reset + seed do cca 60 s); `staging:seed` vypisuje čas po moduloch.

Plánované moduly: `Payments` (fáza 7, úhrady), `Fx` (fáza 8, cudzomenové faktúry a kurzy), `CreditNotes` (fáza 9, dobropisy), `Limits` (fáza 10, daňové limity obratu). Do tej doby fakturujú iba v mene subjektu (klient a subjekt majú rovnakú menu).
