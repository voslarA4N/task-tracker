# Phase 11: Prehľady, exporty a audit - Context

**Gathered:** 2026-11-04
**Status:** Ready for planning

<domain>
## Phase Boundary

Vlastník vidí mesačné a ročné prehľady (vyfakturované a uhradené, po klientoch, v mene subjektu), exportuje prehľady a zoznam faktúr do CSV a XLSX, stiahne ZIP balík pre účtovníčku za obdobie, prehliada denník zmien (len na čítanie, s filtrami) a na PC pracuje v tabuľkových zoznamoch úloh a faktúr. Doplní sa chýbajúca časť UI-03 (obrazovka Používatelia, odkazy na nové obrazovky v menu „Viac“).

Requirements: RPT-01, RPT-02, RPT-03, AUD-02, UI-03. Plné UI pre rolu `accountant` je v2 (mimo fázy).

Rozhodnutia sa podľa želania vlastníka berú zo špecifikácie, PRD a REQUIREMENTS. Interaktívne sa riešili len štyri body, ktoré tam chýbali (D-01, D-08, D-14, D-16).

</domain>

<decisions>
## Implementation Decisions

### Prehľady (RPT-01)
- **D-01 [vlastník]:** „Vyfakturované“ sa priraďuje k mesiacu podľa **dátumu dodania** (`turnover_date`), rovnako ako výpočet limitov (spec 6.8). Dobropisy sa odpočítavajú v mesiaci svojho dátumu dodania. Takto sa súčty prehľadu zhodujú so sledovaním obratu na dashboarde.
- **D-02:** „Uhradené“ sa priraďuje podľa **dátumu úhrady** (zrušené úhrady sa nerátajú). Suma úhrady v mene subjektu sa odvodí z uložených hodnôt faktúry (pomerom k `total_entity_ccy`, bcmath, zaokrúhlenie raz na konci). Nikdy nie aktuálnym kurzom (CUR-04, spec 6.10).
- **D-03:** Prehľad je **za jeden subjekt** (sumy v jeho mene). Ak je subjektov viac, je na stránke výber subjektu, predvolený je aktívny subjekt. Sumy rôznych mien sa nikdy nesčítajú.
- **D-04:** Mesačný pohľad: riadky = klienti, stĺpce = vyfakturované / uhradené, súčtový riadok. Ročný pohľad: riadky = mesiace 1 až 12 (plus rozpad po klientoch), súčty za rok. Navigácia predchádzajúci/ďalší mesiac alebo rok.
- **D-05:** Všetky súčty idú z jednej triedy (NFR-04). Test zhody súčtov sa rozšíri: prehľad = súčet faktúr = obrat z `TurnoverCalculator` za rovnaké obdobie = hodnoty v CSV aj XLSX.

### Exporty (RPT-02)
- **D-06:** XLSX cez `openspout/openspout` (pridať do `require`, produkčná závislosť). CSV natívne `fputcsv`, oddeľovač `;`, UTF-8 BOM. Sumy v CSV majú **desatinnú čiarku** a sú bez oddeľovača tisícov (Excel v SK/PL/CZ lokalite ich načíta ako čísla). XLSX zapisuje sumy ako číselné bunky s formátom `#,##0.00` (nie text).
- **D-07:** Ochrana pred vzorcami: textová bunka začínajúca `=`, `+`, `-`, `@`, tabulátorom alebo CR dostane prefix `'` (CSV) a v XLSX sa zapisuje vždy ako textová bunka (nikdy ako vzorec). Číselné stĺpce (sumy, hodiny) sa neprefixujú. Pokryť testom.
- **D-08:** Exportovať sa dá: mesačný prehľad, ročný prehľad a zoznam faktúr (s aktuálnymi filtrami zoznamu). Tlačidlá CSV / XLSX sú nad tabuľkou. Názov súboru obsahuje subjekt a obdobie (napr. `prehlad-2026-10-<subjekt>.xlsx`).

### Balík pre účtovníčku (RPT-03)
- **D-09 [vlastník]:** ZIP obsahuje **PDF faktúr a dobropisov** (bez výkazov práce) a CSV zoznam týchto dokladov. Obdobie = mesiac (voliteľne rozsah mesiacov), za jeden subjekt.
- **D-10:** Doklady do balíka sa vyberajú podľa **dátumu dodania** (zhodne s D-01), aby balík sedel s prehľadom. Zrušené (stornované) doklady sa zahrnú a v CSV majú stav. Externé faktúry (`origin='external'`) bez PDF sa uvedú v CSV so stĺpcom „bez PDF“.
- **D-11:** PDF sa berú **z uloženého `bytea` (`DocumentStore`)**, nikdy sa negenerujú znova zo živých nastavení. Názvy súborov cez existujúci `DocumentFilename`. ZIP sa stavia streamovo/do dočasného súboru v `writable/` (512 MB RAM), nie celý v pamäti.
- **D-12:** CSV zoznam v balíku: číslo, typ (faktúra/dobropis), klient, dátum vystavenia, dátum dodania, splatnosť, mena, suma v mene dokladu, kurz, suma v mene subjektu, stav úhrady, dátum úhrady. Rovnaká ochrana pred vzorcami ako D-07.

### Denník zmien (AUD-02)
- **D-13:** Obrazovka len na čítanie nad existujúcou tabuľkou `audit_log` (append-only). Filtre: entita (typ), používateľ, časový rozsah, akcia. Stránkovanie (najnovšie hore). Detail záznamu zobrazí polia pred a po, zmenené polia zvýraznené. Dostupné len pre `admin` (citlivé údaje sú už v `AuditLogger` scrubbed).

### UI-03 (PC tabuľky a nastavenia)
- **D-14 [vlastník]:** Zoznamy úloh a faktúr: **rovnaký htmx fragment** vykreslí na šírke ≥ `lg` tabuľku (triediteľné stĺpce, filtre nad tabuľkou) a na mobile ostávajú dnešné karty. Žiadne samostatné „desktopové“ URL ani duplicitné dotazy.
- **D-15:** Obrazovka „Fakturácia mesiaca“ (`/invoicing`) a väčšina nastavení už existujú (subjekty a história, klienti, číslovanie radov, limity, šablóny, zákonné texty, 2FA a zariadenia). Fáza ich iba overí na PC rozložení a doplní chýbajúce položky do menu „Viac“ (Prehľady, Balík pre účtovníčku, Denník zmien, Používatelia).
- **D-16 [vlastník]:** Nastavenia → Používatelia: **zoznam len na čítanie** (e-mail/meno, rola, stav 2FA, posledné prihlásenie). Vytvorenie účtu `accountant` ostáva `spark` príkaz do v2.

### Claude's Discretion
- Presné rozloženie tabuliek, triedenie a stránkovanie (server-side cez query string, bez JS knižníc).
- Či prehľad a balík používajú jednu servisnú triedu na výber dokladov za obdobie (odporúčané, kvôli D-05).
- Zahrnutie externých faktúr do súčtov prehľadu: rátajú sa ako príjem rovnako ako v obrate limitov (`counts_toward_limit` sa na prehľad neaplikuje, prehľad ukazuje celý príjem).
- Limit veľkosti obdobia pre ZIP (napr. max 12 mesiacov) a časový limit generovania.
- Formátovanie a preklady nových textov (SK hotovo v tejto fáze, PL/CZ/EN kľúče pripraviť, preklad je fáza 12).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` §Phase 11 — cieľ, úspešné kritériá, poznámky (OpenSpout, rozšíriť test NFR-04)
- `.planning/REQUIREMENTS.md` — RPT-01, RPT-02, RPT-03, AUD-02, UI-03, NFR-04, CUR-04
- `docs/PRD.md` §E10 (RPT, DSH), AUD-02, UI-03, G3 (zhoda súčtov)
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.8 (prehľady a limity), §6.9 (denník zmien), §6.10 (meny, počítať z uložených súm), §7 PC

### Stack
- `CLAUDE.md` — OpenSpout 5.12.2 na export, CSV `fputcsv` + BOM + `;`, PhpSpreadsheet nie v produkcii, CSP bez inline štýlov/skriptov

### Predchádzajúce fázy
- `.planning/phases/10-limity-obratu/10-CONTEXT.md` — obrat podľa dátumu dodania, dobropisy, počiatočný obrat
- `.planning/phases/10-limity-obratu/10-UI-SPEC.md` — vizuálne vzory nedávnych obrazoviek

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Reporting/TurnoverCalculator.php`: suma `total_entity_ccy` podľa `turnover_date` za subjekt, referencia pre zhodu súčtov prehľadu.
- `app/Libraries/Invoicing/InvoiceQueries.php`: `list()` zoznamu faktúr, rozšíriť o filtre/triedenie pre tabuľku a export.
- `app/Libraries/Invoicing/DocumentStore.php`: PDF dokladov v `bytea` (čítanie len cez `pg_unescape_bytea`, nie Query Builder).
- `app/Libraries/Invoicing/DocumentFilename.php`: názvy PDF súborov pre ZIP.
- `app/Libraries/Audit/AuditLogger.php` + tabuľka `audit_log` (index `entity_type, entity_id, occurred_at DESC`): zdroj pre AUD-02.
- `app/Libraries/Platform/MoneyFormat.php`: formát súm podľa jazyka.
- `app/Libraries/Work/TaskQueries` + `TaskFilter::fromQuery()`: filtre zoznamu úloh.
- `app/Config/AuthGroups.php`: rola `accountant` s oprávnením `reports.read` už existuje.

### Established Patterns
- Routy po súboroch v `app/Config/Routes/*.php`, controllery nastavení v `app/Controllers/Settings/`.
- Zoznamy ako htmx fragmenty (`_list.php`, `#invoice-list`), stránka + fragment pri `HX-Request`.
- Peniaze ako string + bcmath, nikdy float.
- Karty `app-card`, dotykové ciele `btn-touch`, menu „Viac“ v `app/Views/more/index.php`.

### Integration Points
- Navigácia: `app/Views/more/index.php` (nové položky), `app/Views/layouts/_nav_items.php`.
- `composer.json`: pridať `openspout/openspout`; Dockerfile má ext `zip` (overiť).
- Test zhody súčtov (NFR-04) v `tests/` rozšíriť o prehľad, CSV a XLSX.

</code_context>

<specifics>
## Specific Ideas

- Balík pre účtovníčku má sedieť s mesačným prehľadom: rovnaké doklady, rovnaké sumy.
- CSV musí otvoriť slovenský/poľský Excel dvojklikom správne (BOM, `;`, diakritika).

</specifics>

<deferred>
## Deferred Ideas

- Plné UI pre rolu `accountant` (prihlásenie účtovníčky, obmedzené menu) — v2.
- Vytváranie a deaktivácia používateľov v UI — v2 (teraz `spark`).
- Výkazy práce v balíku pre účtovníčku — zatiaľ nie (D-09), dá sa doplniť neskôr.
- Export KSeF FA(3) — mimo V1 (INV-08, P2).

</deferred>

---

*Phase: 11-preh-ady-exporty-a-audit*
*Context gathered: 2026-11-04*
