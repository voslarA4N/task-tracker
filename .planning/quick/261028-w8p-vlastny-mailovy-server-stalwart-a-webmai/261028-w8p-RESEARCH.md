# Quick 261028-w8p: Vlastný mailový server (Stalwart) a webmail - Research

**Researched:** 2026-10-28
**Domain:** self-hosted pošta (Stalwart 1.0, Roundcube 1.7) v Docker Compose vedľa task-tracker stackov a zdieľaného SNI edge nginx, DNS/deliverability pre `vostack.dev`
**Confidence:** MEDIUM-HIGH (Stalwart docs HIGH, overené dnes na stalw.art, verzie overené živo z GitHub/Docker Hub API; stav servera overený read-only cez `ssh my-server-agent`. Stalwart 1.0.0 je 2 dni starý, 1.0.1 vyšiel dnes, preto MEDIUM pre stabilitu.)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **Softvér:** Stalwart (all-in-one: SMTP, IMAP, JMAP, antispam, DKIM, web admin), Docker, pripnutá verzia.
- **Doména a schránky:** vostack.dev (dnes `MX 0 .`, `SPF -all`, `DMARC p=reject` → treba zmeniť). Schránky info@vostack.dev, noreply@vostack.dev. Aliasy na info@: postmaster@, abuse@, dmarc@ (DMARC rua reporty).
- **Prístup:** IMAP klienti (iPhone Mail, Outlook, Thunderbird) + autoconfig/autodiscover. Webmail na webmail.vostack.dev (návrh SnappyMail, ľahký), cez Cloudflare (proxied) a existujúci edge proxy.
- **Fakty:** odchádzajúce 25/465/587 otvorené; PTR predvolený → vlastník nastaví na mail.vostack.dev; Hetzner Cloud Firewall dnes 80/443 iba z CF, 22 otvorený, pre poštu otvoriť 25, 465, 587, 993; mail.vostack.dev DNS-only (sivý oblak); CF token Zone:DNS:Edit v /opt/work-tracker/secrets/cloudflare.ini (LE DNS-01 funguje, certbot/dns-cloudflare); root cez `ssh my-server-agent`, aplikačné ako `deploy`.

### Claude's Discretion
Webmail softvér (SnappyMail vs Roundcube), umiestnenie Stalwart web admin, TLS pre SMTP/IMAP (LE DNS-01 cert pre mail.vostack.dev a reload), zálohy maildatabázy (zatiaľ lokálny dump), limity pamäte kontajnerov, MTA-STS/TLS-RPT voliteľne.

### Specifics / Deferred
- Všetko v repozitári ako ops (`compose.mail.yaml`, `docker/mail/`, `docs/ops/mail.md`), inštalované ručne raz do `/opt/mail` (ako edge), nie cez CI.
- Outward kroky (DNS, firewall, PTR, štart na serveri, heslá) vykoná orchestrátor/vlastník až po potvrdení.
</user_constraints>

## Summary

Stalwart vydal **v1.0.0 (2026-10-26)** s novým DB layoutom (0.16.x sa nedá upgradovať in-place) a **v1.0.1 dnes (2026-10-28)**. Pre čistú inštaláciu použiť priamo `stalwartlabs/stalwart:v1.0.1`, inak by nás o pár týždňov čakala migrácia nástrojom. Konfigurácia sa v 1.x **neukladá do config.toml**: `config.json` drží iba pripojenie k data store (`{"@type":"RocksDb","path":"/var/lib/stalwart/"}`), všetko ostatné (domény, účty, listenery, certifikáty, DKIM) je v data store a mení sa cez WebUI, JMAP API (`x:Foo/set`) alebo `stalwart-cli` (aj idempotentné NDJSON plány `apply`). [CITED: stalw.art/docs/configuration/declarative-deployments]

Kľúčové architektonické rozhodnutie: **mail protokoly (25/465/587/993) publikovať priamo z kontajnera Stalwart na IPv4 hosta, HTTP časti (webmail, autoconfig, autodiscover, mta-sts) cez Cloudflare → edge (SNI passthrough + PROXY) → nový TLS-terminujúci nginx `mail-web` v mail stacku**, ktorý proxuje na Roundcube a len vybrané cesty na Stalwart HTTP listener 8080. Stalwart port 443 sa vôbec nepublikuje a web admin ostáva len cez SSH tunel/CLI. TLS: jeden certbot DNS-01 certifikát (existujúci postup projektu) so SAN mail/webmail/autoconfig/autodiscover/mta-sts, Stalwart ho číta ako `Certificate` objekt typu `File`, reload cez `Action/ReloadTlsCertificates`. Stalwartov vlastný ACME DNS-01 by vyžadoval **automatickú správu DNS zóny** (zapisuje aj SPF/DMARC `p=reject`/CNAME/CAA s `accounturi`), čo by kolidovalo s CF-proxied hostmi a s certbot certifikátmi task-trackera, preto nie.

Server overený dnes: Ubuntu 26.04.1, Docker 29.9.2, 2 vCPU, 3.8 GB RAM (3.0 GB available), disk 28 GB voľných, **má IPv6 `2a01:4f8:c012:70e5::1`**, docker siete sú IPv4-only a `docker-proxy` počúva aj na `[::]` pre publikované porty. **Hetzner resolvery dostávajú od Spamhaus `127.255.255.254` (refused)**, overené aj pre testovaciu adresu 127.0.0.2, takže antispam DNSBL by bez vlastného rekurzívneho resolvera nefungoval.

**Primary recommendation:** `compose.mail.yaml` so službami `stalwart` (v1.0.1, RocksDB, porty iba `0.0.0.0:`), `unbound` (vlastný rekurzívny resolver), `roundcube` (1.7.5-apache-nonroot, SQLite), `web` (nginx 1.30, v sieti `edge` ako `mail-web`), `certbot` (profil); DNS ručne (Manual DNS management), DMARC začať `p=none`, mail iba IPv4 (bez AAAA) kým nie je IPv6 PTR a IPv6 v docker sieti.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| SMTP in (25), submission (465/587), IMAPS (993) | Stalwart kontajner, priamo publikované host porty | Hetzner Cloud FW | potrebuje skutočné IP klientov (SPF, DNSBL, auto-ban), CF neproxuje SMTP/IMAP |
| TLS pre mail protokoly | Stalwart (`Certificate` z File) | certbot DNS-01 (cron) | rovnaký vzor ako stack nginx, bez zápisu Stalwartu do zóny |
| Webmail UI | Roundcube kontajner | `mail-web` nginx (TLS, realip, limit_req) | Roundcube hovorí HTTP 8000, TLS musí ukončiť backend za edge passthrough |
| Autoconfig/autodiscover/MTA-STS policy | Stalwart HTTP listener 8080 (generuje dokumenty) | `mail-web` (TLS, iba whitelist ciest) | 443 na hoste vlastní edge a je iba pre CF, mail.vostack.dev je DNS-only |
| Smerovanie HTTPS podľa SNI | edge nginx (`/opt/edge`) | Cloudflare (proxied) | existujúci D-02 vzor, iba nové riadky v `map` |
| Rekurzívne DNS (DNSBL, DNSSEC) | `unbound` sidecar | — | Hetzner resolvery sú Spamhausom odmietnuté (overené) |
| Správa (domény, účty, aliasy, listenery) | `stalwart-cli` cez `http://stalwart:8080` v sieti `mail` | WebUI cez SSH tunel | bez verejne dostupného admin rozhrania |
| DNS záznamy, PTR, firewall | vlastník/orchestrátor (CF API, Hetzner konzola) | Stalwart `dnsZoneFile` ako zdroj DKIM/MTA-STS hodnôt | Manual DNS management |
| Zálohy | cron na hostiteľovi (stop, tar volume, start) | neskôr restic (projektový plán) | RocksDB nemá online backup |

## Standard Stack

### Core (verzie overené 2026-10-28)

| Komponent | Verzia / tag | Účel | Prečo | Confidence |
|-----------|--------------|------|-------|------------|
| Stalwart | `stalwartlabs/stalwart:v1.0.1` (Debian trixie-slim, 116 MB, beží ako uid 2000) | SMTP/IMAP/antispam/DKIM/MTA-STS/autoconfig | rozhodnutie vlastníka; 1.x hneď, aby odpadla migrácia 0.16→1.0 [VERIFIED: GitHub releases API, Docker Hub API] | HIGH (verzia), MEDIUM (zrelosť 1.0) |
| Data store | RocksDB (default wizardu), blob/search/in-memory store = default | všetko v jednom adresári `/var/lib/stalwart` | doc odporúča pre single server; jedna záloha pokryje všetko [CITED: stalw.art/docs/install/store] | HIGH |
| Roundcube | `roundcube/roundcubemail:1.7.5-apache-nonroot` (port 8000, SQLite v `/var/roundcube/db`) | webmail | bezpečnostné vydania 1.7.5/1.6.20 z 2026-10-19, oficiálny obraz aktualizovaný 2026-10-27 [VERIFIED: GitHub + Docker Hub API] | HIGH |
| nginx (`web`) | `nginx:1.30-alpine` (rovnaký ako edge) | TLS pre webmail/autoconfig/mta-sts, realip z edge, proxy | projektový štandard, existujúce `realip/edge.conf` a `cf-peer.conf` | HIGH |
| Unbound | vlastný mini obraz `FROM alpine:3.24` + `apk add unbound` | rekurzívny validujúci resolver pre Stalwart | Stalwart docs ho odporúčajú; Alpine 3.24 vydaný/obnovený 2026-10-23 [CITED: stalw.art/docs/server/dns/resolver] | HIGH |
| certbot | `certbot/dns-cloudflare:v5.8.0` (už v projekte) | LE DNS-01 cert so SAN pre mail hosty | overený postup projektu | HIGH |
| stalwart-cli | `stalwartlabs/cli:1.0.14` (2026-10-25) | provisioning domény, účtov, aliasov, listenerov | schema-driven, funguje s ľubovoľnou verziou servera [CITED: stalw.art/docs/management/cli] | HIGH |

### Alternatives Considered

| Namiesto | Možnosť | Prečo nie |
|----------|---------|-----------|
| Roundcube | SnappyMail `djmaze/snappymail:v2.38.2.1` | posledné riadne vydanie 2.38.2 je z 2024-10-09, potom až 2026-10-23 docker-only hotfix „php2shell fix“; commity v master sú aktívne, ale bezpečnostné opravy sa nedostávajú do vydaní pravidelne. Roundcube vydáva LTS opravy (1.6.x aj 1.7.x) v ten istý deň a má vstavaný `login_rate_limit` [VERIFIED: GitHub API]. Pamäťovo sú porovnateľné (PHP). |
| Stalwart ACME DNS-01 (Cloudflare) | certbot + `Certificate` File | DNS-01 v Stalwarte vyžaduje Automatic DNS management (potrebuje Zone:Read + DNS:Edit, zapisuje SPF/DMARC `p=reject`/SRV/CNAME na mail host, voliteľne CAA s `accounturi`, ktoré by zablokovalo certbot účet task-trackera) [CITED: stalw.art/docs/server/tls/acme/challenges, /docs/install/setup-wizard] |
| TLS-ALPN-01 | — | LE validátor by išiel na mail.vostack.dev:443 priamo, FW pustí 443 iba z CF |
| RocksDB | PostgreSQL ako data store | online `pg_dump` bez stopnutia, ale ďalší PG kontajner a pamäť; pre 3 schránky zbytočné. Zvážiť len ak by nočný 10-30 s výpadok vadil |
| Vlastný unbound obraz | `klutchell/unbound` (12.9M pulls, aktualizovaný 2026-10-26) | tretia strana; Alpine balík je menšie supply-chain riziko |
| Stalwart 1.0.1 | 0.16.27 (2026-10-18) | zrelšia, ale 1.0 nemá in-place upgrade, migrácia nástrojom |

## Package Legitimacy Audit

Fáza neinštaluje npm/PyPI/Composer balíky, iba Docker obrazy. slopcheck sa na Docker obrazy nevzťahuje; legitimita overená cez oficiálne zdroje.

| Obraz | Registry | Zdroj | Overenie | Disposition |
|-------|----------|-------|----------|-------------|
| `stalwartlabs/stalwart:v1.0.1` | Docker Hub (aj ghcr) | github.com/stalwartlabs/stalwart, binárky s attestation | oficiálne docs uvádzajú tento obraz | Approved |
| `stalwartlabs/cli:1.0.14` | Docker Hub (aj ghcr, cosign) | github.com/stalwartlabs/cli | oficiálne docs | Approved |
| `roundcube/roundcubemail:1.7.5-apache-nonroot` | Docker Hub | github.com/roundcube/roundcubemail-docker | oficiálny README | Approved |
| `nginx:1.30-alpine`, `alpine:3.24` | Docker Official Images | — | už v projekte / oficiálne | Approved |
| `certbot/dns-cloudflare:v5.8.0` | Docker Hub | certbot projekt | už v projekte | Approved |

**Removed:** none. **Suspicious:** none.

## Architecture Patterns

### System Architecture Diagram

```
                 Internet MTAs / IMAP & SMTP klienti                 Prehliadač, Thunderbird, Outlook, MTA-STS fetch
                              |                                                      |
              DNS-only  mail.vostack.dev A 2.31.74.48              CF proxied: webmail / autoconfig / autodiscover / mta-sts
                              |                                                      |
         Hetzner FW: 25,465,587,993 (0.0.0.0/0)                    Cloudflare (Full strict) --> Hetzner FW 443 iba CF
                              |                                                      |
        host 0.0.0.0:{25,465,587,993}  (iptables DNAT,              edge nginx (stream, ssl_preread, proxy_protocol)
         zdrojová IP zachovaná, žiadne [::])                          map SNI -> mail-web:443   (sieť edge 10.250.250.0/24)
                              |                                                      |
                              v                                                      v
   +--------------------------- sieť mail 10.250.251.0/24 (pevná podsieť) ---------------------------------+
   |   stalwart (alias mail.vostack.dev) <---- IMAPS 993 / SMTPS 465 ---- roundcube :8000 <---- web (nginx) |
   |      | listeners 25/465/587/993, http 8080 <---- iba /mail/config-v1.1.xml, /autodiscover/*,          |
   |      |                                          /.well-known/mta-sts.txt ----------------- web        |
   |      +--> DNS dotazy (SPF/DKIM/DMARC/DNSBL) --> unbound (10.250.251.53) --> root/autoritatívne DNS  |
   |      +--> odchádzajúce SMTP (MASQUERADE -> 2.31.74.48, PTR mail.vostack.dev)                        |
   +-------------------------------------------------------------------------------------------------------+
   admin: ssh tunel -> 127.0.0.1:8080 (WebUI) alebo `docker run --network mail stalwartlabs/cli ... http://stalwart:8080`
   certbot (DNS-01, CF token) -> /opt/mail/letsencrypt -> renew skript skopíruje do /opt/mail/tls (uid 2000) -> ReloadTlsCertificates + nginx -s reload
```

### Recommended Project Structure

```
compose.mail.yaml                  # /opt/mail, projekt `mail`, ručná inštalácia
docker/mail/
├── nginx/nginx.conf               # web: listen 443 ssl proxy_protocol, server_name per host
├── nginx/ (kópie realip/edge.conf, http.d/cf-peer.conf alebo bind z docker/nginx)
├── unbound/Dockerfile, unbound.conf
├── roundcube/config.inc.php       # proxy_whitelist, use_https, login_rate_limit
└── stalwart/plan.ndjson           # idempotentný plán bez tajomstiev (účty, aliasy, listener 587, resolver, ...)
scripts/mail-cert-renew.sh         # certbot renew + kópia pre uid 2000 + reload
scripts/mail-backup.sh             # stop, tar, start, rotácia
docs/ops/mail.md                   # runbook (DNS, FW, PTR, bootstrap, obnova)
docker/edge/nginx.conf             # +4 riadky do map
```

### Pattern 1: Bootstrap cez wizard (SSH tunel), potom CLI plán

1. `docker compose -f compose.mail.yaml up -d stalwart unbound` → bez `config.json` beží bootstrap mód, iba plain HTTP 8080 (publikovaný len na `127.0.0.1`). Heslo dočasného `admin` je v `docker logs ... 2>&1 | grep -A8 'bootstrap mode'` (stderr, mení sa pri každom štarte) alebo pevné cez `STALWART_RECOVERY_ADMIN=admin:<heslo>` (po setup-e odstrániť, platí aj v normálnom režime ako plný prístup). [CITED: stalw.art/docs/configuration/bootstrap-mode, /docs/install/platform/docker]
2. Wizard (`ssh -L 8080:127.0.0.1:8080 my-server-agent`, `http://127.0.0.1:8080/admin`): hostname `mail.vostack.dev` (predvyplnené je container id, nutné prepísať), doména `vostack.dev`, **odškrtnúť „Automatically Obtain TLS Certificate“** (Manual), DKIM nechať zapnuté, storage RocksDB default, internal directory, **Log Destination = Console** (inak kontajner nepíše log), DNS = Manual. Wizard ukáže heslo `admin@vostack.dev` raz. [CITED: stalw.art/docs/install/setup-wizard]
3. `docker compose restart stalwart` (config.json sa číta iba pri štarte). Vzniknú default listenery 25/465/993/995/4190/443/8080 (587 nie je default). [CITED: stalw.art/docs/server/listener]
4. Ďalej CLI (plán v gite bez hesiel, heslá cez `--field` z tajomstiev):

```bash
# Source: stalw.art/docs/management/cli (Docker variant), /docs/management/cli/plan-format
docker run --rm --network mail -v "$PWD:/work" -w /work \
  -v stalwart-cli-cache:/home/nonroot/.cache \
  -e STALWART_URL=http://stalwart:8080 -e STALWART_USER=admin@vostack.dev -e STALWART_PASSWORD \
  stalwartlabs/cli:1.0.14 apply --file docker/mail/stalwart/plan.ndjson
```

```jsonl
{"@type":"upsert","object":"NetworkListener","matchOn":["name"],"value":{"l587":{"name":"submission","protocol":"smtp","bind":{"0.0.0.0:587":true}}}}
{"@type":"upsert","object":"Certificate","matchOn":"*","value":{"crt":{"certificate":{"@type":"File","filePath":"/opt/stalwart-tls/fullchain.pem"},"privateKey":{"@type":"File","filePath":"/opt/stalwart-tls/privkey.pem"}}}}
{"@type":"upsert","object":"AllowedIp","matchOn":"*","value":{"net":{"address":"10.250.251.0/24"}}}
```

Presné tvary `Certificate`/`AllowedIp`/`DnsResolver`/účtov overiť cez `stalwart-cli describe <Object>` alebo `snapshot` na bežiacom serveri (kódovanie: zoznamy a sety sú JSON objekty, `{"0":...}` / `{"x":true}`, trvania v ms). [CITED: stalw.art/docs/configuration/object-encoding] Polia `AllowedIp.address` a matchOn pre Certificate sú [ASSUMED].

Účty (príklad z docs, `domainId` je id domény z `stalwart-cli query Domain`):

```json
{"@type":"User","name":"info","domainId":"<id>","description":"Info",
 "credentials":{"0":{"@type":"Password","secret":"<heslo>"}},
 "aliases":{"0":{"name":"postmaster","domainId":"<id>"},"1":{"name":"abuse","domainId":"<id>"},"2":{"name":"dmarc","domainId":"<id>"}},
 "roles":{"@type":"User"}}
```
[CITED: stalw.art/docs/auth/accounts/user] `noreply` ako samostatný User (rovnaký tvar, bez aliasov). Po zmenách `stalwart-cli create Action/ReloadSettings`; listenery a cache sa uplatnia až po reštarte. [CITED: stalw.art/docs/configuration/declarative-deployments]

Ďalšie nastavenia v pláne: `Domain.reportAddressUri = "mailto:dmarc@vostack.dev"` (default `mailto:postmaster`), `Domain.certificateManagement = Manual`, `MtaSts.mode = "testing"`, `DnsResolver` = Custom → `10.250.251.53` udp aj tcp (tcp potrebné pre DNSSEC/DANE check). [CITED: /docs/ref/object/domain, /docs/domains/mta-sts, /docs/server/dns/resolver]

### Pattern 2: compose.mail.yaml (kostra)

```yaml
name: mail
services:
  stalwart:
    image: stalwartlabs/stalwart:v1.0.1
    init: true
    restart: unless-stopped
    mem_limit: 1g                      # odvodené defaulty (RocksDB cache 1/8, budget 1/4) sa riadia cgroup limitom
    ulimits: { nofile: { soft: 65536, hard: 65536 } }
    ports:                             # explicitne 0.0.0.0 = bez [::] docker-proxy (viď Pitfall 2)
      - "0.0.0.0:25:25"
      - "0.0.0.0:465:465"
      - "0.0.0.0:587:587"
      - "0.0.0.0:993:993"
      - "127.0.0.1:8080:8080"          # wizard/CLI/WebUI iba cez SSH tunel
    volumes:
      - stalwart-etc:/etc/stalwart     # config.json, MUSÍ byť perzistentný (inak znova wizard)
      - stalwart-data:/var/lib/stalwart
      - ./tls:/opt/stalwart-tls:ro     # kópia certu, vlastník 2000:2000, privkey 0400
    networks:
      mail: { aliases: ["mail.vostack.dev"] }   # Roundcube a neskôr app overia cert proti tomuto menu
    depends_on: [unbound]
  unbound:
    build: ./docker/mail/unbound
    restart: unless-stopped
    mem_limit: 64m
    networks: { mail: { ipv4_address: 10.250.251.53 } }
  roundcube:
    image: roundcube/roundcubemail:1.7.5-apache-nonroot
    restart: unless-stopped
    mem_limit: 256m
    environment:
      ROUNDCUBEMAIL_DEFAULT_HOST: ssl://mail.vostack.dev
      ROUNDCUBEMAIL_DEFAULT_PORT: "993"
      ROUNDCUBEMAIL_SMTP_SERVER: ssl://mail.vostack.dev
      ROUNDCUBEMAIL_SMTP_PORT: "465"
      ROUNDCUBEMAIL_DB_TYPE: sqlite
    volumes:
      - roundcube-db:/var/roundcube/db
      - ./docker/mail/roundcube:/var/roundcube/config:ro
    networks: [mail]
  web:
    image: nginx:1.30-alpine
    restart: unless-stopped
    mem_limit: 64m
    volumes:
      - ./docker/mail/nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ./letsencrypt:/etc/letsencrypt:ro
    networks:
      mail: {}
      edge: { aliases: ["mail-web"] }
  certbot:
    image: certbot/dns-cloudflare:v5.8.0
    profiles: ["certbot"]
    volumes:
      - ./letsencrypt:/etc/letsencrypt
      - ./secrets/cloudflare.ini:/secrets/cloudflare.ini:ro
networks:
  mail: { external: true, name: mail }   # docker network create --driver bridge --subnet 10.250.251.0/24 mail
  edge: { external: true, name: edge }
volumes: { stalwart-etc: {}, stalwart-data: {}, roundcube-db: {} }
```

Sieť `mail` ako externá s pevnou podsieťou (ako `edge`): fáza 7 do nej pripojí php kontajnery prod/staging a aplikácia pošle cez `ssl://mail.vostack.dev:465` s overeným certom bez hairpin NAT. Podsieť `10.250.251.0/24` pred vytvorením overiť `docker network ls` + `ip route` (rovnako ako edge).

### Pattern 3: edge a `web` nginx

`docker/edge/nginx.conf` (map, bez iných zmien, `proxy_protocol on` je už globálne v server bloku):
```
webmail.vostack.dev      mail-web:443;
autoconfig.vostack.dev   mail-web:443;
autodiscover.vostack.dev mail-web:443;
mta-sts.vostack.dev      mail-web:443;
```
`web` nginx: `listen 443 ssl proxy_protocol; http2 on;`, include realip `set_real_ip_from 10.250.250.0/24; real_ip_header CF-Connecting-IP;` + `cf-peer.conf` (444 mimo CF), rovnako ako stack nginx. Servery:
- `webmail.vostack.dev` → `proxy_pass http://roundcube:8000;`, `client_max_body_size 25m`, `limit_req` na POST login, `proxy_set_header X-Forwarded-For $remote_addr; X-Forwarded-Proto https`.
- `autoconfig.*`, `autodiscover.*`, `mta-sts.*` → iba `location = /mail/config-v1.1.xml`, `^~ /.well-known/autoconfig/`, `^~ /autodiscover/`, `= /.well-known/mta-sts.txt` → `proxy_pass http://stalwart:8080;` (s `proxy_set_header Host $host`), všetko ostatné `return 404`. Tým sa `/admin`, `/jmap`, `/api` nikdy nedostanú von. Autoconfig dokumenty sa generujú zo `SystemSettings`, nie z URL požiadavky. [CITED: stalw.art/docs/server/autoconfig] Že Stalwart obslúži tieto cesty na plain HTTP listeneri pre ľubovoľný Host je [ASSUMED], overiť `curl`.

Roundcube `config.inc.php`: `$config['proxy_whitelist'] = ['10.250.251.0/24']; $config['use_https'] = true; $config['login_rate_limit'] = 3;` (posledné je default) [VERIFIED: roundcubemail release-1.7 config/defaults.inc.php].

### Pattern 4: Certifikát a obnova

- Prvé vydanie: `docker compose -f compose.mail.yaml run --rm certbot certonly --dns-cloudflare --dns-cloudflare-credentials /secrets/cloudflare.ini -d mail.vostack.dev -d webmail.vostack.dev -d autoconfig.vostack.dev -d autodiscover.vostack.dev -d mta-sts.vostack.dev` (token skopírovať z `/opt/work-tracker/secrets/cloudflare.ini`, 0600). DNS-01 nevyžaduje, aby hosty už existovali.
- `scripts/mail-cert-renew.sh` (cron denne): `certbot renew --quiet` → `install -o 2000 -g 2000 -m 0444 .../live/mail.vostack.dev/fullchain.pem tls/` a `-m 0400 privkey.pem` (cez `cat` kvôli symlinkom do `archive/`) → `stalwart-cli create Action/ReloadTlsCertificates` → `docker compose exec -T web nginx -s reload`. [CITED: stalw.art/docs/server/tls/certificates]
- Stalwart zmaže File-certifikát objekt, ktorý expiroval, a potom ho treba vytvoriť znova (výmena súboru nestačí), preto renew monitorovať. [CITED: tamže]

### Anti-Patterns to Avoid
- **Mail porty cez edge/Cloudflare:** CF neproxuje SMTP/IMAP na free pláne a edge by skryl IP odosielateľa (SPF/DNSBL/auto-ban na IP edge).
- **`"25:25"` bez host IP:** Docker vytvorí aj `[::]:25` docker-proxy listener, IPv6 spojenia potom prídu zo zdrojovej IP brány bridge (Pitfall 2).
- **Stalwart Automatic DNS management na zóne vostack.dev:** publikuje `_dmarc p=reject`, CNAME autoconfig/mta-sts na DNS-only mail host a voliteľne CAA s `accounturi` jedného ACME účtu.
- **Trust PROXY protokolu na default listeneroch:** každé spojenie z trusted siete musí niesť PROXY hlavičku, inak sa zahodí [CITED: /docs/server/reverse-proxy/proxy-protocol]. V navrhnutej architektúre Stalwart PROXY vôbec nepotrebuje.
- **`STALWART_RECOVERY_ADMIN` nechaný v `.env`:** funguje aj v normálnom režime ako plný admin prístup.
- **Kopírovanie bežiaceho RocksDB adresára (`cp`/`rsync`):** nekonzistentná kópia [CITED: /docs/management/maintenance/backup].

## Don't Hand-Roll

| Problém | Nerobiť | Použiť | Prečo |
|---------|---------|--------|-------|
| DKIM kľúče a DNS hodnoty | openssl + ručné TXT | Stalwart `dkimManagement Automatic` + `stalwart-cli get Domain <id> --fields dnsZoneFile` | generuje Ed25519 + RSA-2048 a presné záznamy (aj `_mta-sts` id) |
| Autoconfig/autodiscover XML | statické súbory v nginx | Stalwart endpointy | generované zo SystemSettings, aktuálne pri zmene portov |
| Antispam | rspamd sidecar | vstavaný spam filter (pravidlá z GitHubu pri prvom štarte, DNSBL, Bayes) | súčasť Stalwartu |
| Brute-force webmailu | vlastný fail2ban | Roundcube `login_rate_limit` + nginx `limit_req` + Stalwart auto-ban (pre priame IMAP/SMTP) | — |
| TLS reload | reštart kontajnera | `Action/ReloadTlsCertificates` | bez prerušenia spojení |

## Common Pitfalls

### Pitfall 1: Auto-ban zablokuje Roundcube (a neskôr aplikáciu)
**Čo sa stane:** všetky webmail prihlásenia idú do Stalwartu z jednej IP kontajnera; 100 zlých prihlásení/deň (default `authBanRate`) alebo scan cesta na 8080 → **trvalý** ban (bez `authBanPeriod` sú bany trvalé), webmail aj `web` → 502. [CITED: stalw.art/docs/server/auto-ban]
**Prevencia:** `AllowedIp` pre `10.250.251.0/24` (aj vynímka z HTTP rate limitov); brute-force rieši Roundcube + nginx. Diagnostika: `stalwart-cli query BlockedIp`, zmazať a `create Action/ReloadBlockedIps`.

### Pitfall 2: IPv6 a docker-proxy menia zdrojovú IP
**Čo sa stane:** server má IPv6, siete sú IPv4-only, a Docker pri publikovaní bez host IP mapuje host IPv6 porty na IPv4 kontajnera cez userland proxy [CITED: docs.docker.com/engine/network/port-publishing]. Overené na serveri: `docker-proxy -host-ip ::` beží pre 80/443 edge. Pre SMTP by IPv6 odosielatelia vyzerali ako IP brány (SPF fail, ban brány = ban všetkých IPv6).
**Prevencia:** porty `0.0.0.0:X:X`, **bez AAAA pre mail.vostack.dev**, bez IPv6 PTR. Po štarte `ss -ltn | grep -E ':(25|465|587|993) '` nesmie ukázať `[::]`. Že explicitné `0.0.0.0` neotvorí `[::]` je [ASSUMED], tento príkaz to overí. IPv4 DNAT zdrojovú IP zachováva [ASSUMED], overiť v hlavičke `Received` prvej prijatej správy (musí byť IP odosielateľa, nie 10.250.251.1).

### Pitfall 3: DNSBL cez Hetzner resolvery
**Čo sa stane:** `dig 2.0.0.127.zen.spamhaus.org` na serveri vracia `127.255.255.254` (overené dnes) → pravidlá končia `RBL_SPAMHAUS_BLOCKED`, DNSBL nefunguje. [CITED: stalw.art/docs/spamfilter/dnsbl]
**Prevencia:** `unbound` sidecar (rekurzia od root serverov, DNSSEC), `DnsResolver` Custom s udp aj tcp. Overiť `docker compose exec unbound drill 2.0.0.127.zen.spamhaus.org` → `127.0.0.2/4/10`.

### Pitfall 4: Certifikát nečitateľný pre Stalwart
Obraz beží ako `stalwart` (uid 2000, `setcap cap_net_bind_service`) [VERIFIED: Dockerfile v repo stalwartlabs/stalwart]. certbot `archive/privkey*.pem` je 0600 root a `live/` sú symlinky → nemountovať `letsencrypt/` priamo, kopírovať do `./tls` s vlastníkom 2000.

### Pitfall 5: Wizard predvyplní zlé meno / zabudnutý restart / nepersistentný `/etc/stalwart`
Hostname = container id (prepísať na `mail.vostack.dev`), wizard sám nereštartuje, bez volume na `/etc/stalwart` sa pri recreate spustí wizard znova (dáta ostanú, ale wizard ich odmietne). Obnova: ručne zapísať `{"@type":"RocksDb","path":"/var/lib/stalwart/"}`. [CITED: bootstrap-mode]

### Pitfall 6: Null MX a staré záznamy
`MX 0 .` musí byť **zmazaný** (null MX musí byť jediný MX), `v=spf1 -all` nahradený (dva `v=spf1` = permerror), `_dmarc p=reject` zmenený na `p=none` ešte pred prvým odoslaním. MX prepnúť až keď Stalwart beží a cert je platný.

### Pitfall 7: Cloudflare pred autodiscover/mta-sts
Outlook posiela `POST /autodiscover/autodiscover.xml`; CF Browser Integrity Check / Security Level môže neprehliadačové klienty challengovať, a CF môže cachovať `.txt` (mta-sts policy). Bot Fight Mode je v zóne vypnutý (server.md §2). Prevencia: overiť `curl -X POST` a `curl https://mta-sts.vostack.dev/.well-known/mta-sts.txt`, v nginx `Cache-Control: no-store` pre policy; pri problémoch CF Configuration Rule (BIC off) pre tieto hosty. [ASSUMED]

### Pitfall 8: Apple Mail / iOS
iOS nečíta autoconfig ani autodiscover a Stalwart negeneruje `.mobileconfig` → nastaviť ručne: IMAP `mail.vostack.dev` 993 SSL, SMTP `mail.vostack.dev` 465 SSL, login = celá adresa. [CITED: stalw.art/docs/server/clients] Outlook `autodiscover.json` vráti URL na `https://mail.vostack.dev/...`, ktorá je za FW nedostupná → Outlook fallback na XML alebo ručne IMAP. [CITED: /docs/server/autoconfig]

### Pitfall 9: Pamäť
Pri defaultoch na 2 GiB hoste je súčet limitov ~2.4 GiB, ale veľké cache (2×512 MiB) sa plnia iba pre účty s miliónmi správ; pri `mem_limit: 1g` sa RocksDB block cache (1/8) a memory budget (1/4) odvodia z cgroup limitu. [CITED: stalw.art/docs/performance/memory] Reálne rezidentná pamäť pri 3 schránkach je odhadom 100-250 MB [ASSUMED], merať `docker stats`. Spolu mail stack ~1.4 GB limitov, server má 3.0 GB available.

### Pitfall 10: Deliverability z Hetzner IP
- PTR `2.31.74.48 → mail.vostack.dev` a A `mail → 2.31.74.48` (FCrDNS), HELO = `mail.vostack.dev`.
- Microsoft (outlook.com/hotmail) často blokuje Hetzner rozsahy (S3150). Hetzner má postup: consumer OLC cez delist formulár Microsoftu, abuse kontakt IP je abuse@hetzner.com. [CITED: docs.hetzner.com/robot/dedicated-server/troubleshooting/microsoft-blacklist] Pred ostrou prevádzkou poslať test na outlook.com adresu, pri 5.7.1 S3150 požiadať o delist (olcsupport.office.com pre consumer, sender.office.com pre M365). [CITED: learn.microsoft.com Q&A, MEDIUM]
- Registrovať Microsoft SNDS/JMRP a Google Postmaster Tools pre vostack.dev; Spamhaus check cez web (check.spamhaus.org) alebo cez unbound, nie cez 1.1.1.1 (vráti 127.255.255.254, overené). UCEPROTECT L3 pre Hetzner ignorovať.
- Nízky objem (faktúry) = prirodzený warm-up; prvé dni skontrolovať mail-tester.com skóre a v Gmail „Show original“ SPF/DKIM/DMARC = PASS. Gmail/Yahoo požiadavky (SPF+DKIM, FCrDNS, TLS, DMARC pre bulk) [ASSUMED z tréningu, všeobecne známe].
- Hetzner Cloud blokuje 25/465 pre nové účty do schválenia; orchestrátor overil, že sú otvorené. [CITED: komunitné zdroje, MEDIUM]

## DNS záznamy pre vostack.dev (Cloudflare)

| Typ | Meno | Hodnota | CF proxy | Poznámka |
|-----|------|---------|----------|----------|
| A | mail | 2.31.74.48 | **DNS only** | žiadny AAAA (Pitfall 2) |
| MX | @ | 10 mail.vostack.dev | — | zmazať `0 .` |
| TXT | @ | `v=spf1 mx -all` | — | nahradiť `v=spf1 -all` |
| TXT | mail | `v=spf1 a -all` | — | bounce/reporty z HELO hosta [CITED: /docs/install/dns] |
| TXT | `v1-ed25519-YYYYMMDD._domainkey`, `v1-rsa-YYYYMMDD._domainkey` | z `dnsZoneFile` | — | presné selektory až po wizarde |
| TXT | _dmarc | `v=DMARC1; p=none; rua=mailto:dmarc@vostack.dev` | — | nahradiť `p=reject` |
| A | webmail, autoconfig, autodiscover, mta-sts | 2.31.74.48 | **Proxied** | Stalwart navrhuje CNAME na mail, u nás proxied A kvôli FW |
| TXT | _mta-sts | `v=STSv1; id=<z dnsZoneFile>` | — | po každej zmene policy |
| TXT | _smtp._tls | `v=TLSRPTv1; rua=mailto:postmaster@vostack.dev` | — | voliteľné, lacné |
| SRV | _imaps._tcp | `0 1 993 mail.vostack.dev` | — | |
| SRV | _submissions._tcp | `0 1 465 mail.vostack.dev` | — | |
| SRV | _submission._tcp | `0 1 587 mail.vostack.dev` | — | iba ak sa nastaví `services.smtp.cleartext=true` |
| PTR | 2.31.74.48 | mail.vostack.dev | Hetzner konzola | |

Nepublikovať `_jmap/_caldavs/_carddavs/_pop3s` SRV ani `ua-auto-config` (443 na mail hoste je nedostupný, PACC je draft s digestom na ručnú údržbu). CAA dnes neexistuje, nechať tak. DMARC rollout: `p=none` 2-4 týždne čítať rua reporty → `p=quarantine` → `p=reject` (stav pred zmenou). [CITED: /docs/install/dns] MTA-STS: `testing` → po 2-4 týždňoch čistých TLS-RPT `enforce` (max_age 7 d default).

## Zálohy

RocksDB nemá online backup: buď snapshot FS, alebo stop + kópia adresára; s Default blob store je všetko v jednej DB, poradie data/blob nehrá rolu. Export (`stalwart --export`) obsahuje heslá a DKIM kľúče a nezahŕňa `config.json`. [CITED: stalw.art/docs/management/maintenance/backup]

Odporúčanie `scripts/mail-backup.sh` (cron 03:30, ako root/deploy): `docker compose stop stalwart` → `docker run --rm -v mail_stalwart-data:/d:ro -v mail_stalwart-etc:/e:ro -v /var/backups/mail:/b alpine:3.24 tar czf /b/stalwart-$(date +%F).tgz -C / d e` → `docker compose start stalwart`, plus SQLite Roundcube (`sqlite3 .backup` alebo tar pri stop), `/opt/mail/.env`, `tls/`; súbory 0600, rotácia 14 dní; off-site cez projektový restic plán neskôr. Výpadok rádovo sekúnd; odosielajúce MTA opakujú, aplikácia (fáza 7) musí chybu SMTP zniesť (retry). Test obnovy: rozbaliť do nových volumes a spustiť dočasný kontajner bez publikovaných portov, prihlásiť sa CLI a `query Account`.

## Environment Availability

| Závislosť | Potrebné pre | Dostupné | Verzia | Fallback |
|-----------|--------------|----------|--------|----------|
| Docker Engine + compose | celý stack | ✓ (overené) | 29.9.2 | — |
| RAM | 4 kontajnery | ✓ 3.0 GB available | 3.8 GB total, bez swapu | znížiť mem_limit Roundcube |
| Disk | maily | ✓ 28 GB voľných | — | — |
| IPv6 na hoste | — | áno, `2a01:4f8:c012:70e5::1` | — | mail iba IPv4 |
| Odchádzajúce 25 | doručovanie | ✓ (orchestrátor) | — | — |
| Prichádzajúce 25/465/587/993 | príjem, klienti | ✗ (Hetzner FW) | — | vlastník otvorí |
| PTR | deliverability | ✗ (default) | — | vlastník nastaví |
| Rekurzívny resolver nepoužívajúci verejné/ISP | DNSBL | ✗ (Hetzner refused, overené) | — | unbound sidecar |
| Odchádzajúce HTTPS na GitHub | WebUI bundle, spam pravidlá, ASN zoznamy | predpoklad ✓ | — | lokálny súbor pravidiel |
| CF token Zone:DNS:Edit | certbot DNS-01 | ✓ | — | — |
| docker bez sudo pre `aiagent` | — | ✗ (`sudo -n docker` funguje) | — | inštalovať ako `deploy` alebo cez sudo |

## Validation Architecture

Ops úloha, PHPUnit sa netýka. Overenie skriptami a príkazmi (každý < 30 s):

| Kontrola | Príkaz |
|----------|--------|
| compose a nginx syntax (lokálne) | `docker compose -f compose.mail.yaml config -q`; `docker run --rm -v $PWD/docker/mail/nginx/nginx.conf:/etc/nginx/nginx.conf:ro nginx:1.30-alpine nginx -t` (vyžaduje dummy cert, ako `edge-selftest.sh`) |
| edge map | `docker compose -f compose.edge.yaml exec edge nginx -t` |
| porty iba IPv4 | `ss -ltn \| grep -E ':(25\|465\|587\|993) '` |
| TLS cert na SMTP/IMAP | `openssl s_client -connect mail.vostack.dev:993 -servername mail.vostack.dev </dev/null \| openssl x509 -noout -subject -enddate`; `openssl s_client -starttls smtp -connect mail.vostack.dev:25` |
| autoconfig/mta-sts cez CF | `curl -fsS 'https://autoconfig.vostack.dev/mail/config-v1.1.xml?emailaddress=info@vostack.dev'`; `curl -fsS https://mta-sts.vostack.dev/.well-known/mta-sts.txt`; `curl -fsS -X POST https://autodiscover.vostack.dev/autodiscover/autodiscover.xml -d @req.xml` |
| admin nie je verejný | `curl -s -o /dev/null -w '%{http_code}' https://autoconfig.vostack.dev/admin` → 404 |
| DNS | `dig +short @1.1.1.1 MX vostack.dev`, `TXT _dmarc.vostack.dev`, `TXT <selector>._domainkey.vostack.dev`, `dig +short -x 2.31.74.48` |
| DNSBL | `docker compose exec unbound drill 2.0.0.127.zen.spamhaus.org` |
| E2E | odoslať z info@ na Gmail + outlook.com, „Show original“ = SPF/DKIM/DMARC PASS; mail-tester.com ≥ 9/10; príjem z Gmailu do info@ a na alias dmarc@ |
| ban whitelist | `stalwart-cli query AllowedIp`, `query BlockedIp` prázdne |

Wave 0: `docker/mail/*`, `compose.mail.yaml`, skripty a runbook neexistujú (všetko nové). Voliteľne `scripts/mail-selftest.sh` podľa vzoru `edge-selftest.sh`.

## Security Domain

| ASVS | Platí | Kontrola |
|------|-------|----------|
| V2 Authentication | áno | Stalwart heslá (hash default), silné heslá schránok, app passwords ak 2FA; Roundcube `login_rate_limit`; nginx `limit_req` |
| V3 Session | áno (webmail) | Roundcube session cookies secure (`use_https`), za CF/HTTPS |
| V4 Access Control | áno | admin iba 127.0.0.1:8080 a sieť `mail`, `web` púšťa len whitelist ciest; `allowRelaying=false` (default) = žiadny open relay |
| V5 Input | čiastočne | Roundcube 1.7.5 (aktuálne CVE opravy), pin + sledovať vydania |
| V6 Crypto | áno | LE certy, TLS 1.2+, DKIM Ed25519+RSA2048, tajomstvá 0600 mimo gitu |
| V9 Comms | áno | implicitný TLS 465/993, STARTTLS 25/587, MTA-STS testing→enforce |

| Hrozba | STRIDE | Mitigácia |
|--------|--------|-----------|
| Open relay | Elevation | default bez relayingu, test `swaks --to x@gmail.com --server mail.vostack.dev` bez auth musí skončiť 5xx |
| Brute force IMAP/SMTP | Spoofing | Stalwart auto-ban (zvážiť `authBanPeriod` napr. 1 deň namiesto trvalého) |
| Únik admin UI | Elevation | nepublikovať 443, 8080 iba loopback, `STALWART_RECOVERY_ADMIN` odstrániť |
| PROXY/XFF spoof | Spoofing | `cf-peer.conf` 444 mimo CF v `web`; Stalwart PROXY ani XFF nepoužíva |
| Zálohy s DKIM kľúčmi/hashmi | Info disclosure | 0600, šifrovaný off-site (restic) |
| CF token v druhom adresári | Info disclosure | kópia 0600, token je obmedzený na zónu |

## Assumptions Log

| # | Tvrdenie | Sekcia | Riziko ak neplatí |
|---|----------|--------|-------------------|
| A1 | `"0.0.0.0:25:25"` v Docker 29 neotvorí `[::]:25` docker-proxy | Pitfall 2 | IPv6 SMTP so zlou IP; overí `ss -ltn` |
| A2 | IPv4 DNAT zachová zdrojovú IP pre publikované porty | Pitfall 2 | SPF/DNSBL na IP brány; overiť `Received` |
| A3 | Stalwart obslúži autoconfig/autodiscover/mta-sts na plain HTTP 8080 pre ľubovoľný Host | Pattern 3 | inak `web` → stalwart:443 s TLS (`proxy_ssl_verify off`, `proxy_ssl_name`) |
| A4 | Prihlásenie do WebUI cez `http://127.0.0.1:8080/admin` v normálnom (nie bootstrap) režime funguje; docs hovoria, že sign-in je len pre HTTPS origin | Admin | fallback: CLI (overené funguje na http://localhost:8080 s basic auth) alebo recovery mód |
| A5 | Presné polia `AllowedIp.address`, `DnsResolver` Custom, matchOn pre Certificate | Pattern 1 | chyba `invalidPatch`; overiť `stalwart-cli describe` |
| A6 | Rezidentná pamäť Stalwartu 100-250 MB pri 3 schránkach | Pitfall 9 | znížiť cache / mem_limit |
| A7 | CF neblokuje Outlook POST autodiscover a nekešuje mta-sts.txt problémovo | Pitfall 7 | CF Configuration Rule |
| A8 | Stalwart pri nedostupnom IPv6 v kontajneri rýchlo prejde na IPv4 MX | Deliverability | oneskorené doručenie na IPv6-preferujúce MX; overiť test na Gmail |
| A9 | Gmail/Yahoo požiadavky na odosielateľov | Pitfall 10 | nízke, best practice |

## Open Questions (RESOLVED)

1. **Stalwart 1.0.1 je dnes vydaný.** Ostať na `v1.0.1`, alebo počkať pár dní na 1.0.2? Odporúčanie: v1.0.1 pin, po týždni skontrolovať release notes (1.0.x patch = iba `docker pull` nového tagu).
   - **RESOLVED:** pin `stalwartlabs/stalwart:v1.0.1` (PLAN Task 1, compose.mail.yaml); patch upgrady podľa docs/ops/mail.md sekcia 7.
2. **Admin WebUI**: stačí CLI + SSH tunel (A4), alebo chce vlastník WebUI z prehliadača bez tunela? Ak áno, ďalší CF-proxied host (napr. `mail-admin.vostack.dev`) s Cloudflare Access by bol potrebný; neodporúčam teraz.
   - **RESOLVED:** admin nie je verejný; 8080 len na 127.0.0.1, prístup iba SSH tunelom a `stalwart-cli`, mail-web vracia 404 pre /admin /api /jmap (PLAN Task 1, selftest kontrola 10, T-w8p-02).
3. **Admin účet `admin@vostack.dev`** od wizardu je zároveň schránka. Ponechať (iba admin rola) a nepublikovať adresu; alebo po vytvorení info@ s Admin rolou ho zmazať. Odporúčanie: ponechať, silné heslo v správcovi hesiel.
   - **RESOLVED (odchýlka od odporúčania):** samostatný principal `stalwart-admin` s rolou Admin, nie schránka (PLAN Task 2, plan.ndjson). Dôvod: rozhodnutie orchestrátora/vlastníka 2026-10-28 oddeliť správu od pošty, aby admin prihlásenie nebolo vystavené IMAP/SMTP brute-force cez verejné porty a heslo admina sa nepoužívalo v poštových klientoch; bootstrap ide neinteraktívne cez dočasný `STALWART_RECOVERY_ADMIN`, nie cez wizard, takže `admin@vostack.dev` nevzniká.
4. **IPv6 pre poštu neskôr:** vyžaduje IPv6 na sieti `mail` (`enable_ipv6`, ip6tables), AAAA + IPv6 PTR v Hetzner konzole. Mimo rozsahu.
   - **RESOLVED:** mimo rozsahu; žiadny AAAA pre `mail`, porty 25/465/587/993 publikované len na `0.0.0.0` (PLAN Task 1 verify, runbook kroky 9 a 13).
5. **Zálohy off-site:** zatiaľ lokálne `/var/backups/mail` (CONTEXT), restic na Storage Box podľa projektového plánu neskôr.
   - **RESOLVED:** lokálne nočné stop/tar/start do `/var/backups/mail` (0600, rotácia 14) cez scripts/mail-backup.sh (PLAN Task 2); off-site cez restic neskôr podľa projektového plánu záloh.

## Sources

### Primary (HIGH)
- GitHub releases API stalwartlabs/stalwart (v1.0.1 2026-10-28, v1.0.0 2026-10-26, v0.16.27 2026-10-18) a release notes 1.0.0/1.0.1; Dockerfile (uid 2000, setcap, healthcheck)
- Docker Hub API: stalwartlabs/stalwart, stalwartlabs/cli (1.0.14), roundcube/roundcubemail (1.7.5-*), djmaze/snappymail, alpine, klutchell/unbound
- stalw.art docs (Last updated 2026-10-26): install/platform/docker, configuration/bootstrap-mode, declarative-deployments, environment-variables, object-encoding, install/store, install/setup-wizard, install/requirements, install/dns, server/listener, server/reverse-proxy (+proxy-protocol), server/auto-ban, server/autoconfig, server/clients, server/dns/resolver, server/dns/provider (+provider-notes), server/tls/acme/challenges, server/tls/certificates, domains/tls-certificates, domains/dkim-rotation, domains/mta-sts, spamfilter/dnsbl, performance/memory, management/cli, management/maintenance/backup, management/applications, ref/object/{domain,certificate,http,system-settings}, auth/accounts/user, domains/addresses
- roundcube/roundcubemail-docker README a examples/docker-compose-nonroot.yaml; roundcubemail release-1.7 `config/defaults.inc.php`; Roundcube 1.7.5 release notes
- Živé overenie servera (read-only, 2026-10-28): IPv6 adresa, Docker 29.9.2, daemon.json, siete, docker-proxy, `free -m`, resolver, Spamhaus odpoveď
- Živé DNS: `dig @1.1.1.1` vostack.dev (MX `0 .`, `v=spf1 -all`, `p=reject`, bez CAA), PTR default

### Secondary (MEDIUM)
- docs.docker.com/engine/network/port-publishing (IPv6→IPv4 mapovanie cez userland proxy)
- docs.hetzner.com/robot/dedicated-server/troubleshooting/microsoft-blacklist
- learn.microsoft.com Q&A k S3150 delistingu (čiastočne AI-generované odpovede)

### Tertiary (LOW)
- Komunitné zdroje k Hetzner Cloud port 25/465 politike (stav overený orchestrátorom)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, verzie a obrazy overené živo, Stalwart 1.0 zrelosť MEDIUM
- Architecture: MEDIUM-HIGH, vzor edge/realip je v projekte overený, Stalwart časti z oficiálnych docs, A3/A4 treba overiť pri inštalácii
- Pitfalls: HIGH pre DNSBL, IPv6/docker-proxy, uid certifikátu, auto-ban (overené na serveri alebo v docs); MEDIUM pre Microsoft/CF správanie

**Research date:** 2026-10-28
**Valid until:** 2026-11-11 (Stalwart 1.0.x sa mení rýchlo)
