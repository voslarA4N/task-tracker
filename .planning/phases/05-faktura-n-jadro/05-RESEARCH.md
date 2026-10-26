# Phase 5: Fakturačné jadro - Research

**Researched:** 2026-10-26
**Domain:** atómové vystavenie nemennej faktúry v CodeIgniter 4.7 + PostgreSQL 18 (číslovanie bez medzier, insert-only schéma, JSONB snapshot, uložené PDF so sha256, mPDF 8.3.1)
**Confidence:** HIGH (kód fáz 1 až 4 prečítaný, kľúčové PG správanie a bytea cez CI4 driver overené živo v dev kontajneroch 2026-10-26)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/`, bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Jediná nová otázka bola WR-07 (D-22).

**Obrazovka „Fakturácia mesiaca“ (INV-01, INV-15)**
- **D-01:** Používateľ vyberie klienta, fakturačný mesiac a subjekt. Subjekt sa predvyplní podľa dátumu vystavenia (subjekt aktívny k tomu dátumu, Phase 3 D-07) a dá sa zmeniť, aj na prelome dvoch subjektov. Ukončený subjekt sa pre novú faktúru neponúka.
- **D-02:** Náhľad ukazuje nezamknuté časti klienta s daným mesiacom, riadky, súčet, dátumy, subjekt, bankový účet a menu. **Náhľad nemá číslo** a nič nezapisuje. Vzniká tým istým `SnapshotBuilder` + `InvoiceCalculator` v režime „preview“ ako vystavenie, takže čo sa zobrazí, to sa vystaví (výskum ARCHITECTURE).
- **D-03:** Časť sa dá z náhľadu vyradiť presunom do iného mesiaca (predvolene nasledujúci). Presun ide cez existujúci `AllocationService` (atómové `WHERE invoice_id IS NULL`, audit). Náhľad sa obnoví cez htmx.
- **D-04:** Predvolené dátumy (INV-02, spec 6.5): vystavenie = dnes (`Clock`), dodanie = posledný deň fakturačného mesiaca, splatnosť = vystavenie + `due_days` klienta, inak `due_days` verzie subjektu. Všetky tri sa dajú pred vystavením upraviť.
- **D-05:** Spätný dátum vystavenia je povolený (INV-15). Ak je skorší ako dátum vystavenia poslednej faktúry v rade, náhľad zobrazí varovanie a vystavenie vyžaduje výslovné potvrdenie. Kontrolu robí server, nie iba UI.
- **D-06:** Vystavenie je chránené proti dvojitému odoslaniu (dvojklik, opakovaný htmx request): druhý pokus nevytvorí druhú faktúru, vráti 409 so správou (časti sú už zamknuté) alebo presmeruje na už vystavenú faktúru. Po úspechu `HX-Location` na detail faktúry (vzor Phase 4 D-15).

**Položky a sumy (INV-02, INV-09)**
- **D-07:** Jedna položka za každú časť: názov úlohy, hodiny, sadzba, suma, kód DPH. Položka si nesie aj `source_allocation_id` a `source_task_id` (bez FK, výskum ARCHITECTURE), URL portálu a nacenené hodiny úlohy, aby z nich Phase 6 vedela urobiť výkaz bez čítania živých tabuliek.
- **D-08:** Suma riadku = `round(hours × rate, 2)` half-up cez `Money`, súčet = súčet zaokrúhlených riadkov. Je to jediné miesto výpočtu (`InvoiceCalculator`), ktoré používa náhľad, vystavenie, PDF aj testy. Pravidlo zaokrúhlenia sa zapíše do snapshotu.
- **D-09:** Kód DPH na položke (M1) sa predvolí z právneho profilu (krajina, DPH režim, režim fakturácie). Pre PL art. 113 je to `zw`. Hodnota pre SK neplatiteľa sa seeduje **predbežne** a potvrdí ju účtovníčka (riziko R1). Výpočty pre platiteľa DPH sa neimplementujú.
- **D-10:** Mena faktúry = mena častí (mena klienta). Server overí, že všetky časti majú rovnakú menu. **Ak sa mena faktúry líši od meny subjektu, vystavenie sa v tejto fáze odmietne** so zrozumiteľnou správou. Kurzy pridá Phase 8. Stĺpce pre kurz (sadzba, zdroj, dátum, číslo tabuľky, základ dátumu M15) sú v schéme už teraz, s hodnotou NULL.

**Číslovanie (INV-04, INV-14)**
- **D-11:** Číslo sa pridelí jedným príkazom `INSERT … ON CONFLICT DO UPDATE … RETURNING` na tabuľke čítačov (rad × obdobie podľa `SeriesFormat::periodKey()` z dátumu vystavenia), v tej istej transakcii ako vystavenie. Nikdy `SEQUENCE` ani `MAX()+1` (ROADMAP).
- **D-12:** Mesiac a rok v čísle sa berú z dátumu vystavenia (spec 6.5). Reset poradia je mesačný, ak formát obsahuje `MM`, inak ročný. `initial_number` radu platí pre prvé číslo radu (rad, ktorý ešte nemá žiadny doklad). Ďalšie obdobia začínajú od 1. Ak by poradie prekročilo `maxSequence()`, vystavenie sa odmietne.
- **D-13:** `SeriesService::isFormatLocked()` (dnes vždy `false`) začne vracať `true`, keď rad obsahuje vystavený doklad (Phase 3 D-10).
- **D-14:** Akceptačný príklad: prvá faktúra 10/2026 = `202610001`, druhá `202610002`, prvá v 11/2026 = `202611001`. 50 súbežných vystavení na dvoch spojeniach nevytvorí duplicitné ani preskočené číslo. Zlyhanie ktoréhokoľvek kroku vráti číslo aj uvoľní časti.

**Atómové vystavenie a PDF (INV-13, INV-14)**
- **D-15:** Jeden zapisovateľ `InvoiceIssuer::issue()`. V jednej transakcii zamkne časti (`FOR UPDATE`, poradie podľa id, kontrola počtu, poradie zámkov z Phase 4: najprv úloha, potom časť), vypočíta sumy, postaví snapshot, pridelí číslo, vloží faktúru a riadky, nastaví `invoice_id` na častiach, uloží PDF dokladu a zapíše audit.
- **D-16:** **PDF je autoritatívne** (vlastník potvrdil): vygeneruje sa raz pri vystavení zo snapshotu, uloží sa so `sha256` a verziou šablóny a zobrazenie vždy vráti uložené bajty, nikdy neregeneruje. Commitnutá faktúra **vždy** má svoje uložené PDF. Faktúra bez PDF ani PDF bez faktúry nesmie zostať. Mechanizmus (PDF v `bytea` vs súbor + riadok v transakcii s upratovaním, render vnútri transakcie vs pred commitom, vplyv na dĺžku zámku čítača) určí výskum. Výber musí pokryť zálohy.
- **D-17:** PDF v tejto fáze je funkčné minimum: čitateľná faktúra zo snapshotu (subjekt, klient, číslo, dátumy, položky, súčet, platobné údaje, zákonný text zo snapshotu). Šablóna má verziu (napr. `inv-1`). Finálny vzhľad, právne profily a QR sú Phase 6. Výkaz práce (REP-01) je Phase 6, schéma dokladov však rozlišuje druh (`invoice | report`).

**Kópia údajov a nemennosť (INV-03, INV-08, ALC-03)**
- **D-18:** Snapshot (JSONB, verzovaný cez `snapshot_schema`) je **samostatne postačujúci** na vykreslenie faktúry aj budúceho výkazu: verzia subjektu (pripnutá cez `entity_version_id`, verzia platná k dátumu vystavenia), klient, mena, jazyk dokladu (klient, inak subjekt), DPH režim, režim fakturácie, kód oslobodenia, **vyriešené** zákonné texty v jazyku dokladu (nie odkaz na `legal_texts`), preložené popisky, URL portálu, bankový účet podľa meny (`BankAccountSelector`), kurz (zatiaľ NULL) a riadky. Chýbajúci zákonný text vystavenie zablokuje, nikdy ticho prázdny text (Phase 3 D-17).
- **D-19:** Štruktúrované stĺpce (INV-08, KSeF FA(3)) obsahujú všetky polia M1–M16 použiteľné v tejto fáze: kód DPH na riadku (M1), VAT-UE subjektu (M2), typ identifikátora klienta (M3), bankový účet (M4), kód oslobodenia (M5), pole pre dobropis (`corrected_invoice_id`, dôvod, typ opravy; M6, zatiaľ NULL), forma úhrady s predvolenou hodnotou „prevod“ a voliteľná poznámka (M13), `sha256` a verzia šablóny (M14), kurzové stĺpce (M15), a denormalizované `total_entity_ccy`, `counts_toward_limit` (z klienta k dátumu vystavenia), `turnover_date`, `client_id`. Prehľady a limity budú čítať tieto stĺpce, nie JSONB.
- **D-20:** `invoices` a `invoice_lines` sú pre `app_rw` **insert-only** (GRANT SELECT, INSERT, bez UPDATE/DELETE/TRUNCATE). Stav faktúry sa neukladá, odvodzuje sa (pohľad `invoice_status_v`, výskum ARCHITECTURE). Testy bežia aj pod rolou `app_rw` (ROADMAP kritérium 5).
- **D-21:** Zámok časti (ALC-03): `billing_allocations.invoice_id` dostane FK na `invoices`. Server odmieta úpravu zamknutej časti (už z Phase 4) a trigger `alloc_guard` ju odmieta v DB. Zamknutá časť v UI ukazuje číslo faktúry.

**Historické externé faktúry (WR-07)**
- **D-22:** Časti importované z riadkov „Fakturované = ÁNO“ (dev DB: 09/2026, 11 častí, 89 h, 2 403,00 €) sa zamknú na **historickú externú faktúru** (vlastník 2026-10-26, voľba C a „externé číslo voliteľné“):
  - druh záznamu „externá“, **bez čísla z vlastného radu** (nespotrebuje `RRRRMM###`), s voliteľným textovým číslom alebo odkazom na doklad tretej osoby,
  - bez PDF, nezapočítava sa do obratu, limitov ani prehľadov vlastných faktúr (`counts_toward_limit = false`),
  - v zozname faktúr je zreteľne označená ako externá,
  - je rovnako nemenná (insert-only) a s auditom,
  - vzniká jednorazovým idempotentným `spark` príkazom (beh v dev aj na produkcii po importe reálnych dát), **pred prvým ostrým vystavením**.
  Schéma `invoices` musí tento druh povoliť bez oslabenia obmedzení pre vlastné faktúry (napr. `CHECK`, ktorý vyžaduje číslo a rad len pri vlastnej faktúre a dobropise).

**Testy (NFR-06)**
- **D-23:** PHPUnit pokrýva: výpočet súm (zhoda náhľad = faktúra = snapshot = súčet riadkov), číslovanie vrátane súbehu (50 vystavení na dvoch spojeniach) a resetov obdobia, rollback pri zlyhaní neskoršieho kroku, zamykanie častí (server aj DB pod `app_rw`), nemennosť `invoices*` pod `app_rw`, kópiu údajov a test „zmeň subjekt, klienta, sadzbu a menu po vystavení a porovnaj bajty PDF a `sha256`“.

**Zálohy**
- **D-24:** ROADMAP: „skript zálohy sa začína už tu“. V tejto fáze vznikne základný skript (`pg_dump --format=custom` + uložené PDF dokladov, ak sú na disku) s dokumentáciou v `docs/ops/`. Restic, Storage Box, rotácia 14/8/12 a test obnovy sú Phase 12.

### Claude's Discretion
Vlastník nechal na plánovača a výskum (môžu spresniť, nie obrátiť):
- Technické otázky z ROADMAP pre výskum: PDF v `bytea` vs súbor, render vnútri transakcie vs pred commitom, `transException` v CI4 4.7, determinizmus mPDF (rovnaké bajty pri opakovanom vykreslení nie sú potrebné, keďže sa neregeneruje, ale treba vedieť, či ID/dátum v PDF metadátach nezávisia od času mimo `Clock`).
- Predvolený mesiac na obrazovke (odporúčanie: najstarší mesiac s nezamknutými časťami vybraného klienta, inak predchádzajúci mesiac) a predvolený klient (posledný použitý, vzor `LastClient`).
- Poradie položiek na faktúre (napr. podľa dátumu vytvorenia úlohy, potom id časti).
- Rozsah zoznamu a detailu faktúry v tejto fáze: minimálny zoznam (záložka „Faktúry“ v spodnej lište) a detail s tlačidlom na zobrazenie uloženého PDF. Plné PC tabuľky a filtre sú Phase 11.
- Názvy tabuliek a tried podľa výskumu ARCHITECTURE (`InvoiceIssuer`, `InvoiceCalculator`, `SnapshotBuilder`, `NumberAllocator`, `DocumentStore`, `invoice_counters`, `invoice_documents`), presný tvar snapshotu a jeho JSON schéma.
- Text varovaní, tlačidiel a toastov, forma potvrdenia vystavenia na mobile (panel zospodu).

### Deferred Ideas (OUT OF SCOPE)
- Predkontrola limitu obratu v náhľade (M9): Phase 10.
- Faktúra v inej mene, ako je mena subjektu, s kurzom NBP/ECB/ČNB: Phase 8.
- Finálne PDF, právne profily, QR platba, výkaz práce: Phase 6.
- Odoslanie e-mailom, úhrady, stav „čiastočne uhradená“ (M7): Phase 7.
- Dobropis a uvoľnenie častí (výnimka v `alloc_guard`): Phase 9.
- PC tabuľky faktúr, filtre, exporty: Phase 11.
- Restic, Storage Box, rotácia a test obnovy: Phase 12.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INV-01 | Fakturácia mesiaca: výber klienta/mesiaca/subjektu, náhľad bez čísla, vyradenie časti | `SnapshotBuilder` + `InvoiceCalculator` v režime preview, `preview_token`, `AllocationService::update` na presun, vzor htmx z Phase 4 |
| INV-02 | Položka za časť, dátumy, splatnosť, VS = číslo | `invoice_lines` schéma, predvolené dátumy z `Clock`/`BillingMonth`, `due_days` klient, inak verzia subjektu |
| INV-03 | JSONB kópia údajov, nemennosť v DB | snapshot v1, `entity_version_id` pin, insert-only GRANT + `immutable_guard` triggery |
| INV-04 | Číslovanie `RRRRMM###`, bez medzier, bezpečné voči súbehu | `invoice_counters` + `INSERT … ON CONFLICT DO UPDATE … RETURNING` (overené), zámok `invoice_series`, test na 2 procesoch |
| INV-08 | Štruktúrované stĺpce pre KSeF FA(3) | sekcia Schema: stĺpce M1–M16 |
| INV-09 | Kód DPH na položke | `legal_profiles.vat_code` (nová migrácia, predbežné hodnoty) |
| INV-13 | PDF autoritatívne, sha256, verzia šablóny | `invoice_documents.content bytea` + `CHECK (sha256 = encode(sha256(content),'hex'))`, render vnútri transakcie |
| INV-14 | Atómové vystavenie | `Transaction::run`, poradie zámkov, deferred constraint trigger, test rollbacku |
| INV-15 | Spätný dátum s varovaním | server-side kontrola `MAX(issue_date)` po zámku radu + `confirm_backdate` |
| ALC-03 | Zamknutá časť odkazuje na faktúru, server aj DB odmietnu úpravu | FK `invoice_id`, SECURITY DEFINER `lock_allocations()`, rozšírený `alloc_guard` |
| NFR-06 | PHPUnit pre sumy, číslovanie, zamykanie, kópiu údajov | sekcia Validation Architecture |
</phase_requirements>

## Summary

Fáza je najrizikovejšia, ale väčšina základov už existuje: `Transaction::run` (vnoriteľná transakcia s `transException`), `Money::timesHours` (bcmath, half-away-from-zero), `SeriesFormat` (parse/render/periodKey/maxSequence), `EntityVersionResolver::atDate`, `LegalProfileResolver`, `BankAccountSelector`, `AllocationService`, `AuditLogger`, vzor insert-only tabuliek cez default privileges (`app_rw` dostane len `SELECT, INSERT`) a `PgTestCase` s `owner()`/`rw()` spojeniami. Fáza pridá: migrácie (`invoice_counters`, `invoices`, `invoice_lines`, `invoice_documents`, FK na `billing_allocations.invoice_id`, `invoice_status_v`, rozšírenie `alloc_guard`, `legal_profiles.vat_code`), Invoicing doménu (`InvoiceCalculator`, `SnapshotBuilder`, `NumberAllocator`, `InvoiceIssuer`, `DocumentStore`, `InvoicePdfRenderer`), obrazovku Fakturácia mesiaca, zoznam a detail faktúry, `spark` príkaz pre WR-07 a skript zálohy.

Hlavné rozhodnutia výskumu: (1) **PDF ukladať ako `bytea`** v samostatnej insert-only tabuľke `invoice_documents` s DB `CHECK`, že `sha256` zodpovedá obsahu; je to atómové s transakciou a pokryté `pg_dump`. (2) **Render PDF vnútri transakcie**, po pridelení čísla (číslo je v PDF); mPDF spike z Phase 1 meral 0,16 až 0,38 s, zámok čítača sa teda drží rádovo pol sekundy, čo pri jednom používateľovi nevadí. (3) **`transException`**: nič nové netreba, `Transaction::run` už robí `transException(true)->transStart()` a rollback; kritické je nikdy nechytať `DatabaseException` vnútri transakcie a pokračovať (PostgreSQL označí transakciu ako aborted). (4) **mPDF nie je bajtovo deterministický** (`time()` v `CreationDate`, `ModDate` a `/ID`), ale to nevadí, lebo PDF sa nikdy neregeneruje; uložené bajty sú pravda. (5) **Zámok častí cez SECURITY DEFINER funkciu** `lock_allocations(invoice_id, ids[])` namiesto `GRANT UPDATE (invoice_id)` na `app_rw`: zachová Phase 4 rozhodnutie, že `app_rw` nikdy nenastavuje `invoice_id` priamo (existujúci test `AllocationGuardTest` to overuje), a DB môže skontrolovať zhodu klient/mesiac/mena.

**Primary recommendation:** Postav `InvoiceIssuer::issue()` ako jednu `Transaction::run` s poradím zámkov úlohy → klient (SHARE) → časti → subjekt (SHARE) → riadok `invoice_series` (FOR UPDATE) → upsert čítača → render PDF → INSERT faktúra/riadky/dokument → `lock_allocations()` → audit; DB doplní deferred constraint trigger (faktúra bez PDF, súčet riadkov ≠ total) a testy musia súbeh dokázať na dvoch samostatných PHP procesoch.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Výber klienta/mesiaca/subjektu, náhľad, potvrdenie | Frontend Server (CI4 view + htmx fragmenty) | Browser (Alpine CSP, panel zospodu) | server renderuje fragmenty, žiadna SPA |
| Výpočet súm, preview_token, validácia (mena, dátumy, backdate) | API / Backend (`InvoiceCalculator`, `InvoiceIssuer`) | — | jediné miesto výpočtu, server kontrola, nie UI |
| Pridelenie čísla | Database (`invoice_counters`, upsert, riadkový zámok) | API (`NumberAllocator` volá príkaz) | gapless + súbeh sa rieši zámkom v DB |
| Nemennosť faktúry a zamknutých častí | Database (GRANT, triggery, SECURITY DEFINER, CHECK) | API (služba odmieta skôr) | posledná línia obrany musí byť v DB |
| Kópia údajov (snapshot) | API (`SnapshotBuilder`) | Database (JSONB + štruktúrované stĺpce) | snapshot sa postaví v PHP, uloží raz |
| Generovanie PDF | API (mPDF v php-fpm) | Database (bytea, sha256 CHECK) | render vnútri transakcie, uložené v DB |
| Zobrazenie PDF | API (controller, uložené bajty + kontrola sha256) | CDN/Static: nie (autentifikované, `no-store`) | nikdy regenerovať |
| Historická externá faktúra (WR-07) | CLI (`spark` príkaz) | Database | jednorazová, idempotentná operácia |
| Záloha | OS/CLI (shell skript, `pg_dump`) | Database | PDF v DB, takže jeden dump pokryje všetko |

## Standard Stack

Fáza nepridáva žiadny externý balík. Všetko potrebné je už v `composer.json` a v obrazoch. [VERIFIED: composer.json, `composer show mpdf/mpdf` v kontajneri php = v8.3.1, PHP 8.5.12, PostgreSQL 18.6, pg_dump 18.6 v kontajneri db]

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `mpdf/mpdf` | 8.3.1 (nainštalované) | PDF faktúry zo snapshotu | rozhodnutý stack, spike Phase 1: 0,16 až 0,38 s, 28 MB peak, 0 deprecations |
| bcmath + `Money`/`Hours` | PHP ext + `app/Libraries/Platform` | všetky sumy a hodiny | existujúci kód, žiadny float |
| `codeigniter4/framework` | 4.7.5 | `Transaction::run`, DB driver Postgre, htmx helpers | už v projekte |
| PostgreSQL | 18.6 | `INSERT … ON CONFLICT`, `sha256(bytea)`, constraint triggery, SECURITY DEFINER | overené v dev kontajneri |
| `ext-pgsql` | v obraze | `pg_send_query` pre deterministický test súbehu, `pg_unescape_bytea` | v PHP moduloch (`pgsql`, `pdo_pgsql`) |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| PHPUnit | 11.5 (`composer.json`; CLAUDE.md spomína ^10.5, platí `composer.json`) | testy | všetky nové testy, skupiny `unit`/`integration`/`feature` |
| `pdftotext` (poppler) | v kontajneri php | test, že PDF obsahuje číslo, súčet, diakritiku | integračný test PDF |
| `tests/_support/MasterFixtures`, `PgTestCase`, `AuthTestCase` | repo | fixtures a roly | rozšíriť, nepísať nové |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `bytea` v DB | súbor na disku + `rename()` | súbor: dve úložiská, osirelé súbory po rollbacku, záloha dvoch vecí; `bytea` je atómové a v `pg_dump` (zamietnuté) |
| SECURITY DEFINER `lock_allocations()` | `GRANT UPDATE (invoice_id) ON billing_allocations TO app_rw` | grant je jednoduchší, ale rozbije Phase 4 test a princíp „app_rw nikdy nenastaví invoice_id“; použiť len ako núdzový fallback |
| render vnútri transakcie | render po commite + opakovanie | po commite môže vzniknúť faktúra bez PDF, čo D-16 zakazuje (zamietnuté) |

**Installation:** žiadna (žiadne nové balíky).

**Version verification:** `composer show mpdf/mpdf` v kontajneri php: v8.3.1. `psql select version()`: PostgreSQL 18.6. `php -v`: 8.5.12. [VERIFIED: dev kontajnery 2026-10-26]

## Package Legitimacy Audit

Fáza neinštaluje žiadne externé balíky, takže slopcheck sa nespúšťal.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| (žiadne nové) | — | — | — | — | n/a | n/a |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
Browser (htmx, Alpine CSP)
  |  GET /invoicing?client=&month=&entity=      POST /invoicing/preview-move (presun časti)
  v                                              v
Invoicing controller ──────────────► AllocationService::update (atómové WHERE invoice_id IS NULL)
  |  preview (read-only, žiadny zápis, žiadne číslo)
  v
SnapshotBuilder(mode=preview) ◄── InvoiceCalculator ◄── billing_allocations + tasks (nezamknuté, klient, mesiac)
  |   ▲ EntityVersionResolver::atDate(issue_date), LegalProfileResolver, BankAccountSelector
  v   └────────────── preview_token = sha256(zoradené "id:hours:rate:amount" + klient + mesiac + subjekt)
HTML fragment: riadky, súčet, dátumy, varovanie backdate, tlačidlo Vystaviť (panel zospodu)

POST /invoicing/issue  (CSRF, request_key, preview_token, confirm_backdate)
  v
InvoiceIssuer::issue(IssueCommand)  ── Transaction::run (jedna DB transakcia) ────────────────────────┐
  1. SELECT tasks FOR UPDATE (ORDER BY id)                                                           │
  2. SELECT client FOR SHARE (blokuje zmenu meny/sadzby), entity FOR SHARE                           │
  3. SELECT parts FOR UPDATE (ORDER BY id) WHERE id=ANY AND invoice_id IS NULL AND month AND client │
     počet != očakávaný  -> rollback, 409 (už vystavené) | preview_token != -> 409 (zmenilo sa)       │
  4. invoice_series FOR UPDATE (serializácia, initial_number, backdate kontrola MAX(issue_date))     │
  5. InvoiceCalculator::compute -> riadky, súčet; SnapshotBuilder::build(issue) -> snapshot          │
  6. NumberAllocator::next: INSERT … ON CONFLICT DO UPDATE … RETURNING last_value -> číslo           │
  7. InvoicePdfRenderer::render(snapshot+číslo) -> bajty (mPDF, ~0,3 s) ; sha256                      │
  8. INSERT invoices -> id ; INSERT invoice_lines ; INSERT invoice_documents(bytea, sha256)          │
  9. SELECT lock_allocations(invoice_id, ids[]) (SECURITY DEFINER) ; počet == očakávaný              │
 10. AuditLogger::record('invoice', id, 'issue', …)                                                  │
  COMMIT  (deferred constraint trigger: PDF existuje, SUM(lines)=total)  ◄──────────────────────────┘
  v  chyba kdekoľvek -> ROLLBACK: čítač sa vráti, časti ostanú voľné, žiadne PDF ani faktúra
HX-Location -> GET /invoices/{id}  ;  GET /invoices/{id}/pdf -> bajty z invoice_documents (+ kontrola sha256)

spark invoices:lock-historical --client --month (--commit)  -> externá faktúra (origin='external') + lock_allocations()
```

### Recommended Project Structure
```
app/Libraries/Invoicing/
├── InvoiceCalculator.php     # jediné miesto výpočtu (Money::timesHours, súčet zaokrúhlených riadkov)
├── SnapshotBuilder.php       # preview | issue režim, vráti pole (reťazce, nie float)
├── NumberAllocator.php       # upsert čítača, render čísla cez SeriesFormat
├── InvoiceIssuer.php         # jediný zapisovateľ invoices*
├── IssueCommand.php          # readonly DTO: clientId, month, entityId, dates, partIds, previewToken, requestKey, confirmBackdate
├── IssueRejected.php         # kód dôvodu (already_issued, preview_stale, currency_mismatch, backdate_unconfirmed, …)
├── DocumentStore.php         # insert/read bytea, kontrola sha256
├── InvoicePdfRenderer.php    # interface PdfRenderer + MpdfInvoiceRenderer (šablóna inv-1)
├── ExternalInvoiceLocker.php # WR-07 logika (volá spark príkaz)
└── InvoiceQueries.php        # zoznam a detail (bez snapshotu a bez content)
app/Controllers/Invoicing.php, Invoices.php
app/Commands/InvoicesLockHistorical.php
app/Database/Migrations/2026-10-27-0000NN_*.php
app/Views/invoicing/, app/Views/invoices/, app/Views/pdf/invoice_inv1.php
scripts/backup.sh, docs/ops/backup.md
```

### Pattern 1: Číslovanie jedným príkazom (overené)
**What:** čítač (rad × obdobie), pridelenie `INSERT … ON CONFLICT … DO UPDATE … RETURNING`.
**When to use:** vždy pri vystavení, ako posledný zámok pred zápisom.
**Verified:** v scratch DB (PG 18.6): rollback transakcie vrátil čítač (0 riadkov), po dvoch commitoch `last_value` = 1 a 2. [VERIFIED: psql 2026-10-26]
```sql
-- :first = initial_number, ak rad ešte nemá žiadnu vlastnú faktúru, inak 1 (D-12)
INSERT INTO invoice_counters (series_id, period_key, last_value)
VALUES (:series:, :period:, :first:)
ON CONFLICT (series_id, period_key)
DO UPDATE SET last_value = invoice_counters.last_value + 1
RETURNING last_value;
```
- Pri prvom vložení riadku druhá súbežná transakcia **čaká** na commit alebo rollback prvej (speculative insertion), takže problém „FOR UPDATE na neexistujúci riadok“ nevzniká. [CITED: postgresql.org/docs/current/sql-insert.html, sekcia ON CONFLICT]
- `:first` sa použije len pri skutočnom vložení; v konflikte sa ignoruje. Riziko, že dve súbežné prvé faktúry v rôznych obdobiach prázdneho radu obe dostanú `initial_number`, odstraňuje **`SELECT … FROM invoice_series WHERE id = :s FOR UPDATE`** pred upsertom (serializuje vystavovanie jedného radu, čo je pri jednom používateľovi zadarmo). Rovnaký zámok serializuje aj `SeriesService::update` (ten už zamyká oba rady subjektu `FOR UPDATE`, WR-02), takže zmena formátu nemôže bežať cez vystavenie.
- Číslo sa vyrenderuje cez `SeriesFormat::render($issueDate, $seq)`; `OverflowException` (poradie nad `maxSequence()`) preložiť na `IssueRejected('sequence_overflow')`, transakcia sa vráti a čítač s ňou.
- Prvé číslo radu: `NOT EXISTS (SELECT 1 FROM invoices WHERE series_id = :s)` (externé faktúry majú `series_id NULL`, nepočítajú sa). Ďalšie obdobia od 1.
- Poistky: `UNIQUE (series_id, number)` a `UNIQUE (entity_id, number)`.
- Hardening: `BEFORE UPDATE` trigger na `invoice_counters` povolí len `NEW.last_value = OLD.last_value + 1` (app_rw potrebuje UPDATE na `last_value` kvôli upsertu; trigger zabráni „vrátiť čítač“ chybou alebo SQL injection).

### Pattern 2: Poradie zámkov (deadlock-safe)
Existujúce poradia v kóde: `AllocationService::add` = task `FOR UPDATE`, client `FOR SHARE`; `lockRow` = task, potom časť; `ClientService::update` = client `FOR UPDATE`; `SeriesService::update` = oba rady `FOR UPDATE ORDER BY id`. Issuer musí dodržať: **tasks (ORDER BY id) → clients FOR SHARE → billing_allocations (ORDER BY id) → entities FOR SHARE → invoice_series FOR UPDATE → counter row**. Žiadna existujúca služba nedrží zámok v opačnom poradí, takže cyklus nevznikne. [VERIFIED: grep FOR UPDATE/FOR SHARE v app/Libraries]

Zisťovanie `task_id` pred zámkom: nezamknuté čítanie `SELECT task_id FROM billing_allocations WHERE id = ANY(:ids)`, potom zámok úloh, potom zámok častí s podmienkou `invoice_id IS NULL AND billing_month = :m AND task.client_id = :c` a kontrolou počtu. Medzitým presunutá časť (iný mesiac) chýba v zámku a počet nesedí, takže vystavenie sa odmietne (409), nie ticho zmení obsah.

### Pattern 3: Nemennosť v troch vrstvách (konkrétne)
1. **Služba:** iba `InvoiceIssuer` a `ExternalInvoiceLocker` zapisujú do `invoices*`.
2. **GRANT:** default privileges dajú `app_rw` iba `SELECT, INSERT` na nové tabuľky; migrácia nič nerozširuje. Čítač dostane `GRANT UPDATE (last_value)`. `legal_profiles`/`legal_texts` ostávajú read-only (už je).
3. **Trigger a CHECK (posledná línia, aj pred `app_owner` chybami):**
   - generická `immutable_guard()` funkcia `BEFORE UPDATE OR DELETE` na `invoices`, `invoice_lines`, `invoice_documents` (`RAISE EXCEPTION … ERRCODE 'integrity_constraint_violation'`); `TRUNCATE` blokuje chýbajúci grant (a testovací `TRUNCATE` robí owner, ktorý triggery na riadkoch nespúšťa). Uvažované v Phase 7/9: nové udalosti idú do nových tabuliek, nie do `invoices`.
   - `invoice_documents`: `CHECK (sha256 = encode(sha256(content), 'hex'))` (PG 11+ má `sha256(bytea)`), takže uložený hash nemôže nesúhlasiť s obsahom.
   - `invoice_lines`: `CHECK (amount = round(hours * rate, 2))`, rovnaké ako na `billing_allocations`.
   - **deferred constraint trigger** na `invoices` (`CREATE CONSTRAINT TRIGGER … AFTER INSERT … DEFERRABLE INITIALLY DEFERRED`): pri `origin = 'issued'` musí existovať `invoice_documents(kind='invoice')`, aspoň jeden riadok, `SUM(invoice_lines.amount) = total` a každý riadok má `vat_code`. Tým je „faktúra bez PDF nesmie zostať“ (D-16) a „súčty sa zhodujú“ vynútené v DB.
4. **`alloc_guard` rozšíriť** (nová migrácia, nie edit starej): ak `OLD.invoice_id IS NULL AND NEW.invoice_id IS NOT NULL`, povoliť len keď sa ostatné stĺpce (okrem `invoice_id`, `updated_at`) nezmenili; ak `OLD.invoice_id IS NOT NULL`, výnimka ako dnes (výnimku pre dobropis doplní Phase 9).

### Pattern 4: SECURITY DEFINER `lock_allocations`
Phase 4 zámerne nedala `app_rw` `UPDATE (invoice_id)` a `AllocationGuardTest` to testuje (`UPDATE billing_allocations SET invoice_id = 1` pod `app_rw` = permission denied). Zachovať to cez funkciu vo vlastníctve `app_owner`:
```sql
CREATE FUNCTION lock_allocations(p_invoice_id bigint, p_ids bigint[]) RETURNS integer
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n integer;
BEGIN
  UPDATE billing_allocations a SET invoice_id = p_invoice_id
  FROM invoices i, tasks t
  WHERE i.id = p_invoice_id AND a.id = ANY(p_ids) AND a.invoice_id IS NULL
    AND t.id = a.task_id AND t.client_id = i.client_id
    AND a.billing_month = i.billing_month AND a.currency = i.currency;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END $$;
REVOKE ALL ON FUNCTION lock_allocations(bigint, bigint[]) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lock_allocations(bigint, bigint[]) TO app_rw;
```
PostgreSQL dáva `EXECUTE` na nové funkcie štandardne `PUBLIC`, preto `REVOKE … FROM PUBLIC` je povinné. Issuer porovná vrátený počet s očakávaným a pri nesúlade vyhodí výnimku (rollback). Rovnaká funkcia obslúži WR-07 (externá faktúra má `client_id`, `billing_month`, `currency` rovnaké ako časti). [CITED: postgresql.org/docs/current/sql-createfunction.html, sekcia „Writing SECURITY DEFINER Functions Safely“]

### Pattern 5: Snapshot a štruktúrované stĺpce z jedného výpočtu
`SnapshotBuilder::build()` vráti jedno pole; `InvoiceIssuer` z neho (nie z druhého výpočtu) naplní štruktúrované stĺpce, riadky a PDF. Preview používa ten istý builder s `number = null`. Všetky hodnoty v snapshote sú **reťazce** (`"432.00"`), dekódovať `json_decode(..., true)` bez prevodu na float; po `jsonb` round-tripe sa nezachová poradie kľúčov, preto sha256 sa počíta z PDF bajtov, nie zo snapshotu. Tvar podľa ARCHITECTURE.md rozšíriť o: `rounding: {"line": "half_up_2", "total": "sum_of_rounded_lines"}`, `labels` (preložené popisky podľa jazyka dokladu, kópia z `lang()` v čase vystavenia), `template_version`, `bank` (účet podľa meny), `vat_code` na riadku, `portal_url` (výsledok `PortalUrlTemplate` z klienta k vystaveniu), `estimated_hours`, `source_allocation_id`, `source_task_id`.

Vloženie JSONB cez CI4: `CAST(:snapshot: AS jsonb)` (nie `::jsonb`, kolidovalo by so syntaxou `:name:`).

### Pattern 6: PDF v `bytea` cez CI4 Postgre driver (overené)
Zápis: `decode(:hex:, 'hex')` s `bin2hex($pdf)`; čítanie: `pg_unescape_bytea($row['content'])` (`bytea_output = hex`). Overené na 2 kB náhodných bajtoch vrátane `\0`, `\`, `'`, `"`, `0xFF`: round-trip identický, `sha256()` v DB = `hash('sha256')` v PHP. [VERIFIED: pg_query_params cez ext-pgsql, 2026-10-26; CI4 `PreparedQuery` používa `pg_escape_bytea` rovnako]. Pri `$db->query()` s `:name:` placeholdermi sa bajty nikdy nevkladajú priamo (null bajty a escaping), vždy hex.
- Nikdy `SELECT *` z `invoice_documents` v zoznamoch, vždy vymenovať stĺpce bez `content`.
- Veľkosť ~50 kB na doklad, stovky ročne, zanedbateľné pre `pg_dump` aj TOAST. `memory_limit 256M` stačí (hex kópia < 1 MB).

### Pattern 7: Zobrazenie uloženého PDF
Controller `Invoices::pdf($id)`: `SELECT content, sha256 FROM invoice_documents WHERE invoice_id=:id AND kind='invoice'`, `hash('sha256', $bytes) === $sha256` (inak 500 a log, integrita), odpoveď `Content-Type: application/pdf`, `Content-Disposition: inline; filename="<sanitizované číslo>.pdf"` (nahradiť `[^A-Za-z0-9._-]` za `_`, formát čísla pripúšťa `/`), `Cache-Control: private, no-store`, `ETag` = sha256, `X-Content-Type-Options: nosniff`. Nikdy nevolať renderer.

### Anti-Patterns to Avoid
- **`SEQUENCE`, `MAX()+1`, `FOR UPDATE` na neexistujúci riadok** pre číslo (ROADMAP, PITFALLS 5).
- **Chytať `DatabaseException` vnútri transakcie a pokračovať.** PostgreSQL po chybe odmieta všetky ďalšie príkazy (`current transaction is aborted`). Validácie robiť pred zápisom; unikátne konflikty riešiť `SELECT` po zámku, nie `try/catch` okolo `INSERT`. Ak je výnimočne treba, `SAVEPOINT`.
- **Dvojitý výpočet súm** (v SQL aj v PHP, preview inak ako issue).
- **`created_by … REFERENCES users ON DELETE SET NULL`** na `invoices`: referenčná akcia by robila UPDATE nemennej tabuľky. Použiť `NO ACTION` (`entity_versions` to robí s `SET NULL`, pre faktúry to nekopírovať).
- **Čítať živého klienta/subjekt v PDF šablóne.** Šablóna dostane iba snapshot a `lang` reťazce skopírované do snapshotu (`labels`).
- **`NumberFormatter` počas renderu PDF.** ICU verzia sa po rebuilde obrazu mení (medzery, NBSP); sumy v PDF formátovať vlastnou funkciou nad reťazcami (alebo hodnotu predformátovať do snapshotu pri vystavení).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Gapless číslo | PHP `MAX()+1`, advisory-lock cache, vlastná sekvencia | `invoice_counters` + `ON CONFLICT DO UPDATE … RETURNING` | rollback vráti čítač, súbeh serializuje DB |
| Formát čísla a obdobie | nový parser | `SeriesFormat::parse/render/periodKey/maxSequence` | už otestované (WR-05, WR-06) |
| Suma riadku, súčet | `round()` na float, `number_format` | `Money::timesHours`, `Money::sum` | bcmath half-away-from-zero, rozsah numeric(12,2) |
| Hodiny | float | `Hours::plus/of` | existuje |
| Vnorená transakcia | vlastné `transStart/Complete` | `Transaction::run` | rieši transException, vnorenie, reset stavu (WR-01) |
| Verzia subjektu k dátumu | vlastný SQL | `EntityVersionResolver::atDate` | existuje, hádže `VersionNotFound` |
| Kód oslobodenia, zákonný text | vlastný lookup | `LegalProfileResolver::exemptionCode/text` | fallback poradie už vyriešené (WR-11) |
| Bankový účet | vlastný výber | `BankAccountSelector::forCurrency` | `currency_match` príznak, `NoBankAccount` |
| Presun časti z náhľadu | UPDATE v kontroléri | `AllocationService::update` | atómová podmienka + audit |
| Hash PDF | vlastný checksum | `hash('sha256')` + DB `CHECK sha256()` | overené shoda PHP a PG |
| Audit | vlastný log | `AuditLogger::record` v tej istej transakcii | existuje |
| Čas | `new DateTime()` | `service('clock')` (`FrozenClock` v testoch) | pravidlo projektu |
| Test súbehu | vlákna, `pcntl_fork` | `proc_open` PHP workerov + `pg_send_query` | `pcntl` v kontajneri nie je (overené), `proc_open` už používa `MpdfSpikeTest` |

**Key insight:** správnosť faktúry nestojí na jednej dobrej triede, ale na tom, že žiadna cesta (služba, SQL injection, chyba v budúcej fáze, ručný `UPDATE` ownerom) nevie vytvoriť stav, ktorý DB neodmietne; preto sú CHECK, trigger a GRANT súčasť návrhu, nie dodatok.

## Schema Guidance (migrácie, vždy nové súbory pod `app_owner`)

Poradie migrácií (predpona dátumu po `2026-10-26-000001`):
1. `legal_profiles.vat_code` + seed (predbežný, R1), `invoice_counters`, `invoices`, `invoice_lines`, `invoice_documents`, indexy, triggery, komentáre (`COMMENT ON TABLE` vzor Phase 3/4).
2. FK `billing_allocations.invoice_id → invoices(id) ON DELETE RESTRICT`, nová verzia `alloc_guard`, `lock_allocations()`.
3. `invoice_status_v` (minimálny pohľad; Phase 7 môže stĺpce len **pripájať na koniec** cez `CREATE OR REPLACE VIEW`, inak `DROP`+`CREATE`).

`invoices` (stĺpce, sketch):
- identita: `id bigint GENERATED ALWAYS AS IDENTITY`, `origin text CHECK IN ('issued','external')`, `type text CHECK IN ('invoice','credit_note')`, `request_key uuid NULL UNIQUE` (idempotencia dvojkliku), `external_ref text NULL CHECK (char_length <= 300)`.
- subjekt: `entity_id`, `entity_version_id`, `series_id` (všetky NULL pri `external`), `number text NULL`, `UNIQUE (series_id, number)`, `UNIQUE (entity_id, number)`.
- väzby: `client_id bigint NOT NULL`, `billing_month char(7) NOT NULL`, `corrected_invoice_id bigint NULL REFERENCES invoices`, `correction_reason text NULL`, `correction_type text NULL` (M6; `CHECK ((type = 'credit_note') = (corrected_invoice_id IS NOT NULL))`).
- dátumy: `issue_date`, `delivery_date`, `due_date`, `turnover_date` (NOT NULL len pri `issued`).
- sumy: `currency char(3)`, `total numeric(12,2)`, `total_entity_ccy numeric(12,2) NULL`, `counts_toward_limit boolean NOT NULL`; `CHECK (type = 'credit_note' OR total >= 0)`.
- kurz (M15, NULL): `fx_rate numeric(18,8)`, `fx_source`, `fx_date`, `fx_table_no`, `fx_date_basis`.
- profil (M1–M5, M13): `doc_language`, `vat_regime`, `billing_mode`, `exemption_code` (M5), `entity_vat_id` (M2), `client_identifier_type` + `client_identifier_value` (M3), `bank_iban`, `bank_swift` (M4), `payment_method text NOT NULL DEFAULT 'transfer'`, `payment_note text NULL` (M13).
- doklad: `snapshot jsonb NOT NULL`, `snapshot_schema smallint NOT NULL`, `template_version text NULL`.
- audit: `created_at timestamptz NOT NULL`, `created_by integer NULL REFERENCES users(id)` (bez `ON DELETE SET NULL`).
- konzistencia druhov (jeden CHECK): `origin = 'issued'` ⇒ entity/verzia/rad/číslo/štyri dátumy NOT NULL a `external_ref IS NULL`; `origin = 'external'` ⇒ `series_id`, `number`, `entity_id`, `entity_version_id`, `turnover_date` NULL, `counts_toward_limit = false`, `type = 'invoice'`. Vlastné faktúry a dobropisy tak nemožno vložiť bez čísla a radu.
- `CREATE UNIQUE INDEX … ON invoices (client_id, billing_month) WHERE origin = 'external'` (idempotencia WR-07).
- Indexy: `(client_id, billing_month)`, `(entity_id, issue_date)`, `(series_id, issue_date)` pre backdate kontrolu.

`invoice_lines`: `id`, `invoice_id bigint NOT NULL REFERENCES invoices ON DELETE RESTRICT`, `position int`, `source_allocation_id bigint NULL` a `source_task_id bigint NULL` (**bez FK**), `title`, `portal_url`, `estimated_hours numeric(7,2)`, `hours numeric(7,2)`, `rate numeric(12,2)`, `amount numeric(12,2) CHECK (amount = round(hours*rate,2))`, `vat_code text NULL` (povinné pre `issued` vynucuje deferred trigger), `UNIQUE (invoice_id, position)`. Bez `hours > 0` (dobropis má záporné).

`invoice_documents`: `id`, `invoice_id` FK, `kind CHECK IN ('invoice','report')`, `content bytea NOT NULL`, `byte_size int`, `sha256 char(64) CHECK (sha256 ~ '^[0-9a-f]{64}$' AND sha256 = encode(sha256(content),'hex'))`, `template_version text NOT NULL`, `renderer text NOT NULL` (napr. `mpdf 8.3.1`), `created_at`, `UNIQUE (invoice_id, kind)`.

`legal_profiles.vat_code`: tabuľka dnes má len `exemption_code` (M5, napr. `ART_113_1`), nie kód M1. Nová migrácia pridá `vat_code text NULL` a predbežne ho naplní: PL `pl_exempt_113_*` bez režimu `zw`; PL `eu_b2b` (reverse charge) `np`; SK `sk_non_vat` a CZ `cz_non_vat` `np`. Hodnoty sú **predbežné** (riziko R1, potvrdí účtovníčka); sú to textové kódy FA(3) P_12 (`zw`, `np I`, `np II`, `oo`) [CITED: .planning/research/FEATURES.md]. Chýbajúci `vat_code` pre profil = `LegalTextMissing`-podobné odmietnutie vystavenia, nie ticho prázdny kód. `LegalProfileResolver::profile()` rozšíriť o `vat_code`. [ASSUMED: konkrétne kódy `np` pre SK/CZ a reverse charge]

`invoice_status_v` (Phase 5): `id, origin, type, number, external_ref, client_id, entity_id, billing_month, issue_date, due_date, currency, total, counts_toward_limit, created_at` + odvodený `status` (`'external'` pre externé, inak `'issued'`) a **bez** `snapshot`/`content`. Phase 7 a 9 stĺpce pripájajú.

## WR-07: historické externé faktúry

- **V DB nie je značka, ktoré časti pochádzali z „Fakturované = ÁNO“.** `tasks.import_key` je sha256 hash (mesiac, URL, názov, hodiny, poradie) a nenesie príznak; `ImportRunner` príznak `billed` zahodí. Príkaz preto nemôže filtrovať podľa značky. [VERIFIED: ImportRunner.php, CreateTasks migrácia]
- Odporúčanie (bez zmeny importu): `spark invoices:lock-historical --client <id> --month <RRRR-MM> [--ref <text>] [--expect-parts N --expect-hours H --expect-amount A] [--commit]`. Vyberie **nezamknuté časti** klienta v mesiaci patriace úlohám s `import_key IS NOT NULL`, vypíše počet, hodiny, sumu a zoznam (dry-run predvolene, ako `import:excel`). `--expect-*` guard (pre dev `11 / 89.00 / 2403.00`) ukončí príkaz chybou pri nezhode, takže nezamkne omylom nesprávne časti na produkcii. PhpSpreadsheet netreba, príkaz teda beží aj v produkčnom obraze (`import:excel` je dev-only a produkcia dostáva dáta inak, preto sa nesmie spoliehať na Excel).
- Ostrý beh: jedna `Transaction::run`: zámky úloh, klienta a častí v rovnakom poradí ako issuer, `INSERT invoices` (`origin='external'`, `number NULL`, `series_id NULL`, `counts_toward_limit=false`, `total` = súčet, `snapshot` = `{"schema":1,"origin":"external","external_ref":…,"lines":[…]}`), `invoice_lines` (bez `vat_code`), `lock_allocations()`, `AuditLogger::record('invoice', id, 'external_lock', …)`. Idempotencia: unikátny index `(client_id, billing_month) WHERE origin='external'` a druhý beh nájde 0 nezamknutých častí.
- Zamknutie zablokuje **aj** úpravu importovaných častí (aj keby boli omylom označené) iba cez `--commit` a po dry-run kontrole; dokumentovať v `docs/ops/import-excel.md` (doplniť sekciu „Čo import nerobí“, kde dnes stojí „rozhodne vlastník“).
- Open design point pre planner: či chcieť trvalý príznak `tasks.import_billed` (INSERT-only stĺpec, `ImportRunner` ho nastaví). Pre dev dáta už importované by to aj tak vyžadovalo spätné doplnenie, preto je odporúčaná varianta s `--month/--expect-*` jednoduchšia a stačí.

## Obrazovka a UX (stručne, detail rieši UI-SPEC)

- Routy: `GET /invoicing` (výber + náhľad), `GET /invoicing/preview` (fragment), `POST /invoicing/issue`, `GET /invoices`, `GET /invoices/(:num)`, `GET /invoices/(:num)/pdf`. Aktivovať položku „Faktúry“ v `_nav_items.php` (dnes `aria-disabled` span).
- `preview_token` (PITFALLS 6): sha256 zoradených `id:hours:rate:amount` + klient + mesiac + entita; issue ho porovná po zámku. Nesúlad = 409 so znovu vykresleným náhľadom (htmx konfigurácia v `layouts/app.php` už swapuje 409 aj 422).
- `request_key` (uuid z formulára): po zámku, ak časti už nie sú voľné, `SELECT id FROM invoices WHERE request_key = :k` → nájdené = idempotentné presmerovanie na faktúru, nenájdené = 409 „časti už boli vystavené“ (D-06). Tlačidlo s `hx-disabled-elt`. Existuje aj `tests/js/submit-once.test.js` (vzor).
- Backdate (D-05): server po zámku radu `SELECT MAX(issue_date) FROM invoices WHERE series_id = :s`; ak `issue_date < max` a chýba `confirm_backdate=1` → `IssueRejected('backdate_unconfirmed')`. Náhľad zobrazí varovanie rovnakým výpočtom (bez zámku).
- Predvolený mesiac (diskrécia): najstarší `billing_month` s nezamknutými časťami klienta, inak predchádzajúci; predvolený klient `LastClient::resolve()`.
- Poradie položiek: `ORDER BY tasks.created_at, billing_allocations.id`; `position` sa uloží.
- Texty cez `lang()`, nové súbory `app/Language/{sk,en}/Invoicing.php`; žiadne inline skripty/štýly (CSP), Alpine CSP build.

## Common Pitfalls

### Pitfall 1: Existujúce Phase 4 testy sa zmenia
**What goes wrong:** FK na `invoices` a zmena zámku rozbijú testy, ktoré dnes nastavujú `invoice_id = 5` bez faktúry (`AllocationGuardTest` riadok ~80) a tie, čo `resetMasterTables()` neobsahujú nové tabuľky.
**How to avoid:** `resetMasterTables()` rozšíriť o `invoice_documents, invoice_lines, invoices, invoice_counters` (TRUNCATE … CASCADE už existuje); fixture `createLockedAllocation()` v `MasterFixtures`, ktorá vloží skutočnú (alebo externú) faktúru pod ownerom. Test „`app_rw` nemôže `UPDATE billing_allocations SET invoice_id = 1`“ zostane zelený, ak sa použije `lock_allocations()`.
**Warning signs:** `foreign key violation` v `AllocationGuardTest`, `AllocationServiceTest`, `TaskBillingViewTest`.

### Pitfall 2: Aborted transaction po chytenej DB chybe
**What goes wrong:** `try { INSERT … } catch (DatabaseException) { … ďalší SELECT }` skončí `current transaction is aborted`.
**How to avoid:** žiadne chytanie vo vnútri `issue()`; konflikty zistiť dotazom. `Transaction::run` vo vonkajšej vrstve zachytí, rollbackne a znova hodí. Prevod `DatabaseException` na `IssueRejected` robiť až mimo transakcie (vzor `AllocationService::translate`).

### Pitfall 3: Číslo v PDF vs zámok
**What goes wrong:** PDF potrebuje číslo, ktoré existuje až po upsert; render je teda pod zámkom čítača (~0,2 až 0,4 s).
**How to avoid:** všetko pomalé a nezávislé od čísla (načítanie dát, snapshot bez čísla, validácie) spraviť **pred** upsertom; po upsert len render a inserty. Pri jednom používateľovi je to bezproblémové. Test súbehu používa `FakePdfRenderer` (rozhranie `PdfRenderer`), aby 50 vystavení netrvalo 50 × 0,3 s. Jeden samostatný test s reálnym mPDF overí bajty a diakritiku.

### Pitfall 4: mPDF nie je deterministický
**What goes wrong:** `CreationDate`, `ModDate` a `/ID` v mPDF používajú `time()`/`date()` (`MetadataWriter.php`), takže dvojité vykreslenie dá rôzne bajty. [VERIFIED: vendor/mpdf/mpdf/src/Writer/MetadataWriter.php, riadky 57, 177, 804]
**Why OK:** PDF sa nikdy neregeneruje; autoritatívne sú uložené bajty. Zobrazený dátum na faktúre musí pochádzať **iba zo snapshotu** (dátumy vystavenia/dodania/splatnosti), nie z `date()` v šablóne. `SetTitle`, `SetAuthor`, `SetCreator('Task Tracker inv-1')` nastaviť explicitne. Test „rovnaké bajty“ nepísať pre regeneráciu; písať ho pre **čítanie uložených bajtov** po zmene všetkého.

### Pitfall 5: `invoice_series` zámok a `SeriesService`
**What goes wrong:** zmena formátu radu počas vystavenia. **How to avoid:** issuer drží `invoice_series FOR UPDATE`; `SeriesService::isFormatLocked($seriesId)` implementovať ako `EXISTS (SELECT 1 FROM invoices WHERE series_id = :s)` (konzistentné, lebo `SeriesService::update` už drží ten istý zámok).

### Pitfall 6: `invoice_status_v` a `CREATE OR REPLACE VIEW`
Pridanie stĺpcov do pohľadu v neskorších fázach je možné len na koniec zoznamu; planner to poznamená pre Phase 7/9 (inak `DROP VIEW` + `CREATE`, grant sa musí zopakovať).

### Pitfall 7: `app_rw` a čítač
Upsert potrebuje `INSERT` aj `UPDATE (last_value)` a `SELECT`. Bez `GRANT UPDATE (last_value)` skončí `ON CONFLICT DO UPDATE` chybou `permission denied`, ktorú neodhalí test pod `app_owner`. Test súbehu a číslovania **musí** bežať cez `rw()`.

### Pitfall 8: Mena klienta vs subjektu
D-10: `clients.currency != entities.currency` ⇒ `IssueRejected('currency_mismatch')` pred zámkami a znova po zámku (klient je zamknutý `FOR SHARE`, takže mena sa už nezmení). Časti musia mať všetky rovnakú menu (`billing_allocations.currency`, `char(3)`, trim).

### Pitfall 9: Zaokrúhľovanie a súčty
Súčet = súčet zaokrúhlených riadkov (`Money::sum`). Riadok sa **prepočíta** z `hours × rate` v `InvoiceCalculator` a porovná s `billing_allocations.amount` (už je `CHECK amount = round(hours*rate,2)`); pri nezhode vystavenie padne (signál korupcie), nie ticho prepíše. Test zhody: obrazovka (`preview`) = riadky = `invoices.total` = `SUM(invoice_lines.amount)` = snapshot totals = PDF text (`pdftotext`).

## Code Examples

### Poradie zámkov v issuer (skica)
```php
// Source: kombinácia existujúceho vzoru AllocationService + PITFALLS 5/6
return Transaction::run($this->db, function () use ($cmd): Invoice {
    $taskIds = $this->taskIdsOf($cmd->partIds);                       // nezamknuté čítanie
    $this->db->query('SELECT id FROM tasks WHERE id = ANY(...) ORDER BY id FOR UPDATE', [...]);
    $client = $this->db->query('SELECT * FROM clients WHERE id = :c: FOR SHARE', [...])->getRowArray();
    $parts  = $this->db->query(
        'SELECT a.*, t.title, t.url, t.hours AS estimated_hours FROM billing_allocations a
         JOIN tasks t ON t.id = a.task_id
         WHERE a.id = ANY(...) AND a.invoice_id IS NULL AND a.billing_month = :m: AND t.client_id = :c:
         ORDER BY a.id FOR UPDATE OF a',
        [...],
    )->getResultArray();
    if (count($parts) !== count($cmd->partIds)) { throw new IssueRejected('already_issued_or_changed'); }
    // … entity FOR SHARE, invoice_series FOR UPDATE, backdate, calculator, snapshot,
    // NumberAllocator::next(), renderer->render(), INSERT invoices/lines/documents,
    // SELECT lock_allocations(:inv, :ids) == count, audit
});
```
(PostgreSQL pole do `ANY()` posielať ako literál `'{1,2,3}'` zostavený z validovaných `int`, nie z reťazcov.)

### Test súbehu na dvoch samostatných procesoch
```php
// Source: vzor MpdfSpikeTest::runSpike (proc_open) + PITFALLS 5
// Parent: owner() vytvorí 50 klientov/úloh/častí (raw INSERT), spustí 2 workery, počká, overí.
$workers = [];
foreach ([0, 1] as $w) {
    $workers[] = proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/issue_worker.php', (string) $w, '25'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
}
// worker: vlastné spojenie `tests` (app_rw), FakePdfRenderer, čaká na štart cez pg_advisory_lock(777) držaný parentom,
// potom 25× InvoiceIssuer::issue(); parent uvoľní zámok, oba bežia naraz.
// Overenie (owner): SELECT number FROM invoices ORDER BY number -> 202610001..202610050, žiadny duplikát ani diera,
//                   SELECT last_value FROM invoice_counters = 50.
```
Worker potrebuje bootstrap CI4 bez PHPUnit; vzor v `scripts/spikes/mpdf.php` (len autoload, bez CI4). Pre worker použiť `require vendor/autoload.php` + `Config\Database::connect('tests', false)` a ručné vytvorenie služieb (`new InvoiceIssuer($db, …)`); `service('clock')` a `auth()` v `AuditLogger` vyžadujú CI4 kontext, preto planner v 05-0x počíta s malým bootstrap helperom (`Boot::bootTest` pod `CI_ENVIRONMENT=testing`, konštanty `SUPPORTPATH` z `system/Test/bootstrap.php`). Pokus o `Boot::bootSpark` bez `SUPPORTPATH` zlyhal (`Undefined constant SUPPORTPATH`), použiť `system/Test/bootstrap.php`. [VERIFIED: skúška 2026-10-26]

Doplnkový deterministický test cez `pg_send_query` (jeden proces, dve spojenia): spojenie A `BEGIN` + upsert (drží zámok), spojenie B `pg_send_query` upsert (nevráti sa), `pg_connection_busy(B) === true`, A `COMMIT`, B dostane `last_value` = predchádzajúci + 1. A variant s `ROLLBACK`: B dostane rovnaké číslo ako A.

### Kontrola uloženého PDF
```php
// Source: vlastný návrh, overený round-trip bytea
$row   = $db->query("SELECT content, sha256 FROM invoice_documents WHERE invoice_id = :i: AND kind = 'invoice'", ['i' => $id])->getRowArray();
$bytes = pg_unescape_bytea($row['content']);
if (! hash_equals((string) $row['sha256'], hash('sha256', $bytes))) { /* log + 500 */ }
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `SERIAL` pre číslo faktúry | čítač v tabuľke + upsert | trvalo | gapless |
| PDF regenerované pri každom zobrazení | autoritatívne uložené PDF + hash | rozhodnutie vlastníka (INV-13) | nemennosť bajtov |
| `sha256` cez `pgcrypto` | vstavaná `sha256(bytea)` | PG 11 | bez rozšírenia, použiteľná v `CHECK` |
| `pg_dump` bez PDF konzistencie | PDF v `bytea` | tu | jeden konzistentný dump |

**Deprecated/outdated:** `MAX()+1`, `SELECT … FOR UPDATE` na neexistujúci riadok, ukladanie stavu faktúry do nemennej tabuľky.

## Backup skript (D-24, minimálny)

`scripts/backup.sh` (POSIX sh, vzor `scripts/test-db.sh`): `docker compose exec -T db pg_dump -U app_owner --format=custom --no-owner "$DB" > backups/task_tracker-$(date +%Y%m%dT%H%M%S).dump`, potom `pg_restore --list` do `/dev/null` ako kontrola čitateľnosti a `sha256sum` do vedľajšieho súboru, výstup do adresára mimo gitu (`backups/` pridať do `.gitignore`), nenulový exit pri chybe. Pretože PDF sú v `bytea`, žiadna samostatná záloha súborov nie je potrebná; `docs/ops/backup.md` to musí uviesť (D-24 „ak sú na disku“ sa tým stáva „nie sú na disku“). Klient `pg_dump` 18.6 zodpovedá serveru (overené v kontajneri db). Šifrovanie, mimo-serverové úložisko, rotácia a test obnovy sú Phase 12. [VERIFIED: pg_dump 18.6 v kontajneri db]

## Runtime State Inventory

Nie je rename/refactor fáza, ale dotýka sa existujúcich dát:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | dev DB: 11 nezamknutých častí 09/2026 (89 h, 2 403,00 €) importovaných z „ÁNO“ riadkov | `spark invoices:lock-historical` (data migration, nie code edit) |
| Live service config | žiadne (žiadna externá služba) | žiadna |
| OS-registered state | žiadne | žiadna |
| Secrets/env vars | žiadne nové | žiadna |
| Build artifacts | testovacia DB `task_tracker_test` treba prebudovať (`scripts/test-db.sh`) po nových migráciách; dev DB `make migrate` | spustiť pri vykonaní |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Predbežné kódy DPH (`zw` pre PL art. 113, `np` pre SK/CZ neplatiteľa a PL EÚ B2B reverse charge) | Schema Guidance (`vat_code`) | nesprávny kód na doklade; potvrdí účtovníčka (R1), oprava novou migráciou len pre budúce doklady |
| A2 | Backdate pravidlo „skôr ako `MAX(issue_date)` vlastných faktúr radu“ je dostatočné a poradie čísel nemusí byť chronologické | UX | účtovníčka môže vyžadovať chronológiu; zatiaľ len varovanie (INV-15 to tak definuje) |
| A3 | Pri jednom používateľovi serializácia vystavovania na `invoice_series FOR UPDATE` neprekáža a render ~0,3 s pod zámkom je prijateľný | Pattern 1, Pitfall 3 | pri raste na viac používateľov by sa zmenil na predvýpočet; dnes nerelevantné |
| A4 | Ročné objemy (stovky PDF po ~50 kB) robia `bytea` a `pg_dump` bezproblémovými | Pattern 6 | pri tisíckach veľkých výkazov by sa zvážil súborový archív (Phase 12) |
| A5 | Phase 7/9 pridajú stav cez nové tabuľky a pohľad, nie UPDATE na `invoices` | Pattern 3 | `immutable_guard` by bolo treba upraviť |
| A6 | Pre dev DB platí očakávanie 11 častí / 89 h / 2 403,00 € (z CONTEXT/import docs), zvolený guard `--expect-*` | WR-07 | iný stav dát = príkaz zlyhá bezpečne a planner/vlastník upraví očakávanie |

## Open Questions

1. **Zahrnúť `immutable_guard` triggery na `invoices*` (nad rámec GRANT)?**
   - Čo vieme: ARCHITECTURE odporúča GRANT, triggery len pre podmienené výnimky; PITFALLS 4 odporúča trigger.
   - Odporúčanie: zahrnúť (lacné, chráni pred chybou ownera a budúcim chybným GRANT); planner potvrdí, že Phase 7/9 nepotrebujú UPDATE na `invoices`.
2. **Presný zoznam kódov DPH a ich potvrdenie** (A1) — nezablokuje kód, len seed hodnoty.
3. **Trvalý príznak `tasks.import_billed`?** — odporúčané bez; planner môže zmeniť, ak vlastník chce.
4. **Číslo externej faktúry:** `external_ref` je voľný text (max 300 znakov), URL sa nezobrazuje ako aktívny odkaz bez validácie schémy `http(s)` (XSS/`javascript:`).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker Compose stack (php, db, nginx, mailpit) | vývoj a testy | ✓ | bežia (healthy) | — |
| PHP | všetko | ✓ | 8.5.12 | — |
| PostgreSQL | schéma, súbeh | ✓ | 18.6 | — |
| `pg_dump` | skript zálohy | ✓ | 18.6 (kontajner db) | — |
| mPDF | PDF | ✓ | 8.3.1 | — |
| `pdftotext` | test obsahu PDF | ✓ (kontajner php) | poppler | test sa preskočí (vzor MpdfSpikeTest) |
| `ext-pcntl` | (nepoužíva sa) | ✗ | — | `proc_open` workery, `pg_send_query` |
| `pg_send_query` (ext-pgsql) | deterministický test zámku | ✓ | — | — |

**Missing dependencies with no fallback:** none
**Missing dependencies with fallback:** `pcntl` (nepotrebný)

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 (`composer.json`), CI4 `CIUnitTestCase`, `failOnDeprecation/Notice/Warning/Risky` |
| Config file | `/mnt/c/personal-projects/task-tracker/phpunit.dist.xml` (suites `unit`, `integration`, `feature`) |
| Quick run command | `make test-unit` alebo `docker compose exec -T -u www-data php vendor/bin/phpunit --filter <Trieda>` |
| Full suite command | `make test` (`perms` + `test-db` + `composer test`), potom `composer stan`, `composer cs` |

Poznámky: testovacia DB sa buduje `scripts/test-db.sh` (migrácie ako `app_owner`), integračné testy dedia `PgTestCase` (`rw()` = `app_rw`), čistia cez `MasterFixtures::resetMasterTables()` (TRUNCATE ako owner), hodiny `FrozenClock` cez `Services::injectMock('clock', …)`.

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INV-02, INV-09 | zaokrúhlenie, súčet = súčet riadkov, kód DPH z profilu | unit | `phpunit --filter InvoiceCalculatorTest` | ❌ Wave 0 |
| INV-04 | `202610001`, `202610002`, `202611001`, initial_number, ročný reset, overflow | integration (rw) | `phpunit --filter NumberAllocatorTest` | ❌ Wave 0 |
| INV-04, INV-14 | 50 vystavení na 2 procesoch bez medzier a duplicít | integration (proc_open, rw) | `phpunit --filter IssueConcurrencyTest` | ❌ Wave 0 |
| INV-04 | `pg_send_query`: B čaká na A; po ROLLBACK A dostane B rovnaké číslo | integration | `phpunit --filter CounterLockTest` | ❌ Wave 0 |
| INV-14 | zlyhanie po prideleni čísla (FaultInjection) vráti číslo aj uvoľní časti, žiadna faktúra/PDF | integration | `phpunit --filter IssueRollbackTest` | ❌ Wave 0 |
| INV-14, D-06 | druhé vystavenie rovnakých častí = 409/idempotentné; zmenený preview_token = 409 | integration + feature | `phpunit --filter IssueIdempotencyTest` | ❌ Wave 0 |
| INV-15 | spätný dátum bez potvrdenia odmietnutý, s potvrdením prejde (server) | integration | `phpunit --filter BackdateTest` | ❌ Wave 0 |
| ALC-03 | úprava/zmazanie zamknutej časti: server 409 aj DB trigger; `app_rw` nevie nastaviť `invoice_id` priamo | integration (rw) | `phpunit --filter AllocationLockTest` (+ upraviť `AllocationGuardTest`) | ⚠️ upraviť |
| INV-03, D-20 | `UPDATE/DELETE/TRUNCATE` na `invoices*` pod `app_rw` = permission denied; owner UPDATE = trigger výnimka; čítač nejde znížiť | integration (rw + owner) | `phpunit --filter InvoiceImmutabilityTest` | ❌ Wave 0 |
| INV-08 | všetky stĺpce M1–M16, `CHECK` druhov (`issued` vs `external`), `UNIQUE` | integration | `phpunit --filter InvoiceSchemaTest` | ❌ Wave 0 |
| INV-13 | `sha256` v DB = `hash('sha256')`, CHECK, deferred trigger (faktúra bez PDF sa nedá commitnúť) | integration | `phpunit --filter InvoiceDocumentTest` | ❌ Wave 0 |
| INV-03, INV-13 | „zmeň subjekt, klienta, sadzbu, menu a zákonný text po vystavení“ ⇒ rovnaké PDF bajty a `sha256`, rovnaký snapshot | integration | `phpunit --filter InvoiceImmutableAfterChangeTest` | ❌ Wave 0 |
| INV-01, INV-02 | zhoda súčtov obrazovka (preview) = faktúra = snapshot = text v PDF (`pdftotext`) | feature | `phpunit --filter InvoicingScreenTest` | ❌ Wave 0 |
| INV-01 | presun časti z náhľadu, predvolený mesiac/klient/subjekt, ukončený subjekt sa neponúka | feature | `phpunit --filter InvoicingPreviewTest` | ❌ Wave 0 |
| D-10 | mena klienta ≠ mena subjektu odmietnutá; zmiešané meny častí | integration | `phpunit --filter CurrencyGuardTest` | ❌ Wave 0 |
| D-13 | `isFormatLocked` true po faktúre; `SeriesService::update` odmietne | integration | rozšíriť `SeriesServiceTest` | ⚠️ upraviť |
| D-22 | `invoices:lock-historical`: dry-run nič nezapíše, `--expect-*` guard, idempotencia, externá faktúra bez čísla/PDF, `counts_toward_limit=false`, časti zamknuté | integration (CLI) | `phpunit --filter LockHistoricalTest` | ❌ Wave 0 |
| D-24 | `scripts/backup.sh` produkuje čitateľný dump | unit (shell guard, vzor `tests/unit/Scripts`) | `phpunit --filter BackupScriptTest` | ❌ Wave 0 |
| NFR-06 | uvedené testy v `composer test` | — | `composer test` | — |

### Sampling Rate
- **Per task commit:** `phpunit --filter <dotknutá trieda>` + `composer stan` (unit a PHPStan rýchle)
- **Per wave merge:** `make test` (celé suity vrátane `integration`)
- **Phase gate:** `make test`, `composer stan`, `composer cs`, `node --test tests/js/` zelené pred `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/_support/IssueFixtures.php` — vytvorenie subjektu, klienta, úloh, častí a `issueCommand()` (rozšíriť `MasterFixtures`, pridať nové tabuľky do `resetMasterTables()`)
- [ ] `tests/_support/issue_worker.php` + bootstrap pre proces bez PHPUnit (spojenie `tests`, `FakePdfRenderer`)
- [ ] `tests/_support/FakePdfRenderer.php` a `FaultInjectingRenderer` (vyhodí výnimku po pridelení čísla)
- [ ] migrácie a prebudovanie testovacej DB (`scripts/test-db.sh`)
- [ ] žiadny nový framework netreba

## Security Domain

### Applicable ASVS Categories (ASVS level 1, `security_enforcement` zapnuté)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (existujúce Shield + TOTP) | existujúce, nové routy za filtrom `auth` |
| V3 Session Management | no | existujúce |
| V4 Access Control | yes | všetky dotazy nesú `client_id`/`entity_id`; ID faktúry v URL overiť (jediný používateľ, ale zachovať IDOR vzor Phase 3); CLI príkaz len cez `spark` |
| V5 Input Validation | yes | validácia mesiaca (`BillingMonth::parse`), dátumov (`Clock::parseDate`), id častí ako `int`, `external_ref` dĺžka a schéma URL, uuid `request_key` regexom |
| V6 Cryptography | yes | `hash('sha256')`, `hash_equals`; nič vlastné; `preview_token` len proti zastaranému náhľadu, nie autorizácia |
| V7/V8 Errors, data protection | yes | PDF `Cache-Control: private, no-store`, `nosniff`; audit bez snapshotu |
| V13 API/CSRF | yes | CSRF token pre POST (htmx hlavička už v layoute), idempotencia cez `request_key` |

### Known Threat Patterns for CI4 + PostgreSQL + mPDF

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Úprava vystavenej faktúry (chyba kódu, SQLi) | Tampering | GRANT insert-only + `immutable_guard` + `alloc_guard` + CHECK sha256 |
| Duplicitné/preskočené číslo pri súbehu | Tampering/Repudiation | upsert čítača v transakcii, UNIQUE, serializácia radu |
| HTML/JS injekcia do PDF šablóny (názov úlohy, názov klienta) | Tampering/Info disclosure | `esc()` všetkých hodnôt v šablóne pre mPDF (mPDF spracúva HTML a môže načítať `<img src>`/`<link>`); `$mpdf->curlAllowUnsafeSslRequests` ostáva false, vypnúť externé zdroje (`whitelistStreamWrappers` ponechať predvolené, `allow_url_fopen` neotvárať), žiadne užívateľské URL ako zdroje obrázkov |
| `javascript:`/neplatná schéma v `portal_url` alebo `external_ref` | Tampering | validovať `http(s)` pred uložením do snapshotu a pred vykreslením odkazu (`rel="noopener"`) |
| Header injection v `Content-Disposition` (číslo s `/`, `"`) | Tampering | sanitizovať názov súboru `[^A-Za-z0-9._-]` |
| SQL injection pri `= ANY(:ids)` | Tampering | pole zostaviť len z `int` castov, nikdy z reťazcov |
| Dvojklik / replay POST | Repudiation | `request_key`, 409/idempotentné presmerovanie |
| SECURITY DEFINER eskalácia | Elevation | `SET search_path = public, pg_temp`, `REVOKE … FROM PUBLIC`, funkcia nič nevracia okrem počtu, kontroluje klient/mesiac/menu |
| Únik PDF cez cache/nginx | Info disclosure | `no-store` pre autentifikované odpovede (nginx už má pre HTML), PDF bez statického adresára |

## Sources

### Primary (HIGH confidence)
- Repo kód (priamo prečítaný): `app/Libraries/Platform/{Transaction,Money,Hours,Clock}.php`, `Master/{SeriesService,SeriesFormat,EntityVersionResolver,LegalProfileResolver,BankAccountSelector,ClientService}.php`, `Work/{AllocationService,TaskService,BillingMonth,LastClient}.php`, `Audit/AuditLogger.php`, migrácie `2026-10-25-000002..000009`, `docker/postgres/provision.sql`, `tests/_support/*`, `tests/integration/MpdfSpikeTest.php`, `scripts/spikes/mpdf.php`, `.planning/phases/01-.../01-06-SUMMARY.md`, `.planning/research/{ARCHITECTURE,PITFALLS,FEATURES}.md`, `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.4/6.5
- Živé overenie v dev kontajneroch (2026-10-26): upsert čítača vrátil číslo 1 a 2, rollback vrátil čítač; `bytea` round-trip cez ext-pgsql (hex, `pg_unescape_bytea`, `sha256()` zhoda s PHP); `pcntl` nie je načítaný; PG 18.6, PHP 8.5.12, mPDF 8.3.1, `pdftotext` a `pg_dump` 18.6 prítomné
- mPDF zdrojový kód `vendor/mpdf/mpdf/src/Writer/MetadataWriter.php` (použitie `time()`/`date()`)

### Secondary (MEDIUM confidence)
- PostgreSQL dokumentácia (`INSERT … ON CONFLICT`, `CREATE FUNCTION` SECURITY DEFINER, `CREATE CONSTRAINT TRIGGER`); správanie overené skúšobným behom v scratch DB (PG 18.6, 2026-10-26): deferred constraint trigger odmietol commit faktúry bez PDF, `CHECK (sha = encode(sha256(content),'hex'))` odmietol nesprávny hash, SECURITY DEFINER funkcia zamkla riadok, ktorý rola s iba SELECT/INSERT priamo `UPDATE`-núť nemohla (permission denied)

### Tertiary (LOW confidence)
- konkrétne kódy DPH (A1), chronologický backdate (A2)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, žiadne nové balíky, verzie overené v kontajneroch
- Architecture: HIGH pre zámky, číslovanie a bytea (overené alebo odvodené z existujúceho kódu); deferred constraint trigger, sha256 CHECK a SECURITY DEFINER overené skúšobným behom
- Pitfalls: HIGH, väčšina vychádza z PITFALLS.md a z priamo pozorovaného kódu a testov

**Research date:** 2026-10-26
**Valid until:** 2026-11-25 (stabilný stack; pri aktualizácii mPDF alebo PG minor znova skontrolovať)
