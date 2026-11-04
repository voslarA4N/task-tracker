# Phase 12: Lokalizácia a produkcia - Context

**Gathered:** 2026-11-04
**Status:** Ready for planning

<domain>
## Phase Boundary

Aplikácia je dostupná v jazykoch rozhrania SK, PL, CZ a EN a je pripravená na ostré používanie: šifrované zálohy s rotáciou a overenou obnovou, ročný archív dokladov, nemožnosť mazať doklady (ani DB oprávneniami), hardening a splnený výkonnostný cieľ na cieľovom VPS. Požiadavky: UI-02, NFR-01, NFR-05, NFR-09. Súčasťou je zdokumentovaný CLI reset 2FA a meranie `docker stats` a p95.

Mimo rozsahu: nové funkcie, off-site úložisko záloh (pozri D-05 a Deferred).

</domain>

<decisions>
## Implementation Decisions

Väčšina rozhodnutí je prevzatá zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/` (vlastník sa na tieto dokumenty spolieha). Vlastník sa vyjadroval iba k zálohám a retencii (D-05 až D-10).

### Lokalizácia (UI-02)
- **D-01:** Výber jazyka rozhrania už existuje (`user_security.locale`, Phase 2 D-23, `app/Controllers/Settings/Security.php`, filter `app/Filters/LocaleNegotiation.php`). Fáza ho nemení, iba rozšíri `Config\App::$supportedLocales` z `['sk', 'en']` na `['sk', 'pl', 'cs', 'en']` a doplní voľby v nastaveniach. Pred prihlásením sa jazyk vyberá cez `Accept-Language`, predvolená je slovenčina. Kód jazyka v CI4 je `cs` (nie `cz`), v UI sa zobrazí „Čeština“ / CZ.
- **D-02:** Doplniť všetky súbory `app/Language/pl/` a `app/Language/cs/` (dnes obsahujú iba `InvoicePdf.php` a `ReportPdf.php`, `sk` a `en` majú po 32 súborov). Preklady pripraví Claude, vlastník ich prezrie. Jazyk dokladov (PDF, e-maily) sa riadi klientom alebo subjektom, nie jazykom rozhrania, a už existuje.
- **D-03:** Test parity kľúčov: každý kľúč v `sk` musí existovať v `pl`, `cs` aj `en` a naopak (rozšíriť `tests/unit/I18n/LangKeysTest.php`). Chýbajúci preklad je chyba testu.
- **D-04:** Čísla, dátumy a meny sa formátujú podľa jazyka rozhrania cez intl (`NumberFormatter`, `IntlDateFormatter`) existujúcimi helpermi (`MoneyFormat`, `LimitMath::formatPercent`). Napríklad `1 080,00 €` / `1 080,00 zł`, pre EN anglický formát. Odstrániť zostávajúce natvrdo zapísané formáty, ak sa nájdu.

### Zálohy (NFR-05): vedomý odklon vlastníka
- **D-05:** **Zálohy sa zatiaľ ukladajú priamo na serveri**, nie na samostatné úložisko (vlastník nemá rozpočet na Storage Box ani iné úložisko). Ide o vedomý odklon od NFR-05 a kritéria úspechu č. 2 („mimo servera“). Overenie fázy ho má uviesť ako akceptovaný odklon, nie ako chybu. Riziko (strata servera = strata záloh na serveri) zmierňuje D-06.
- **D-06:** **Kópia mimo servera ručným pullom na PC vlastníka:** skript v repozitári (rsync cez SSH, spúšťaný z WSL) stiahne celý restic repozitár k vlastníkovi. Repozitár ostáva šifrovaný. Spúšťa sa podľa potreby, odporúčane raz týždenne. Runbook to uvedie ako pravidelnú úlohu.
- **D-07:** Nástroj: **restic 0.19.1** s lokálnym repozitárom na serveri (napr. `/var/backups/restic/work-tracker`, prístup iba pre root, mimo Docker volumes aplikácie). Rotácia `restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 12 --prune`. Neskorší prechod na off-site úložisko pôjde cez `restic copy` alebo zmenu `-r` bez prepisovania skriptov. Ochrana pred útočníkom so serverovým prístupom sa pri lokálnom repozitári nerieši (odložené spolu s off-site).
- **D-08:** Čo sa zálohuje: **iba DB** (`pg_dump --format=custom`, rozšírený existujúci `scripts/backup.sh`). PDF dokladov sú v DB (`invoice_documents.content` bytea), takže jeden dump pokrýva DB aj PDF v jednom konzistentnom okamihu. `writable/` (logy, cache, sessions, mPDF temp, `writable/exports`) sa nezálohuje. Heslo restic repozitára a `encryption.key` (spolu s ostatnými tajomstvami servera) si vlastník jednorazovo uloží mimo servera (správca hesiel). Runbook to vyžaduje, lebo bez nich obnova nejde.
- **D-09:** **Upozornenie na vynechanú alebo zlyhanú zálohu: healthchecks.io (bezplatný plán) + e-mail.** Skript pri úspechu pingne healthchecks.io (dead man's switch zachytí aj nespustený cron alebo výpadok servera) a pri chybe pošle e-mail s detailom cez vlastný Stalwart SMTP. URL pingu je tajomstvo v `.env` servera, nie v gite. Staging zálohy nerobí (DB sa pri každom deployi reseeduje), alebo ich robí bez pingu, podľa rozhodnutia plánovača.

### Archív a nemazateľnosť dokladov (NFR-09)
- **D-10:** **Retencia ročného archívu: 10 rokov pre všetky subjekty** (SK 10 rokov pokryje aj PL, zvyčajne 5 rokov, takže sa nečaká na účtovníčku). Ročný archív = ZIP so všetkými PDF dokladov (faktúry, výkazy, dobropisy, všetky verzie dokumentov) vystavených v danom roku + CSV index (číslo, typ, dátum, subjekt, klient, suma, mena, sha256). Je čitateľný bez aplikácie. Vytvára ho `spark` príkaz, uloží sa do restic s tagom `archive`, ktorý `forget` nikdy nemaže (napr. `--keep-tag archive`), a príde aj s pullom na PC (D-06). Spúšťa sa raz ročne (začiatkom januára za predchádzajúci rok) cez cron, s možnosťou ručného spustenia pre ľubovoľný rok.
- **D-11:** Nemazateľnosť: `immutable_guard` (BEFORE UPDATE OR DELETE, platí aj pre vlastníka schémy) a insert-only granty pre `app_rw` už chránia `invoices`, `invoice_lines`, `invoice_documents`, platby a dobropisy. Fáza to **overí testom pre každú tabuľku dokladov** (DELETE a TRUNCATE ako `app_rw` aj `app_owner` musí zlyhať) a doplní ochranu tam, kde chýba (vrátane `TRUNCATE`, ktorý row-level trigger nezachytí, teda REVOKE TRUNCATE alebo statement trigger).

### Výkon a hardening (NFR-01)
- **D-12:** Merať sa bude **iba produkčný stack** (php, nginx, db projektu `task-tracker`). Na serveri bežia aj staging, edge a mail, tie do limitu 512 MB RAM nerátať, ale v reporte ich uviesť. Merania: p95 bežných stránok (prihlásený, teplá cache, bežné stránky: dashboard, úlohy, faktúry, prehľady), čas generovania PDF, `docker stats` v pokoji. Výsledky zapísať do `docs/ops/`.
- **D-13:** Overiť (a doplniť, čo chýba): HTTP/2 a gzip v nginx (`docker/nginx/templates/default.conf.template` a edge), `Cache-Control: public, max-age=31536000, immutable` pre `/assets/vendor/*` s verziou v názve, OPcache prod (`validate_timestamps=0`, preload, `jit=off` už sú v `docker/php/conf.d/opcache-prod.ini`), `composer --classmap-authoritative`, bezpečnostné hlavičky a CSP podľa CLAUDE.md. Ak cieľ nevyjde, ladiť podľa CLAUDE.md (PG `shared_buffers`, `pm` FPM).
- **D-14:** CLI reset 2FA už existuje (`app/Commands/UserReset2fa.php`). Fáza ho iba zdokumentuje v runbooku (`docs/ops/server.md`), bez nového kódu, ak nie je chyba.

### Test obnovy
- **D-15:** Zdokumentovaná obnova (`docs/ops/backup.md`, prepísať sekcie „Mimo rozsahu (Phase 12)“) sa vyskúša na **čistom prostredí s reálnymi produkčnými dátami**: z repozitára stiahnutého pullom (D-06) obnoviť do čistej lokálnej Docker inštalácie vo WSL a overiť počty riadkov, posledné číslo faktúry, sha256 PDF a prihlásenie s 2FA (overí `encryption.key`). Mesačný automatický test obnovy do dočasnej DB na serveri (`restic restore` + `pg_restore` + porovnanie) podľa CLAUDE.md.

### Claude's Discretion
- presné cesty, názvy skriptov a `spark` príkazov, cron vs systemd timer (konzistentne s existujúcimi cron riadkami `cert-renew.sh` a `mail-backup.sh`),
- či restic beží na hostiteľovi alebo v malom kontajneri (CLAUDE.md pripúšťa oboje),
- formát a štruktúra CSV indexu archívu,
- konkrétny nástroj na meranie p95 (napr. `ab`, `hey`, `wrk` alebo skript nad nginx access logom),
- poradie a rozdelenie plánov (lokalizácia je nezávislá od záloh a môže ísť paralelne).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Požiadavky a rozsah
- `.planning/ROADMAP.md` §Phase 12: cieľ, kritériá úspechu, poznámky (Storage Box, prune, účtovníčka, CLI reset 2FA, meranie)
- `.planning/REQUIREMENTS.md`: UI-02, NFR-01, NFR-05, NFR-09
- `docs/PRD.md`: G6, NFR-01, NFR-02, NFR-05, UI-02
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md`: jazyky (r. ~220), zálohy a prevádzka (r. ~290)

### Výskum
- `CLAUDE.md`: sekcie Zálohy (restic), Prevádzkové ladenie pre malý VPS, nginx, Docker obrazy
- `.planning/research/PITFALLS.md`: zálohy (r. ~227) a retencia
- `.planning/research/FEATURES.md`: M12 retencia
- `.planning/research/SUMMARY.md`: M12, otvorená otázka 7 (retencia), ktorú rieši D-10

### Prevádzka (existujúce runbooky)
- `docs/ops/backup.md`: dnešný lokálny dump, sekcia „Mimo rozsahu (Phase 12)“ sa má nahradiť
- `docs/ops/server.md`: topológia servera (prod, staging, edge na jednom VPS), tajomstvá, cron riadky, deploy
- `docs/ops/mail.md`: Stalwart SMTP (odosielateľ upozornení D-09)
- `.planning/phases/02-identita-a-skor-nasadenie/02-CONTEXT.md` D-23: výber jazyka

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/backup.sh`: dump s overením (`pg_restore -f /dev/null`), sha256, zápis cez `.partial`. Základ pre restic zálohu.
- `scripts/mail-backup.sh`: vzor cron skriptu na serveri (umask 077, `install -d -m 0700`).
- `tests/unit/Scripts/BackupScriptTest.php`, `DeployGuardTest.php`: vzor testov shell skriptov.
- `app/Filters/LocaleNegotiation.php` a `user_security.locale`: výber jazyka.
- `tests/unit/I18n/LangKeysTest.php` (použité kľúče existujú v `sk`), `ViewsHardcodedTextTest.php`: rozšíriť o paritu `sk`/`pl`/`cs`/`en`.
- `MoneyFormat`, `LimitMath::formatPercent`: formátovanie podľa locale.
- `app/Commands/UserReset2fa.php`, `ReadsSecretTrait.php`: CLI vzory pre nový archívny príkaz.
- `app/Libraries/Export/AccountantBundle.php`: existujúci ZIP export dokladov pre účtovníčku, ktorý sa dá znova použiť pre ročný archív (D-10).

### Established Patterns
- Migrácie robia `REVOKE` pre `app_rw` a `immutable_guard` trigger (`2026-10-27-000002_CreateInvoices.php`, `2026-10-29-000005_CreatePayments.php`).
- Tajomstvá: `app.env` (0640 `deploy:82`, needitovať cez `sed -i`), `migrate.env`, `secrets/`. Nové tajomstvo (healthchecks URL, restic heslo) patrí mimo gitu.
- Dva stacky na jednom serveri (`/opt/work-tracker`, `/opt/work-tracker-staging`), oddelené `BACKUP_DIR`. Testovať najprv na stagingu.
- Texty v UI cez `lang()`, bez natvrdo zapísaných reťazcov (test to vynucuje).

### Integration Points
- `Config\App::$supportedLocales`, výber jazyka v `Settings/Security`.
- Cron na hostiteľovi (deploy) pre zálohu, archív a mesačný test obnovy.
- `docker/nginx/templates/default.conf.template`, `docker/edge/`, `docker/php/conf.d/opcache-prod.ini`.

</code_context>

<specifics>
## Specific Ideas

- Vlastník nechce platiť za ďalšie úložisko. Riešenie má byť zadarmo a neskôr ľahko presunuteľné na Storage Box.
- Google Drive (restic cez rclone) bol zvážený a zatiaľ zamietnutý.
- Ročný archív má byť čitateľný bez aplikácie (ZIP + CSV), aby sa dal dať účtovníčke.

</specifics>

<deferred>
## Deferred Ideas

- **Off-site úložisko záloh** (Hetzner Storage Box so snapshotmi ako ochrana pred zmazaním, alternatívne Google Drive cez rclone). Až bude rozpočet. Prechod cez `restic copy`. Potom znova otvoriť ochranu pred útočníkom so serverovým prístupom (prune s oddeleným kľúčom alebo snapshoty).
- **Off-site záloha mail stacku** (`scripts/mail-backup.sh` spomína „off-site neskôr cez restic“). Nie je súčasťou požiadaviek tejto aplikácie.
- Potvrdenie PL doby uchovávania účtovníčkou. D-10 ho zatiaľ nepotrebuje, ale je dobré ho mať.

</deferred>

---

*Phase: 12-lokaliz-cia-a-produkcia*
*Context gathered: 2026-11-04*
