# Súhrn výskumu projektu

**Projekt:** Task Tracker (evidencia úloh a fakturácia pre jedného živnostníka)
**Doména:** samohostovaná PWA, fakturácia nacenených úloh po častiach a mesiacoch (SK živnosť, potom PL JDG), PHP 8.5 / CodeIgniter 4.7 / PostgreSQL 18
**Preskúmané:** 2026-10-23
**Istota:** MEDIUM (stack HIGH, architektúra MEDIUM-HIGH, features a pitfalls MEDIUM, právne body LOW až MEDIUM, čakajú na účtovníčku)

Detaily: [STACK.md](STACK.md), [FEATURES.md](FEATURES.md), [ARCHITECTURE.md](ARCHITECTURE.md), [PITFALLS.md](PITFALLS.md).

## Executive Summary

Ide o malý, ale účtovne citlivý monolit: jeden používateľ, pár klientov, desiatky faktúr ročne. Tvrdá požiadavka je, že vystavený doklad sa nikdy nezmení a súčty sa zhodujú všade. Takéto systémy sa stavajú ako **jedna transakčná hranica pri vystavení**. `InvoiceIssuer` zamkne časti, vypočíta sumy, postaví JSONB kópiu údajov, pridelí číslo ako posledný krok a vloží nemenné riadky. Všetko ostatné (PDF, e-mail, stavy, obrat, prehľady) je z výsledku odvodené. Nemennosť sa vynucuje na troch úrovniach: služba, DB oprávnenia a triggery (rola `app_rw` bez UPDATE/DELETE na `invoices*`) a testy pod touto rolou.

Stack je daný a research ho potvrdil: PHP 8.5.12, CI4 4.7.5, Shield 1.4.1, PostgreSQL 18, nginx 1.30, mPDF 8.3.1, Bootstrap 5.3.8, htmx 2.0.11, Alpine CSP build, OpenSpout na export, restic na zálohy.

Odporúčaný postup:
1. Základ (Money, Clock, DB role, CSP/CSRF/htmx).
2. Skoré nasadenie s 2FA na HTTPS, lebo iOS PWA sa inak nedá overiť.
3. Master dáta, potom úlohy a časti.
4. Fakturačné jadro, najdôležitejšia úloha projektu.
5. Dokumenty a e-mail, potom kurzy.
6. Až nakoniec dobropisy, limity, prehľady a lokalizácia s produkčným hardeningom.

Do schémy musia od prvej migrácie prísť polia M1–M16, ktoré PRD vynecháva. Nemenné doklady sa po prvých reálnych faktúrach migrovať nedajú.

Hlavné riziká:
- Peniaze a zaokrúhľovanie na viacerých miestach.
- Únik nemennosti (PDF prepočítané po aktualizácii mPDF/ICU).
- Medzery a súbeh v číslovaní.
- Právne neisté body (limit SK, PL a reverse charge, dátum kurzu), ktoré rozhodne účtovníčka.
- TOTP nad Shieldom a iOS PWA.

Mitigácia: jedna trieda `Money`, uložené PDF so `sha256`, číslovanie jedným atomickým príkazom, právne veci ako konfigurácia v DB a skoré spiky (Shield, mPDF na PHP 8.5, reálny iPhone).

## Key Findings

### Odporúčaný stack

Zafixované verzie, rozhodnuté otvorené body [builder], prevádzkové hodnoty pre VPS do 512 MB v pokoji (štartovné, treba zmerať).

- **PHP 8.5.12 FPM (`php:8.5-fpm-alpine`):** CI4 4.7.x je prvá línia s oficiálnou podporou 8.5.
- **CI4 4.7.5 + Shield 1.4.1:** Shield 1.4 má `ConditionalActionInterface` a gateway akcie, takže TOTP ide ako vlastná akcia.
- **PostgreSQL 18.6:** `numeric`, `jsonb`, zámky, GRANT a triggery. Ladenie: `shared_buffers=64MB`, `max_connections=20`, `jit=off`.
- **mPDF 8.3.1:** jeden pripnutý font s latin-ext.
- **nginx 1.30 stable, Bootstrap 5.3.8, htmx 2.0.11, `@alpinejs/csp` 3.17.5:** lokálne súbory s verziou v názve, prísna CSP.
- **`spomky-labs/otphp` 11.5.0 + `bacon/bacon-qr-code` 3.1.1 (SVG):** lokálny QR, žiadna externá služba.
- **`openspout/openspout` 5.12.2:** export XLSX.
- **`phpoffice/phpspreadsheet` 5.10.0:** len `require-dev`, jednorazový import (bunkové hyperlinky na Taskman).
- **restic 0.19.1 cez SFTP na Hetzner Storage Box:** retencia 14/8/12.
- **Mailpit 1.32:** len dev.
- **PHPUnit:** pin z appstarter-a (`^10.5.16`).

Kritické body:
- NBP cez rozsah `D-10..D-1` a posledný záznam (overené živým volaním).
- ECB kurz je „za 1 EUR“, takže sa delí.
- ČNB má `amount` (napr. HUF 100).
- Peniaze len ako string/`numeric`, nikdy float.
- PG 18 mount na `/var/lib/postgresql`, klient `pg_dump` verzie 18.
- Vo WSL2 dať `vendor/` a `writable/` do named volumes.

### Očakávané funkcie

**Must have (table stakes):**
- Nemenná faktúra (JSONB kópia + uložené PDF + `sha256`) s právnym profilom podľa krajiny.
- Číslovanie `RRRRMM###` bez medzier, rad na subjekt, atómové vystavenie.
- Subjekty s verziami, klienti s typom ID, režimom fakturácie a príznakom limitu.
- Úlohy, časti, serverové zamykanie.
- Výkaz, e-mail s PDF, ručné úhrady, po splatnosti.
- Kurzy s ručným prepisom uložené v kópii.
- Limity SK a PL s predkontrolou (M9).
- 2FA, audit, zálohy s overenou obnovou, CSV/XLSX.

**Should have (differentiators):**
- Fakturácia úlohy po častiach cez mesiace (jadro produktu).
- Výkaz „16 z 40 h“ s odkazmi na Taskman.
- Samostatné subjekty SK a PL.
- Konfigurovateľné zákonné texty a príznak limitu.
- ZIP balík pre účtovníčku.
- Vystavenie plus výkaz plus odoslanie do 2 minút.

**Defer (v2+):** FA(3) a KSeF, VIES a register klientov, zálohové a proforma faktúry, čiastkové dobropisy, režim platiteľa DPH, QR platba (M8).

**Anti-features:** opakované faktúry, automatické upomienky, časomerač, napojenie na banku, online platby, klientsky portál, úprava a mazanie vystavených faktúr, návrhár šablón, offline dáta.

### Architektonický prístup

Monolit v CI4 zoskupený podľa doménových kontextov (`app/Domain/<Kontext>`) s tenkými kontrolérmi. Do `invoices*` zapisuje iba `InvoiceIssuer`/`CreditNoteIssuer`. Stavy sa neukladajú, ale odvodzujú SQL pohľadmi, takže `invoices` je plne insert-only. Reporty a limity sčítavajú denormalizované stĺpce zapísané pri vystavení (`total_entity_ccy`, `counts_toward_limit`, `turnover_date`), nikdy JSONB ani živé nastavenia klienta. Kurz je vstup príkazu, načítaný v náhľade, nie sieťové volanie v transakcii.

**Komponenty:**
1. **Identity:** Shield + akcia `Totp`, záložné kódy, `trusted_devices`, `login_log`.
2. **MasterData:** subjekty (insert-only verzie), klienti, `legal_texts`, `RateProvider`.
3. **Work:** úlohy a `billing_allocations` (zámok = stĺpec `invoice_id`, strážený SQL podmienkou a triggerom).
4. **Invoicing:** `InvoiceIssuer`, `CreditNoteIssuer`, `InvoiceCalculator`, `SnapshotBuilder`, `NumberAllocator`.
5. **Documents:** mPDF zo snapshotu, `invoice_documents` (`sha256`, `template_version`).
6. **Delivery, Receivables, Reporting, Audit, Platform:** `AuditLogger` zapisuje v tej istej transakcii ako zmena.

### Kritické pasce

1. **Float a rozdielne zaokrúhľovanie.**
   - Jedna trieda `Money`/`InvoiceCalculator`.
   - Riadok = `round_half_up(hodiny × sadzba, 2)`, súčet = súčet zaokrúhlených riadkov.
   - Sumy v JSONB ako reťazce, Alpine prepočet len ako náhľad.
   - Property test „obrazovka = výkaz = faktúra = export = dashboard“.
2. **Únik nemennosti.**
   - PDF sa pri vystavení vygeneruje raz a uloží, „znova odoslať“ servíruje uložené bajty.
   - Kópia obsahuje preložené popisky, zákonné texty a URL portálu.
   - Pinnuté fonty, DB oprávnenia a triggery.
   - Povinný test „zmeň všetko po vystavení a porovnaj bajty“.
3. **Medzery a súbeh v číslovaní.**
   - Žiadna `SEQUENCE`/`MAX()+1`.
   - Riadok čítača `(series, period_key)` v transakcii vystavenia, číslo ako posledný krok, `UNIQUE (entity_id, number)` ako poistka.
   - Test s dvoma samostatnými spojeniami (50 súbežných vystavení bez diery).
4. **Limity a kurzy sú právne jemnejšie než „súčet za rok“.**
   - `TurnoverCalculator` so stratégiami `calendar_year`, `rolling_12m`, `pro_rata_first_year` a prahmi z tabuľky.
   - Kurz cez NBP rozsah, nepísať vlastný kalendár sviatkov.
   - Smer a jednotky kurzu uložené, súčet prepočítaný raz.
5. **TOTP nad Shieldom a iOS PWA.**
   - Pending stav nesmie dať prístup, replay ochrana (`last_used_step`), per-user throttling bez trvalého zámku.
   - Reset hesla nesmie obísť TOTP.
   - Perzistentná session cookie, `sw.js` bez cache, HTML nikdy v SW cache.
   - `Vary: HX-Request`, CSRF bez regenerácie pri každej požiadavke.
   - Test na reálnom iPhone od fázy 2.

Ďalšie: Clock a časové pásma, zálohy bez testu obnovy, mPDF pamäť, SPF/DKIM/DMARC, import z Excelu (dry-run, hyperlinky), CSV injection.

## Rozpory medzi researchermi a odporúčané rozhodnutia

1. **Číslovanie.**
   - STACK: `SELECT … FOR UPDATE` na riadok čítača.
   - ARCHITECTURE: `INSERT … ON CONFLICT DO NOTHING`, potom `UPDATE … RETURNING`.
   - PITFALLS: jeden `INSERT … ON CONFLICT DO UPDATE … RETURNING`.
   - **Rozhodnutie:** jeden príkaz `INSERT … ON CONFLICT (series_id, period_key) DO UPDATE SET last_value = last_value + 1 RETURNING last_value`.
   - Dôvod: pokrýva prvé číslo obdobia, kde `FOR UPDATE` na neexistujúci riadok nič nezamkne. Zámok drží do COMMIT a rollback vráti čítač.
   - Prvé číslo radu je z `initial_number`. `SEQUENCE` je zakázaná.
2. **Uložené PDF.**
   - Spec: cache.
   - ARCHITECTURE: eager generovanie, kanonický artefakt so `sha256`.
   - PITFALLS: autoritatívny artefakt, zvážiť `bytea`, render vo vnútri transakcie.
   - **Rozhodnutie:** PDF je autoritatívny artefakt (`invoice_documents`: `sha256`, `template_version`, verzia mPDF). Bez toho nie je G4 „bit po bite“ splniteľné pri aktualizácii mPDF/ICU.
   - Je to odchýlka od spec (platí spec), takže **vyžaduje potvrdenie vlastníka**.
   - Úložisko: odporúčam `bytea` (atomické s transakciou aj `pg_dump`). Súbory na disku sú alternatíva.
   - Moment generovania: odporúčam render ako posledný krok vo vnútri transakcie (≤2 s, jeden používateľ, žiadna faktúra bez PDF, rollback čísla pri zlyhaní). ARCHITECTURE preferuje po commite s opakovaním.
   - Rozhodnúť po mPDF spike-u vo fáze 1. E-mail a HTTP nikdy v transakcii.
3. **SK limit: kalendárny rok vs kĺzavých 12 mesiacov.**
   - Spec: 50 000 € za kalendárny rok.
   - FEATURES: zdroje sa rozchádzajú (50 000 € rok vs 49 790 € kĺzavo). Prah 62 500 € sa zhoduje.
   - PITFALLS: podľa znalostí kĺzavo.
   - **Rozhodnutie:** implementovať obe stratégie (`calc_method`) a do potvrdenia účtovníčkou zobrazovať prísnejšiu hodnotu spolu so vzorcom. Neseedovať jednu hodnotu ako pravdu. Blokuje fázu 8.
4. **Krajina a mena subjektu: nemenné vs verziované.**
   - Spec ich vymenúva medzi verziovanými poľami.
   - ARCHITECTURE navrhuje `entities.country` a `entities.currency` nemenné a formát čísla na `invoice_series`.
   - **Rozhodnutie:** nemenné. Zmena meny by rozbila obrat, limity aj kurzy.
   - Odchýlka od spec, takže **vyžaduje potvrdenie vlastníka** pred návrhom schémy vo fáze 3.
5. **Stav faktúry.** Insert-only `invoices` s odvodeným `invoice_status_v` (ARCHITECTURE), nie trigger s povolenými stĺpcami (PITFALLS).
6. **Zálohy.**
   - STACK: restic so `forget --prune` zo servera.
   - PITFALLS: append-only kľúč a prune z iného miesta.
   - **Rozhodnutie:** restic ostáva. Prune spúšťať mimo produkčného servera alebo s oddeleným kľúčom.
   - Overiť u Hetzner, čo Storage Box umožňuje. Kópia hesla repozitára a `encryption.key` mimo servera.
7. **Dátum kurzu.** Konfigurovateľný v právnom profile, predvolene dátum dodania (M15). Do snapshotu `effectiveDate`, číslo tabuľky a základ.
8. **Presnosť kurzu.** STACK `numeric(18,8)` vs PITFALLS `numeric(14,6)`. Zvolené `numeric(18,8)` a uložené zdroj, smer, jednotky a dátum platnosti.
9. **Knižnica na peniaze.** bcmath (STACK) vs `brick/math`. Rozhodnúť vo fáze 1. Odporúčam bcmath, ak `bcround` (PHP 8.4+) pokryje half-up. Dôležitá je jedna trieda `Money`.
10. **CSRF s htmx.** `regenerate=false` (token viazaný na reláciu) plus `hx-headers` na `<body>`. Názvy konfigurácie overiť v 4.7.
11. **PHP-FPM pool.** STACK `ondemand`/max 5, ARCHITECTURE `static`/3. Štart `ondemand` s max 3 až 5, zmerať cold-start proti p95 < 100 ms. Samostatný pool pre PDF s `memory_limit` 192 až 256 MB.
12. **Dobropis a obrat.** Odpočítať v období pôvodnej faktúry (obaja researcheri sa zhodujú). Potvrdiť účtovníčkou.

## Implications for Roadmap

Navrhnutých je 9 fáz, zlúčených z ARCHITECTURE (fázy 0–8) a PITFALLS (F0–F10).

### Fáza 1: Základ a infraštruktúra
**Rationale:** konvencie sa dodatočne opravujú draho (numeric ako string, `lang()` všade, Clock, DB role).
**Delivers:**
- Docker Compose, CI4 a migrácie, DB roly `app_owner`/`app_rw`.
- Layout a Bootstrap, `Money`, `Clock` (jedna TZ), `AuditLogger`.
- htmx vzory (`Vary`, 422, CSRF, toast pri chybe), CSP, `.gitattributes` s LF.
- Testovacia DB na PostgreSQL, spike mPDF s diakritikou pod PHP 8.5 (`E_ALL`).

**Avoids:** pasce 1, 7, 11, 13, 14, 18, 19.

### Fáza 2: Identita a skoré nasadenie
**Rationale:** bez prihlásenia sa nič nesmie vystaviť verejne. iOS PWA a cookies sa dajú overiť len na HTTPS. R4 je najväčšie technické neznáme.
**Delivers:**
- Shield 1.4.1 a akcia `Totp`: povinný enrollment, záložné kódy, trusted devices 30 dní, throttling, `login_log`, `last_used_step`.
- Roly `admin`/`accountant`, PWA kostra (`sw.js` no-cache, manifest, offline stránka).
- Staging na Hetzner s Let's Encrypt, test na reálnom iPhone.

**Avoids:** pasce 9, 10.

### Fáza 3: Master dáta a nemenné vzory
**Rationale:** subjekt a klient sú vstupom snapshotu. Tu sa prvýkrát použije vzor „GRANT + insert-only“.
**Delivers:**
- `entities` a insert-only `entity_versions`: VAT-UE, viac bankových účtov podľa meny, miesto vystavenia, údaj o zápise.
- `invoice_series`, klienti (typ ID s prefixom, `billing_mode`, `counts_toward_limit`).
- `legal_texts` seed SK/PL s kódmi dôvodu (`ART_113_1`, `ART_28B_RC`), schéma `turnover_limits`, audit.

**Addresses:** M2, M3, M4, M5.
**Avoids:** pascu 7.

### Fáza 4: Úlohy, časti a import
**Rationale:** časti sú vstupom fakturácie. Import dáva reálne dáta na testovanie.
**Delivers:**
- CRUD úloh, `billing_allocations` (atómový `UPDATE … WHERE invoice_id IS NULL` a trigger).
- Dialóg Zapracované, odvodený stav úlohy, dashboard (variant B).
- `spark import:excel` (dev-only PhpSpreadsheet, dry-run, rozdiel proti Excel súčtom, hyperlinky, idempotentný kľúč).

**Avoids:** pasce 6, 15.

### Fáza 5: Fakturačné jadro
**Rationale:** srdce produktu a najdôležitejšia fáza. Rieši sa izolovane bez FX.
**Delivers:**
- `InvoiceCalculator`, `NumberAllocator`, `SnapshotBuilder`, `InvoiceIssuer` (M16).
- Kód DPH na položke (M1), insert-only `invoices` s GRANT.
- `invoice_documents` so `sha256` (M14), náhľad bez čísla.
- Schéma pripravená na dobropis, test súbehu na dvoch spojeniach, property test súčtov, test „zmeň všetko po vystavení“.
- Skript zálohy už tu.

**Avoids:** pasce 1, 4, 5, 6, 20.

### Fáza 6: Dokumenty, doručenie, úhrady
**Rationale:** závisí od hotového snapshotu. Je to prvý použiteľný produkt pre SK.
**Delivers:**
- PDF faktúra a výkaz zo snapshotu, právne profily SK neplatiteľ, PL art. 113 a EÚ B2B.
- E-mail so SPF/DKIM/DMARC, BCC archívom a `Message-ID`, opätovné odoslanie.
- Úhrady vrátane viacerých na faktúru (M7), po splatnosti, ručná upomienka.
- Forma úhrady a poznámka o úrokoch (M13), `X-Accel-Redirect` pre PDF.

**Avoids:** pasce 4, 13, 17.

### Fáza 7: Meny a kurzy
**Rationale:** samostatná kvôli externým API. Potrebná pred PL subjektom.
**Delivers:**
- `RateProvider` pre NBP (rozsah), ECB (delenie), ČNB (`amount`) a ručný prepis.
- Kurz v náhľade, uloženie do snapshotu, prepočet súčtu jedným kurzom, PDF s dvoma sumami.
- Konfigurovateľný základ dátumu (M15).

**Avoids:** pasce 2, 3.

### Fáza 8: Dobropis, limity, prehľady, exporty
**Rationale:** závisí od faktúr a kurzov. Limity potrebujú odpovede účtovníčky.
**Delivers:**
- `CreditNoteIssuer`: celé storno, vlastný rad, dôvod, odkaz na originál (M6).
- `TurnoverCalculator`: stratégie, predkontrola (M9), kĺzavé okno (M10), pomerný prvý rok PL.
- Prehľady, CSV (`;` + BOM) a XLSX s ochranou pred injection, ZIP pre účtovníčku (M11).
- Audit viewer, upomienky.

**Avoids:** pasce 8, 16, 20.

### Fáza 9: Lokalizácia a produkcia
**Rationale:** zálohy a obnova treba preskúšať s reálnymi dátami pred ostrým používaním.
**Delivers:**
- Preklady PL/CZ/EN s testom parity kľúčov.
- Backup sidecar (restic) s ročným archívom (M12), automatizovaný test obnovy a runbook, dead man's switch.
- Hardening, meranie pamäte a p95 na cieľovom VPS, CLI reset 2FA.

**Avoids:** pasce 12, 14, 17.

### Dôvody poradia

- Závislosti:
  - Identita je pred všetkým.
  - Master dáta sú pred snapshotom.
  - Časti sú pred faktúrou.
  - Faktúra je pred PDF a dobropisom.
  - Kurzy sú pred PL subjektom.
  - Dobropis je pred limitmi.
- Riziko je izolované: jadro bez FX, kurzy samostatne, právne citlivé limity na konci.
- Dopredu sa ťahá to, čo sa nedá opraviť neskôr: M1–M5, M14, dobropisová štruktúra, `lang()`, skorý staging.
- Dlhé čakacie lehoty sa spúšťajú paralelne od fázy 3: otázky pre účtovníčku a voľba SMTP poskytovateľa.

### Research Flags

**Potrebujú research-phase:**
- **Fáza 2:** správanie Shield (pending stav, remember-me, `ConditionalActionInterface`), iOS cookies. Spike s časovým limitom a fallback.
- **Fáza 5:** zámky a atómové vystavenie, PDF v DB vs súbor a vo vnútri vs po transakcii, `transException` v 4.7, determinizmus mPDF.
- **Fáza 6:** právne profily (účtovníčka), SMTP poskytovateľ a SPF/DKIM, mPDF fonty a pamäť.
- **Fáza 7:** SDMX parametre ECB, limit rozsahu NBP, časy publikácie.
- **Fáza 8:** limity SK a PL, dobropis a obrat, EÚ B2B vs limit (účtovníčka).

**Štandardné vzory (research-phase preskočiť):**
- **Fáza 1:** okrem mPDF spike-u.
- **Fáza 3.**
- **Fáza 4.**
- **Fáza 9:** overiť len Storage Box.

## Gapy v PRD (M1–M16) a odporúčanie

V1 = P0+P1+P2 podľa PROJECT.md, takže „v1“ znamená zahrnúť do V1 v uvedenej fáze. Nič z M1–M16 nie je mimo rozsahu, jediná položka na odloženie je M8.

| # | Medzera | Odporúčanie | Fáza |
|---|---------|-------------|------|
| M1 | Kód DPH na položke | **v1** (stĺpec od prvej migrácie, bez výpočtov platiteľa) | 5 |
| M2 | VAT-UE / NIP UE subjektu | **v1** | 3 |
| M3 | Typ ID klienta + prefix krajiny | **v1** | 3 |
| M4 | Viac bankových účtov + SWIFT | **v1** | 3 |
| M5 | Právny dôvod oslobodenia ako pole | **v1** | 3 a 5 |
| M6 | Dobropis: dôvod, odkaz, dátum, typ | **v1** (schéma vo fáze 5) | 8 |
| M7 | Čiastočná úhrada, viac úhrad | **v1** (odvodený stav zo `SUM(payments)`) | 6 |
| M8 | QR platba | **odložiť na v2** (stretch vo fáze 8, ak zostane kapacita; jeden klient, UX nie zákon, PAY by square potrebuje LZMA) | — |
| M9 | Predkontrola limitu | **v1** | 8 |
| M10 | Kĺzavé 12-mesačné okno SK | **v1** (strategy v `TurnoverCalculator`) | 8 |
| M11 | ZIP pre účtovníčku | **v1** | 8 |
| M12 | Retencia dokladov | **v1 ako politika a ročný archív** (bez DELETE oprávnení, doba podľa účtovníčky) | 9 |
| M13 | Forma úhrady, úroky z omeškania | **v1** | 6 |
| M14 | Uložené PDF + hash | **v1, kritické** | 5 |
| M15 | Konfigurovateľný dátum kurzu | **v1** (voľba v právnom profile, predvolene dodanie) | 7 |
| M16 | Atómové vystavenie | **v1, kritické** | 5 |

**v2+:** FA(3) a KSeF export, rola `accountant` v plnom UI (model a roly vo V1), VIES, register klientov, zálohové faktúry, čiastkové dobropisy, režim platiteľa DPH.

## Otvorené otázky

### Pre účtovníčku (poslať pri štarte fázy 3, blokujú fázy 6 a 8)

1. **SK limit:** 50 000 € za kalendárny rok alebo 49 790 € za kĺzavých 12 mesiacov? Čo sa do obratu počíta? Potvrdiť 62 500 €.
2. **PL limit 240 000 zł:** dátum vzniku povinnosti (dodanie, vystavenie, záloha, úhrada), pomerný prvý rok (dni vrátane dňa začatia, základ 365), čo sa nezapočítava.
3. **PL subjekt, EÚ B2B:** zákonná poznámka (reverse charge podľa art. 28b alebo art. 113), povinnosť VAT-UE a `PL`+NIP pri zwolnieniu podmiotowym, započítanie do 240 000 zł (predvolené „áno“ je konzervatívne), kód `np II`.
4. **SK neplatiteľ fakturujúci PL klientovi:** bez DPH a bez reverse charge, tržba sa započítava do limitu SK.
5. **Číslovanie:** musí byť poradie chronologické voči dátumu vystavenia, smie sa spätne dátumovať, smie sa meniť formát v strede roka?
6. **Kurz:** dátum, od ktorého sa hľadá (dodanie vs vystavenie, SK a PL), prepočet súčtu vs riadkov, pravidlo zaokrúhľovania.
7. **Retencia:** SK 10 rokov, PL 5 rokov. Stačí záloha 14/8/12 plus ročný archív?
8. **Dobropis a obrat:** odpočítať v období pôvodnej faktúry alebo dobropisu?
9. **Obsah dokladov:** znenie zákonných textov a náležitostí (§ 3a ObZ, údaj o zápise, art. 106e, art. 113 ust. 1 vs 9), potrebnosť podpisového záznamu (§ 10 zákona 431/2002).
10. **PL dobropis:** povinnosť `PrzyczynaKorekty` a číslo KSeF pôvodnej faktúry.

### Pre vlastníka (blokujú návrh schémy)

11. Potvrdiť odchýlky od spec: (a) PDF ako autoritatívny artefakt, (b) `country` a `currency` nemenné na subjekte. Do potvrdenia platí spec.
12. Definícia „vyfakturovaná“ úlohy. Návrh: zapracovaná, bez nezamknutej časti a aspoň jedna zamknutá. Inak „čiastočne“.
13. SMTP poskytovateľ (R6) a DNS pre odosielateľov SK a PL, pred fázou 6.
14. Hetzner plán a architektúra (x86 vs ARM CAX), podmienky Storage Box (port, append-only).
15. Majú QR platbu klienti vyžadovať (M8)?
16. Spätné dátumovanie: povoliť s varovaním (návrh) alebo zakázať.

## Confidence Assessment

| Oblasť | Istota | Poznámky |
|--------|--------|----------|
| Stack | HIGH | Verzie overené cez Packagist, npm, endoflife.date, Docker Hub a živé volania NBP/ECB/ČNB. MEDIUM: ladenie pamäte, hyperlinky v OpenSpout, detaily Storage Box |
| Features | MEDIUM | Konkurencia z tréningových znalostí. PL limit a FA(3) overené webom. SK limit sa rozchádza v zdrojoch. Právne náležitosti neoverené na texte zákona |
| Architecture | MEDIUM-HIGH | Doménové vzory a PostgreSQL techniky HIGH. Shield a CI4 4.7 API MEDIUM, overiť na nainštalovaných verziách |
| Pitfalls | MEDIUM | Znalosť domény bez živého overenia. Položky `[overiť]` ostávajú otvorené |

**Overall:** MEDIUM. Technická stránka je pevná. Nejasnosti sú právne (účtovníčka) a niekoľko integračných detailov, ktoré rozhodnú spiky.

### Gapy

- Právne otázky 1–10: seed limitov ani `legal_texts` neberať ako pravdu bez potvrdenia.
- Shield + TOTP (R4): spike vo fáze 2. Fallback: Shield pre používateľov a heslá, TOTP vo vlastnom filtri (`totp_pending`), až potom vlastné prihlasovanie.
- mPDF na PHP 8.5 a diakritika (ł, ń, ě, ť): spike vo fáze 1, zmerať čas a pamäť.
- PDF v DB vs na disku a vo vnútri vs po transakcii: rozhodnúť pred fázou 5 s vlastníkom.
- Pamäť 512 MB: štartovné čísla, merať `docker stats` už na stagingu a vo fáze 9.
- Hetzner plány a Storage Box: overiť pri objednávke.
- SDMX ECB a limit rozsahu NBP: overiť pri fáze 7.
- PHPUnit: držať pin `^10.5.16`, overiť podporu vyššej línie pre `CIUnitTestCase`.
- FA(3) XSD neprečítaná: overiť až pri budúcej fáze KSeF.

## Sources

- **Primary (HIGH):**
  - Packagist, php.net, endoflife.date, npm registry, GitHub releases, Docker Hub.
  - Shield `auth_actions.md` a `ActionInterface.php`.
  - Živé volania NBP, ECB (`eurofxref`, `data-api`) a ČNB (`api.cnb.cz`).
  - Analýza `docs/All4net-fakturovanie-prace.xlsx`.
  - Spec, PRD a PROJECT.md (pri rozpore platí spec).
- **Secondary (MEDIUM):**
  - infor.pl a Sejm (druk 1232): PL limit 240 000 zł.
  - aksamec.sk, pravnenoviny.sk, sita.sk: SK prahy DPH.
  - akademialtca.pl, fakturowo.pl, firmove.pl: štruktúra FA(3).
  - CI4 user guide, Shield docs, `michalsn/codeigniter-htmx`, dokumentácia htmx a PostgreSQL.
- **Tertiary (LOW):**
  - Konkurenčné produkty z tréningových znalostí.
  - Zákonné náležitosti (431/2002, art. 106e, 106j, 28b, 113) neoverené na texte zákona.
  - Ladenie PG/FPM/OPcache a správanie iOS PWA, treba zmerať a testovať.
  - beancount.io: zamietnutie zmeny SK limitu na 85 000 €, jeden zdroj.

---
*Výskum dokončený: 2026-10-23*
*Pripravené pre roadmap: áno*
