# Phase 12: Lokalizácia a produkcia - Research

**Researched:** 2026-11-04
**Domain:** CI4 i18n (SK/PL/CS/EN), restic zálohy, nemazateľnosť dokladov v PostgreSQL, ročný archív, výkonové meranie na malom VPS
**Confidence:** HIGH pre kód a repozitár (overené priamo v kóde), MEDIUM pre restic/healthchecks detaily (z tréningových znalostí, riadiť sa `--help` na serveri)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- D-01: `Config\App::$supportedLocales` z `['sk','en']` na `['sk','pl','cs','en']`, kód `cs` (nie `cz`), predvolený `sk`, pred prihlásením `Accept-Language`, po prihlásení `user_security.locale` (existujúci `LocaleNegotiation`).
- D-02: doplniť všetky súbory `app/Language/pl/` a `app/Language/cs/` (dnes len `InvoicePdf.php`, `ReportPdf.php`); `sk` a `en` majú po 32 súborov. Preklady pripraví Claude, vlastník prezrie. Jazyk dokladov (PDF, e-maily) sa riadi klientom/subjektom, nie UI.
- D-03: test parity kľúčov sk = pl = cs = en (rozšíriť `tests/unit/I18n/LangKeysTest.php`), chýbajúci preklad je chyba testu.
- D-04: čísla/dátumy/meny podľa jazyka UI cez intl (`MoneyFormat`, `LimitMath::formatPercent`), odstrániť natvrdo zapísané formáty.
- D-05: zálohy zatiaľ priamo na serveri (vedomý odklon od NFR-05 "mimo servera"; overenie ho uvedie ako akceptovaný odklon).
- D-06: kópia mimo servera ručným pullom na PC (skript v repozitári, rsync cez SSH z WSL, celý restic repozitár, odporúčane týždenne).
- D-07: restic 0.19.1, lokálny repozitár na serveri (napr. `/var/backups/restic/work-tracker`, iba root, mimo Docker volumes), `restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune`. Ochrana pred útočníkom so serverovým prístupom sa nerieši.
- D-08: zálohuje sa iba DB (`pg_dump --format=custom`, rozšírený `scripts/backup.sh`); PDF sú v `invoice_documents.content`. `writable/` sa nezálohuje. Heslo restic repozitára a `encryption.key` si vlastník uloží mimo servera.
- D-09: upozornenie healthchecks.io (bezplatný plán, dead man's switch) + e-mail pri chybe cez Stalwart SMTP. URL pingu je tajomstvo v `.env` servera. Staging: bez záloh alebo bez pingu (rozhodne plánovač).
- D-10: retencia ročného archívu 10 rokov pre všetky subjekty. Archív = ZIP všetkých PDF dokladov (faktúry, výkazy, dobropisy, všetky verzie) z daného roka + CSV index (číslo, typ, dátum, subjekt, klient, suma, mena, sha256). Spark príkaz, uloží sa do restic s tagom `archive` (`--keep-tag archive`), prichádza aj s pullom. Raz ročne (začiatok januára) cez cron + ručné spustenie pre ľubovoľný rok.
- D-11: nemazateľnosť: overiť testom pre každú tabuľku dokladov (DELETE a TRUNCATE ako `app_rw` aj `app_owner` musí zlyhať), doplniť ochranu kde chýba.
- D-12: merať iba produkčný stack (php, nginx, db projektu `task-tracker`); staging/edge/mail do limitu 512 MB nerátať, ale uviesť v reporte. Výsledky do `docs/ops/`.
- D-13: overiť/doplniť HTTP/2, gzip, immutable cache `/assets/vendor/*`, OPcache prod, `composer --classmap-authoritative`, bezpečnostné hlavičky a CSP; ak cieľ nevyjde, ladiť PG `shared_buffers` a FPM `pm`.
- D-14: CLI reset 2FA (`app/Commands/UserReset2fa.php`) iba zdokumentovať v `docs/ops/server.md`.
- D-15: obnova sa vyskúša na čistom prostredí s reálnymi prod dátami z pullu do lokálnej Docker inštalácie vo WSL (počty riadkov, posledné číslo faktúry, sha256 PDF, prihlásenie s 2FA). Plus mesačný automatický test obnovy do dočasnej DB na serveri.

### Claude's Discretion
- cesty, názvy skriptov a spark príkazov, cron vs systemd timer (konzistentne s `cert-renew.sh` a `mail-backup.sh` = cron),
- restic na hostiteľovi vs v kontajneri,
- formát/štruktúra CSV indexu,
- nástroj na meranie p95,
- poradie a rozdelenie plánov (lokalizácia je nezávislá od záloh).

### Deferred Ideas (OUT OF SCOPE)
- Off-site úložisko záloh (Hetzner Storage Box, Google Drive/rclone); prechod cez `restic copy`; ochrana pred útočníkom so serverovým prístupom.
- Off-site záloha mail stacku.
- Potvrdenie PL doby uchovávania účtovníčkou.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| UI-02 | Jazyky UI SK/PL/CZ/EN, voľba používateľa, formát čísel/dátumov/mien | Sekcia Lokalizácia: chýba UI/endpoint voľby (UI-SPEC to potvrdzuje), 30 súborov na preklad x2, parita test, existujúce formattery |
| NFR-01 | p95 < 100 ms, PDF < 2 s, HTTP/2, kompresia, OPcache, cache statiky | Existujúci nginx/opcache stav je už väčšinou splnený; chýba meranie, `gzip_types` bez `text/html`, `docs/ops` report |
| NFR-05 | Šifrovaný denný dump, rotácia 14/8/12, upozornenie, obnova | restic na hostiteľovi + backup.sh, healthchecks, pull skript, mesačný restore-test |
| NFR-09 | Doklady nemazateľné aj DB právami, ročný archív | Zoznam chránených tabuliek, test matica, `archive:year` spark príkaz nad `AccountantBundle` vzorom |
</phase_requirements>

## Summary

Väčšina infraštruktúry už existuje a fáza ju má hlavne **overiť, doplniť a zdokumentovať**. nginx šablóna už má `http2 on`, gzip, `immutable` cache pre `/assets/`, CSP/HSTS snippet; `opcache-prod.ini` má `validate_timestamps=0`, preload a `jit=off`; produkčná pamäť v pokoji bola nameraná v ranných fázach na ~58 MiB (docs/ops/server.md). `scripts/backup.sh` robí overený dump (`pg_restore -f /dev/null`), sha256 a `.partial` zápis. Immutable triggery a `truncate_guard` už pokrývajú faktúry, riadky, dokumenty, počítadlá, odosielania, platby a `invoice_regen_windows`. Skutočne nové sú: (1) preklady PL/CS (30 súborov každý) a **UI voľby jazyka, ktoré v kóde neexistujú** (UI-SPEC to zistil, CONTEXT D-01 sa mýli), (2) restic vrstva + healthchecks + pull skript + restore test, (3) `spark` príkaz ročného archívu, (4) matica testov nemazateľnosti, (5) meranie a reporty.

Kľúčová architektonická voľba: **restic beží na hostiteľovi** (root cron), nie v kontajneri. Hostiteľ už má docker, cron a `deploy` konvencie; `pg_dump` zostáva v kontajneri `db` (zhodná verzia 18). Skript najprv vytvorí overený dump do dočasného súboru (existujúci `backup.sh`), potom `restic backup` súboru s tagom `db`, potom zmaže dočasný súbor a spraví `forget --prune`. Tým sa zachová overenie archívu pred uložením.

**Primary recommendation:** rozdeliť na 4 plány: (A) i18n (pl/cs súbory, locale karta + POST, parita test, formát testy), (B) restic zálohy + healthchecks + pull + docs, (C) archív (`archive:year`) + test nemazateľnosti, (D) meranie výkonu/hardening + restore test + docs. A je nezávislé, B predchádza D (restore test) a C (archív ide do restic).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Výber jazyka UI | API / Backend (kontroler + `user_security.locale`) | Frontend Server (filter `LocaleNegotiation`) | Server-rendered, bez JS |
| Formát čísel/dátumov | API / Backend (helpery, intl) | — | PdfFormat natvrdo pre PDF, intl pre UI |
| Nemazateľnosť dokladov | Database (triggery, GRANT) | API (app vrstva zamyká) | Musí držať aj proti `app_owner` |
| Ročný archív | API / Backend (spark) | Host cron + restic | PDF sú v DB, ZIP sa skladá v php kontajneri |
| Záloha, rotácia, pull | Host (cron, restic, rsync) | Database (`pg_dump` v `db` kontajneri) | Mimo aplikácie |
| Upozornenie na zlyhanú zálohu | Host skript (curl healthchecks + e-mail) | Stalwart SMTP | Dead man's switch musí byť mimo servera |
| HTTP/2, gzip, cache, hlavičky | CDN/Static (nginx + edge) | — | Hotové, overiť |
| Meranie výkonu | Host / WSL klient | — | Report v `docs/ops/` |

## Standard Stack

### Core
| Library/Tool | Version | Purpose | Why Standard |
|---|---|---|---|
| restic | 0.19.1 [VERIFIED: GitHub releases API, v0.19.1 publikované 2026-07-05] | šifrovaná deduplikovaná záloha, rotácia | Rozhodnuté v CLAUDE.md a D-07 |
| PostgreSQL `pg_dump` v kontajneri `db` | 18 (`postgres:18-alpine`) | dump | Zhodná verzia klienta a servera |
| PHP `intl` (`NumberFormatter`, `IntlDateFormatter`) | súčasť obrazu | locale formáty | Už použité v `MoneyFormat`, `BillingMonth`... |
| PHP `ZipArchive` | súčasť PHP | ročný archív | Už použité v `AccountantBundle` |
| CI4 `Language` | 4.7.5 | preklady | Predvolené súbory `app/Language/<locale>` |
| healthchecks.io | free plán | dead man's switch | D-09 |
| rsync cez SSH | systémový | pull repozitára na PC | D-06 |

Nič sa nepridáva do `composer.json`. Nové balíky: žiadne, takže Package Legitimacy Audit nie je potrebný. restic sa sťahuje ako binárka z GitHub releases s overením `SHA256SUMS` (nie z `apt`, ktorý má staršiu verziu) [ASSUMED: apt verzia na serveri je staršia, overiť `apt-cache policy restic`].

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| restic na hostiteľovi | restic v kontajneri | Kontajner pridá obraz a mount repozitára, nič neprináša. Hostiteľ stačí |
| `restic backup --stdin` | dump do súboru potom backup | stdin preskočí overenie `pg_restore -f /dev/null`. Použiť súbor (dočasný, 0600, zmazať po zálohe) |
| cron | systemd timer | Konzistencia s `cert-renew.sh`, `mail-backup.sh` = cron (discretion) |

**Installation (na serveri, ako root):** stiahnuť `restic_0.19.1_linux_amd64.bz2` + `SHA256SUMS` z GitHub releases, overiť sumu, `bunzip2`, `install -m 0755 restic /usr/local/bin/restic`. Overiť `restic version` a architektúru VPS (`uname -m`; Hetzner CX = amd64 alebo arm64, [ASSUMED] amd64).

## Architecture Patterns

### Diagram: tok záloh a archívu
```
cron (root, 03:10) -> scripts/backup-restic.sh
   |-- docker compose exec db pg_dump (custom) -> /var/backups/work-tracker/tmp/*.dump.partial
   |-- docker compose exec db pg_restore -f /dev/null  (overenie)  -> mv + sha256
   |-- restic backup --tag db  (repo /var/backups/restic/work-tracker, heslo z /root/.restic-pass)
   |-- restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --keep-tag archive --prune
   |-- restic check --read-data-subset=5% (týždenne)
   |-- rm dočasný dump
   |-- OK  -> curl healthchecks ping (URL z env súboru)
   '-- ERR -> curl <ping>/fail + e-mail (SMTP Stalwart)
cron (1. jan, 04:00) -> scripts/archive-yearly.sh
   |-- docker compose exec php php spark archive:year [rok] -> writable/archive/<rok>/*.zip + index.csv
   |-- docker compose cp -> host tmp, restic backup --tag archive, uloženie, zmazanie
PC vlastníka (WSL, ručne/týždenne) -> scripts/pull-backups.sh (rsync -a --delete? NIE, bez delete)
cron (1. v mesiaci) -> scripts/restore-test.sh: restic restore latest -> createdb tmp -> pg_restore -> porovnanie -> dropdb
```

### Recommended Structure
```
scripts/backup.sh               # ostáva (dump + overenie), rozšíriť o použitie z restic skriptu
scripts/backup-restic.sh        # nový: orchestrácia, forget, ping/mail
scripts/restore-test.sh         # nový: mesačný test na serveri
scripts/pull-backups.sh         # nový: spúšťa sa vo WSL
scripts/archive-yearly.sh       # nový: wrapper nad spark
app/Commands/ArchiveYear.php    # nový: archive:year [rok]
app/Libraries/Export/YearArchive.php  # ZIP + CSV (vzor AccountantBundle)
app/Views/settings/_language.php, Settings/Security::setLocale  # podľa UI-SPEC
docs/ops/backup.md (prepísať), docs/ops/performance.md (nový), server.md (cron, 2FA reset)
```

### Pattern 1: parita jazykov (test)
Rozšíriť `tests/unit/I18n/LangKeysTest.php`: `load()` a `flatten()` už existujú. Namiesto `testSkAndEnHaveSameFilesAndKeys` porovnať `sk` oproti každému z `['pl','cs','en']` (súbory aj kľúče, aj poradie nie je nutné). Pridať kontrolu, že hodnota nie je prázdny reťazec a že placeholdery `{0}`, `{name}` v preklade zodpovedajú `sk` (regex `\{[^}]+\}`), inak preklad zlyhá až za behu.

### Pattern 2: voľba jazyka (UI-SPEC je autoritatívny)
`POST /settings/security/locale` (CSRF), whitelist `config('App')->supportedLocales`, `UPDATE user_security SET locale` (stĺpec je mutable pre `app_rw`, `varchar(10)` bez CHECK, takže `cs` sa zmestí bez migrácie), `AuditLog`, PRG a flash v novom jazyku. Detaily v `12-UI-SPEC.md`.

### Pattern 3: archív
`YearArchive::build(int $year): path` číta `invoices` JOIN `invoice_documents` (všetky verzie, stĺpce `kind`, `version`, `content`, `sha256`) kde `issue_date` v roku; streamuje do `ZipArchive` v `WRITEPATH . 'archive'`. Pozor: súbor `content` je `bytea`, CI4 ho vráti ako stream/string, treba overiť, ako to rieši `DocumentStore` a rovnaký postup použiť (nenačítať všetky PDF naraz, pre každý riadok načítať a pridať `addFromString`, limit pamäte). `AccountantBundle` má `MAX_BYTES = 64 MB`, pre ročný archív tento limit neplatí, ale `memory_limit` CLI je potrebné skontrolovať (php-cli v obraze má predvolene -1 alebo 128M, [ASSUMED], overiť). Pre veľké ročné objemy zvážiť dočasné súbory na disk a `ZipArchive::addFile` (ZipArchive drží zdroje do `close()`, takže `addFromString` pre stovky PDF zaberie RAM = súčet PDF). Pri jedinom živnostníkovi (rádovo 150 faktúr/rok x ~100 kB = ~15 MB) je to bezproblémové, napriek tomu použiť dočasné súbory v `writable/archive/tmp`.
CSV: UTF-8 BOM, bodkočiarka, hlavička podľa D-10 (`number;type;issue_date;entity;client;total;currency;sha256;file`), `fputcsv`, sumy ako string z DB (žiadny float).

### Anti-Patterns to Avoid
- `restic backup --stdin` bez prechádzajúceho overenia dumpu.
- `sed -i` na `app.env` (rozbije skupinu `deploy:82`, memory). Nové tajomstvá (restic heslo, healthchecks URL) patria do **nového** root súboru (`/root/.config/work-tracker-backup.env`, 0600), nie do `app.env`; healthchecks URL nepotrebuje PHP, a keď ju chce e-mail skript, číta ju z toho súboru.
- `rsync --delete` v pull skripte: pri zmazaní repozitára na serveri by sa zmazala aj kópia na PC. Pull bez `--delete`, alebo s `--backup`. Repozitár restic je append-like (nové balíky), `prune` ale balíky maže, takže kópia pribúda. Pre zrkadlenie stačí `rsync -a` s `--delete-delay` len vedome, odporúčané **bez** delete a ručné premazanie PC kópie raz ročne (zdokumentovať).
- Generovanie archívu z živých nastavení: vždy z uložených `invoice_documents.content`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Šifrovanie, dedupe, rotácia záloh | vlastné gpg + find -mtime | restic `forget --keep-*` | Rozhodnutie D-07 |
| Dead man's switch | vlastný monitor | healthchecks.io ping | Zachytí aj výpadok servera |
| ZIP | vlastný archivér | `ZipArchive` | Už v projekte |
| Formát čísel/dátumov | vlastné formátovače pre UI | `NumberFormatter`/`IntlDateFormatter` | PdfFormat je zámerne natvrdo len pre PDF (stabilita mimo ICU) |
| P95 | vlastný benchmark engine | `ab` alebo skript nad nginx logom s `$request_time` | Menej kódu |

## Runtime State Inventory

Nejde o rename. Relevantný runtime stav:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `user_security.locale` existujúce riadky `sk`/`en`, hodnoty zostanú platné | žiadna migrácia |
| Live service config | healthchecks.io check (vytvorí vlastník ručne, URL do env súboru), cron riadky na serveri | ručný krok v runbooku |
| OS-registered state | nové cron riadky (root: záloha, mesačný restore-test, ročný archív) | zdokumentovať v `server.md` |
| Secrets/env vars | nový `/root/.config/work-tracker-backup.env` (restic heslo cesta, healthchecks URL, SMTP parametre pre alert) | mimo gitu, 0600 root |
| Build artifacts | restic binárka na hostiteľovi, nie v obraze | inštalácia v runbooku |

## Common Pitfalls

### Pitfall 1: `compose.server.yaml` vyžaduje `IMAGE_TAG`
`scripts/backup.sh` volá `docker compose exec` bez `-f`. Na serveri to zlyhá na interpolácii (server.md r. 269). Skript musí prijať `COMPOSE_FILE=compose.server.yaml` a `IMAGE_TAG=$(cat .last-good-tag)` alebo volať `docker exec <kontajner> pg_dump` priamo (meno kontajnera `task-tracker-db-1`). Odporúčanie: v novom skripte exportovať `COMPOSE_FILE`, `IMAGE_TAG` z `/opt/work-tracker/.last-good-tag`, `-U app_owner` heslo: `pg_dump` v kontajneri `db` používa lokálny socket (peer/trust v docker-entrypoint?) [ASSUMED]. Overiť na stagingu, či `pg_dump -U app_owner` neprosí o heslo; ak áno, použiť `-U postgres` cez socket (kontajner `db` má `POSTGRES_PASSWORD_FILE`) alebo `PGPASSFILE`.
**Dôležité:** `app_owner` musí mať SELECT na všetko, vrátane `bytea`, v poriadku (vlastník schémy).

### Pitfall 2: `--keep-tag archive` a skupiny snapshotov
`restic forget` zoskupuje snapshoty podľa `host,paths`. Ak `db` dump aj archív majú rôzne cesty, sú v rôznych skupinách a pravidlá `--keep-daily` sa uplatnia samostatne, takže archív by sa pri dennom pravidle redukoval na posledný/rok. `--keep-tag archive` ho zachová bez ohľadu na skupinu. Test: vytvoriť testovací repo, 20 denných snapshotov + 1 s tagom archive staršie než 1 rok, `forget --dry-run`. Uložiť dump pod **stabilnou cestou** (`/var/backups/work-tracker/staging/task_tracker.dump` alebo `--stdin-filename`), nie s časovou značkou v názve, inak každý snapshot tvorí vlastnú skupinu (paths sa líšia) a rotácia nič nezmaže. Toto je najčastejšia chyba pri restic + dátumované súbory. Ďalej `--group-by tags` ako poistka.

### Pitfall 3: `restic prune` pamäť/čas na 512 MB VPS
Pri malom repozitári (desiatky MB) zanedbateľné. Neukladať `--cache-dir` do `/tmp` tmpfs. Zmerať pamäť počas behu (súčasť `docker stats` merania, ale restic je mimo kontajnerov).

### Pitfall 4: PL/CS pluralizácia a skloňovanie
CI4 `lang()` nemá pluralizáciu. Texty typu „3 faktúry“ riešiť neutrálnymi vetami (UI-SPEC to už predpisuje: „Neutrálne vety“). Názov mesiaca: použiť `LLLL y` (standalone), nie `MMMM` (genitív v PL/CS), UI-SPEC to uvádza.

### Pitfall 5: `IntlDateFormatter` závisí od ICU dát v alpine obraze
`intl` v `php:8.5-fpm-alpine` používa ICU zo systému (icu-data-full). Ak chýba `icu-data-full`, locale `pl`/`cs` môžu padnúť na fallback (angličtina). Test: `NumberFormatter('cs', CURRENCY)` pre CZK dáva `Kč` a `IntlDateFormatter('pl', ...)` pre november dáva `listopad`. Overiť v prod obraze, nielen na hostiteľovi s PHP 8.3 (lokálny `php -v` hostiteľa je 8.3.6, testy bežia v kontajneri). [ASSUMED: alpine ICU plné dáta, overiť v Dockerfile]. Test do suite: `MoneyFormat::format('1080.00','PLN','pl')` očakáva `1 080,00 zł` s NBSP medzerami; ICU verzie dávajú U+00A0 vs U+202F (v CLDR 42+ pre `fr`, pre pl/cs/sk U+00A0). Test porovnať po normalizácii všetkých whitespace na bežnú medzeru.

### Pitfall 6: hardcoded formáty sú už väčšinou preč
grep na `number_format`/`date('...')` v `app/` našiel len `errors/html/error_exception.php` (dev šablóna, ignorovať). `PdfFormat::SYMBOLS` a `date()` majú `en`/`pl`/default vetvy iba pre jazyky dokladov. `PdfFormat::date` pre `cs` vracia „4. 11. 2026“ (default), čo je správne pre cs. Nič netreba meniť.

### Pitfall 7: TRUNCATE a prázdne tabuľky
`truncate_guard` povolí TRUNCATE prázdnej tabuľky (zámer: testy). Test nemazateľnosti musí najprv vložiť riadok (alebo použiť tabuľky so seedom). Test ako `app_owner`: `DELETE` zlyhá cez `immutable_guard` (aj pre ownera), `TRUNCATE` zlyhá, ak tabuľka nie je prázdna. `app_owner` môže `ALTER TABLE ... DISABLE TRIGGER` alebo `DROP TABLE`, to je akceptované a zdokumentované v migrácii (viditeľný zásah). V RESEARCH/PLAN uviesť ako zvyškové riziko, nie chybu.

### Pitfall 8: `invoice_counters` používa `counter_guard`, nie `immutable_guard`
Počítadlo sa smie UPDATE-ovať (inkrement), DELETE nie. Test matica musí zohľadniť, že tabuľka nie je „insert-only“, ale DELETE/TRUNCATE zlyhajú. Overiť definíciu `counter_guard`.

### Pitfall 9: meranie p95 vyžaduje prihlásenie s 2FA
Prihlásenie je za 2FA, `ab` potrebuje cookie. Postup: prihlásiť sa v prehliadači, skopírovať session cookie (neukladať do gitu ani reportu), spustiť `ab -n 300 -c 1 -C 'ci_session=...' URL`. Alternatíva presnejšia: pridať `$request_time` do nginx `log_format` a vypočítať p95 zo skutočného prevádzkového/skriptovaného prechodu (php-fpm čas je v `$upstream_response_time`). Edge a Cloudflare pridávajú latenciu, p95 „servera“ merať na origin (`curl --resolve` na IP origin, `ORIGIN_IP` je už v `smoke-server.sh`) alebo z `$request_time`. Prod meranie vykonať s cookie vlastníka (prod dáta); staging je tá istá VPS a seed dáta, vhodné na skúšku skriptu (memory: testovať len na stagingu, takže skript otestovať na stagingu, na prod spustiť iba čítacie GET požiadavky po schválení vlastníkom, čo je čisto read-only).

### Pitfall 10: `gzip_types` neobsahuje `text/html`
nginx gzipuje `text/html` vždy (predvolene povolený typ), takže netreba pridávať. `application/javascript` je v zozname; moderné nginx posiela `.js` ako `text/javascript`, ak `mime.types` to tak definuje. Overiť `curl -sI -H 'Accept-Encoding: gzip' .../assets/vendor/...js` a prípadne pridať `text/javascript`, `application/manifest+json`, `image/svg+xml` (je). [ASSUMED: nginx 1.30 `mime.types` mapuje js na `text/javascript`.] Navyše edge (Cloudflare) môže komprimovať sám (brotli), `Content-Encoding` na origin overiť osobitne.

## Code Examples

### restic orchestrácia (kostra)
```sh
#!/bin/sh
# Source: restic docs (manual) - forget/--keep-*, --keep-tag, check; overiť `restic <cmd> --help` pre 0.19.1
set -eu
umask 077
. /root/.config/work-tracker-backup.env     # RESTIC_REPOSITORY, RESTIC_PASSWORD_FILE, HC_URL, ...
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE
fail() { curl -fsS -m 10 --retry 3 "$HC_URL/fail" >/dev/null 2>&1 || true; mail_alert "$1"; exit 1; }
trap 'fail "backup zlyhal (riadok $LINENO)"' EXIT
DUMP_DIR=/var/backups/work-tracker/restic-tmp; install -d -m 0700 "$DUMP_DIR"
# 1) dump + overenie (backup.sh); stabilny nazov kvoli skupine snapshotov
sh scripts/backup.sh "$DUMP_DIR"           # vyrobi casove znackovany subor, premenovat na stabilny nazov
restic backup --tag db --host work-tracker "$DUMP_DIR/task_tracker.dump"
restic forget --tag db --group-by host,tags --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune
rm -f "$DUMP_DIR"/task_tracker*.dump*
trap - EXIT
curl -fsS -m 10 --retry 3 "$HC_URL" >/dev/null
```
(Druhý forget pre archív netreba, ak sa archívne snapshoty vedú pod tagom `archive` a `forget --tag db` sa ich nedotkne. Tým je `--keep-tag archive` poistka, nie nutnosť, ale CONTEXT ho predpisuje, takže ho ponechať v `forget` bez `--tag` filtra alebo v oboch.)

### Pull na PC (WSL)
```sh
rsync -a --partial -e "ssh -i ~/.ssh/<kluc>" deploy@2.31.74.48:/var/backups/restic/work-tracker/ ~/backups/work-tracker-restic/
```
Repozitár má práva iba root, takže pull ako `deploy` nepôjde: dať repozitár čitateľný pre skupinu `backup-pull` (dedikovaný SSH používateľ s `rsync` len na čítanie), alebo `rsync --rsync-path="sudo rsync"` s `sudoers` povolením pre konkrétny príkaz. Rozhodnúť v pláne (Open Question 1). Šifrovaný repozitár je bezpečné čítať širšie než heslo, ale D-07 hovorí „iba root“.

### Test nemazateľnosti (vzor)
```php
// tests/integration/Database/ImmutableDocumentsTest.php, vzor z tests/integration/DbRolesTest.php (assertPermissionDenied)
foreach (['invoices','invoice_lines','invoice_documents','invoice_counters','invoice_sends','invoice_send_voids','payments', /* + tabuľky z CreatePayments */ 'invoice_regen_windows'] as $t) {
    $this->assertPermissionDenied(fn () => $this->rw()->query("DELETE FROM {$t}"));   // GRANT
    $this->assertPermissionDenied(fn () => $this->rw()->query("TRUNCATE {$t}"));      // GRANT
    $this->assertDbError(fn () => $this->owner()->query("DELETE FROM {$t} WHERE true")); // trigger
    $this->assertDbError(fn () => $this->owner()->query("TRUNCATE {$t}"));            // statement trigger (neprázdna)
}
```
Zoznam tabuliek generovať dotazom z `pg_trigger`/`information_schema` a **porovnať s explicitným zoznamom** (poistka proti novej tabuľke dokladov bez ochrany): každá tabuľka, ktorá má FK na `invoices`/`invoice_documents`, musí byť v zozname.

## State of the Art

| Old | Current | Impact |
|---|---|---|
| Storage Box + `restic -r sftp:` (CLAUDE.md) | lokálny repozitár + ručný pull (D-05..D-07) | `restic copy` neskôr, žiadne prepisovanie skriptov |
| Úprava `app.env` seddom | nový root env súbor | vyhne sa 500 na staging/prod |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `pg_dump -U app_owner` v kontajneri `db` nepýta heslo (socket trust) | Pitfall 1 | backup skript zlyhá, treba `PGPASSFILE` alebo `-U postgres` |
| A2 | Server je amd64 a `apt` má staršiu verziu restic | Standard Stack | zlá binárka |
| A3 | alpine obraz má plné ICU dáta pre pl/cs | Pitfall 5 | zlé formáty/mesiace v PL/CS |
| A4 | `php-cli` `memory_limit` v obraze stačí pre ročný archív (~desiatky MB) | Pattern 3 | OOM pri archíve |
| A5 | nginx 1.30 `mime.types` posiela `.js` ako `text/javascript` (gzip_types ho nepokrýva) | Pitfall 10 | statika bez gzipu |
| A6 | healthchecks.io free plán stačí (20 checkov, e-mail integrácia) | Summary | vlastník musí zmeniť plán |
| A7 | Zoznam chránených tabuliek: invoices, invoice_lines, invoice_documents, invoice_counters, invoice_sends (+voids), payments tabuľky, invoice_regen_windows. Presné mená platieb a sends z migrácií neoverené | Code Examples | test vynechá tabuľku |

## Open Questions (RESOLVED)

1. **Kto a ako stiahne repozitár (práva)?**
   - Čo vieme: repozitár root-only (D-07), pull ide cez SSH z WSL ako `deploy`.
   - Nejasné: `deploy` nemá čítanie root súborov.
   - Odporúčanie: skupina `backup` + `chmod g+rX`, `deploy` v skupine, alebo `sudo rsync` pravidlo. Rozhodnúť v pláne B; preferovať skupinové čítanie (šifrovaný obsah).
   - RESOLVED: samostatný systémový používateľ `backuppull` s kľúčom obmedzeným `command="sudo -n /usr/bin/rrsync -ro /var/backups/restic/work-tracker",restrict` a úzkym sudoers pravidlom (iba rrsync -ro nad repozitárom); repozitár ostáva root-only, `deploy` sa nemení (plány 12-11 `pull-backups.sh` a 12-13 `install-backup.sh`).
2. **Beží záloha aj pre staging?**
   - Odporúčanie: nie (DB sa reseeduje), skript prijme `STACK_DIR` a v stagingu sa necrontabuje; test skriptu a restore sa overí na stagingu raz ručne bez pingu (`HC_URL` prázdny = bez pingu).
   - RESOLVED: staging nemá cron záloh; `backup-restic.sh` je parametrizovaný (`BACKUP_ENV_FILE`, `STACK_DIR`, `BACKUP_ENV_NAME`, prázdne `HC_URL` = bez pingu) (plán 12-04), staging sa raz ručne otestuje so samostatným env súborom a repozitárom, ktoré sa potom zmažú (12-13 §21, 12-14 Task 2 krok 4).
3. **E-mail z hostiteľa:** bez MTA na hostiteli. Odporúčanie: `curl --url smtp://...` na Stalwart s údajmi z root env súboru, alebo `docker compose exec php php spark mail:test`-podobný príkaz. Najjednoduchšie: healthchecks.io e-mailová integrácia pri `/fail` (nulový kód) + vlastný e-mail cez curl SMTP ako D-09 predpisuje. Rozhodnúť v pláne B.
   - RESOLVED: oboje; ping `HC_URL` a `HC_URL/fail` na healthchecks.io (vynechaná záloha) a vlastný e-mail `curl --ssl-reqd --url $ALERT_SMTP_URL` na Stalwart (noreply@, heslo z `ALERT_SMTP_PASS_FILE` cez dočasný netrc 0600) v zdieľanej `scripts/lib-backup-notify.sh` (plán 12-04; používajú ju aj 12-10 a 12-11).
4. **Výpočet p95 na prod vs staging** (pitfall 9): schváliť s vlastníkom, že prod sa meria iba čítacími GET.
   - RESOLVED: meranie p95 beží na stagingu; na produkcii iba po výslovnom checkpointe vlastníka a iba čítacie GET (N=50, concurrency 1); nástroj a postup v 12-05 (skript merania, smoke statiky), meranie a zápis v 12-15.
5. **Zdroj `issue_date` pre „vystavené v danom roku“** a zahrnutie stornovaných/externých faktúr (`invoices.type`): overiť stĺpce pri plánovaní (nie je overené v tomto behu).
   - RESOLVED: „vystavené v roku“ = `invoices.origin = 'issued'` a `issue_date` v `[rok-01-01, rok-12-31]`, vrátane dobropisov (`type = 'credit_note'`) a stornovaných (dokument existuje), všetky verzie dokumentov; externé faktúry (`origin = 'external'`) sa nearchivujú (plán 12-10).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|---|---|---|---|---|
| Docker | testy, WSL restore | ✓ (lokálne) | 29.9.2 | — |
| PHP hostiteľ | len pomocné | ✓ | 8.3.6 (testy bežia v kontajneri 8.5) | — |
| restic (lokálne WSL) | pull/restore test | ✗ (`which restic` nenašiel) | — | stiahnuť binárku 0.19.1 vo WSL |
| `ab`, `hey`, `wrk` (lokálne) | p95 | ✗ | — | `curl -w '%{time_total}'` slučka + skript, alebo nginx `$request_time` |
| restic na serveri | zálohy | nezistené (SSH nepoužité) | — | inštalácia je súčasť plánu |
| healthchecks.io | D-09 | služba, vlastník vytvorí check | — | bez pingu len e-mail |
| Stalwart SMTP | alert e-mail | ✓ (fáza 7) | — | — |

**Missing dependencies with no fallback:** žiadne.
**Missing with fallback:** `ab/hey/wrk` (použiť skript s curl), restic lokálne (stiahnuť).

## Validation Architecture

### Test Framework
| Property | Value |
|---|---|
| Framework | PHPUnit 10.5 s CI4 `CIUnitTestCase`, skupiny `unit`, `integration`, `performance` |
| Config file | `phpunit.dist.xml` |
| Quick run command | `make test-unit` (v kontajneri `php`) |
| Full suite command | `make check` (phpunit + phpstan + cs) |
| JS testy | `node --test tests/js/*.test.js` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|---|---|---|---|---|
| UI-02 | parita kľúčov sk=pl=cs=en, rovnaké placeholdery | unit | `vendor/bin/phpunit tests/unit/I18n/LangKeysTest.php` | ✅ rozšíriť |
| UI-02 | `supportedLocales` = sk,pl,cs,en; zápis locale POST (whitelist, CSRF, audit) | feature | `phpunit tests/feature/Settings/LocaleTest.php` | ❌ Wave 0 |
| UI-02 | `MoneyFormat`/`formatPercent`/mesiac v 4 jazykoch | unit | `phpunit tests/unit/Platform/MoneyFormatLocaleTest.php` | ❌ Wave 0 |
| UI-02 | zmena locale nemení vystavené PDF | integration | `phpunit tests/integration/Invoicing/...` | ❌ Wave 0 |
| NFR-05 | restic skript: volanie, forget parametre, ping OK/fail, žiadny dump po behu | unit (falošný `docker`, `restic`, `curl` na PATH) | `phpunit tests/unit/Scripts/ResticBackupScriptTest.php` | ❌ Wave 0 (vzor `BackupScriptTest`) |
| NFR-05 | `forget` neodstráni `archive` snapshot, rotácia 14/8/12 | shell test s reálnym restic v tmp repo (preskočiť, ak restic chýba) | `phpunit --filter ResticRetention` | ❌ Wave 0 |
| NFR-05 | obnova na čistom prostredí s reálnymi dátami | manuálny + restore-test.sh | `sh scripts/restore-test.sh` na stagingu | manuálne (reálne prod dáta) |
| NFR-09 | DELETE/TRUNCATE ako app_rw a app_owner zlyhá pre každú tabuľku dokladov | integration | `phpunit tests/integration/Database/ImmutableDocumentsTest.php` | ❌ Wave 0 |
| NFR-09 | `archive:year`: ZIP obsahuje všetky PDF roka, CSV sha256 sedí, prázdny rok, rok bez dokumentu | integration | `phpunit tests/integration/Export/YearArchiveTest.php` | ❌ Wave 0 |
| NFR-01 | p95 a PDF < 2 s | performance (existujúca skupina), merania na serveri | `phpunit --group performance`, `scripts/measure-p95.sh` | skupina ✅, skript ❌ |
| NFR-01 | nginx hlavičky: HTTP/2, gzip, immutable, CSP | smoke | `scripts/smoke-server.sh` (rozšíriť) | ✅ rozšíriť |

### Sampling Rate
- Per task commit: `phpunit tests/unit/I18n tests/unit/Scripts` (pod 30 s)
- Per wave merge: `make check`
- Phase gate: `make check` zelený + manuálne: restore test s prod dátami, meranie na prod, `docker stats`

### Wave 0 Gaps
- [ ] `tests/feature/Settings/LocaleTest.php`
- [ ] `tests/unit/Platform/MoneyFormatLocaleTest.php`
- [ ] `tests/unit/Scripts/ResticBackupScriptTest.php`
- [ ] `tests/integration/Database/ImmutableDocumentsTest.php`
- [ ] `tests/integration/Export/YearArchiveTest.php`
- [ ] 4 x 30 jazykových súborov (`pl`, `cs`) pred spustením parity testu (test bude do vtedy červený, preto parita test pridať až po preklade alebo v tom istom pláne)

## Security Domain

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---|---|---|
| V2 Authentication | čiastočne (2FA reset CLI existuje) | Shield + TOTP, CLI iba zo servera |
| V3 Session | nie (nemení sa) | — |
| V4 Access Control | áno | DB GRANT/triggery, route len pre prihláseného |
| V5 Input Validation | áno | whitelist `supportedLocales` pri POST locale |
| V6 Cryptography | áno | restic (AES-256), `encryption.key` mimo servera, žiadny vlastný crypto |
| V8 Data Protection | áno | repozitár root-only 0700, env 0600, žiadne heslá v logoch |
| V14 Configuration | áno | hlavičky/CSP už nasadené, overiť |

### Known Threat Patterns
| Pattern | STRIDE | Mitigation |
|---|---|---|
| Únik restic hesla alebo healthchecks URL | Information disclosure | root-only env súbor, nie v gite ani `app.env`, skript heslá nevypisuje |
| Útočník so serverom zmaže zálohy | Tampering/DoS | akceptované (D-07), zmierňuje pull na PC (D-06) |
| Zmazanie dokladu cez DB | Tampering | immutable_guard, truncate_guard, GRANT, test matica |
| Open redirect/CSRF pri locale POST | Tampering | CSRF filter, PRG na pevnú trasu |
| Cookie v meraní p95 uložená v shell histórii/reporte | Info disclosure | cookie len v premennej prostredia, nikdy do `docs/` |
| rsync pull so širokým SSH kľúčom | Elevation | samostatný kľúč, obmedzenie príkazu (`command=` v authorized_keys) |

## Project Constraints (from CLAUDE.md)
- Bez SPA a build pipeline, JS/CSS lokálne, CSP bez `unsafe-eval`/inline (nová karta bez inline `style` a `onclick`).
- Peniaze `numeric(12,2)` ako string, bcmath, nikdy float (archív CSV sumy zo stringu).
- Doklady nemenné, generovanie vždy z uloženej kópie.
- Tajomstvá v `.env` mimo gitu, Docker secrets, nie v compose.
- PHPUnit povinne pre výpočty, číslovanie, zamykanie, kópiu, kurzy, limity; `error_reporting=E_ALL`, `failOnDeprecation`.
- Výkon: p95 < 100 ms, PDF < 2 s, do 512 MB RAM v pokoji; `pConnect=false`, `jit=off`.
- Zálohy: odklon D-05 má prednosť pred textom o Storage Box.
- GSD workflow: zmeny cez `/gsd-*` príkazy. Pracovať v `~/projects/task-tracker`, `app.env` zostáva `deploy:82` a bez `sed -i`, testovať na stagingu (develop), produkcia cez `main`.

## Sources

### Primary (HIGH confidence)
- Repozitár (priamo čítané): `docker/nginx/templates/default.conf.template`, `docker/nginx/templates/snippets/security-headers.conf`, `docker/php/conf.d/opcache-prod.ini`, `docker/php/fpm/zz-www.conf`, `compose.server.yaml`, `scripts/backup.sh`, `scripts/mail-backup.sh`, `docs/ops/backup.md`, `docs/ops/server.md`, migrácie `2026-10-27-000002`, `000006`, `2026-10-29-000002/000005`, `2026-11-04-000002`, `app/Filters/LocaleNegotiation.php`, `app/Libraries/Platform/MoneyFormat.php`, `app/Libraries/Invoicing/PdfFormat.php`, `app/Libraries/Export/AccountantBundle.php`, `tests/unit/I18n/LangKeysTest.php`, `tests/integration/DbRolesTest.php`
- GitHub API restic/restic releases: v0.19.1 (2026-07-05)
- `12-UI-SPEC.md` (zistenie, že UI voľby jazyka neexistuje)

### Secondary / Tertiary
- Chovanie `restic forget` skupín (`--group-by`, `--keep-tag`), healthchecks `/fail` endpoint, ICU v alpine: tréningové znalosti [ASSUMED], overiť `restic forget --help` a testom v dočasnom repozitári pred nasadením (LOW až MEDIUM).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nič nové okrem restic
- Architecture: HIGH pre i18n/DB/archív, MEDIUM pre zálohový tok (práva, pg_dump autentifikácia)
- Pitfalls: MEDIUM, pitfally 2, 5, 9, 10 vyžadujú overenie experimentom

**Research date:** 2026-11-04
**Valid until:** 2026-12-04
