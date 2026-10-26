# Phase 5: Fakturačné jadro - Context

**Gathered:** 2026-10-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník na obrazovke „Fakturácia mesiaca“ vyberie klienta, mesiac a subjekt, skontroluje náhľad nezamknutých častí (bez čísla) a vystaví faktúru. Vystavenie v jednej transakcii zamkne časti, vypočíta sumy, uloží kópiu údajov (JSONB aj štruktúrované stĺpce), pridelí číslo bez medzier a uloží autoritatívne PDF so `sha256`. Po vystavení faktúru nič nezmení (služba, DB oprávnenia, triggery, testy).

V tejto fáze: INV-01, INV-02, INV-03, INV-04, INV-08, INV-09, INV-13, INV-14, INV-15, ALC-03, NFR-06 a zamknutie historických častí z WR-07.

Mimo tejto fázy (schéma na ne musí byť pripravená, logika nie): právne profily a finálne PDF, QR platba, výkaz práce (Phase 6), e-mail, úhrady, upomienky (Phase 7), kurzy (Phase 8), dobropisy (Phase 9), limity a predkontrola limitu (Phase 10), prehľady a exporty (Phase 11).

</domain>

<decisions>
## Implementation Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/`, bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Jediná nová otázka bola WR-07 (D-22).

### Obrazovka „Fakturácia mesiaca“ (INV-01, INV-15)
- **D-01:** Používateľ vyberie klienta, fakturačný mesiac a subjekt. Subjekt sa predvyplní podľa dátumu vystavenia (subjekt aktívny k tomu dátumu, Phase 3 D-07) a dá sa zmeniť, aj na prelome dvoch subjektov. Ukončený subjekt sa pre novú faktúru neponúka.
- **D-02:** Náhľad ukazuje nezamknuté časti klienta s daným mesiacom, riadky, súčet, dátumy, subjekt, bankový účet a menu. **Náhľad nemá číslo** a nič nezapisuje. Vzniká tým istým `SnapshotBuilder` + `InvoiceCalculator` v režime „preview“ ako vystavenie, takže čo sa zobrazí, to sa vystaví (výskum ARCHITECTURE).
- **D-03:** Časť sa dá z náhľadu vyradiť presunom do iného mesiaca (predvolene nasledujúci). Presun ide cez existujúci `AllocationService` (atómové `WHERE invoice_id IS NULL`, audit). Náhľad sa obnoví cez htmx.
- **D-04:** Predvolené dátumy (INV-02, spec 6.5): vystavenie = dnes (`Clock`), dodanie = posledný deň fakturačného mesiaca, splatnosť = vystavenie + `due_days` klienta, inak `due_days` verzie subjektu. Všetky tri sa dajú pred vystavením upraviť.
- **D-05:** Spätný dátum vystavenia je povolený (INV-15). Ak je skorší ako dátum vystavenia poslednej faktúry v rade, náhľad zobrazí varovanie a vystavenie vyžaduje výslovné potvrdenie. Kontrolu robí server, nie iba UI.
- **D-06:** Vystavenie je chránené proti dvojitému odoslaniu (dvojklik, opakovaný htmx request): druhý pokus nevytvorí druhú faktúru, vráti 409 so správou (časti sú už zamknuté) alebo presmeruje na už vystavenú faktúru. Po úspechu `HX-Location` na detail faktúry (vzor Phase 4 D-15).

### Položky a sumy (INV-02, INV-09)
- **D-07:** Jedna položka za každú časť: názov úlohy, hodiny, sadzba, suma, kód DPH. Položka si nesie aj `source_allocation_id` a `source_task_id` (bez FK, výskum ARCHITECTURE), URL portálu a nacenené hodiny úlohy, aby z nich Phase 6 vedela urobiť výkaz bez čítania živých tabuliek.
- **D-08:** Suma riadku = `round(hours × rate, 2)` half-up cez `Money`, súčet = súčet zaokrúhlených riadkov. Je to jediné miesto výpočtu (`InvoiceCalculator`), ktoré používa náhľad, vystavenie, PDF aj testy. Pravidlo zaokrúhlenia sa zapíše do snapshotu.
- **D-09:** Kód DPH na položke (M1) sa predvolí z právneho profilu (krajina, DPH režim, režim fakturácie). Pre PL art. 113 je to `zw`. Hodnota pre SK neplatiteľa sa seeduje **predbežne** a potvrdí ju účtovníčka (riziko R1). Výpočty pre platiteľa DPH sa neimplementujú.
- **D-10:** Mena faktúry = mena častí (mena klienta). Server overí, že všetky časti majú rovnakú menu. **Ak sa mena faktúry líši od meny subjektu, vystavenie sa v tejto fáze odmietne** so zrozumiteľnou správou. Kurzy pridá Phase 8. Stĺpce pre kurz (sadzba, zdroj, dátum, číslo tabuľky, základ dátumu M15) sú v schéme už teraz, s hodnotou NULL.

### Číslovanie (INV-04, INV-14)
- **D-11:** Číslo sa pridelí jedným príkazom `INSERT … ON CONFLICT DO UPDATE … RETURNING` na tabuľke čítačov (rad × obdobie podľa `SeriesFormat::periodKey()` z dátumu vystavenia), v tej istej transakcii ako vystavenie. Nikdy `SEQUENCE` ani `MAX()+1` (ROADMAP).
- **D-12:** Mesiac a rok v čísle sa berú z dátumu vystavenia (spec 6.5). Reset poradia je mesačný, ak formát obsahuje `MM`, inak ročný. `initial_number` radu platí pre prvé číslo radu (rad, ktorý ešte nemá žiadny doklad). Ďalšie obdobia začínajú od 1. Ak by poradie prekročilo `maxSequence()`, vystavenie sa odmietne.
- **D-13:** `SeriesService::isFormatLocked()` (dnes vždy `false`) začne vracať `true`, keď rad obsahuje vystavený doklad (Phase 3 D-10).
- **D-14:** Akceptačný príklad: prvá faktúra 10/2026 = `202610001`, druhá `202610002`, prvá v 11/2026 = `202611001`. 50 súbežných vystavení na dvoch spojeniach nevytvorí duplicitné ani preskočené číslo. Zlyhanie ktoréhokoľvek kroku vráti číslo aj uvoľní časti.

### Atómové vystavenie a PDF (INV-13, INV-14)
- **D-15:** Jeden zapisovateľ `InvoiceIssuer::issue()`. V jednej transakcii zamkne časti (`FOR UPDATE`, poradie podľa id, kontrola počtu, poradie zámkov z Phase 4: najprv úloha, potom časť), vypočíta sumy, postaví snapshot, pridelí číslo, vloží faktúru a riadky, nastaví `invoice_id` na častiach, uloží PDF dokladu a zapíše audit.
- **D-16:** **PDF je autoritatívne** (vlastník potvrdil): vygeneruje sa raz pri vystavení zo snapshotu, uloží sa so `sha256` a verziou šablóny a zobrazenie vždy vráti uložené bajty, nikdy neregeneruje. Commitnutá faktúra **vždy** má svoje uložené PDF. Faktúra bez PDF ani PDF bez faktúry nesmie zostať. Mechanizmus (PDF v `bytea` vs súbor + riadok v transakcii s upratovaním, render vnútri transakcie vs pred commitom, vplyv na dĺžku zámku čítača) určí výskum. Výber musí pokryť zálohy.
- **D-17:** PDF v tejto fáze je funkčné minimum: čitateľná faktúra zo snapshotu (subjekt, klient, číslo, dátumy, položky, súčet, platobné údaje, zákonný text zo snapshotu). Šablóna má verziu (napr. `inv-1`). Finálny vzhľad, právne profily a QR sú Phase 6. Výkaz práce (REP-01) je Phase 6, schéma dokladov však rozlišuje druh (`invoice | report`).

### Kópia údajov a nemennosť (INV-03, INV-08, ALC-03)
- **D-18:** Snapshot (JSONB, verzovaný cez `snapshot_schema`) je **samostatne postačujúci** na vykreslenie faktúry aj budúceho výkazu: verzia subjektu (pripnutá cez `entity_version_id`, verzia platná k dátumu vystavenia), klient, mena, jazyk dokladu (klient, inak subjekt), DPH režim, režim fakturácie, kód oslobodenia, **vyriešené** zákonné texty v jazyku dokladu (nie odkaz na `legal_texts`), preložené popisky, URL portálu, bankový účet podľa meny (`BankAccountSelector`), kurz (zatiaľ NULL) a riadky. Chýbajúci zákonný text vystavenie zablokuje, nikdy ticho prázdny text (Phase 3 D-17).
- **D-19:** Štruktúrované stĺpce (INV-08, KSeF FA(3)) obsahujú všetky polia M1–M16 použiteľné v tejto fáze: kód DPH na riadku (M1), VAT-UE subjektu (M2), typ identifikátora klienta (M3), bankový účet (M4), kód oslobodenia (M5), pole pre dobropis (`corrected_invoice_id`, dôvod, typ opravy; M6, zatiaľ NULL), forma úhrady s predvolenou hodnotou „prevod“ a voliteľná poznámka (M13), `sha256` a verzia šablóny (M14), kurzové stĺpce (M15), a denormalizované `total_entity_ccy`, `counts_toward_limit` (z klienta k dátumu vystavenia), `turnover_date`, `client_id`. Prehľady a limity budú čítať tieto stĺpce, nie JSONB.
- **D-20:** `invoices` a `invoice_lines` sú pre `app_rw` **insert-only** (GRANT SELECT, INSERT, bez UPDATE/DELETE/TRUNCATE). Stav faktúry sa neukladá, odvodzuje sa (pohľad `invoice_status_v`, výskum ARCHITECTURE). Testy bežia aj pod rolou `app_rw` (ROADMAP kritérium 5).
- **D-21:** Zámok časti (ALC-03): `billing_allocations.invoice_id` dostane FK na `invoices`. Server odmieta úpravu zamknutej časti (už z Phase 4) a trigger `alloc_guard` ju odmieta v DB. Zamknutá časť v UI ukazuje číslo faktúry.

### Historické externé faktúry (WR-07)
- **D-22:** Časti importované z riadkov „Fakturované = ÁNO“ (dev DB: 09/2026, 11 častí, 89 h, 2 403,00 €) sa zamknú na **historickú externú faktúru** (vlastník 2026-10-26, voľba C a „externé číslo voliteľné“):
  - druh záznamu „externá“, **bez čísla z vlastného radu** (nespotrebuje `RRRRMM###`), s voliteľným textovým číslom alebo odkazom na doklad tretej osoby,
  - bez PDF, nezapočítava sa do obratu, limitov ani prehľadov vlastných faktúr (`counts_toward_limit = false`),
  - v zozname faktúr je zreteľne označená ako externá,
  - je rovnako nemenná (insert-only) a s auditom,
  - vzniká jednorazovým idempotentným `spark` príkazom (beh v dev aj na produkcii po importe reálnych dát), **pred prvým ostrým vystavením**.
  Schéma `invoices` musí tento druh povoliť bez oslabenia obmedzení pre vlastné faktúry (napr. `CHECK`, ktorý vyžaduje číslo a rad len pri vlastnej faktúre a dobropise).

### Testy (NFR-06)
- **D-23:** PHPUnit pokrýva: výpočet súm (zhoda náhľad = faktúra = snapshot = súčet riadkov), číslovanie vrátane súbehu (50 vystavení na dvoch spojeniach) a resetov obdobia, rollback pri zlyhaní neskoršieho kroku, zamykanie častí (server aj DB pod `app_rw`), nemennosť `invoices*` pod `app_rw`, kópiu údajov a test „zmeň subjekt, klienta, sadzbu a menu po vystavení a porovnaj bajty PDF a `sha256`“.

### Zálohy
- **D-24:** ROADMAP: „skript zálohy sa začína už tu“. V tejto fáze vznikne základný skript (`pg_dump --format=custom` + uložené PDF dokladov, ak sú na disku) s dokumentáciou v `docs/ops/`. Restic, Storage Box, rotácia 14/8/12 a test obnovy sú Phase 12.

### Claude's Discretion
Vlastník nechal na plánovača a výskum (môžu spresniť, nie obrátiť):
- Technické otázky z ROADMAP pre výskum: PDF v `bytea` vs súbor, render vnútri transakcie vs pred commitom, `transException` v CI4 4.7, determinizmus mPDF (rovnaké bajty pri opakovanom vykreslení nie sú potrebné, keďže sa neregeneruje, ale treba vedieť, či ID/dátum v PDF metadátach nezávisia od času mimo `Clock`).
- Predvolený mesiac na obrazovke (odporúčanie: najstarší mesiac s nezamknutými časťami vybraného klienta, inak predchádzajúci mesiac) a predvolený klient (posledný použitý, vzor `LastClient`).
- Poradie položiek na faktúre (napr. podľa dátumu vytvorenia úlohy, potom id časti).
- Rozsah zoznamu a detailu faktúry v tejto fáze: minimálny zoznam (záložka „Faktúry“ v spodnej lište) a detail s tlačidlom na zobrazenie uloženého PDF. Plné PC tabuľky a filtre sú Phase 11.
- Názvy tabuliek a tried podľa výskumu ARCHITECTURE (`InvoiceIssuer`, `InvoiceCalculator`, `SnapshotBuilder`, `NumberAllocator`, `DocumentStore`, `invoice_counters`, `invoice_documents`), presný tvar snapshotu a jeho JSON schéma.
- Text varovaní, tlačidiel a toastov, forma potvrdenia vystavenia na mobile (panel zospodu).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` §Phase 5 — cieľ, kritériá úspechu, poznámky (výskum, WR-07)
- `.planning/REQUIREMENTS.md` — INV-01..04, INV-08, INV-09, INV-13..15, ALC-03, NFR-06
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.1, §6.4, §6.5 (Číslovanie, Zákonné náležitosti), §6.10, §7 PC — doménový model faktúry
- `docs/PRD.md` — produktové požiadavky a míľniky

### Výskum
- `.planning/research/ARCHITECTURE.md` — Invoicing ako jediný zapisovateľ, schéma `invoices*`, obsah snapshotu, poradie krokov `InvoiceIssuer`, nemennosť v troch vrstvách, Documents/`DocumentStore`
- `.planning/research/FEATURES.md` — položky M1–M16, ktoré musí schéma obsahovať
- `.planning/research/PITFALLS.md` — pasce číslovania, súbehu, zaokrúhľovania a nemennosti
- `.planning/research/STACK.md` — mPDF, PG 18, číslovanie bez `SEQUENCE`

### Predchádzajúce fázy
- `.planning/phases/03-master-d-ta-subjekty-a-klienti/03-CONTEXT.md` — subjekty a verzie, rady, právny profil, `legal_texts`, audit
- `.planning/phases/04-lohy-faktura-n-asti-a-dashboard/04-CONTEXT.md` — `billing_allocations`, zámok cez `invoice_id`, poradie zámkov, htmx vzory
- `.planning/phases/04-lohy-faktura-n-asti-a-dashboard/04-REVIEW-FIX.md` §Owner decision WR-07 — historická externá faktúra
- `.planning/phases/01-z-klad-a-infra-trukt-ra/01-06-SUMMARY.md` — spike mPDF pod PHP 8.5 (čas, pamäť, diakritika)

### Prevádzka
- `docs/ops/import-excel.md` — import, po ktorom sa spúšťa zamknutie WR-07
- `docs/ops/staging.md` — nasadenie na staging

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Platform/Money.php`, `Hours.php`, `MoneyFormat.php`: výpočty a formát súm bez float.
- `app/Libraries/Platform/Transaction.php` (`Transaction::run`): transakčná hranica pre `InvoiceIssuer`.
- `app/Libraries/Platform/Clock.php` / `FrozenClock.php`: dnešný dátum a testy dátumov.
- `app/Libraries/Master/SeriesFormat.php` (`parse`, `render`, `periodKey`, `resetsMonthly`, `maxSequence`): formát a obdobie čísla, už otestované.
- `app/Libraries/Master/SeriesService.php`: `isFormatLocked()` je dnes stub (`false`), Phase 5 ho naplní. Úprava radu už zamyká riadky radu `FOR UPDATE` (serializácia s pridelením čísla, WR-02).
- `app/Libraries/Master/EntityVersionResolver.php` (`atDate`): verzia subjektu k dátumu vystavenia.
- `app/Libraries/Master/LegalProfileResolver.php` (`profile`, `exemptionCode`, `text`): kód oslobodenia a vyriešené zákonné texty pre snapshot.
- `app/Libraries/Master/BankAccountSelector.php` (`forCurrency`): bankový účet do snapshotu.
- `app/Libraries/Work/AllocationService.php`: presun časti do iného mesiaca pri vyradení z náhľadu.
- `app/Libraries/Work/BillingMonth.php`: práca s fakturačným mesiacom.
- `app/Libraries/Work/LastClient.php`: posledný použitý klient.
- `app/Libraries/Audit/AuditLogger.php`: audit v tej istej transakcii.
- `tests/integration/MpdfSpikeTest.php`: overené nastavenie mPDF (fonty, tempDir).

### Established Patterns
- Insert-only tabuľky cez GRANT (`entity_versions`, `audit_log`) a triggerová posledná línia (`alloc_guard` v `2026-10-25-000008_CreateBillingAllocations.php`).
- Atómové podmienené `UPDATE … WHERE … AND invoice_id IS NULL` s kontrolou počtu riadkov, chyby ako 409/422.
- Poradie zámkov: najprv `tasks` `FOR UPDATE`, potom `billing_allocations` (komentár tabuľky).
- Migrácie bežia pod `app_owner`, aplikácia pod `app_rw`. Testy oprávnení pod `app_rw`.
- htmx: po POST `HX-Location`/`HX-Redirect`, obnova fragmentov cez `HX-Trigger`, CSP bez inline skriptov, Alpine CSP build.
- Všetky texty cez `lang()`, doménové služby v `app/Libraries/<Kontext>/`.

### Integration Points
- `billing_allocations.invoice_id` → FK na nové `invoices`.
- Spodná lišta: položka „Faktúry“ v `_nav_items.php` sa stane aktívnym odkazom.
- Detail úlohy (Phase 4) zobrazí číslo faktúry pri zamknutej časti.
- Pohľad `task_billing_v` začne reálne ukazovať čiastočne vyfakturované a vyfakturované úlohy.
- Import (`app/Libraries/Work/Import/`) + nový `spark` príkaz pre WR-07.

</code_context>

<specifics>
## Specific Ideas

- Vystavenie musí byť správne a nemenné (Core Value): rovnaký súčet na obrazovke, v PDF, v snapshote a v štruktúrovaných stĺpcoch.
- Akceptačné čísla: `202610001`, `202610002`, `202611001`.
- WR-07 dáta v dev DB: 09/2026, 11 častí, 89 h, 2 403,00 €.

</specifics>

<deferred>
## Deferred Ideas

- Predkontrola limitu obratu v náhľade (M9): Phase 10.
- Faktúra v inej mene, ako je mena subjektu, s kurzom NBP/ECB/ČNB: Phase 8.
- Finálne PDF, právne profily, QR platba, výkaz práce: Phase 6.
- Odoslanie e-mailom, úhrady, stav „čiastočne uhradená“ (M7): Phase 7.
- Dobropis a uvoľnenie častí (výnimka v `alloc_guard`): Phase 9.
- PC tabuľky faktúr, filtre, exporty: Phase 11.
- Restic, Storage Box, rotácia a test obnovy: Phase 12.

</deferred>

---

*Phase: 05-faktura-n-jadro*
*Context gathered: 2026-10-26*
