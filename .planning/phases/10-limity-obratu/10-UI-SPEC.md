---
phase: 10
slug: limity-obratu
status: approved
reviewed_at: 2026-11-02
shadcn_initialized: false
preset: none
created: 2026-11-02
---

# Phase 10 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 10 (Limity obratu, LIM-01 až LIM-05, CUR-04). Dedí tokeny z `02-UI-SPEC.md` až `09-UI-SPEC.md` a nemení ich (spacing násobky 4, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk UI je slovenčina, texty iba cez `lang()` (nový súbor `app/Language/sk/Limits.php` + rovnaké kľúče v `en`; doplnky do `Dashboard.php` a `Invoicing.php`; PL/CS preklady Phase 12). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine CSP build sa v tejto fáze nepoužíva, htmx `allowEval=false`.
> Zdroje: 10-CONTEXT.md (D-01 až D-17), 09-UI-SPEC.md, 08-UI-SPEC.md, CLAUDE.md, existujúce `app/Views/settings/fx/index.php`, `home/_overdue.php`, `more/index.php`, `invoicing/_preview.php`, `_fx_card.php`, `public/assets/css/app.css`. Vlastník nechce interaktívnu diskusiu; kroky mimo CONTEXT sú označené **[predpoklad]** s dôvodom.
> Rozsah: (A) obrazovka Nastavenia → Limity (čerpanie, tabuľka limitov, počiatočný obrat), (B) formulár limitu (sheet), (C) formulár počiatočného obratu (sheet), (D) upozornenia na dashboarde, (E) predkontrola v náhľade faktúry.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 (nepoužité) |
| Icon library | žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`); stav nikdy iba ikonou |
| Font | systémový stack z `app.css`, čísla `tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.app-main`, `.work-wide`, `.work-group`, `.master-kv`, `.master-sticky-actions`, `.inv-warn`, `.fx-card`, `.fx-meta`, `.fx-sub`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `.dl-sheet` (offcanvas-bottom na mobile, modál na PC, cieľ `#sheet`), `.more-item` (odkaz v menu), `data-submit-once`, `HtmxCsrf`, `responseHandling` pre 422 a 409, `fragments/alert.php`, `.htmx-indicator`.

Nové triedy v `app.css` (nová verzia súboru v názve cez `asset_url`; prefix `.lim-*`), bez nových farieb a veľkostí:

| Trieda | Účel |
|--------|------|
| `.lim-card` | karta čerpania jedného limitu (derivát `.app-card`, padding 16px, `overflow-wrap:anywhere`); modifikátory `.lim-card--warn` a `.lim-card--crit` len pridávajú 4px ľavý okraj `--bs-warning` / `--bs-danger` (stav je vždy aj textom) |
| `.lim-bar` | natívny `<progress value max="100">` s `aria-label`, výška 8px, zaoblenie 4px, pozadie `#e9ecef`, výplň neutrálna `--bs-secondary`; pri `--warn` výplň `--bs-warning`, pri `--crit` `--bs-danger`; hodnota sa cap-uje na 100 vizuálne, text vždy ukazuje skutočné percento (napr. „112,4 %“). Žiadny inline `style` (šírku nesie atribút `value`) |
| `.lim-formula` | blok vzorca v `.lim-card` (12px, `tabular-nums`, `overflow-wrap:anywhere`, pozadie `#f5f6f8`, padding 8px 12px, radius 4px) |
| `.lim-draft` | odznak „návrh, neoverené účtovníčkou“ (derivát `.badge`, `text-bg-light border`) |
| `.lim-row` | riadok v zozname limitov (nie tabuľka; grid 1 stĺpec mobil, na PC nadpis + meta vľavo, suma vpravo, akcie pod) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 9 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera medzi názvom limitu a metou, hint pod poľom |
| sm | 8px | medzi tlačidlami, odznakmi, medzi `.lim-bar` a percentom, padding `.lim-formula` (vertikálne) |
| md | 16px | padding `.lim-card`, `.lim-row`, `mb-3` polí, okraje na mobile |
| lg | 24px | medzi sekciami obrazovky (Čerpanie / Limity / Počiatočný obrat) |
| xl | 32px | medzi kartami subjektov v sekcii Čerpanie |
| 2xl | 48px | horné odsadenie prázdnych stavov (existujúce) |
| 3xl | 64px | výška sticky lišty a spodná rezerva (existujúce) |

Exceptions:
- Dotykové ciele min **44px**: tlačidlá Pridať/Upraviť/Zmazať, `select`, `input`, odkaz v dashboardovom upozornení (celý riadok je odkaz, `d-block py-2`), tlačidlá sheetu.
- Výška `.lim-bar` 8px a ľavý okraj 4px sú dekoratívne prvky (nie rozloženie), nie dotykové ciele.
- Žiadne horizontálne posúvanie: žiadne tabuľky; zoznam limitov je zoznam riadkov `.lim-row`, vzorce a názvy `overflow-wrap:anywhere`.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 až 9, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, text upozornení, názvy limitov v riadkoch) | 16px | 400 | 1.5 |
| Label / meta (popisky, hint, `.lim-formula`, meta riadok limitu, odznaky, `invalid-feedback`, text „návrh“) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2`, názov limitu v `.lim-card`, nadpis sheetu, percento čerpania) | 20px | 600 | 1.2 |
| Display (nepoužité v tejto fáze) | 28px | 600 | 1.2 |

Hrúbka 600 iba pre: `h1`/`h2`, názov limitu a percento v `.lim-card`. Všetko ostatné 400. Čísla `tabular-nums`.

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky, pozadie `.lim-formula` |
| Secondary (30%) | `#ffffff` | `.app-card`, `.lim-card`, `.lim-row`, sheet, sticky pätka |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | zmazanie limitu, stav „100 % a viac“, chyby |
| Warning (semantická, existujúca) | Bootstrap `--bs-warning` | stav „80 % a viac“, chýbajúci rok limitu, varovanie v predkontrole |

Accent reserved for (výlučne):
1. Obrazovka Limity: primárne tlačidlo **Pridať limit** (jedna hlavná akcia obrazovky)
2. Sheet limitu a sheet počiatočného obratu: tlačidlo **Uložiť**
3. Aktívna položka navigácie (existujúce)
4. Focus ring polí a tlačidiel
5. Textové odkazy v tele (odkaz „Skontrolovať limity“ z dashboardu, „Upraviť“ ako textový odkaz nie je; akcie riadkov sú `btn-outline-secondary`)
6. Zaškrtnuté checkbox/radio (Bootstrap predvolené)

Accent sa **nepoužíva** pre `.lim-bar`, odznaky, upozornenia ani percentá (stav nesú warning/danger/neutrálna sivá). Stav čerpania: pod najnižším prahom neutrálna, od prahu 1 (štandardne 80 %) žltá `warning`, od prahu 2 (štandardne 100 %) červená `danger`. Pri vlastných prahoch platí: dosiahnutý najvyšší prah = `crit`, akýkoľvek nižší dosiahnutý = `warn` **[predpoklad]** (jednoduché pravidlo pre 1 až 3 prahy; riadok s jediným prahom `{100}` je neutrálny do 100 %, potom `crit`). Zmazanie limitu je `btn-danger` iba v potvrdzovacom sheete; na riadku `btn-outline-secondary`.

### Odznaky (vždy text, nikdy iba farba)

| Stav | Trieda | Text |
|------|--------|------|
| Čerpanie pod prahom | `text-bg-light border` | V norme |
| Čerpanie ≥ prvý prah | `text-bg-warning` | Blíži sa limit |
| Čerpanie ≥ najvyšší prah | `text-bg-danger` | Limit dosiahnutý |
| Seed neoverený | `lim-draft` | Návrh, neoverené účtovníčkou |
| Nekonzistentná mena (D-11) | `text-bg-danger` | Chyba konfigurácie |
| Náhradný rok (D-05) | `text-bg-warning` | Použitý rok {Y} |

---

## Trasy a vstupy

| Obrazovka | Trasa | Poznámka |
|-----------|-------|----------|
| Nastavenia → Limity | `GET /settings/limits` | jedna stránka, tri sekcie; odkaz v `more/index.php` za „Kurzy“ (`Limits.navTitle`) |
| Sheet nový/úprava limitu | `GET /settings/limits/new`, `GET /settings/limits/{id}/edit` do `#sheet` | `.dl-sheet`, bez JS fallback je samostatná stránka s rovnakým formulárom |
| Uloženie limitu | `POST /settings/limits`, `POST /settings/limits/{id}` | CSRF, skryté `revision`; 422 vráti formulár s chybami; úspech PRG + flash |
| Zmazanie limitu | `GET /settings/limits/{id}/delete` (sheet), `POST /settings/limits/{id}/delete` | potvrdenie v sheete |
| Sheet počiatočného obratu | `GET /settings/limits/opening?entity={id}&year={Y}` do `#sheet` | |
| Uloženie počiatočného obratu | `POST /settings/limits/opening` | CSRF, `entity_id`, `year`, `amount`, `note`, `revision` |
| Dashboard blok | existujúci fragment `body` v `Home::data()`, nový partial `home/_limits.php` | iba ak sú upozornenia (D-15) |
| Predkontrola | `Invoicing::renderPreview()`, nový partial `invoicing/_limit_warning.php` | nový fragment netreba, vkladá sa do existujúceho náhľadu |

Server nikdy neprijíma obrat ani percento z formulára; všetko počíta `TurnoverCalculator` (D-08).

---

## A. Nastavenia → Limity (`GET /settings/limits`)

Layout `.work-wide`, jeden stĺpec na mobile, na PC (≥ 992px) sekcie pod sebou, karty v mriežke 2 stĺpce. Poradie zhora nadol:

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | Odkaz „Späť na Viac“ | `d-inline-block py-2` (vzor FxSettings) |
| 2 | `h1` 20px „Limity obratu“ + úvod 12px muted | „Sleduje obrat za subjekt voči limitom. Hodnoty sa dajú upraviť bez zásahu do kódu.“ |
| 3 | Upozornenie `alert alert-secondary` (12px, `role="status"`) | „Predvyplnené limity sú návrh a nie sú overené účtovníčkou. Skontrolujte ich pred použitím.“ Zobrazuje sa, kým existuje aspoň jeden riadok so seed príznakom `is_seed` **[predpoklad]** (stĺpec/príznak, ktorý sa vynuluje prvým uložením vlastníka daného riadku; alternatíva zvolená plánom) |
| 4 | Sekcia „Čerpanie“ (`h2`, `.work-group`) | pre každý **aktívny** subjekt podnadpis (názov subjektu, 16px 400, 12px muted krajina a mena) a pod ním karty `.lim-card` podľa B |
| 5 | Sekcia „Tabuľka limitov“ (`h2`) | pod nadpisom primárne tlačidlo **Pridať limit** (`btn-primary`, 44px, `w-100` mobil), zoznam `.lim-row` zoradený krajina → rok zostupne → názov, popri každom riadku akcie Upraviť, Zmazať |
| 6 | Sekcia „Počiatočný obrat“ (`h2`) | pre každý aktívny subjekt a rok (aktuálny a predošlý) jeden riadok: subjekt, rok, suma alebo „nezadaný“, tlačidlo Upraviť/Zadať (`btn-outline-secondary`) |

Výber rokov v sekcii 6: aktuálny rok (`Clock`) a rok `active_from` subjektu, ak je iný; ďalší rok sa dá pridať v sheete (pole Rok). **[predpoklad]** bez špeciálneho výberu rokov: riadky sa tvoria z už zadaných záznamov + aktuálny rok (prázdny, „nezadaný“).

### A1. Riadok limitu `.lim-row`

Zhora nadol: názov limitu (16px 400) + odznaky `lim-draft` (ak seed) · meta 12px muted „{krajina} · {rok} · {suma} · {spôsob výpočtu} · prahy {80, 100} %“ · akcie vpravo/dole: `Upraviť` (`btn-outline-secondary btn-touch`), `Zmazať` (`btn-outline-secondary btn-touch`). Celý riadok `p-3 border-bottom`, v karte `.app-card p-0`.

### A2. Prázdny stav (tabuľka limitov prázdna, seed sa nevložil alebo zmazaný)

`h2` 20px „Žiadne limity“, text „Pridajte aspoň jeden limit obratu, inak sa upozornenia nezobrazujú.“, tlačidlo **Pridať limit** (accent). Horné odsadenie 48px.

---

## B. Čerpanie: karta `.lim-card` (LIM-02, LIM-05, D-13)

Jedna karta na limit aktívneho subjektu, zoradené podľa percenta zostupne; **karta s najvyšším percentom** je prvá a má nad nadpisom 12px odznak „Prísnejšia hodnota“ (`text-bg-light border`, D-02). Obsah zhora nadol:

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | Hlavička | názov limitu (20px 600) · vpravo odznak stavu (tabuľka odznakov) |
| 2 | Meta 12px muted | „{krajina} · {rok} · {spôsob výpočtu}“ + odznaky `lim-draft` / „Použitý rok {Y}“ ak platí |
| 3 | `.lim-bar` | `<progress>` s `aria-label` „Čerpanie limitu {názov}“, vedľa percento (20px 600) „88,5 %“ |
| 4 | Čerpanie | 16px: „{spolu} z {limit}“ (napr. „44 230,00 € z 50 000,00 €“), 12px muted „Zostáva {suma}“; pri prekročení „Prekročené o {suma}“ (nie záporný zostatok) |
| 5 | `.lim-formula` | vety podľa „Vzorec“ nižšie |
| 6 | Meta dokladov 12px muted | „{n} faktúr, {m} dobropisov“ (prvé je počet nezáporných dokladov, druhé počet dobropisov v okne) |

### Vzorec (D-13, kontrolovateľný účtovníčkou)

Riadky `.lim-formula` (každý sám v riadku, `<div>`), hodnoty cez `MoneyFormat`, dátumy `IntlDateFormatter` SHORT-ish ako existujúce:

1. `Obdobie: 1. 1. 2026 – 31. 12. 2026 (kalendárny rok)`
2. `Obrat z dokladov: 41 230,00 € ({n} faktúr, {m} dobropisov)`
3. `Počiatočný obrat: 3 000,00 €` (riadok sa vynecháva, ak nie je zadaný; pri `rolling_12m` sa pridá hint: „Počiatočný obrat sa pripočíta celý, lebo okno zasahuje do roka {Y}.“)
4. `Spolu: 41 230,00 € + 3 000,00 € = 44 230,00 €`
5. `Limit: 50 000,00 €` a pri `pro_rata_first_year` v prvom roku: `Limit: 240 000,00 zł × 170 / 365 = 111 780,82 zł` (zaokrúhlenie len na zobrazenie, half-up, D-12) a 12px hint „Dni od {active_from} vrátane, základ 365 dní.“ Mimo prvého roka pri tejto metóde: hint „Pomerný limit sa uplatňuje len v prvom roku činnosti, teraz platí plný limit.“
6. `Čerpanie: 44 230,00 € / 50 000,00 € = 88,5 %`

Názvy metód (`Limits.methodCalendar|Rolling|ProRata`): „Kalendárny rok“, „Kĺzavých 12 mesiacov“, „Pomerný prvý rok“.

### Stavy karty

- **V norme / warn / crit**: modifikátory `.lim-card--warn` / `--crit` + odznak + text („Dosiahnutých 88,5 % limitu“ nie je potrebné, stačí percento a odznak).
- **Náhradný rok (D-05)**: nad `.lim-bar` `alert alert-warning` 12px `role="alert"` „Limit pre rok {X} chýba, použitý rok {Y}. Skontrolujte hodnoty alebo pridajte limit pre rok {X}.“ + tlačidlo „Pridať limit pre rok {X}“ (`btn-outline-secondary`, otvorí sheet s predvyplneným rokom a krajinou). Nedá sa skryť ani odložiť.
- **Nekonzistentná mena (D-11)**: karta bez `.lim-bar`, `alert alert-danger` 12px „Chyba konfigurácie: limit je v {mena limitu}, subjekt účtuje v {mena subjektu}. Obrat sa nepočíta. Upravte menu limitu.“
- **Subjekt bez limitu pre krajinu a bez posledného známeho roka**: `alert alert-info` 12px „Pre krajinu {krajina} nie je zadaný žiadny limit.“ + tlačidlo „Pridať limit“.
- **Bez dokladov**: vzorec sa ukáže s nulami („Obrat z dokladov: 0,00 € (0 faktúr, 0 dobropisov)“), nie prázdny stav.
- **Loading**: stránka je server-rendered, žiadny spinner; sheet používa `.htmx-indicator`.

---

## C. Formulár limitu (sheet, LIM-01, LIM-05, D-01 až D-06)

`.dl-sheet`, `h2` „Pridať limit“ / „Upraviť limit“. Polia v poradí, všetky 44px, 16px, `label for`, hint 12px, `aria-describedby`:

| Pole | Typ | Poznámka |
|------|-----|----------|
| Krajina | `select` SK, PL, CZ | rovnaký zoznam ako subjekty; texty `FxSettings.countrySK` atď. znovupoužiť |
| Rok platnosti | `input type="number"` `inputmode="numeric"` `min=2000 max=2100` | predvolene aktuálny rok |
| Názov | `input type="text"` `maxlength=120` | hint „Napr. Registrácia DPH (kalendárny rok).“ |
| Suma | `input type="text"` `inputmode="decimal"` | formát podľa lokality, uloží sa `numeric(12,2)`; mena vedľa v rovnakom riadku |
| Mena | `select` (aktívne `currencies`) | hint „Mena musí byť rovnaká ako mena subjektu danej krajiny.“ |
| Spôsob výpočtu | `select` | tri voľby (názvy metód vyššie); pod poľom 12px muted vysvetlenie vybranej metódy v statickom `<dl>` troch viet (bez JS, všetky tri naraz): „Kalendárny rok: od 1. 1. do 31. 12. daného roka.“ · „Kĺzavých 12 mesiacov: posledných 12 mesiacov vrátane mesiaca dodania.“ · „Pomerný prvý rok: v roku začatia činnosti suma × dni činnosti / 365, inak plný limit.“ |
| Prahy upozornenia (%) | `input type="text"` `inputmode="numeric"` | **[predpoklad]** zoznam čísel oddelených čiarkou „80, 100“ (DB `int[]`); hint „Celé čísla od 1 do 200, oddelené čiarkou. Predvolene 80, 100.“ |

Pätka sheetu: vľavo „Zrušiť“ (`btn-outline-secondary`), vpravo **„Uložiť“** (`btn-primary`, `data-submit-once`, počas requestu `disabled` + `.spinner-border-sm`). Pri úprave seed riadku hore `alert alert-secondary` 12px „Hodnota je predvyplnený návrh. Po uložení sa už nebude označovať ako neoverená.“

Validácia (422, `is-invalid` + `invalid-feedback`, focus na prvé chybné pole, zachované hodnoty):

| Chyba | Text |
|-------|------|
| Krajina | Vyberte krajinu. |
| Rok | Zadajte rok od 2000 do 2100. |
| Názov | Zadajte názov limitu. |
| Suma | Zadajte sumu väčšiu ako 0. |
| Mena | Vyberte menu. |
| Spôsob výpočtu | Vyberte spôsob výpočtu. |
| Prahy | Zadajte 1 až 3 celé čísla od 1 do 200 oddelené čiarkou. |
| Duplicita (rovnaká krajina, rok, názov) | Limit s týmto názvom už pre krajinu a rok existuje. |
| Mena nezodpovedá subjektu | Mena nezodpovedá mene subjektu pre túto krajinu ({mena}). (varovanie `alert-warning`, ukladanie **neblokuje**, kontrola sa vyhodnotí v čerpaní, D-11) |
| Súbeh (zastaraný `revision`) | Limit sa medzitým zmenil. Načítajte ho znova a skúste uloženie zopakovať. (409) |

Úspech: PRG na `/settings/limits`, flash „Limit {názov} bol uložený.“ Mazanie: sheet s `h2` „Zmazať limit“, text „Limit {názov} ({krajina}, {rok}) sa odstráni. Doklady sa nezmenia, len sa prestane sledovať tento limit. Zmena sa zapíše do denníka.“, pätka „Späť“ + **„Zmazať limit“** (`btn-danger`); flash „Limit {názov} bol zmazaný.“ Zmeny sa auditujú (D-06), bez zobrazenia v UI.

---

## D. Formulár počiatočného obratu (sheet, LIM-02, D-07)

`.dl-sheet`, `h2` „Počiatočný obrat“, 12px muted „{subjekt} · {rok}“. Polia: Subjekt (`select` aktívnych subjektov, pri otvorení z riadka `disabled` + skryté pole), Rok (`input number`), Suma (`input text inputmode=decimal`, mena subjektu v `input-group-text`, hint „Obrat za rok {rok} pred nasadením aplikácie, v mene subjektu ({mena}).“), Poznámka (`textarea rows=2 maxlength=255`, nepovinné, hint „Napr. obrat z Excelu do 30. 9. 2026.“). Pätka „Zrušiť“ + **„Uložiť“** (`btn-primary`). Prázdna suma pri uložení záznam zmaže (hint „Prázdna suma odstráni počiatočný obrat.“).

Chyby: Suma „Zadajte sumu 0 alebo viac.“ · Rok „Zadajte rok od 2000 do 2100.“ · Subjekt „Vyberte subjekt.“ · Súbeh „Počiatočný obrat sa medzitým zmenil. Načítajte ho znova a skúste uloženie zopakovať.“ Úspech: flash „Počiatočný obrat bol uložený.“ a prepočítané karty v sekcii Čerpanie.

---

## E. Dashboard (`home/_limits.php`, LIM-03, D-15)

Blok sa vykreslí **iba ak** existuje aspoň jeden dôvod; inak `return` (rovnaký vzor ako `_overdue.php`) a na dashboarde sa nič nepridáva. Umiestnenie: v `_body.php` **nad** sekciou „Po splatnosti“ **[predpoklad]** (limit je kritickejší ako pohľadávka), pod prípadným výberom subjektu.

Štruktúra: `section.work-group` s `aria-labelledby`, `h2` „Limity obratu“ (+ `badge` s počtom upozornení), potom `.app-card p-0 overflow-hidden` s jedným riadkom na upozornenie. Riadok je celý odkaz (`a.d-block py-2 px-3`, min 44px) na `/settings/limits`; zoradené: crit, warn, info.

| Typ | Vzhľad | Text |
|-----|--------|------|
| 100 % a viac | ľavý okraj 4px `--bs-danger`, odznak `text-bg-danger` „Limit dosiahnutý“ | „{subjekt}: {názov} {percento} % ({spolu} z {limit})“ |
| 80 % a viac | ľavý okraj `--bs-warning`, odznak `text-bg-warning` „Blíži sa limit“ | rovnaký tvar |
| Chýbajúci riadok pre aktuálny rok | ľavý okraj `--bs-info`, odznak `text-bg-info` „Chýba limit“ | „{subjekt}: limit pre rok {X} chýba, použitý rok {Y}. Skontrolujte hodnoty.“ |
| Nekonzistentná mena | ľavý okraj danger, odznak „Chyba konfigurácie“ | „{subjekt}: {názov} má inú menu ako subjekt.“ |

Pod názvom 12px muted: „{spôsob výpočtu} · {obdobie od–do}“. Pri viacerých riadkoch limitu pre krajinu sa zobrazia všetky prekročené prahy samostatne (D-02). Chýbajúci rok sa **nedá zavrieť ani odložiť** (D-05). Bez tlačidla „Skryť“. Pre viac aktívnych subjektov každý vlastné riadky.

Responzívne: jeden stĺpec, bez horizontálneho posúvania, text sa zalamuje. Stav nikdy iba farbou: vždy odznak s textom + percento.

---

## F. Predkontrola v náhľade faktúry (`invoicing/_limit_warning.php`, LIM-04, D-16)

Zobrazí sa iba ak: klient má `counts_toward_limit = true` a vystavenie by prekročilo niektorý prah niektorého limitu subjektu. Umiestnenie: v `_preview.php` pod kartou kurzu (`_fx_card`), nad súhrnom a sticky pätkou (blízko miesta rozhodnutia, nad „Pokračovať“). **Nikdy neblokuje** vystavenie a nemení tlačidlá ani poradie.

Komponent: `alert alert-warning` (`alert-danger` ak by sa prekročil najvyšší prah, t. j. 100 %) s `role="alert"`, 12px text, 16px padding. Obsah:

- Nadpis 16px 400 (nie tučne): „Vystavenie prekročí prah limitu.“ (pri crit: „Vystavenie prekročí limit obratu.“)
- Pre každý dotknutý limit jeden riadok: „{názov} ({rok}): {pred} % → {po} %“ a pod ním 12px muted „{obrat pred} + {suma faktúry} = {obrat po} z {limit}“ (suma faktúry v mene subjektu z náhľadového kurzu, pri cudzej mene s hintom „Prepočítané kurzom z náhľadu.“).
- Pätka 12px muted: „Upozornenie nebráni vystaveniu. Rozhodnutie je na vás.“ + odkaz „Zobraziť limity“ (`/settings/limits`, nový panel/karta `target="_blank" rel="noopener"` **[predpoklad]**, aby sa nestratil rozpracovaný náhľad).

Stavy:
- Faktúra už prekračuje prah pred vystavením (obrat pred ≥ prah): text „Limit už je prekročený: {pred} % → {po} %“ (rovnaká štruktúra, `alert-danger` ak ≥ 100 %).
- Nekonzistentná mena alebo chýbajúci rok: `alert alert-secondary` 12px „Predkontrola limitu nie je dostupná: {dôvod}.“ s odkazom do nastavení (chýbajúci rok: použitý posledný známy, preto sa kontrola vykoná a pridá sa hint „Limit pre rok {X} chýba, použitý rok {Y}.“).
- Klient nezapočítavaný (`counts_toward_limit = false`): žiadne upozornenie, bez vysvetlenia.
- Výpočet zlyhal (výnimka): `alert alert-secondary` 12px „Predkontrolu limitu sa nepodarilo vypočítať. Vystavenie to neovplyvní.“ (vystavenie je dovolené).
- Zmena dátumu dodania prekreslí náhľad (existujúci `hx-get`), upozornenie sa prepočíta spolu s ním; `.htmx-indicator` je už na náhľade.

Potvrdzovací sheet vystavenia (Phase 5) sa **nemení**; upozornenie sa v ňom neopakuje **[predpoklad]** (vlastník ho už videl a sheet zostáva krátky na mobile).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, vecný tón. Texty iba cez `lang()` (`Limits.php`, `Dashboard.php`, `Invoicing.php`).

| Element | Copy |
|---------|------|
| Primary CTA (obrazovka) | Pridať limit |
| Primary CTA (sheety) | Uložiť |
| Sekundárne | Späť na Viac, Upraviť, Zmazať, Zrušiť, Späť, Zobraziť limity, Pridať limit pre rok {X} |
| Nadpis stránky | Limity obratu |
| Položka v menu Viac | Limity obratu |
| Sekcie | Čerpanie · Tabuľka limitov · Počiatočný obrat |
| Úvod | Sleduje obrat za subjekt voči limitom. Hodnoty sa dajú upraviť bez zásahu do kódu. |
| Seed upozornenie | Predvyplnené limity sú návrh a nie sú overené účtovníčkou. Skontrolujte ich pred použitím. |
| Odznak seed | Návrh, neoverené účtovníčkou |
| Empty state heading | Žiadne limity |
| Empty state body | Pridajte aspoň jeden limit obratu, inak sa upozornenia nezobrazujú. |
| Počiatočný obrat nezadaný | nezadaný |
| Odznaky stavu | V norme · Blíži sa limit · Limit dosiahnutý · Prísnejšia hodnota · Chyba konfigurácie · Použitý rok {Y} · Chýba limit |
| Zostáva / prekročené | Zostáva {suma} · Prekročené o {suma} |
| Vzorec | Obdobie · Obrat z dokladov · Počiatočný obrat · Spolu · Limit · Čerpanie |
| Metódy | Kalendárny rok · Kĺzavých 12 mesiacov · Pomerný prvý rok |
| Hint pomerný limit | Dni od {dátum} vrátane, základ 365 dní. |
| Hint pomerný limit mimo 1. roka | Pomerný limit sa uplatňuje len v prvom roku činnosti, teraz platí plný limit. |
| Hint počiatočný obrat pri kĺzavom | Počiatočný obrat sa pripočíta celý, lebo okno zasahuje do roka {Y}. |
| Chýbajúci rok (alert) | Limit pre rok {X} chýba, použitý rok {Y}. Skontrolujte hodnoty alebo pridajte limit pre rok {X}. |
| Žiadny limit pre krajinu | Pre krajinu {krajina} nie je zadaný žiadny limit. |
| Chyba meny (alert) | Chyba konfigurácie: limit je v {mena limitu}, subjekt účtuje v {mena subjektu}. Obrat sa nepočíta. Upravte menu limitu. |
| Dashboard nadpis | Limity obratu |
| Dashboard riadok | {subjekt}: {názov} {percento} % ({spolu} z {limit}) |
| Predkontrola nadpis | Vystavenie prekročí prah limitu. · Vystavenie prekročí limit obratu. |
| Predkontrola riadok | {názov} ({rok}): {pred} % → {po} % |
| Predkontrola pätka | Upozornenie nebráni vystaveniu. Rozhodnutie je na vás. |
| Predkontrola nedostupná | Predkontrola limitu nie je dostupná: {dôvod}. |
| Predkontrola zlyhala | Predkontrolu limitu sa nepodarilo vypočítať. Vystavenie to neovplyvní. |
| Error state (uloženie zlyhalo, 500) | Limit sa nepodarilo uložiť. Nič sa neuložilo, skúste to znova. |
| Error state (súbeh, 409) | Limit sa medzitým zmenil. Načítajte ho znova a skúste uloženie zopakovať. |
| Destructive confirmation (zmazanie limitu) | Zmazať limit: Limit {názov} ({krajina}, {rok}) sa odstráni. Doklady sa nezmenia, len sa prestane sledovať tento limit. Zmena sa zapíše do denníka. |
| Flash úspech | Limit {názov} bol uložený. · Limit {názov} bol zmazaný. · Počiatočný obrat bol uložený. |

Validačné texty: v sekciách C a D.

---

## Interaction and State Contract

- **Jediný zdroj čísla**: všetky čísla (nastavenia, dashboard, predkontrola) prináša `TurnoverCalculator` (D-08); UI nikdy nepočíta samo, iba formátuje reťazce cez `MoneyFormat` a zobrazuje percento zaokrúhlené na 1 desatinné miesto, half-up, len na zobrazenie (D-12). Žiadny `float`.
- **Dvojkrokový tok netreba**: úpravy nastavení sú jednokrokové sheety s `data-submit-once`; iba zmazanie vyžaduje potvrdenie.
- **Auditovanie**: každé uloženie/zmazanie limitu a počiatočného obratu ide cez `AuditLogger` (D-06); v UI sa to neukazuje okrem vety v potvrdení zmazania.
- **Neutíšiteľné upozornenia**: chýbajúci rok a prekročenie 100 % sa nedajú skryť, odložiť ani zavrieť (D-05, D-15).
- **Dashboard výkon**: blok sa načítava s existujúcim fragmentom `body`; dodatočný request ani spinner nevzniká (p95 < 100 ms, SUM nad indexom).
- **Mobil jednou rukou**: jeden stĺpec, tlačidlá `w-100` 44px v spodnej polovici (sticky pätka v sheete), 16px písmo (bez iOS zoomu), `select` natívny, bez horizontálneho posúvania; pridanie limitu najviac 3 klepnutia mimo vypĺňania (Pridať limit, polia, Uložiť).
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; šírka progressu sa nesie natívnym atribútom `value` na `<progress>`, rozmery cez triedy v `app.css`.
- **Prístupnosť**: `label for`, `aria-describedby`, `role="alert"` pre varovania a chyby, `role="status"` pre informačné bloky, `<progress>` s `aria-label` a textom percenta, kontrast min 4.5:1 (text na `text-bg-warning` je tmavý), stav nikdy iba farbou ani iba ikonou, focus na prvé neplatné pole.
- **Offline**: stránky a fragmenty limitov sa nekešujú (SW nekešuje autentifikované HTML).
- **Mimo rozsahu**: mesačné/ročné prehľady a exporty (Phase 11), prehliadač denníka (Phase 11), preklady PL/CZ/EN (Phase 12), notifikácie e-mailom alebo push, vlastné prahy podľa subjektu mimo tabuľky limitov.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Tvar UI nastavení (zoznam vs záložky) | jedna stránka `/settings/limits`, tri sekcie pod sebou, bez záložiek; úpravy v `.dl-sheet` |
| Zoznam limitov | zoznam `.lim-row` (nie tabuľka), kvôli žiadnemu horizontálnemu posúvaniu na iPhone |
| Zadávanie prahov | jedno textové pole „80, 100“ (zoznam celých čísel, 1 až 3 hodnoty, 1 až 200), v DB `int[]` |
| Zobrazenie stavu | natívny `<progress>` + text percenta + odznak; farby warning/danger/neutrálna, nikdy accent |
| Počiatočný obrat pri `rolling_12m` | pripočíta sa celý, ak okno zasahuje do roka; vo vzorci vyznačené vetou (konzervatívne, D-07) |
| Dashboard umiestnenie | blok `_limits.php` nad „Po splatnosti“, iba pri dôvode |
| Predkontrola | `alert-warning`/`alert-danger` v náhľade nad pätkou, neblokuje, v potvrdzovacom sheete sa neopakuje |
| Odkaz na limity z predkontroly | nová karta, aby sa nestratil náhľad |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Žiadne nové balíky ani CDN.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
