# Staging runbook: work-tracker.vostack.dev

## 1. Prehľad

- D-01: doména `work-tracker.vostack.dev` (DNS a proxy v Cloudflare).
- D-02: server Hetzner Cloud #172176757, IP `2.31.74.48`.
- D-03: staging je zároveň budúca produkcia (rovnaké obrazy, rovnaký compose).
- D-04: architektúra amd64, obrazy z GHCR (`ghcr.io/voslara4n/task-tracker-php`, `-nginx`).
- Projektový adresár na serveri: `/opt/work-tracker`, compose súbor `compose.staging.yaml`.

## 2. Cloudflare

V Cloudflare -> `vostack.dev`:

- DNS -> Records: `A` záznam `work-tracker` -> `2.31.74.48`, **Proxied** (oranžový obláčik).
- SSL/TLS: režim **Full (strict)**, Always Use HTTPS zapnuté, Minimum TLS Version 1.2.
- Speed -> Optimization: **Rocket Loader OFF**.
- Scrape Shield: **Email Obfuscation OFF**.
- Security -> Bots: **Bot Fight Mode OFF** (inak výzvy rozbijú htmx a service worker, Pitfall 8).
- HSTS v Cloudflare nechať vypnuté, hlavičku posiela nginx (`NGINX_HSTS_VALUE`).

## 3. TLS podľa rozhodnutia O-4 (02-01-SUMMARY.md): `letsencrypt`

### Vetva `letsencrypt` (zvolená)

- Cloudflare -> My Profile -> API Tokens -> Create Token. Oprávnenie iba `Zone:DNS:Edit`, zdroje: iba zóna `vostack.dev`.
- Na serveri (po bootstrape, sekcia 5) vytvor `/opt/work-tracker/secrets/cloudflare.ini` s právami 0600 a jediným riadkom:

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

## 5. Bootstrap servera

Z koreňa repozitára na vývojovom stroji (verejný kľúč je `~/.ssh/work_tracker_deploy.pub`):

```
ssh root@2.31.74.48 "DEPLOY_PUBKEY='$(cat ~/.ssh/work_tracker_deploy.pub)' sh -s" < scripts/server-bootstrap.sh
```

Skript je idempotentný. Nainštaluje Docker Engine a compose plugin z oficiálneho apt repozitára, vytvorí používateľa `deploy`, vypne SSH heslá, vytvorí adresáre a na konci vypíše odtlačok host kľúča `SHA256:...`. Ten pošli späť, použije sa na overenie `known_hosts`.

## 6. Serverový `.env`

Súbor `/opt/work-tracker/.env` vytvor ako `deploy` priamo na serveri, tajomstvá generuj tam (nikdy ich nepíš do gitu ani chatu):

```
DBPW_SU=$(openssl rand -hex 24)
DBPW_OWNER=$(openssl rand -hex 24)
DBPW_RW=$(openssl rand -hex 24)
ENC=$(openssl rand -hex 32)
PEPPER=$(openssl rand -hex 32)
cat > /opt/work-tracker/.env <<ENV
CI_ENVIRONMENT = production
app.baseURL = 'https://work-tracker.vostack.dev/'
APP_DOMAIN = work-tracker.vostack.dev
NGINX_HSTS_VALUE = max-age=31536000

TLS_MODE = letsencrypt
SSL_CERT = /etc/letsencrypt/live/work-tracker.vostack.dev/fullchain.pem
SSL_KEY = /etc/letsencrypt/live/work-tracker.vostack.dev/privkey.pem
LE_EMAIL = <tvoj e-mail>

IMAGE_PREFIX = ghcr.io/voslara4n/task-tracker

DB_SUPERUSER_PASSWORD = $DBPW_SU
DB_OWNER_PASSWORD = $DBPW_OWNER
DB_RW_PASSWORD = $DBPW_RW

database.default.hostname = db
database.default.database = task_tracker
database.default.username = app_rw
database.default.password = $DBPW_RW
database.default.DBDriver = Postgre
database.default.port = 5432

database.owner.hostname = db
database.owner.database = task_tracker
database.owner.username = app_owner
database.owner.password = $DBPW_OWNER
database.owner.DBDriver = Postgre
database.owner.port = 5432

email.protocol = smtp
email.transport = log
email.fromEmail = noreply@work-tracker.vostack.dev
email.fromName = 'Work Tracker'

encryption.key = hex2bin:$ENC
auth.backupCodePepper = $PEPPER
ENV
unset DBPW_SU DBPW_OWNER DBPW_RW ENC PEPPER
chown deploy:82 /opt/work-tracker/.env   # vyzaduje root (deploy nie je v skupine 82)
chmod 0640 /opt/work-tracker/.env
```

Poznámky: `IMAGE_TAG` nastavuje `scripts/deploy.sh`, nepatrí do `.env`. `chown ... :82` môže vyžadovať root (spusti ako root: `chown deploy:82 /opt/work-tracker/.env`). GID 82 je `www-data` v alpine php obraze. `email.transport = log` znamená, že e-maily sa neodosielajú, do logu idú iba metadáta.

## 7. Tajomstvá mimo servera

Ulož do správcu hesiel: `encryption.key`, `auth.backupCodePepper`, tri DB heslá a Cloudflare API token (`cat /opt/work-tracker/.env` na serveri, iba pre tieto riadky). Strata `encryption.key` znamená, že TOTP tajomstvá sa nedajú dešifrovať, obnova je cez `spark user:reset-2fa` (plán 02-18). Strata `auth.backupCodePepper` zneplatní záložné kódy.

## 8. GitHub

Environment `staging` v `voslarA4N/task-tracker` a štyri secrets (nastavuje Claude cez `gh`): `DEPLOY_SSH_KEY` (privátny ed25519 kľúč `~/.ssh/work_tracker_deploy`), `DEPLOY_KNOWN_HOSTS` (overený riadok z `ssh-keyscan`), `DEPLOY_HOST` (`2.31.74.48`), `DEPLOY_USER` (`deploy`). Používateľ `deploy` je v skupine `docker`, čo je ekvivalent roota (akceptované riziko, jediný používateľ a server, kľúč iba v GitHub secrets a na dev stroji).

## 9. Nasadenie a rollback

- Nasadenie robí `scripts/deploy.sh` (plán 02-07) cez SSH z GitHub Actions.
- Posledný fungujúci tag je v `/opt/work-tracker/.last-good-tag`.
- Pred migráciou sa robí `pg_dump` do `/var/backups/work-tracker`.
- Obnova: `pg_restore --clean --if-exists -d task_tracker <súbor>` v kontajneri `db`.

## 10. Prvý admin

```
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && docker compose -f compose.staging.yaml exec php php spark user:create <e-mail> --admin'
```

Heslo sa zadáva iba interaktívne (D-09), pre interaktívny prompt použi `ssh -t`.

## 11. Stav prípravy

Stav k 2026-10-24 (overil Claude):

- Bootstrap: hotový cez `ssh my-server-agent` (používateľ `aiagent` so sudo), Ubuntu 26.04 `resolute`, Docker Engine 29.9.2, Docker Compose v5.6.1. Ubuntu 26.04 má uutils coreutils, `install -g 82` nefunguje, skript preto používa `chown deploy:82`.
- Odtlačok host kľúča: `SHA256:LslaUrQDpTdX38kT4AUjvq1CLyLA92Qt2yZifDTScUQ` (ED25519), overený cez `ssh-keyscan` z vývojového stroja.
- SSH: `deploy` sa prihlási deploy kľúčom, prihlásenie heslom je vypnuté (`Permission denied (publickey)`), `PermitRootLogin prohibit-password`.
- Adresáre `/opt/work-tracker/{secrets,certs,letsencrypt,docker}` a `/var/backups/work-tracker` existujú so správnymi právami.
- GitHub environment `staging` existuje, nastavené sú `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS`. **Chýba** `DEPLOY_SSH_KEY` (nastaví vlastník).
- TLS_MODE: `letsencrypt` (O-4).
- DNS: `work-tracker.vostack.dev` je proxovaný cez Cloudflare (A 188.114.96.11/97.11, AAAA 2a06:98c1:3120::b/3121::b), origin IP sa nezverejňuje. Cloudflare edge vracia 521 (origin odmietol spojenie, nginx ešte nebeží), nie 522, takže firewall Cloudflare na port 443 púšťa.
- Firewall: Hetzner Cloud Firewall nastavený (2026-10-24). Z vývojového stroja porty 80, 443 a 5432 končia timeoutom (zahodené), SSH (deploy aj aiagent) a ICMP fungujú. Prístup z Cloudflare na 80/443 sa overí pri prvom deployi (02-09).
- Cloudflare token: `secrets/cloudflare.ini` existuje (600, deploy), jediný riadok `dns_cloudflare_api_token`, token je podľa `/user/tokens/verify` aktívny a vidí iba zónu `vostack.dev`.
- Čaká na vlastníka: `.env` (sekcia 6, spustiť celé ako root), `DEPLOY_SSH_KEY`.

## 12. Správa používateľa na stagingu

Na stagingu je `email.transport = log` (O-3), reset hesla e-mailom sa preto nedá dokončiť. Náhrada:

```
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && docker compose -f compose.staging.yaml exec php php spark user:password <e-mail>'
ssh -t deploy@2.31.74.48 'cd /opt/work-tracker && docker compose -f compose.staging.yaml exec php php spark user:reset-2fa <e-mail>'
```

- `user:password`: skrytý prompt, minimálne 12 znakov, heslo nesmie obsahovať časť e-mailu pred `@`. Odvolá všetky dôveryhodné zariadenia.
- `user:reset-2fa`: zruší TOTP aj záložné kódy, 2FA sa nastaví znova pri ďalšom prihlásení (`--yes` preskočí potvrdenie).
