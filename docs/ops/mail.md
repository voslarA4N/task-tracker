# Mail server: Stalwart + Roundcube pre `vostack.dev`

Vlastný mailový server vedľa stackov task-trackera (quick 261028-w8p). Poskytuje schránky `info@` a `noreply@` (aliasy `postmaster@`, `abuse@`, `dmarc@` -> `info@`), IMAP/SMTP pre klientov, webmail a autoconfig. Fáza 7 aplikácie bude cez neho posielať faktúry. Všetko je v repozitári, inštaluje sa **ručne raz** do `/opt/mail` (rovnako ako edge), nie cez CI. Tajomstvá sa generujú na serveri a nikdy nejdú do gitu.

## 1. Prehľad

```
Internet MTA / IMAP a SMTP klienti                 Prehliadač, Thunderbird, Outlook, MTA-STS fetch
        |                                                         |
 DNS only: mail.vostack.dev A 2.31.74.48                CF proxied: webmail / autoconfig / autodiscover / mta-sts
        |                                                         |
 Hetzner FW: 25, 465, 587, 993 (0.0.0.0/0)               Cloudflare -> Hetzner FW 443 iba z CF
        |                                                         |
 host 0.0.0.0:{25,465,587,993}                           edge nginx (stream, SNI, PROXY protocol)
        |                                                         |  map SNI -> mail-web:443  (sieť edge)
        v                                                         v
 +------------------------ sieť mail 10.250.251.0/24 ---------------------------------------------+
 |  stalwart (alias mail.vostack.dev) <-- IMAPS 993 / SMTPS 465 -- roundcube :8000 <-- web (nginx) |
 |     |  http 8080 <-- iba /mail/config-v1.1.xml, /autodiscover/*, /.well-known/mta-sts.txt -- web |
 |     +--> DNS dotazy (SPF, DKIM, DMARC, DNSBL) --> unbound 10.250.251.53 --> root servery          |
 +------------------------------------------------------------------------------------------------+
 Správa: SSH tunel na 127.0.0.1:8080 alebo stalwart-cli (scripts/mail-provision.sh --cli ...)
```

| Komponent | Verzia (pripnutá) | Poznámka |
|---|---|---|
| Stalwart | `stalwartlabs/stalwart:v1.0.1` | RocksDB v `/var/lib/stalwart`, beží ako uid 2000 |
| stalwart-cli | `stalwartlabs/cli:1.0.14` | spúšťa sa v kontajneri (`scripts/mail-provision.sh`) |
| Roundcube | `roundcube/roundcubemail:1.7.5-apache-nonroot` | SQLite vo volume `roundcube-db` |
| nginx `web` | `nginx:1.30-alpine` | TLS pre webmail/autoconfig/autodiscover/mta-sts, v sieti `edge` ako `mail-web` |
| unbound | vlastný obraz z `alpine:3.24` | rekurzívny validujúci resolver (DNSBL) |
| certbot | `certbot/dns-cloudflare:v5.8.0` | profil `certbot`, DNS-01, jeden certifikát pre všetky mená |

**Porty.** 25/465/587/993 sú publikované priamo z Stalwartu **iba na IPv4** (`0.0.0.0:`), aby sa zachovala skutočná IP klienta (SPF, DNSBL, auto-ban). Bez `AAAA` záznamu a bez IPv6 PTR. Port 8080 (HTTP správa) je iba na `127.0.0.1`. Port 443 Stalwartu sa nepublikuje nikdy. `web` púšťa zvonka iba whitelist ciest, `/admin`, `/api` a `/jmap` vracajú 404.

**Správa.** Žiadne verejné admin rozhranie. Správa ide cez `stalwart-cli` (`sh scripts/mail-provision.sh --cli ...`) alebo SSH tunel. Admin je samostatný principal `stalwart-admin@vostack.dev` s rolou Admin (Stalwart 1.x vyžaduje doménu pri každom používateľovi, preto má adresu, ale nie je to publikovaná schránka a nepoužíva sa na poštu). Prihlasovacie meno je **celá adresa** `stalwart-admin@vostack.dev`.

**Pamäť.** Limity kontajnerov: Stalwart 1 GB, Roundcube 256 MB, nginx 64 MB, unbound 64 MB (spolu ~1.4 GB, server má ~3.0 GB voľných). Namerané lokálne po selftest-e v pokoji: Stalwart ~200 MiB, Roundcube ~24 MiB, nginx ~3 MiB, unbound ~8 MiB (spolu ~235 MiB). Na serveri po nasadení zmerať `docker stats --no-stream`.

**Prečo Roundcube** (nie SnappyMail): vydáva bezpečnostné opravy v ten istý deň (1.7.5 z 2026-10-19), má vstavaný `login_rate_limit`, SnappyMail nemá riadne vydanie od 2024-10. **Prečo certbot** (nie Stalwart ACME): DNS-01 v Stalwarte vyžaduje automatickú správu zóny (zapisuje SPF, DMARC `p=reject`, CNAME), čo by kolidovalo s Cloudflare-proxied hosťami a s certbotom task-trackera. **Prečo vlastný unbound:** Hetzner resolvery dostávajú od Spamhaus odpoveď `127.255.255.254` (refused), DNSBL by bez vlastnej rekurzie nefungoval.

## 2. Súbory v repozitári a rozloženie `/opt/mail`

| Repozitár | Server |
|---|---|
| `compose.mail.yaml` | `/opt/mail/compose.mail.yaml` |
| `docker/mail/**` (unbound, roundcube, nginx, stalwart) | `/opt/mail/docker/mail/**` |
| `docker/nginx/realip/edge.conf`, `docker/nginx/http.d/cf-peer.conf` | `/opt/mail/docker/nginx/...` (rovnaký zdroj pravdy ako stack nginx) |
| `scripts/mail-{provision,backup,cert-renew}.sh` | `/opt/mail/scripts/` |
| | `/opt/mail/.env` (0600 deploy): `ROUNDCUBE_DES_KEY` |
| | `/opt/mail/secrets/` (0700 deploy): `admin_password`, `info_password`, `noreply_password`, `cloudflare.ini` (všetko 0600) |
| | `/opt/mail/letsencrypt/` (certbot), `/opt/mail/tls/` (kópie pre uid 2000: `fullchain.pem` 0444, `privkey.pem` 0400) |
| | `/var/backups/mail` (0700 deploy) |

Kopírovanie z lokálneho repozitára na reviewnutom commite:

```
git archive HEAD compose.mail.yaml docker/mail docker/nginx/realip/edge.conf docker/nginx/http.d/cf-peer.conf \
  scripts/mail-provision.sh scripts/mail-backup.sh scripts/mail-cert-renew.sh | ssh deploy@2.31.74.48 'tar -x -C /opt/mail'
```

## 3. Tajomstvá

- `secrets/{admin,info,noreply}_password`: generuje sa na serveri (`openssl rand -base64 24 | tr -d '\n'`), 0600, nikdy v gite. Skript `mail-provision.sh` ich číta zo `SECRETS_DIR` a povolí iba znaky `A-Za-z0-9+/=_.@%^-` (heslo ide do JSON plánu).
- `.env`: iba `ROUNDCUBE_DES_KEY` (kľúč šifrovania session Roundcube). **Nikdy** do `.env` nedávať `STALWART_RECOVERY_ADMIN`: funguje aj v bežnom režime ako plný admin prístup. Používa sa iba počas bootstrapu z príkazového riadka a potom sa kontajner obnoví bez neho.
- `secrets/cloudflare.ini`: kópia tokenu Zone:DNS:Edit z `/opt/work-tracker/secrets/cloudflare.ini` (0600).
- Vlastník heslá schránok číta cez SSH (`sudo cat /opt/mail/secrets/info_password`) a ukladá do správcu hesiel. Do chatu a do gitu nejdú.
- Zálohy (sekcia 8) obsahujú heslá a DKIM kľúče: 0600, mimo servera iba šifrovane (restic).

## 4. Jednorazová inštalácia (vykoná orchestrátor po potvrdení vlastníka)

`deploy` = aplikačný používateľ, `aiagent` = sudo cez `ssh my-server-agent`. Mená DKIM selektorov a `_mta-sts` id sa dozvieme až v kroku 8.

0. **Predpoklady (vlastník):** PTR `2.31.74.48 -> mail.vostack.dev` nastavený v Hetzner konzole (`dig +short -x 2.31.74.48`), Hetzner Cloud Firewall povoľuje prichádzajúce 25/465/587/993 z 0.0.0.0/0, vetva `feature/mail-server` reviewnutá.
1. **Kontroly len na čítanie (aiagent):** `docker network ls`, `ip route | grep 10.250.251` (podsieť voľná), `sudo ss -ltnp | grep -E ':(25|465|587|993|8080) '` (nič nepočúva, žiadny host MTA), `free -m`, `df -h /`.
2. **Adresáre (aiagent, root):**
   ```
   install -d -o deploy -g deploy -m 0750 /opt/mail
   install -d -o deploy -g deploy -m 0700 /opt/mail/secrets /var/backups/mail
   install -d -o deploy -g deploy -m 0755 /opt/mail/tls /opt/mail/letsencrypt
   ```
3. **Súbory (deploy):** príkaz `git archive ... | ssh deploy@2.31.74.48 'tar -x -C /opt/mail'` zo sekcie 2.
4. **Sieť (deploy):** `docker network create --driver bridge --subnet 10.250.251.0/24 mail`
5. **Tajomstvá (deploy, nikdy nevypisovať):**
   ```
   cd /opt/mail
   for n in admin info noreply; do openssl rand -base64 24 | tr -d '\n' > secrets/${n}_password; done
   printf 'ROUNDCUBE_DES_KEY=%s\n' "$(openssl rand -hex 12)" > .env
   sudo install -o deploy -g deploy -m 0600 /opt/work-tracker/secrets/cloudflare.ini secrets/cloudflare.ini
   chmod 0600 secrets/* .env
   ```
6. **Certifikát (deploy, `/opt/mail`):**
   ```
   docker compose -f compose.mail.yaml run --rm certbot certonly --dns-cloudflare \
     --dns-cloudflare-credentials /secrets/cloudflare.ini \
     -d mail.vostack.dev -d webmail.vostack.dev -d autoconfig.vostack.dev -d autodiscover.vostack.dev -d mta-sts.vostack.dev \
     --agree-tos -m info@vostack.dev --non-interactive
   sh scripts/mail-cert-renew.sh --copy-only
   ```
   (DNS-01 nepotrebuje existujúce záznamy; ak `info@` ešte nefunguje, použiť adresu, ktorú vlastník uprednostní.)
7. **Bootstrap a provisioning (deploy, `/opt/mail`):**
   ```
   docker compose -f compose.mail.yaml build unbound
   sh scripts/mail-provision.sh --init
   BOOT=$(openssl rand -hex 12)
   STALWART_RECOVERY_ADMIN=admin:$BOOT docker compose -f compose.mail.yaml up -d unbound stalwart
   STALWART_BOOTSTRAP_USER=admin STALWART_BOOTSTRAP_PASSWORD=$BOOT sh scripts/mail-provision.sh
   unset BOOT
   docker compose -f compose.mail.yaml up -d      # obnoví stalwart BEZ recovery admina, spustí roundcube a web
   sh scripts/mail-provision.sh                   # znova (idempotentne), už ako stalwart-admin
   ```
   `--init` zapíše do volume `stalwart-etc` iba `config.json` (`{"@type":"RocksDb","path":"/var/lib/stalwart/"}`); wizard netreba. Ak by neinteraktívny bootstrap zlyhal, použiť záložný postup z podsekcie "Bootstrap cez wizard" nižšie.
8. **DNS hodnoty (deploy):** `sh scripts/mail-provision.sh --print-dns` vypíše zónu domény. Z nej treba **iba**: DKIM TXT (`v1-ed25519-RRRRMMDD._domainkey`, `v1-rsa-RRRRMMDD._domainkey`) a `_mta-sts` id. Ostatné riadky (CNAME na mail, `_dmarc p=reject`, TLSA, `ua-auto-config`) **nepublikovať**, publikuje sa podľa tabuľky v sekcii 5.
9. **Lokálne kontroly na serveri pred DNS:**
   - `ss -ltn | grep -E ':(25|465|587|993) '` ukáže iba `0.0.0.0` (žiadne `[::]`), plus `127.0.0.1:8080`.
   - `openssl s_client -connect 127.0.0.1:993 -servername mail.vostack.dev </dev/null | openssl x509 -noout -subject -enddate`
   - `openssl s_client -starttls smtp -connect 127.0.0.1:25 -servername mail.vostack.dev </dev/null`
   - `docker compose -f compose.mail.yaml exec unbound drill 2.0.0.127.zen.spamhaus.org @127.0.0.1` vráti `127.0.0.2/4/10`.
   - `sh scripts/mail-provision.sh --cli query AllowedIp` obsahuje `10.250.251.0/24`, `--cli query BlockedIp` je prázdne.
   - `docker stats --no-stream` v rámci limitov.
10. **Edge (deploy), PRED akýmkoľvek záznamom v Cloudflare:**
    - a) `docker network inspect edge --format '{{range .Containers}}{{.Name}} {{end}}'` obsahuje kontajner `web` mail stacku (`docker compose -f /opt/mail/compose.mail.yaml ps web` ukáže presný názov, zvyčajne `mail-web-1`) a `docker inspect mail-web-1 --format '{{json .NetworkSettings.Networks.edge.Aliases}}'` obsahuje `mail-web`. Ak nie, stop.
    - b) Nový edge config: `cp /opt/edge/nginx.conf /opt/edge/nginx.conf.pre-mail`, skopírovať `docker/edge/nginx.conf`, potom `cd /opt/edge && docker compose -f compose.edge.yaml exec edge nginx -t && docker compose -f compose.edge.yaml exec edge nginx -s reload`.
    - c) Smerovanie cez skutočný edge. Edge publikuje `0.0.0.0:443` (a `[::]:443`), takže na serveri funguje adresa `127.0.0.1`: `curl -sk --max-time 10 --resolve webmail.vostack.dev:443:127.0.0.1 https://webmail.vostack.dev/` má skončiť **bez odpovede** (444: PROXY zdroj nie je Cloudflare, rovnako ako Run C v `edge-selftest.sh`). Dôkaz, že spojenie došlo na `mail-web` a nie na aplikáciu: `docker compose -f /opt/mail/compose.mail.yaml logs --since 2m web | grep 'host=webmail.vostack.dev'` ukáže riadok so stavom `444`.
    - d) Obsahová kontrola klientom, ktorý vie PROXY protokol a predstiera zdroj z Cloudflare. Obraz `nginx:1.30-alpine` obsahuje curl 8.22 (`--haproxy-clientip` vyžaduje curl >= 8.2, overené aj v `mail-selftest.sh` s `curlimages/curl:8.17.0`):
      ```
      W="docker run --rm --network edge --entrypoint curl nginx:1.30-alpine -sk --max-time 10 --haproxy-protocol --haproxy-clientip 173.245.48.1 --connect-to ::mail-web:443"
      $W https://webmail.vostack.dev/ | grep -q rcmloginuser && echo webmail-OK
      $W https://mta-sts.vostack.dev/.well-known/mta-sts.txt | grep 'mode: testing'
      $W -o /dev/null -w '%{http_code}\n' https://autoconfig.vostack.dev/admin      # 404
      ```
    - Až potom krok 11. Skontrolovať, že prod/staging aplikácie ostali dostupné: `curl -fsS https://work-tracker.vostack.dev/ -o /dev/null`.
11. **Cloudflare DNS** (orchestrátor cez API s tokenom obmedzeným na zónu, po ukázaní tohto diffu vlastníkovi): tabuľka v sekcii 5.
12. **Cron (deploy crontab):**
    ```
    10 4 * * * cd /opt/mail && sh scripts/mail-cert-renew.sh >> /opt/mail/cert-renew.log 2>&1
    30 3 * * * cd /opt/mail && sh scripts/mail-backup.sh >> /opt/mail/backup.log 2>&1
    ```
    `mail-backup.sh` raz spustiť ručne a skontrolovať archív s právami 0600.
13. **Externé overenie:** sekcia 9.
14. **Odovzdanie:** vlastník prečíta heslá cez SSH (`sudo cat /opt/mail/secrets/info_password`), uloží admin/info/noreply heslá a umiestnenie tokenu do správcu hesiel. Do chatu iba `info@` a iba ak o to vlastník výslovne požiada.

### Bootstrap cez wizard (záložný postup)

Ak by `--init` + `provision` zlyhal (napr. Stalwart 1.0.x zmení bootstrap):

1. Zmazať volume `mail_stalwart-etc` (bez `config.json` beží Stalwart v bootstrap móde), `docker compose -f compose.mail.yaml up -d unbound stalwart`.
2. `ssh -L 8080:127.0.0.1:8080 my-server-agent`, otvoriť `http://127.0.0.1:8080/admin`. Heslo dočasného `admin` je v `docker compose -f compose.mail.yaml logs stalwart 2>&1 | grep -A8 'bootstrap mode'`.
3. Wizard: hostname `mail.vostack.dev` (predvyplnené je id kontajnera, prepísať), doména `vostack.dev`, **odškrtnúť** "Automatically Obtain TLS Certificate", DKIM nechať zapnuté, storage RocksDB, **Log Destination = Console**, DNS Manual. Heslo `admin@vostack.dev` sa ukáže raz.
4. `docker compose -f compose.mail.yaml restart stalwart` (config sa číta pri štarte), potom provisioning s `STALWART_BOOTSTRAP_USER=admin@vostack.dev STALWART_BOOTSTRAP_PASSWORD=<heslo z wizardu> sh scripts/mail-provision.sh`. Plán vytvorí aj `stalwart-admin`; po ňom `admin@vostack.dev` zmazať (`--cli delete Account --ids <id>`).

V tomto behu (Stalwart 1.0.1) **žiadny blocker nevznikol**: neinteraktívny bootstrap cez `config.json` + `STALWART_RECOVERY_ADMIN` + `stalwart-cli apply` prešiel lokálne end to end (`scripts/mail-selftest.sh`). Prihlásenie do WebUI cez `http://127.0.0.1:8080/admin` na plain HTTP origine nebolo overené (podľa dokumentácie môže vyžadovať HTTPS origin); overená cesta správy je CLI.

## 5. DNS v Cloudflare

Poradie: najprv edge a kontroly z kroku 10, potom záznamy 1-14 (nezávislé na sebe), **MX až naposledy** (po zelených krokoch 9 a 10 a po tom, čo záznamy 1-14 rezolvujú). Pred zmenou si zachytiť presné súčasné hodnoty (`dig +short @1.1.1.1 TXT _dmarc.vostack.dev`) kvôli rollbacku. Existujúce záznamy work-trackera sa nemenia.

| # | Typ | Názov | Teraz | Nové | Proxy |
|---|---|---|---|---|---|
| 1 | A | mail | (nič) | `2.31.74.48` | DNS only, **žiadny AAAA** |
| 2 | A | webmail | (nič) | `2.31.74.48` | Proxied |
| 3 | A | autoconfig | (nič) | `2.31.74.48` | Proxied |
| 4 | A | autodiscover | (nič) | `2.31.74.48` | Proxied |
| 5 | A | mta-sts | (nič) | `2.31.74.48` | Proxied |
| 6 | TXT | `<ed25519 selektor>._domainkey` | (nič) | hodnota z kroku 8 | - |
| 7 | TXT | `<rsa selektor>._domainkey` | (nič) | hodnota z kroku 8 | - |
| 8 | TXT | mail | (nič) | `v=spf1 a -all` | - |
| 9 | TXT | _mta-sts | (nič) | `v=STSv1; id=<z kroku 8>` | - |
| 10 | TXT | _smtp._tls | (nič) | `v=TLSRPTv1; rua=mailto:postmaster@vostack.dev` | - |
| 11 | SRV | _imaps._tcp | (nič) | `0 1 993 mail.vostack.dev` | - |
| 12 | SRV | _submissions._tcp | (nič) | `0 1 465 mail.vostack.dev` | - |
| 13 | TXT | @ (SPF) | `v=spf1 -all` | `v=spf1 mx -all` | - |
| 14 | TXT | _dmarc | `v=DMARC1; p=reject;` (stav 2026-10-28; pred zmenou overiť digom) | `v=DMARC1; p=none; rua=mailto:dmarc@vostack.dev` | - |
| 15 | MX | @ | `0 .` (null MX) | zmazať, potom `10 mail.vostack.dev` | - (**NAPOSLEDY**) |

Nepublikovať: `_submission._tcp` (587), `_jmap/_caldavs/_carddavs/_pop3s`, `ua-auto-config` / `_ua-auto-config`, TLSA (bez DNSSEC), CAA. Null MX musí byť pred pridaním nového MX zmazaný (musí byť jediný MX) a dva `v=spf1` na jednom mene dajú permerror, preto sa záznamy 13 a 15 **nahrádzajú**.

**Postupné sprísňovanie.** DMARC: `p=none` 2-4 týždne a čítať rua reporty (prichádzajú na `dmarc@` = `info@`), potom `p=quarantine`, potom `p=reject`. MTA-STS: `mode: testing` (nastavené v `plan.ndjson`), po 2-4 týždňoch čistých TLS-RPT reportov prepnúť `MtaSts.mode` na `enforce` (úprava `plan.ndjson` + `mail-provision.sh`) a zvýšiť `id` v `_mta-sts` TXT (hodnotu ukáže `--print-dns`).

## 6. Klienti

- **iPhone Mail** (nečíta autoconfig ani autodiscover): ručne IMAP `mail.vostack.dev`, port 993, SSL/TLS; SMTP `mail.vostack.dev`, port 465, SSL/TLS (alternatíva 587 STARTTLS). Používateľ = celá adresa (`info@vostack.dev`).
- **Thunderbird:** autoconfig (`https://autoconfig.vostack.dev/mail/config-v1.1.xml`), stačí zadať adresu a heslo.
- **Outlook:** autodiscover (`POST /autodiscover/autodiscover.xml`); `autodiscover.json` odkazuje na `https://mail.vostack.dev/...`, ktorá je mimo whitelistu, preto pri problémoch zadať IMAP/SMTP ručne ako vyššie.
- **Webmail:** `https://webmail.vostack.dev/` (Roundcube, prihlásenie celou adresou).
- **Aplikácia (fáza 7):** pripojiť php kontajnery do siete `mail` a posielať cez `ssl://mail.vostack.dev:465` (overený certifikát vďaka aliasu `mail.vostack.dev` v sieti `mail`, bez hairpin NAT) ako `noreply@vostack.dev`. Sieť `10.250.251.0/24` je v `AllowedIp`, takže ju auto-ban neblokuje.

## 7. Bežná prevádzka

Všetky príkazy v `/opt/mail` ako `deploy`.

- **Dotazy a správa:** `sh scripts/mail-provision.sh --cli query Account`, `... --cli get Domain <id>`, `... --cli query BlockedIp`.
- **Nová schránka alebo alias:** upraviť `docker/mail/stalwart/plan.ndjson` (nový záznam v upsert `Account`, heslo ako nový placeholder, napr. `<DEV_PASSWORD>`), doplniť riadok `-e "s|<DEV_PASSWORD>|...|g"` a `read_secret` v `scripts/mail-provision.sh`, založiť `secrets/dev_password` a spustiť `sh scripts/mail-provision.sh` (idempotentné: `apply` hlási "updated" aj pri nezmenených hodnotách, ale nevytvára duplicity).
- **DKIM selektory a DNS hodnoty:** `sh scripts/mail-provision.sh --print-dns`. Kľúče sa rotujú automaticky (rotácia 90 dní), po rotácii publikovať nový selektor.
- **Aktualizácia patch verzie:** zmeniť tag v `compose.mail.yaml` (a prípadne `CLI_IMAGE`), skopírovať, `docker compose -f compose.mail.yaml pull && docker compose -f compose.mail.yaml up -d`. Pred tým `sh scripts/mail-backup.sh`. Medzi 0.16 a 1.x neexistuje in-place upgrade, 1.0.x patch je bežný pull.
- **Zablokovaná IP (auto-ban):** `--cli query BlockedIp`, `--cli delete BlockedIp --ids <id>`, `--cli create Action/ReloadBlockedIps --json '{}'`. Ban za zlé prihlásenia trvá 1 deň (`authBanPeriod`), sieť `mail` je vždy vyňatá.
- **Certifikát:** obnovuje cron (`mail-cert-renew.sh`). **Stalwart zmaže File-certifikát, ktorý expiroval**, a vtedy treba objekt vytvoriť znova (`sh scripts/mail-provision.sh`) a načítať (`--cli create Action/ReloadTlsCertificates --json '{}'`). Preto sa výstup `cert-renew.log` musí kontrolovať a `enddate` overovať (krok 9).
- **Microsoft (outlook.com, hotmail):** Hetzner rozsahy často dostávajú `5.7.1 S3150`. Ak test doručenia na outlook.com zlyhá, požiadať o delist (consumer: olcsupport.office.com, M365: sender.office.com; abuse kontakt IP je abuse@hetzner.com). Zaregistrovať Microsoft SNDS/JMRP a Google Postmaster Tools pre `vostack.dev`. Spamhaus kontrolovať cez check.spamhaus.org alebo unbound (nie cez 1.1.1.1).
- **Logy:** `docker compose -f compose.mail.yaml logs --tail 100 stalwart web roundcube`.

## 8. Zálohy a obnova

`sh scripts/mail-backup.sh` (cron 03:30): zastaví `stalwart` a `roundcube` (RocksDB nemá online zálohu, kópia bežiaceho adresára je nekonzistentná), cez jednorazový `alpine:3.24` zabalí volumes `mail_stalwart-data`, `mail_stalwart-etc`, `mail_roundcube-db` plus `tls/`, `secrets/` a `.env` do `/var/backups/mail/mail-RRRRMMDDTHHMMSS.tgz.partial`, služby spustí (aj pri chybe, trap), overí `tar tzf`, premenuje, vytvorí `.sha256`, nastaví 0600 a zmaže archívy staršie ako 14 dní. Výpadok je rádovo sekundy (odosielajúce MTA opakujú; aplikácia vo fáze 7 musí chybu SMTP zniesť a opakovať). Archív obsahuje heslá a DKIM kľúče: mimo servera iba šifrovane (off-site cez projektový restic plán neskôr).

**Obnova** (overená v `mail-selftest.sh`, kontrola 16):

```
cd /opt/mail
docker compose -f compose.mail.yaml stop stalwart roundcube web
docker run --rm -v mail_stalwart-etc:/e -v mail_stalwart-data:/d -v /var/backups/mail/<archív>.tgz:/a.tgz:ro alpine:3.24 \
  sh -c 'tar xzf /a.tgz -C /tmp ./stalwart-etc ./stalwart-data && find /e /d -mindepth 1 -delete && cp -a /tmp/stalwart-etc/. /e/ && cp -a /tmp/stalwart-data/. /d/'
docker compose -f compose.mail.yaml up -d
sh scripts/mail-provision.sh --cli query Account     # info@, noreply@, stalwart-admin@
```

Potom IMAP prihlásenie do `info@` a kontrola poslednej správy. Roundcube (`roundcube-db`), `tls/` a `secrets/` sa z archívu obnovujú obdobne (`./roundcube-db`, `./tls`, `./secrets`).

**Mesačný test obnovy** (bez zásahu do produkcie): archív rozbaliť do nových volumes `mail-restore-etc` a `mail-restore-data`, spustiť dočasný `stalwartlabs/stalwart:v1.0.1` v sieti `mail` s aliasom `restore-stalwart` a bez publikovaných portov (`--memory 512m`, mount `tls/` ako `/opt/stalwart-tls:ro`) a porovnať: `STALWART_URL=http://restore-stalwart:8080 sh scripts/mail-provision.sh --cli query Account` oproti produkcii. Potom kontajner a volumes zmazať.

## 9. Overenie

Po nasadení a po zmene DNS (krok 13):

1. `curl -fsS 'https://autoconfig.vostack.dev/mail/config-v1.1.xml?emailaddress=info@vostack.dev'` (XML s `mail.vostack.dev`, 993).
2. `curl -fsS -X POST https://autodiscover.vostack.dev/autodiscover/autodiscover.xml -d @req.xml` (XML požiadavka Outlooku, 200).
3. `curl -fsS https://mta-sts.vostack.dev/.well-known/mta-sts.txt` (`mode: testing`).
4. `curl -s -o /dev/null -w '%{http_code}\n' https://autoconfig.vostack.dev/admin` -> 404.
5. Webmail: prihlásenie ako `info@`.
6. Porty zvonka: `openssl s_client -connect mail.vostack.dev:993 -servername mail.vostack.dev </dev/null`, `-starttls smtp -connect mail.vostack.dev:25`, 465 (`-connect`), 587 (`-starttls smtp`).
7. Open relay: neautentifikovaný `RCPT TO:<niekto@gmail.com>` na `mail.vostack.dev:25` musí skončiť 5xx.
8. DNS: `dig +short @1.1.1.1 MX vostack.dev`, `TXT vostack.dev`, `TXT _dmarc.vostack.dev`, DKIM selektory, `dig +short -x 2.31.74.48`.
9. Odoslať z `info@` na Gmail a outlook.com ("Show original": SPF, DKIM, DMARC PASS; pri S3150 pozri sekciu 7). mail-tester.com skóre >= 9/10.
10. Prijať z Gmailu do `info@` a na alias `dmarc@`. Prvá hlavička `Received` musí ukazovať skutočnú IP odosielateľa, nie `10.250.251.1` (inak DNAT zdrojovú IP nezachováva).
11. `ss -ltn` na serveri: porty 25/465/587/993 stále iba `0.0.0.0`, žiadne `[::]`.

## 10. Rollback

Prvé tri kroky sú nezávislé, poradie je odporúčané:

1. **DNS:** vrátiť MX `0 .`, SPF `v=spf1 -all`, `_dmarc` na `v=DMARC1; p=reject;` (alebo hodnotu zachytenú pred zmenou), zmazať záznamy 1-12 z tabuľky v sekcii 5.
2. **Edge:** `cp /opt/edge/nginx.conf.pre-mail /opt/edge/nginx.conf`, `cd /opt/edge && docker compose -f compose.edge.yaml exec edge nginx -t && docker compose -f compose.edge.yaml exec edge nginx -s reload`.
3. **Stack:** `cd /opt/mail && docker compose -f compose.mail.yaml down` (volumes ostávajú; `down -v` iba na výslovnú žiadosť vlastníka).
4. Odstrániť dva cron riadky (sekcia 4, krok 12).
5. Voliteľne `docker network rm mail`.

Work-tracker prod a staging sa nedotýka žiadny krok okrem reloadu edge v kroku 10 (najprv validovaný cez `nginx -t`, spravený pred akýmkoľvek záznamom v Cloudflare).

## 11. Lokálny test

- `sh scripts/mail-selftest.sh`: celý stack lokálne s vlastnou CA (SMTP submission 465 a 587 s AUTH, alias, žiadny open relay, IMAP, autoconfig/autodiscover/mta-sts/admin 404 cez edge, prihlásenie do Roundcube, reálny `cf-peer.conf`, `mail-cert-renew.sh`, záloha a obnova). Trvá niekoľko minút, používa len `127.0.0.1:18025/18465/18587/18993/18080/18443` a siete `mail-selftest` (`10.250.251.0/24`) a `mail-selftest-edge` (`10.250.250.0/24`); ak sú obsadené (skutočné siete `mail`/`edge` na tom istom hostiteľovi), test sa nespustí. Všetko po sebe odstráni. `MAIL_SELFTEST_KEEP=1` nechá stack bežať na ladenie.
- `sh scripts/edge-selftest.sh`: edge (vrátane štyroch mail riadkov v `map`).
- Skripty: `docker run --rm -v "$PWD:/mnt:ro" -w /mnt koalaman/shellcheck:stable -x scripts/mail-*.sh`.
