<!-- GSD:project-start source:PROJECT.md -->

## Project

**Task Tracker (evidencia úloh a fakturácia)**

Webová aplikácia (PWA) pre jedného živnostníka-programátora, ktorá nahrádza ručne vedený Excel. Eviduje úlohy pre klientov a ich nacenenie, fakturuje ich po mesiacoch aj po častiach, vystavuje nemenné faktúry a výkazy práce v PDF, posiela ich e-mailom a sleduje úhrady a daňové limity obratu. Používa sa na PC aj na iPhone (inštalovaná PWA).

**Core Value:** Vystavená faktúra a výkaz sú **správne a nemenné**: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena (subjekt, klient, sadzba, mena) už vystavený doklad nezmení. Ak zlyhá všetko ostatné, toto musí fungovať.

### Constraints

- **Tech stack**: PHP 8.5 (FPM, OPcache, preloading), CodeIgniter 4.7.x, CodeIgniter Shield + TOTP knižnica (`spomky-labs/otphp` alebo `robthree/twofactorauth`), PostgreSQL (najnovšia stabilná), Bootstrap 5.3, htmx 2, Alpine.js, mPDF, CI4 Email (SMTP), PhpSpreadsheet alebo ľahšia alternatíva. Všetko v najnovších stabilných verziách. Dohodnuté s používateľom.
- **Žiadna SPA ani build pipeline**: JS a CSS hostované lokálne, minimálne, s dlhou cache a verziou v názve. Dôvod: rýchlosť na mobile, PWA a nízke nároky na server.
- **Výkon**: odozva servera p95 pod 100 ms na bežných stránkach, generovanie PDF pod 2 s, HTTP/2 a kompresia.
- **Zdroje**: najmenší VPS Hetzner Cloud (Docker Compose: nginx, php-fpm, PostgreSQL), cieľovo do 512 MB RAM v pokoji. PostgreSQL nastavený na nízku spotrebu pamäte.
- **Integrita dát**: peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, žiadny float. Vystavené doklady sú nemenné a kontroluje to serverová logika. Číslovanie je transakčné, bez medzier a bezpečné voči súbehu (zámok v DB).
- **Bezpečnosť**: HTTPS (Let's Encrypt), CSP, HSTS a ďalšie hlavičky, CSRF, secure a httpOnly cookies, tajomstvá v `.env` mimo gitu, šifrované TOTP tajomstvá.
- **Prenositeľnosť**: rovnaké Docker obrazy pre vývoj (WSL2) aj produkciu. Vo vývoji Mailpit.
- **Zálohy**: denný šifrovaný `pg_dump` a PDF dokladov mimo servera (napr. Hetzner Storage Box), rotácia 14 / 8 / 12, zdokumentovaná a otestovaná obnova.
- **Testy**: PHPUnit povinne pre výpočty súm, číslovanie, zamykanie častí, kópiu údajov, kurzy a limity.
- **UX**: ovládateľné jednou rukou na iPhone, dotykové ciele aspoň 44 px, bez horizontálneho posúvania.
- **Závislosti**: produkčný SMTP poskytovateľ ešte nie je zvolený (konfigurácia cez `.env`, vyberie vlastník pred nasadením). API kurzov (NBP, ECB, ČNB) môže byť nedostupné, preto je k dispozícii ručné zadanie.

<!-- GSD:project-end -->

<!-- GSD:stack-start source:research/STACK.md -->

## Technology Stack

## Zhrnutie rozhodnutí [builder]

| Otvorený bod | Rozhodnutie | Dôvod (skrátene) |
|--------------|-------------|------------------|
| TOTP knižnica | `spomky-labs/otphp` 11.5.0 + `bacon/bacon-qr-code` 3.1.1 (SVG) | minimálne závislosti, len RFC 6238/4226, QR bez GD/Imagick |
| XLSX export | `openspout/openspout` 5.12.2 | streamovanie, konštantná pamäť, explicitne podporuje PHP 8.5 |
| XLSX import (jednorazový) | `phpoffice/phpspreadsheet` 5.10.0 len v `require-dev`, spúšťané ako `spark` príkaz | zdrojový Excel má skutočné bunkové hyperlinky, OpenSpout ich nečíta |
| Shield + TOTP | Shield 1.4.1, vlastná akcia `Totp` (ConditionalActionInterface) | Shield 1.4 má podmienené a gateway akcie, netreba vlastné prihlasovanie |
| Zálohy | `restic` 0.19.1 cez SFTP na Hetzner Storage Box | jeden binárny súbor, šifrovanie a deduplikácia vstavané, `forget` politika 14/8/12 |
| Obraz PHP | `php:8.5-fpm-alpine` | najmenší, oficiálny, aktualizovaný 2026-10-22 |

## Recommended Stack

### Core Technologies

| Technology | Version (k 2026-10-23) | Purpose | Why Recommended | Confidence |
|------------|------------------------|---------|-----------------|------------|
| PHP (FPM) | 8.5.12 (vydaná 2026-10-21, aktívna podpora do 2027-12-31, bezpečnostná do 2029-12-31) | runtime | pripnúť obraz na `8.5-fpm-alpine`, tag sa pravidelne prebuilduje | HIGH |
| CodeIgniter 4 | `codeigniter4/framework` v4.7.5 (2026-10-09), `^8.2`, ext-intl, ext-mbstring | framework | 4.7.x je prvá línia s oficiálnou podporou PHP 8.5, spec spomína 4.7.4 z 7/2026, aktuálna je 4.7.5 | HIGH |
| `codeigniter4/appstarter` | v4.7.5 | kostra projektu | `composer create-project codeigniter4/appstarter`, obsahuje `preload.php` | HIGH |
| CodeIgniter Shield | v1.4.1 (2026-08-18), `^8.2`, závisí na `codeigniter4/settings ^2.1` | autentifikácia, skupiny, sessions, remember-me | 1.4 má `ConditionalActionInterface` a gateway akcie, presne to, čo TOTP potrebuje | HIGH |
| PostgreSQL | 18.6 (`postgres:18-alpine`, podpora do 2030-11) | DB | najnovšia stabilná línia, 17.11 je záložná voľba. PG 18 má async I/O (`io_method=worker`) | HIGH |
| nginx | 1.30.x stable (`nginx:1.30-alpine`, 1.30.6 z 2026-10-14) | web server, HTTP/2, gzip, statické súbory | "stable" vetva, nie mainline 1.31 | HIGH |
| Bootstrap | 5.3.8 (npm `latest`) | UI | nepoužívať 6.0.0-alpha | HIGH |
| htmx | 2.0.11 (npm `latest`) | čiastočné aktualizácie | nepoužívať tag `next` (4.0.0) | HIGH |
| Alpine.js | 3.17.5, **CSP build** (`@alpinejs/csp`) | drobná interaktivita | štandardný build potrebuje `unsafe-eval`, čo rozbíja prísnu CSP | HIGH |
| mPDF | v8.3.1 (2026-03-11), composer povoľuje `~8.5.0`, vyžaduje ext-gd + ext-mbstring | PDF faktúry a výkazy | PHP 8.5 explicitne v `require` | HIGH |
| CI4 Email + SMTP | súčasť frameworku | odosielanie | produkčný SMTP cez `.env` (`email.SMTPHost` atď.), vývoj Mailpit | HIGH |
| Mailpit | v1.32.0 (`axllent/mailpit:v1.32`) | dev SMTP, len profil `dev` | zachytáva e-maily s PDF | HIGH |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `spomky-labs/otphp` | 11.5.0 (2026-06-06), `php >=8.1`, závisí na `paragonie/constant_time_encoding`, `psr/clock` | generovanie a overenie TOTP, provisioning URI | vždy (E1) |
| `bacon/bacon-qr-code` | v3.1.1 (2026-04-05), `^8.1` | QR kód ako inline SVG pre aktiváciu 2FA | vždy, použiť `SvgImageBackEnd` (bez ext-gd/imagick) |
| `openspout/openspout` | v5.12.2 (2026-10-13), `~8.4 \|\| ~8.5 \|\| ~8.6`, ext-dom, xmlreader, zip, libxml, filter | export XLSX a CSV | mesačné a ročné prehľady (E10) |
| `phpoffice/phpspreadsheet` | 5.10.0 (2026-09-17), `^8.2` | jednorazový import `All4net-fakturovanie-prace.xlsx` vrátane hyperlinkov | **len `require-dev`**, produkčný obraz ho nemá |
| bcmath (PHP ext) | súčasť PHP | presná aritmetika pre peniaze, kurzy a súčty | všade, kde sa počíta (nikdy float). Alternatíva `brick/math` len ak bcmath prekáža |
| ext-intl | súčasť PHP | formátovanie čísel, mien, dátumov podľa jazyka | `NumberFormatter` pre `1 080,00 €` / `1 080,00 zł` |
| PHPUnit | `^10.5.16` (pin z `appstarter` 4.7.5). Aktuálne existuje 12.5.x aj 13.4.3 (vyžaduje PHP >=8.4.1) | testy súm, číslovania, zamykania, kópie údajov, kurzov, limitov | držať pin z appstarter-a, kým `CIUnitTestCase` oficiálne nepodporí vyššiu líniu (overiť pri založení projektu) |
| `fakerphp/faker`, `mikey179/vfsstream` | z appstarter-a | testovacie dáta | dev |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| Docker Compose | dev aj prod, rovnaké obrazy | profily: `dev` (mailpit), `prod` (certbot, backup) |
| `mlocati/docker-php-extension-installer` | inštalácia PHP rozšírení v Dockerfile | `intl pdo_pgsql pgsql gd zip bcmath opcache` (dom, xmlreader, mbstring, sodium sú už v obraze) |
| PHPStan + `codeigniter/phpstan-codeigniter` ^2.0 | statická analýza | voliteľné, lacné |
| `php-cs-fixer` / `codeigniter4/devkit` ^1.3 | štýl kódu | voliteľné |
| WSL2 poznámka | bind mount z `/mnt/c` je pomalý (I/O cez 9p) | vo vývoji dať `vendor/` a `writable/` do named volume, inak bude Composer a cache pomalá. Prod nie je dotknutý |

## Installation

# Kostra

# Produkčné závislosti

# Dev (import z Excelu je len dev/CLI nástroj, nepatrí do produkčného obrazu)

# Produkčný build obrazu

# Frontend: žiadny build, jednorazové stiahnutie súborov do public/assets/vendor/

# s verziou v názve (bootstrap-5.3.8.min.css, htmx-2.0.11.min.js, alpine-csp-3.17.5.min.js)

## Otvorené body: podrobné zdôvodnenie

### 1. TOTP: `spomky-labs/otphp` vs `robthree/twofactorauth`

- otphp robí presne TOTP/HOTP (RFC 6238/4226), `provisioning URI` (`otpauth://`) a nič iné. Tri malé závislosti, dnes aktívne udržiavaná (11.5.0 zo 6/2026, 11.4.3 z 5/2026), `php >=8.1`, bez známych problémov s PHP 8.5.
- `robthree/twofactorauth` v3.0.3 (1/2026) je tiež udržiavaná, ale prináša vlastné QR providery a abstrakciu, ktorú nepotrebujeme. Menej flexibilné pri overovaní (kontrola opakovaného použitia kódu sa robí aj tak vlastná).
- QR: `bacon/bacon-qr-code` so `SvgImageBackEnd` vráti SVG reťazec, ktorý sa vloží inline do stránky. Žiadne GD/Imagick, žiadny obrázok na disku, žiadne tajomstvo posielané tretej strane (nikdy nepoužiť Google Chart/QR API).
- Overenie: `TOTP::createFromSecret($secret)->verify($code, null, 1)` (leeway 1 krok = 30 s). Knižnica **nechráni pred opakovaným použitím**, preto si ulož `last_used_timestep` a odmietni kód s krokom <= uloženému (RFC 6238 §5.2). Porovnanie kódov len `hash_equals`.
- Tajomstvo ukladať **šifrované** CI4 `Encryption` službou (`Config\Encryption`, SodiumHandler, kľúč v `.env`), nikdy v otvorenom texte (požiadavka z PROJECT.md).

### 2. TOTP nad Shield-om bez zásahu do jadra

- `ConditionalActionInterface::appliesTo(User): bool` (akcia sa spustí len, ak vráti true),
- gateway akciu (jedna registrovaná akcia, ktorá sama vyberie spôsob overenia: TOTP alebo záložný kód).

### 3. XLSX: OpenSpout vs PhpSpreadsheet

- Export prehľadov je čistý zápis tabuliek (hodnoty, formát čísla, šírky stĺpcov, štýl hlavičky). OpenSpout zapisuje streamovo, pamäť je rádovo konštantná. PhpSpreadsheet drží celý zošit v pamäti, na 512 MB stroji, kde beží aj PG a FPM, je to zbytočné riziko.
- OpenSpout 5.12.2 vyžaduje `~8.4 || ~8.5 || ~8.6`, teda PHP 8.5 je zámerne podporované.
- PhpSpreadsheet 5.10.0 (`^8.2`) funguje s PHP 8.5, ale je ťažký (načítanie celej štruktúry) a v produkcii je zbytočný.
- **Dôvod pre PhpSpreadsheet pri importe:** `docs/All4net-fakturovanie-prace.xlsx` ukladá odkazy na Taskman ako skutočné bunkové hyperlinky (`<hyperlink ref="A4" r:id="rId1"/>` v `sheet1.xml`, cieľ v `sheet1.xml.rels`, nie vzorec `HYPERLINK()`). Reader v OpenSpout hyperlinky podľa dostupných zdrojov nevystavuje (nenašiel som ich podporu, nepotvrdené oficiálnou dokumentáciou), PhpSpreadsheet ich číta cez `$cell->getHyperlink()->getUrl()`. Import je jednorazový (09 a 10/2026), preto `spark import:excel` v dev prostredí, súbor zostáva mimo gitu.
- Alternatíva k PhpSpreadsheet pri importe: ručne rozbaliť ZIP a prečítať `.rels`, no je to krehké, nerobiť to.
- CSV: natívne `fputcsv` s UTF-8 BOM (Excel v SK/PL/CZ lokalite) a bodkočiarkou, OpenSpout CSV writer nie je nutný.

## Prevádzkové ladenie pre malý VPS

### PostgreSQL 18 (`postgresql.conf` cez `command:` alebo mount)

- PG 18 obraz mení štruktúru dát: mount volume na `/var/lib/postgresql` (nie `/var/lib/postgresql/data`). Overiť v README obrazu pri písaní compose.
- Pripojenie z PHP bez perzistentných spojení (`pConnect=false`), pri 1 používateľovi netreba PgBouncer.
- Číslovanie faktúr: `SELECT ... FOR UPDATE` na riadok čítača (rad × obdobie) v tej istej transakcii ako vystavenie. Alternatíva `pg_advisory_xact_lock`. **Nepoužívať `SEQUENCE`**, tie vytvárajú medzery pri rollbacku (požiadavka bez medzier).
- `numeric(12,2)` a `jsonb` sú v CI4 Query Builderi bez problémov, ale CI4 vracia `numeric` ako **string**. Ponechať ako string a počítať cez bcmath, nikdy `(float)`.
- Backup konzistencia: `pg_dump --format=custom` je konzistentný snapshot bez zastavenia služby.

### PHP-FPM a OPcache

- `ondemand` šetrí pamäť v pokoji (jediný používateľ). Prvý request po nečinnosti dostane cold-start okolo desiatok ms, čo vo vzťahu k cieľu p95 < 100 ms treba zmerať. Ak by prekážalo, `pm = dynamic`, `pm.start_servers=1`, `min_spare=1`, `max_spare=2`.
- mPDF: nastaviť `tempDir` na `writable/mpdf` (volume), `memory_limit` zvýšiť len pre PDF požiadavku, vkladať len potrebné fonty (DejaVu Sans alebo Noto/Roboto s latin-ext pokrýva SK, PL, CZ). Nepoužívať `useSubstitutions` ani `autoLangToFont` plošne, spomaľuje.
- OPcache (`conf.d/opcache.ini`):
- V PHP 8.5 je OPcache vždy zostavený (nedá sa vypnúť pri build-e), `docker-php-ext-install opcache` už nie je potrebné.
- **Preloading s CI4:** appstarter dodáva `preload.php` v koreni projektu, ktorý načíta triedy z `system/`. Preload sa vykoná pri štarte FPM a trieda sa nedá meniť bez reštartu, čo je v poriadku pri `validate_timestamps=0`. Úskalia: (a) preload s `opcache.preload_user` musí byť povolený na používateľa `www-data`, (b) chybný `preload.php` zhodí FPM pri štarte, preto build kontrolovať `php -d opcache.preload=... -r 'echo 1;'` v CI, (c) zisk je mierny (desiatky ms pri prvom načítaní, potom už cache), nepreháňať: preloadovať len `system/` a Shield, nie `app/` a nie `vendor/` celé.
- `composer install --classmap-authoritative` dáva väčší a istejší zisk ako preload.
- CI4 4.7 má aj `Config\WorkerMode` (FrankenPHP worker). **Nepoužívať**, stack je pevne FPM a worker režim mení stav medzi requestami.
- Sessions: `Config\Session` handler `DatabaseHandler` (PG) alebo `FileHandler` na volume. Pri jednom používateľovi stačí súborový handler, DB je vhodnejšia, ak sa kedy pridá druhý kontajner.
- PHP 8.5: v testoch zapnúť `error_reporting=E_ALL` a sledovať deprecations (nové deprecácie v 8.5, napr. staré PDO pgsql konštanty prejdú na `Pdo\Pgsql`). CI4 4.7.5 a Shield 1.4.1 sú s 8.5 spárované, ale vlastný kód a menšie knižnice treba prejsť cez PHPUnit s `failOnDeprecation`.

### nginx

- HTTP/2, `gzip on` pre `text/css application/javascript image/svg+xml application/json text/html`, (`brotli` nie je v oficiálnom obraze, nepreháňať).
- Statika: `/assets/vendor/*` s verziou v názve: `Cache-Control: public, max-age=31536000, immutable`. HTML: `no-store` (autentifikované stránky).
- `fastcgi_pass php:9000`, `fastcgi_read_timeout 30s`, `client_max_body_size 5m`.
- Hlavičky: `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, **CSP** `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'`.
- CSP kompatibilita frontendu: Alpine CSP build (bez `unsafe-eval`), htmx `htmx.config.allowEval=false`, `includeIndicatorStyles=false` (htmx inak vkladá inline `<style>`), žiadne inline `style=""` ani inline `onclick`. Service worker `sw.js` musí byť na koreni (`/sw.js`) kvôli scope a s `Cache-Control: no-cache`.
- HTTPS: Let's Encrypt cez kontajner `certbot/certbot` (webroot, obnova cez cron na hostiteľovi) alebo `certbot` na hostiteľovi. Obe fungujú. Voľba: **certbot kontajner s webroot** (všetko v compose).

### Docker obrazy (pripnúť na minor, nie `latest`)

| Služba | Obraz | Poznámka |
|--------|-------|----------|
| php | `php:8.5-fpm-alpine` + vlastný Dockerfile (multi-stage: composer stage, runtime) | rozšírenia: `intl pdo_pgsql pgsql gd bcmath zip opcache`, gd s `--with-freetype --with-jpeg --with-webp`. musl a `intl`/`iconv` fungujú, ak by sa objavili problémy s `iconv` v mPDF, prejsť na `php:8.5-fpm` (Debian) |
| nginx | `nginx:1.30-alpine` | stable vetva |
| db | `postgres:18-alpine` | `POSTGRES_PASSWORD_FILE` (Docker secret), nie v compose |
| mailpit | `axllent/mailpit:v1.32` | len profil `dev` |
| backup | vlastný malý obraz s `restic` 0.19.1 + `postgresql18-client` (alebo `docker exec pg_dump`) | cron kontajner alebo systemd timer na hostiteľovi |

## Kurzy mien (overené živými volaniami 2026-10-23)

### NBP (PL subjekt), tabuľka A (priemerný kurz)

- Jeden deň: `https://api.nbp.pl/api/exchangerates/rates/a/{kód}/{RRRR-MM-DD}/?format=json`
- **Pravidlo „posledný pracovný deň pred dátumom dodania“:** na nepracovný deň vráti 404, na budúci dátum 400 (`Invalid date range`). Preto volať **rozsah** `.../rates/a/eur/{D-10}/{D-1}/?format=json` a vziať **posledný** prvok poľa `rates` (overené: rozsah 2026-10-15 až 10-19 vrátil 15, 16, 19, teda víkend je preskočený). Limit rozsahu je 93 dní pre tabuľky A/B/C pri tomto type volania (pri `/rates/` cestách NBP uvádza obmedzenie okolo 367 dní, v kóde sa držať <= 30 dní).
- `effectiveDate` uložiť do kópie údajov spolu s číslom tabuľky `no` (dôkazný údaj pre účtovníčku).
- Confidence: HIGH (živé volanie), MEDIUM pre presný limit rozsahu.

### ECB (SK subjekt)

- Denný súbor: `https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml` (XML, `Cube time='2026-10-23'`, `Cube currency='PLN' rate='4.3945'`). Kurz = jednotiek cudzej meny za **1 EUR**, takže pre faktúru v PLN a mene subjektu EUR je suma v EUR = `suma_PLN / rate` (zaokrúhliť až na konci na 2 des. miesta, pravidlo zaokrúhľovania určiť raz, zdokumentovať).
- História: `eurofxref-hist-90d.xml` (90 dní) a `eurofxref-hist.xml` (celá). Pre dátum v minulosti použiť SDMX: `https://data-api.ecb.europa.eu/service/data/EXR/D.PLN+CZK.EUR.SP00.A?startPeriod=2026-10-01&endPeriod=2026-10-23&format=jsondata` (JSON, overené, odpoveď je v SDMX-JSON štruktúre, parsovanie zložitejšie), alebo `format=csvdata` (jednoduchšie parsovanie, odporúčané).
- Zverejnenie približne o 16:00 CET v pracovné dni TARGET. Pre dátum dodania, ktorý je ešte bez kurzu, vziať posledný dostupný pred ním.
- Confidence: HIGH pre daily XML, MEDIUM pre SDMX parametre (overiť pri implementácii).

### ČNB (CZ subjekt)

- Nové JSON API: `https://api.cnb.cz/cnbapi/exrates/daily?date=2026-10-22&lang=EN` vráti `{"rates":[{"validFor":"2026-10-22","order":204,"country":"Australia","currency":"dollar","amount":1,"currencyCode":"AUD","rate":15.178}, ...]}`. **Pozor na `amount`** (napr. HUF = 100), kurz v CZK = `rate / amount`.
- Staršie textové: `https://www.cnb.cz/en/financial-markets/foreign-exchange-market/central-bank-exchange-rate-fixing/central-bank-exchange-rate-fixing/daily.txt?date=DD.MM.RRRR` (formát `Country|Currency|Amount|Code|Rate`, hlavička `22 Oct 2026 #204`). Ako záložný formát.
- `validFor` v odpovedi určuje skutočný deň platnosti, ukladať ho (pri nepracovných dňoch ČNB vráti najbližší predchádzajúci).
- Confidence: HIGH (živé volanie).

## Zálohy

- Prečo nie borg 1.4.5: borg je tiež podporovaný Hetznerom, no vyžaduje klienta aj kompatibilnú vzdialenú verziu (`--remote-path`), a pri zmene verzií je viac trenia. restic je jediný statický binárny súbor, šifruje a deduplikuje vždy (AES-256 + Poly1305), repozitár sa obnoví kdekoľvek.
- Storage Box prístup: SFTP (port 23 pre Storage Box), vlastný SSH kľúč len pre backup, `restic -r sftp:uXXXX@uXXXX.your-storagebox.de:restic-repo`, heslo repozitára v súbore s právami 600 mimo repozitára (a **kópia hesla uložená mimo servera**, bez neho sa obnoviť nedá).
- Skript (denne, systemd timer alebo cron kontajner):
- Obnova: `restic restore latest --tag db --target /tmp/r` potom `pg_restore --clean --if-exists -d ...`. **Test obnovy** ako súčasť definície hotového: mesačne obnoviť do dočasnej DB a porovnať počty riadkov a posledné číslo faktúry. Zdokumentovať v `docs/`.
- Monitorovanie: výstup skriptu mailom (CI4 Email alebo `msmtp`) pri chybe, alebo ping na healthchecks.io (voliteľné, tretia strana).
- Pri `pg_dump` nezabudnúť verziu klienta = verzia servera (18).
- Confidence: HIGH pre restic a retenciu, MEDIUM pre detaily Storage Box (port, cesta, overiť v dokumentácii Hetzner pri nastavení).

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| otphp 11.5 | robthree/twofactorauth 3.0.3 | ak by sa chcel hotový QR provider a nevadia extra závislosti |
| OpenSpout (export) | PhpSpreadsheet | ak by export vyžadoval vzorce, zlúčené bunky, grafy alebo čítanie hyperlinkov v produkcii |
| restic | borgbackup 1.4.5 | ak je už borg zavedený na ostatných serveroch |
| `php:8.5-fpm-alpine` | `php:8.5-fpm` (Debian) | ak sa objavia problémy s musl (iconv, fonty v mPDF) |
| Shield + TOTP akcia | vlastné prihlasovanie nad sessions | len ak spike v prvej fáze ukáže, že akcia nejde skombinovať s remember-me |
| PG 18 | PG 17.11 | ak by niektorý nástroj ešte nepodporoval 18 (nie je známy) |
| pm=ondemand | pm=dynamic | ak cold-start FPM pokazí p95 |
| CSP Alpine build | štandardný Alpine + `unsafe-eval` | nepoužívať, oslabuje CSP |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| htmx `next` (4.0.0) a Bootstrap 6 alpha | nestabilné, nie sú v `latest` | htmx 2.0.11, Bootstrap 5.3.8 |
| CDN pre JS/CSS | PWA, rýchlosť, CSP, súkromie | lokálne súbory s verziou v názve |
| Google Charts QR API | tajomstvo TOTP by uniklo tretej strane | `bacon/bacon-qr-code` SVG |
| PHP `float` na peniaze a hodiny | zaokrúhľovacie chyby, presne tá trieda chýb, ktorú má aplikácia odstrániť | `numeric` v DB, string + bcmath v PHP |
| PostgreSQL `SEQUENCE` pre čísla faktúr | medzery po rollbacku | riadkový zámok na tabuľke čítačov |
| PhpSpreadsheet v produkčnom obraze | pamäť, veľkosť | OpenSpout |
| CI4 WorkerMode / FrankenPHP | mimo rozhodnutého stacku, stav medzi requestami | php-fpm |
| `opcache.jit` a perzistentné DB spojenia | žiadny zisk, vyššia pamäť | `jit=off`, `pConnect=false` |
| Tajomstvá v `docker-compose.yml` / v obraze | únik cez git a registre | `.env` mimo gitu a Docker secrets |
| PHPUnit 13 bez overenia | vyžaduje PHP >=8.4.1, nie je isté, že `CIUnitTestCase` ho podporuje | pin z appstarter-a (`^10.5.16`), prípadne overiť vyššiu líniu |
| Generovanie PDF z živých nastavení | rozbije nemennosť | vždy z JSONB kópie |

## Stack Patterns by Variant

- Ponechať Shield pre používateľov, skupiny a heslá, TOTP overovať vlastným filtrom a stavom v session (`totp_pending`).
- Dôvod: stále lacnejšie než vlastné prihlasovanie.
- Zmenšiť `shared_buffers` na 48MB, `pm.max_children=3`, vypnúť `opcache.preload`, nastaviť `mem_limit`.
- Dôvod: PG a FPM sú dominantní spotrebitelia.
- Obmedziť fonty na jeden, zakázať substitúcie, cache vygenerovaného PDF na disk (spec to povoľuje), generovať výkaz a faktúru v samostatných požiadavkách cez htmx.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| `codeigniter4/framework` 4.7.5 | PHP `^8.2`, 8.5 podporované od 4.7.0 | ext-intl a ext-mbstring povinné, `ext-pgsql` pre PostgreSQL |
| `codeigniter4/shield` 1.4.1 | `codeigniter4/framework` `>=4.3.5 <4.5.0 \|\| ^4.5.1`, PHP `^8.2` | vyžaduje `codeigniter4/settings ^2.1` |
| `mpdf/mpdf` 8.3.1 | PHP `~8.5.0` povolené v `composer.json` | vyžaduje ext-gd, ext-mbstring |
| `spomky-labs/otphp` 11.5.0 | PHP `>=8.1` | nezávislá na frameworku |
| `openspout/openspout` 5.12.2 | PHP `~8.4 \|\| ~8.5 \|\| ~8.6` | ext-dom, xmlreader, zip, libxml, filter |
| `phpoffice/phpspreadsheet` 5.10.0 | PHP `^8.2` | len dev |
| `bacon/bacon-qr-code` 3.1.1 | PHP `^8.1` | SVG backend nepotrebuje ext |
| PostgreSQL 18 | `php:8.5-fpm-alpine` s `pdo_pgsql` | `pg_dump` klient musí byť 18 |
| Alpine.js `@alpinejs/csp` | htmx 2 | htmx `allowEval=false` kvôli CSP |

## Sources

- Packagist p2 JSON (`repo.packagist.org/p2/...`) pre codeigniter4/framework, shield, appstarter, spomky-labs/otphp, robthree/twofactorauth, mpdf/mpdf, openspout/openspout, phpoffice/phpspreadsheet, bacon/bacon-qr-code, phpunit/phpunit, stiahnuté 2026-10-23 (HIGH)
- `https://www.php.net/releases/index.php?json&version=8` a `https://endoflife.date/api/php.json`, `postgresql.json`, `nginx.json` (HIGH)
- npm registry `registry.npmjs.org/{bootstrap,htmx.org,alpinejs}` dist-tags (HIGH)
- GitHub releases API: restic v0.19.1, borg 1.4.5, mailpit v1.32.0 (HIGH)
- Docker Hub API: `php:8.5-fpm-alpine`, `postgres:18-alpine`, `nginx:1.30-alpine`, `axllent/mailpit:v1.32` existujú, aktualizované 2026-10-22 (HIGH)
- Shield docs `raw.githubusercontent.com/codeigniter4/shield/develop/docs/references/authentication/auth_actions.md` a `src/Authentication/Actions/ActionInterface.php` (HIGH)
- Živé volania: `api.nbp.pl`, `ecb.europa.eu/stats/eurofxref`, `data-api.ecb.europa.eu`, `api.cnb.cz/cnbapi` a `cnb.cz daily.txt` (HIGH, 2026-10-23)
- Analýza `docs/All4net-fakturovanie-prace.xlsx` (`xl/worksheets/sheet1.xml`, `_rels`): bunkové hyperlinky (HIGH)
- Ladenie PG/FPM/OPcache: skúsenosti z praxe, bez jednotného oficiálneho zdroja (MEDIUM, treba zmerať)
- Limit rozsahu a Hetzner plány/port Storage Boxu: nepotvrdené oficiálnou dokumentáciou v tomto behu (LOW až MEDIUM)

<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->

## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->

## Architecture

Architecture not yet mapped. Follow existing patterns found in the codebase.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->

## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->

## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:

- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->

## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
