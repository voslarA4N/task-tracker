# Phase 10: Limity obratu - Context

**Gathered:** 2026-11-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník v nastaveniach spravuje tabuľku limitov obratu a počiatočný obrat za subjekt a rok. Aplikácia počíta obrat za subjekt z uložených denormalizovaných stĺpcov vystavených faktúr a dobropisov, ukazuje čerpanie limitu so vzorcom, upozorní na dashboarde pri 80 % a 100 % (aj pri chýbajúcom riadku limitu pre aktuálny rok) a v náhľade faktúry upozorní, ak by vystavenie prekročilo prah.

Požiadavky: LIM-01, LIM-02, LIM-03, LIM-04, LIM-05, CUR-04 (časť pre limity).

Mimo tejto fázy: mesačné a ročné prehľady, CSV/XLSX exporty a ZIP pre účtovníčku (Phase 11, môžu znovu použiť `TurnoverCalculator`), prehliadač denníka (Phase 11), tabuľkové PC zoznamy (Phase 11), preklady PL/CZ/EN (Phase 12), právne overenie hodnôt limitov účtovníčkou (mimo kódu, riešené konfiguráciou).

Postup: vlastník rozhodol prevziať rozhodnutia zo špecifikácie, PRD, REQUIREMENTS, výskumu a predošlých fáz bez ďalšej diskusie (2026-11-02).

</domain>

<decisions>
## Implementation Decisions

### Tabuľka limitov (LIM-01, LIM-05)
- **D-01:** Nová tabuľka `turnover_limits` upraviteľná v Nastavenia → Limity: krajina (`SK|PL|CZ`, rovnaký zoznam ako `entities.country`), rok platnosti, názov, suma `numeric(12,2)`, mena (FK na `currencies`), spôsob výpočtu, prahy upozornenia v percentách (predvolené `{80,100}`). Žiadna hodnota limitu v kóde ani v `.env`. Zdroj: spec §6.8, LIM-01, ARCHITECTURE schéma `turnover_limits`.
- **D-02:** Na jednu krajinu a rok môže byť **viac riadkov** (napr. SK 50 000 € kalendárne, SK 49 790 € kĺzavo, SK 62 500 € okamžitá registrácia). Každý riadok sa vyhodnocuje samostatne a upozornenia sa zobrazujú pre všetky; zvýraznený je riadok s najvyšším percentom čerpania („prísnejšia hodnota“). Zdroj: ROADMAP poznámka („nesmie sa seedovať jedna hodnota ako pravda, zobrazuje sa prísnejšia hodnota so vzorcom“), FEATURES rozpor zdrojov SK, PITFALLS Pasca 8.
- **D-03:** Spôsoby výpočtu (`calc_method`): `calendar_year`, `rolling_12m` (posledných 12 kalendárnych mesiacov končiacich aktuálnym mesiacom, resp. mesiacom dátumu dodania pri predkontrole), `pro_rata_first_year` (v roku začatia činnosti subjektu: `suma × dni_pôsobenia / 365`, dni vrátane dňa `entities.active_from`, mimo prvého roka sa správa ako `calendar_year`). Základ 365 aj v prestupnom roku je predvolený a označený ako [overiť]. Zdroj: LIM-05, PITFALLS Pasca 8, ARCHITECTURE Vzor 8.
- **D-04:** Seed (len predvyplnený návrh, označený v UI ako „neoverené účtovníčkou“): SK 2026 „Registrácia DPH (kalendárny rok)“ 50 000 € `calendar_year`; SK 2026 „Registrácia DPH (12 mesiacov)“ 49 790 € `rolling_12m`; SK 2026 „Okamžitá registrácia“ 62 500 € `calendar_year`, prah `{100}`; PL 2026 „Zwolnienie podmiotowe (art. 113)“ 240 000 zł `pro_rata_first_year`. Seed sa vloží len ak tabuľka je prázdna (nezmaže úpravy vlastníka). Zdroj: LIM-01, spec §6.8, ROADMAP poznámka.
- **D-05:** Výber riadku pre rok: riadky s `year = rok`; ak pre krajinu a rok neexistuje žiadny, použijú sa riadky posledného známeho roka a zobrazí sa upozornenie „limit pre rok X chýba, použitý rok Y, skontroluj“. Upozornenie sa nesmie dať potlačiť. Zdroj: spec §6.8, LIM-03, PITFALLS Pasca 8.
- **D-06:** Zmeny v `turnover_limits` aj v počiatočnom obrate idú cez `AuditLogger` (hodnoty pred a po). Mazanie riadku limitu je povolené (sú to nastavenia, nie doklady) a auditované. Zdroj: AUD-01 (limity explicitne).

### Počiatočný obrat (LIM-02)
- **D-07:** Tabuľka `entity_opening_turnover` (`entity_id`, `year`, `amount numeric(12,2)` v mene subjektu, voliteľná poznámka), unikát `(entity_id, year)`. Pri `calendar_year` a `pro_rata_first_year` sa pripočíta za daný rok. Pri `rolling_12m` sa počiatočný obrat nedá rozložiť po mesiacoch: pripočíta sa celý, ak okno zasahuje do daného roka, a vo vzorci sa to vyznačí (konzervatívne). Zdroj: spec §6.8 [návrh], ARCHITECTURE schéma; spôsob pri `rolling_12m` je Claude's Discretion, môže ho upraviť plán.

### Výpočet obratu (LIM-02, CUR-04)
- **D-08:** Jedna služba `TurnoverCalculator` (Reporting kontext) je jediný zdroj čísla pre dashboard, nastavenia, náhľad faktúry aj neskôr Phase 11. Sčítava SQL-om `invoices.total_entity_ccy` (faktúry kladné, dobropisy majú uložené záporné sumy) `WHERE entity_id = :e AND counts_toward_limit AND origin = 'issued' AND turnover_date` v okne + počiatočný obrat. Nikdy nečíta JSONB `snapshot`, tabuľku kurzov ani živý príznak klienta. Zdroj: ARCHITECTURE Vzor 8 a §denormalizované stĺpce, CUR-04, ROADMAP poznámka.
- **D-09:** Rozhodujúci dátum = `turnover_date` (dnes = dátum dodania). Dobropis sa odpočíta v období pôvodnej faktúry, lebo nesie jej `turnover_date` a `counts_toward_limit` (už implementované v Phase 9, D-07/D-08). Stornovaná faktúra + dobropis sa tak v rovnakom období vynulujú; netreba filtrovať stav `cancelled`. Zdroj: Phase 9 CONTEXT D-07, D-08, PITFALLS §Dobropisy.
- **D-10:** Externé faktúry (`origin = 'external'`, import z Excelu) sa do obratu nepočítajú (majú `counts_toward_limit = false`); príjmy pred nasadením patria do počiatočného obratu. Zdroj: `ExternalInvoiceLocker`, spec §6.8.
- **D-11:** Obrat sa počíta za subjekt, nikdy za používateľa ani naprieč subjektmi; pri prechode SK → PL sa neprenáša. Limit sa vyberá podľa `entities.country`, porovnáva sa v mene limitu; ak sa mena limitu líši od meny subjektu, riadok sa vyhodnotí ako nekonzistentný a zobrazí sa chyba konfigurácie (žiadny prepočet aktuálnym kurzom). Zdroj: spec §6.8, §6.10, PITFALLS Pasca 8.
- **D-12:** Peniaze cez existujúcu triedu `Money` a bcmath, percento čerpania sa počíta z reťazcov (bez float), zaokrúhlenie len pre zobrazenie. Zdroj: CLAUDE.md Integrita dát, Phase 1.

### Zobrazenie a vzorec (LIM-05, success criterion 3)
- **D-13:** Pri každom limite sa zobrazí: názov, spôsob výpočtu, obdobie od–do, obrat z faktúr, počiatočný obrat, spolu, limit (pri pomernom aj vzorec `240 000 × 170 / 365 = …`), percento a zostatok do limitu, a počet zahrnutých dokladov. Cieľ: dá sa skontrolovať s účtovníčkou. Zdroj: PITFALLS Pasca 8 („zobraziť vzorec“), ROADMAP SC3.
- **D-14:** Prehľad čerpania je na obrazovke Nastavenia → Limity (tabuľka limitov + počiatočný obrat + výpočet pre každý aktívny subjekt). Detailná stránka prehľadov patrí do Phase 11.

### Dashboard (LIM-03)
- **D-15:** Dashboard zobrazí upozornenie len ak je dôvod: niektorý limit aktívneho subjektu prekročil najnižší prah (80 %) alebo 100 %, alebo pre aktuálny rok chýba riadok limitu. 80 % = varovanie (žltá), 100 % = kritické (červená), chýbajúci rok = informácia s odkazom do nastavení. Bez upozornenia sa na dashboarde nič nepridáva (mobil, jedna ruka). Pre viac aktívnych subjektov sa vyhodnotí každý. Zdroj: LIM-03, spec §6.8, UI konvencie z Phase 4.

### Predkontrola v náhľade faktúry (LIM-04)
- **D-16:** Náhľad faktúry (`Invoicing::preview`) spočíta obrat v okne podľa dátumu dodania návrhu + suma návrhu v mene subjektu (z náhľadového kurzu, ak je cudzia mena) a ak by sa prekročil niektorý prah niektorého limitu, zobrazí varovanie s hodnotou pred a po. Iba ak klient má `counts_toward_limit = true`. **Varovanie neblokuje vystavenie** (zákon neprikazuje nevystaviť, rozhoduje vlastník). Pri vystavení sa nič nové neukladá. Zdroj: LIM-04, ROADMAP SC5, spec §6.8 („upozornenie“).

### Testy (NFR-06)
- **D-17:** PHPUnit pokrýva `TurnoverCalculator` a výber limitu: hraničné dni 1. 1. a 31. 12., `rolling_12m` cez prelom roka, začiatok činnosti 15. 7. (pomerný limit), prestupný rok, dobropis v inom roku než faktúra, faktúra v cudzej mene (`total_entity_ccy`), vylúčený klient (príznak zmenený po vystavení nemení výsledok), externá faktúra, počiatočný obrat, chýbajúci rok limitu (fallback + príznak), viac riadkov pre krajinu a výber prísnejšieho, predkontrola na hrane prahu. Zdroj: PITFALLS Pasca 8, NFR-06.

### Claude's Discretion
- Presný tvar UI nastavení (jeden zoznam vs záložky), poradie stĺpcov, texty v `lang()` (SK teraz, ostatné jazyky Phase 12).
- Či `TurnoverCalculator` vracia hodnotový objekt (napr. `LimitStatus`) a ako sa volá z htmx fragmentu dashboardu.
- Spôsob započítania počiatočného obratu pri `rolling_12m` (D-07) a presná definícia okna pri predkontrole (podľa mesiaca dátumu dodania vs dnes).
- Indexy pre SUM (napr. `(entity_id, turnover_date) WHERE counts_toward_limit`), ak meranie ukáže potrebu (p95 < 100 ms).
- Ako sa prahy zadávajú vo formulári (zoznam percent oddelený čiarkou alebo pevné dva polia), pri zachovaní `int[]` v DB.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Špecifikácia a požiadavky
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.8 Prehľady a limity (riadky 181–193), §6.10 Meny a kurzy (riadok 213), riadok 160–162 (cezhraničná fakturácia, príznak započítavania)
- `docs/PRD.md` — E10 (LIM-01..03), CLI-06, CUR-04, riziká R1, R2
- `.planning/REQUIREMENTS.md` — LIM-01..LIM-05, CUR-04, otvorené otázky pre účtovníčku 1–3
- `.planning/ROADMAP.md` — Phase 10 (success criteria a Poznámky)

### Výskum
- `.planning/research/PITFALLS.md` — Pasca 8 (výpočet limitov SK, PL, dobropisy, testy), Pasca 7 (dátumy, `Clock`)
- `.planning/research/ARCHITECTURE.md` — Vzor 8 Limity a obrat, schéma `turnover_limits` a `entity_opening_turnover` (riadky 218–219), §denormalizované stĺpce (riadok 182)
- `.planning/research/FEATURES.md` — rozpor zdrojov SK limitu, PL 240 000 zł, tabuľka limitov (riadky 150–160)
- `.planning/research/SUMMARY.md` — otvorené otázky 1, 2, 3, 8

### Predošlé fázy
- `.planning/phases/09-dobropisy/09-CONTEXT.md` — D-07, D-08 (dobropis nesie `turnover_date` a `counts_toward_limit` originálu)
- `.planning/phases/08-meny-a-kurzy/08-CONTEXT.md` — D-14 (`total_entity_ccy` a FX stĺpce, CUR-04 odložené sem)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Invoicing/InvoiceIssuer.php` a `CreditNoteIssuer.php`: zapisujú `turnover_date`, `total_entity_ccy`, `counts_toward_limit` (dobropis záporne, s dátumom originálu). Výpočet ich iba číta.
- `Money` (Phase 1): bcmath aritmetika nad reťazcami, half-up na 2 desatinné miesta.
- `app/Libraries/Audit/AuditLogger`: audit zmien nastavení (vzor v `ClientService`).
- `app/Controllers/Settings/*` (napr. `FxSettings.php`, `LegalTexts.php`) a `app/Views/settings/*`: vzor obrazoviek nastavení s htmx a 422 validáciou.
- `Clock` služba: aktuálny dátum a rok, nikdy `CURRENT_DATE` v dotazoch.

### Established Patterns
- `invoices` je insert-only pod rolou `app_rw`; nové tabuľky nastavení potrebujú granty v migrácii (`grantMutable`/`grantUpdateColumns` vzor z `CreateEntities`).
- `entities.country` a `currency` sú nemenné; `active_from` je základ pre pomerný prvý rok.
- Stavy faktúr sú odvodené (`invoice_status_v`, `cancelled` pri existencii dobropisu) – pre obrat netreba, stačí súčet so záporným dobropisom.
- `origin IN ('issued','external')`: externé faktúry z importu majú `counts_toward_limit = false`.
- Všetky texty cez `lang()`, CSP bez inline skriptov a štýlov, htmx fragmenty cez `htmx_view`.

### Integration Points
- Dashboard: `app/Controllers/Home.php` (`data()`, fragment `body`) + `app/Views/home/_body.php` – sem pribudne blok upozornení (vzor `_overdue.php`).
- Náhľad faktúry: `app/Controllers/Invoicing.php::renderPreview()` + `app/Views/invoicing/_preview.php` (vzor `_fx_card.php` pre kartu s varovaním).
- Nastavenia: nové routy v module settings (`app/Config/Routes.php`), odkaz v menu „Viac“/nastaveniach.
- Seed: migrácia alebo seeder pre `turnover_limits` (len ak je tabuľka prázdna); `StagingSeed` modul pre staging dáta.

</code_context>

<specifics>
## Specific Ideas

- Vzorec na obrazovke má byť čitateľný pre účtovníčku: „Obdobie 1. 1. 2026 – 31. 12. 2026, kalendárny rok, 12 faktúr + 1 dobropis, obrat 41 230,00 € + počiatočný 3 000,00 € = 44 230,00 € z 50 000,00 € (88,5 %)“.
- Seedované hodnoty sú v UI viditeľne označené ako návrh na overenie účtovníčkou.

</specifics>

<deferred>
## Deferred Ideas

- Ročné a mesačné prehľady obratu, export čerpania do CSV/XLSX: Phase 11 (použije `TurnoverCalculator`).
- Strategy pre rozhodujúci dátum `delivery_date | issue_date | payment_date` (PITFALLS Pasca 8): dnes stĺpec `turnover_date` = dátum dodania; ak účtovníčka určí iný dátum, rieši sa novou fázou (zmena zápisu pri vystavení).
- Otvorené otázky pre účtovníčku (neblokujú implementáciu, riešia sa úpravou tabuľky limitov a príznakom klienta): SK kalendárny rok vs 12 mesiacov a čo sa započítava, potvrdenie 62 500 €, PL rozhodujúci dátum a základ 365 pri pomernom roku, započítanie EÚ B2B tržieb PL subjektu, obdobie odpočtu dobropisu.

</deferred>

---

*Phase: 10-limity-obratu*
*Context gathered: 2026-11-02*
