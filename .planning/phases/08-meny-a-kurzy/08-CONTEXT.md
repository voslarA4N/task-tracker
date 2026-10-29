# Phase 8: Meny a kurzy - Context

**Gathered:** 2026-10-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník vystaví faktúru v mene odlišnej od meny subjektu (napr. PL subjekt v PLN, klient v EUR). V **náhľade** sa automaticky načíta kurz z oficiálneho zdroja podľa krajiny subjektu (PL: NBP, SK: ECB, CZ: ČNB), používateľ ho môže ručne prepísať a pri nedostupnom API ho zadá ručne bez zablokovania vystavenia. Kurz, zdroj, dátum (a číslo tabuľky) a súčet prepočítaný na menu subjektu sa pri vystavení uložia do kópie údajov aj do štruktúrovaných stĺpcov faktúry. PDF obsahuje obe sumy. Neskoršia zmena kurzu v zdroji ani v cache vystavený doklad nezmení. Dátum, ku ktorému sa kurz hľadá, sa nastavuje v právnom profile krajiny a zoznam mien sa dá rozšíriť bez zmeny modelu.

V tejto fáze: CUR-01, CUR-02, CUR-03, CUR-05.

Mimo tejto fázy: CUR-04 (prehľady a limity čítajú `total_entity_ccy`, Phase 10/11), dobropis v cudzej mene so zdedeným kurzom (Phase 9), „uhradené v mene subjektu“ a kurzové rozdiely pri úhradách (Phase 11 alebo mimo V1), KSeF polia `KursWalutyZ` (mimo V1, údaje sa však už ukladajú).

</domain>

<decisions>
## Implementation Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/` bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Medzery, ktoré dokumenty nepokrývajú, vlastník potvrdil 2026-10-30 (D-05, D-11, D-15). Zvyšok je v „Claude's Discretion“.

### Meny (CUR-01)
- **D-01:** Zoznam mien je **dátový**: nová tabuľka `currencies` (`code char(3) PK`, počet desatinných miest, `active`, poradie). Seed EUR, PLN, CZK. Pevné `CHECK (currency IN ('EUR','PLN','CZK'))` v `entities`, `clients`, `entity_bank_accounts`, `billing_allocations` a `invoices` sa novou migráciou nahradia cudzím kľúčom na `currencies(code)`. Pridanie meny = nový riadok (migrácia alebo seed), bez zmeny schémy. PHP enum `App\Libraries\Master\Currency` sa nahradí čítaním z tabuľky, prípadne zostane len ako zoznam predvolených hodnôt. Zdroj: CUR-01, spec §6.10.
- **D-02:** Jedna faktúra má vždy jednu menu = mena častí (mena klienta). Kontrola miešaných mien `InvoiceGuards::partsCurrency()` ostáva. Zdroj: spec §6.10, Phase 5 D-10.
- **D-03:** Odmietnutie `currency_mismatch` z Phase 5 D-10 (`InvoiceGuards::assertCurrency`) sa **nahradí** požiadavkou na kurz: ak sa mena faktúry líši od meny subjektu, vystavenie vyžaduje platný kurz (stiahnutý alebo ručný) v náhľade, inak sa odmietne so zrozumiteľnou správou. Pri rovnakej mene je `fx = null` a `total_entity_ccy = total` (súčasné správanie). Zdroj: CUR-02, Phase 5 D-10.
- **D-04:** Bankový účet sa vyberá podľa **meny faktúry** cez existujúci `BankAccountSelector` (Phase 3 D-06, Phase 5 snapshot). Chýbajúci účet v mene faktúry ostáva chybou `no_bank_account`, kurz na tom nič nemení.

### Zdroj a dátum kurzu (CUR-02, CUR-05)
- **D-05:** Nastavenie kurzu je **v právnom profile krajiny** a má tri položky: zdroj (`NBP` | `ECB` | `CNB`), základ dátumu (`delivery_date` | `issue_date`, predvolene `delivery_date`) a pravidlo dňa (`previous_business_day` = posledný publikovaný kurz prísne pred dátumom | `on_or_before` = kurz platný v daný deň, inak posledný pred ním). **Predvolené hodnoty (vlastník 2026-10-30):**
  - PL: NBP, dátum dodania, `previous_business_day` (spec),
  - SK: ECB, dátum dodania, `previous_business_day` (zvyklosť podľa SK zákona o účtovníctve: kurz z predchádzajúceho dňa),
  - CZ: ČNB, dátum dodania, `on_or_before` (CZ zákon o účetnictví: kurz platný v deň uskutočnenia).
  Všetko je konfigurácia s možnosťou zmeny po vyjadrení účtovníčky (riziko R1), nič nie je natvrdo. Zdroj: CUR-02, CUR-05, spec §6.10, FEATURES M15, PITFALLS Pasca 3 a 8, ROADMAP poznámka „závislosť od účtovníčky“.
- **D-06:** Nikdy vlastný kalendár sviatkov. Autoritou pre pracovný deň je API zdroja:
  - **NBP:** tabuľka A, rozsah `/api/exchangerates/rates/a/{kód}/{D-10}/{D-1}/?format=json`, posledný prvok `rates`. Uloží sa `effectiveDate` a číslo tabuľky `no` (napr. `209/A/NBP/2026`). Pri `on_or_before` sa horná hranica posunie na D.
  - **ECB:** kurz je „1 EUR = X cudzej meny“. Pre dátum v minulosti SDMX `data-api.ecb.europa.eu` (`format=csvdata`), prípadne denný či 90-dňový XML. Vezme sa posledný pozorovaný deň podľa pravidla dňa.
  - **ČNB:** `api.cnb.cz/cnbapi/exrates/daily?date=…`, uloží sa `validFor`, `order` (ako číslo tabuľky) a **`amount`**, kurz za 1 jednotku = `rate / amount`. Textový `daily.txt` slúži ako záloha.
  Presné parametre (SDMX, limit rozsahu NBP, časy publikácie) overí `/gsd-research-phase`. Zdroj: CLAUDE.md §Kurzy mien, PITFALLS Pasca 2 a 3, ROADMAP poznámky.
- **D-07:** Kurz sa sťahuje **v kroku náhľadu** (htmx request), nikdy počas transakcie vystavenia ani pod zámkom čítača čísiel. Timeout je krátky (cca 3 s). Výsledky sa cachujú v DB tabuľke kurzov s unikátnym kľúčom zdroj + mena + deň platnosti. Cache je pomôcka, faktúra sa nikdy nespolieha na neskorší obsah cache, len na vlastnú uloženú kópiu. Zdroj: PITFALLS Pasca 3 a 5, ROADMAP poznámky.
  - *Poznámka k implementácii (plánovanie 2026-10-30, schválené plan-checkerom):* cache `exchange_rate_cache` je kľúčovaná `zdroj + mena + bound_date` (deň, ku ktorému sa kurz hľadá, po aplikovaní pravidla dňa), nie `valid_for`. Dôvod: dotaz „posledný kurz ≤ bound“ sa bez uloženého bound nedá z cache zodpovedať (chýbajúci novší kurz sa nedá vylúčiť). `valid_for` (skutočný deň platnosti zo zdroja) sa ukladá ako údaj a ide do faktúry. Intencia D-07 ostáva zachovaná (pozri 08-04-PLAN).
- **D-08:** Zmena dátumu dodania alebo vystavenia v náhľade kurz znova vyhľadá. Kurz je súčasťou snapshotu, takže `InvoiceCalculator::previewToken()` sa pri zmene kurzu zmení a vystavenie so zastaraným náhľadom sa odmietne (existujúci mechanizmus z Phase 5). Zdroj: PITFALLS Pasca 6 (preview token vrátane kurzu).

### Prepočet a zaokrúhlenie
- **D-09:** Kurz sa ukladá **presne tak, ako ho zdroj publikuje** (NBP 4 desatinné, ECB 4, ČNB 3 + `amount`) do `fx_rate numeric(18,8)` spolu so smerom (`base`/`quote`). Nikdy sa neukladá prevrátený kurz. Zdroj: PITFALLS Pasca 2, ARCHITECTURE (kurzy `numeric(18,8)`).
- **D-10:** Prepočítava sa **súčet faktúry** (nie riadky) jedným kurzom, bcmath, zaokrúhlenie **raz half-up na 2 desatinné** → `total_entity_ccy`. Smer podľa zdroja: NBP a ČNB násobia (`total × rate / amount`), ECB pri mene subjektu EUR delí (`total / rate`). Jediná funkcia s jednotkovými testami pre všetky smery (EUR→PLN, PLN→EUR, CZK→EUR, EUR→CZK, PLN→CZK) a hraničné prípady. Pravidlo zaokrúhlenia je zdokumentované a dá sa zmeniť po vyjadrení účtovníčky. Zdroj: PITFALLS Pasca 1 a 2, CLAUDE.md (bcmath, nikdy float), ROADMAP „pravidlo zaokrúhľovania“.

### Ručný prepis a nedostupné API (CUR-02, CUR-03)
- **D-11:** Ručný prepis v náhľade **nevyžaduje dôvod** (vlastník 2026-10-30). Pri prepise sa uloží `fx_source = 'manual'`, ručný kurz a dátum, ku ktorému platí (predvolene vypočítaný deň podľa D-05). Do kópie údajov sa uloží **aj pôvodne stiahnutý kurz** (hodnota, zdroj, dátum, tabuľka), ak bol k dispozícii. Vystavenie s ručným kurzom sa zapíše do `audit_log`. Zdroj: CUR-02, PITFALLS Pasca 3 (`manual_override`).
- **D-12:** Ak API zlyhá (timeout, 4xx/5xx, prázdny rozsah), náhľad ukáže upozornenie a pole na ručné zadanie. Vystavenie **nie je blokované**, len sa bez kurzu nedá vystaviť (D-03). Chyba sa zapíše do aplikačného logu. Zdroj: CUR-03, ROADMAP kritérium 2, PRD R3.
- **D-13:** Ručne zadaný kurz sa zadáva **v rovnakom smere, aký publikuje zdroj profilu**, s jasným popisom (napr. „1 EUR = ___ PLN“). Validácia na serveri: kladné číslo, najviac 8 desatinných miest, bez floatu. Zdroj: D-09, PITFALLS Pasca 2 (zámena smeru).

### Uloženie a nemennosť
- **D-14:** Pri vystavení sa vyplnia existujúce stĺpce z Phase 5 D-19 (`fx_rate`, `fx_source`, `fx_date`, `fx_table_no`, `fx_date_basis`, `total_entity_ccy`) a snapshot `fx` (dnes `null`) s položkami: zdroj, kurz, `amount`, smer, deň platnosti, číslo tabuľky, základ dátumu, pravidlo dňa, mena subjektu, prepočítaný súčet, príznak ručného zadania, pôvodne stiahnutý kurz a **predformátovaný text pre PDF** v jazyku dokladu. `snapshot_schema` sa zvýši. Prehľady a limity budú čítať stĺpce, nikdy tabuľku kurzov (CUR-04, Phase 10/11). Zdroj: CUR-02, INV-03, Phase 5 D-18/D-19, PITFALLS Pasca 2 a 4.

### PDF a zobrazenie
- **D-15:** Na PDF (`inv-2`) je **riadok pod súčtom** (vlastník 2026-10-30), riadky položiek ostávajú len v mene faktúry:
  ```
  Spolu:                1 000,00 €
  Kurz NBP 1 EUR = 4,2512 PLN
  z 28. 10. 2026, tab. 209/A/NBP/2026
  Spolu v PLN:          4 251,20 zł
  ```
  Pri ručnom kurze sa namiesto zdroja zobrazí „ručne zadaný kurz“ a dátum, ku ktorému platí. Popisky sú preložené pre SK, PL, CS a EN a uložené v snapshote (LABEL_KEYS). Formát čísel, mien a dátumov podľa jazyka dokladu (`MoneyFormat`). Šablóna má pre `fx` miesto už od Phase 6. Zdroj: CUR-02 („obe sumy sa vytlačia na PDF“), Phase 6 CONTEXT, spec §7.

### Claude's Discretion
- Názvy a presná štruktúra tabuliek (`currencies`, cache kurzov, nové stĺpce právneho profilu alebo samostatná tabuľka nastavení kurzu podľa krajiny). Nastavenie má byť editovateľné v existujúcich nastaveniach právnych profilov (`Settings/LegalTexts`) a zmeny idú do `audit_log`.
- Rozhranie `RateProvider` s implementáciami NBP, ECB, ČNB (ARCHITECTURE: `Rates/{Nbp,Ecb,Cnb,Manual}`), HTTP klient CI4 `CURLRequest` s timeoutom a testy na fixtúrach (žiadne živé volania v PHPUnit).
- UX v náhľade (mobil, 44 px): zobrazenie kurzu, zdroja a prepočítanej sumy, tlačidlo „Zadať ručne“ a stav načítania cez htmx.
- Zobrazenie druhej sumy na detaile a v zozname faktúr (z `total_entity_ccy`).
- Rozšírenie staging seedu (`StagingSeed`) o faktúru v cudzej mene, ak to pomôže UAT.
- Či sa kurzy sťahujú len na požiadanie, alebo aj predbežne (napr. cron). Predvolene len na požiadanie.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Požiadavky a rozsah
- `.planning/ROADMAP.md` §Phase 8: cieľ, kritériá úspechu 1–4, poznámky (research, smer ECB, ČNB `amount`, kurz v náhľade, závislosť od účtovníčky)
- `.planning/REQUIREMENTS.md`: CUR-01, CUR-02, CUR-03, CUR-05 (CUR-04 pre kontext, Phase 10)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.10 Meny a kurzy, §6.5 kópia údajov, §7 formát čísel a mien
- `docs/PRD.md` E9 (CUR-01..03), INV-03, NFR-06, riziko R3

### Výskum a pasce
- `.planning/research/PITFALLS.md` Pasca 1 (float), Pasca 2 (prepočet a zaokrúhlenie), Pasca 3 (dátum kurzu, sviatky, cache, timeout), Pasca 4 (nemennosť), Pasca 5 (externé volania mimo transakcie), Pasca 8 (základ dátumu), Pasca 6 (preview token), tabuľka integrácií (ECB, ČNB), kontrolný zoznam kurzu
- `.planning/research/FEATURES.md` M15 (konfigurovateľný dátum kurzu), KSeF `KursWalutyZ`
- `.planning/research/ARCHITECTURE.md`: MasterData `RateProvider`, `Rates/{Nbp,Ecb,Cnb,Manual}`, `numeric(18,8)` pre kurzy
- `CLAUDE.md` §Kurzy mien (overené URL a správanie NBP, ECB, ČNB z 2026-10-23)

### Predchádzajúce fázy
- `.planning/phases/05-faktura-n-jadro/05-CONTEXT.md` D-10 (odmietnutie inej meny, ktoré sa teraz nahrádza), D-18 (snapshot), D-19 (kurzové stĺpce)
- `.planning/phases/06-dokumenty-fakt-ry-a-v-kazu/06-CONTEXT.md`: miesto pre `fx` v šablóne `inv-2`
- `.planning/phases/07-doru-enie-hrady-a-upomienky/07-CONTEXT.md`: odložené „uhradené v mene subjektu“

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php`: stĺpce `fx_rate numeric(18,8)`, `fx_source ('NBP','ECB','CNB','manual')`, `fx_date`, `fx_table_no`, `fx_date_basis`, `total_entity_ccy` už existujú (NULL).
- `app/Libraries/Invoicing/SnapshotBuilder.php`: `'fx' => null` (riadok ~169), `LABEL_KEYS` na preložené popisky.
- `app/Views/pdf/invoice_inv2.php:132`: blok `if (! empty($s['fx']))` vypisuje `fx.text`, na D-15 ho treba rozšíriť.
- `app/Libraries/Invoicing/InvoiceCalculator.php::previewToken()`: hash celého snapshotu, takže kurz je v tokene automaticky.
- `app/Libraries/Invoicing/InvoiceGuards.php`: `partsCurrency()` ostáva, `assertCurrency()` sa mení (D-03).
- `app/Libraries/Platform/MoneyFormat.php`: formát súm podľa jazyka.
- `app/Libraries/Master/BankAccountSelector.php`: účet podľa meny faktúry.
- `app/Controllers/Settings/LegalTexts.php`: existujúce UI právnych profilov a textov (Phase 6 SET-01), miesto pre nastavenie kurzu.

### Established Patterns
- Peniaze ako string + bcmath, `numeric` z DB nikdy `(float)`.
- Insert-only `invoices`, `app_rw` práva cez default privileges a `grantMutable()`. Migrácie cez `spark migrate -g owner`.
- Pevné `CHECK (currency IN …)` v 5 tabuľkách (`entities`, `clients`, `entity_bank_accounts`, `billing_allocations`, `invoices`) a PHP enum `Currency` sa používa na 9 miestach. Oboje treba prerobiť kvôli D-01.
- Opravy konfigurácie novou migráciou, nikdy editom starej (komentár v `CreateLegalProfiles`).
- Náhľad faktúry: `InvoiceDraftService::draft()` → `Draft` s tokenom, htmx re-render.

### Integration Points
- `InvoiceDraftService::draft()`: vyhľadanie kurzu podľa profilu subjektu a dátumov, ručný prepis z formulára.
- `InvoiceIssuer` / `SnapshotBuilder`: zápis `fx` a stĺpcov v transakcii (bez sieťového volania).
- Šablóna PDF `inv-2` a popisky v jazykových súboroch (SK, PL, CS, EN).
- Detail a zoznam faktúr (`app/Views/invoices/show.php`, `_list.php`): druhá suma.

</code_context>

<specifics>
## Specific Ideas

- Riadok na PDF presne podľa schválenej ukážky v D-15 (`Kurz NBP 1 EUR = 4,2512 PLN` / `z 28. 10. 2026, tab. 209/A/NBP/2026` / `Spolu v PLN: 4 251,20 zł`).
- Testovacie dátumy z PITFALLS: pondelok po víkende, 2. 11., 1. 1., 11. 11. (PL sviatok), nedostupné NBP (ručné zadanie).
- Hlavný scenár použitia: PL subjekt (PLN) fakturuje klientovi All4Net v EUR.

</specifics>

<deferred>
## Deferred Ideas

- „Uhradené v mene subjektu“ a kurzové rozdiely pri úhradách cudzej meny: Phase 11 (prehľady) alebo mimo V1.
- Prehľady a limity z `total_entity_ccy` (CUR-04): Phase 10 a 11.
- Dobropis v cudzej mene preberá kurz pôvodnej faktúry: Phase 9.
- UI na pridávanie mien (V1: nová mena = riadok v tabuľke cez migráciu alebo seed).
- KSeF FA(3) `KursWalutyZ`: mimo V1.

</deferred>

---

*Phase: 08-meny-a-kurzy*
*Context gathered: 2026-10-30*
