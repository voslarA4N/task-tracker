---
phase: 8
slug: meny-a-kurzy
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-30
revised: 2026-10-30
reviewed_at: 2026-10-30
---

# Phase 8 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 8 (Meny a kurzy). Dedí tokeny z `02-UI-SPEC.md` až `07-UI-SPEC.md` a nemení ich (spacing násobky 4, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk UI je slovenčina, texty iba cez `lang()` (nový súbor `app/Language/sk/Currency.php` + rovnaké kľúče v `en`; doplnky do `Invoicing.php`, `Invoices.php`, `LegalTexts.php`). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build (v tejto fáze sa nepoužíva), htmx `allowEval=false`.
> Zdroje: 08-CONTEXT.md (D-01 až D-15), 08-RESEARCH.md (Pattern 3 smer kurzu, pasce, trasy, `fx_country_settings`), 07-UI-SPEC.md, 05-UI-SPEC.md, CLAUDE.md, existujúce `app/Views/invoicing/_preview.php`, `_confirm_sheet.php`, `app/Views/invoices/show.php`, `_list.php`, `app/Views/settings/legal_texts/*`, `app/Views/pdf/invoice_inv2.php`. Vlastník nechce interaktívnu diskusiu; predpoklady sú označené **[predpoklad]**.
> Rozsah: (A) blok kurzu v náhľade faktúry, (B) ručné zadanie a nedostupné API, (C) potvrdenie vystavenia v cudzej mene, (D) detail a zoznam faktúr (druhá suma), (E) PDF riadok kurzu, (F) Nastavenia → Kurz mien (podľa krajiny), (G) zoznam mien (bez UI, len dátový kontrakt).

---

## Smer kurzu: základ (`{base}`) a protimena (`{quote}`) — platí pre celý dokument

Smer sa nikdy nepredpokladá. Vždy sa odvodzuje z `RateQuote.direction` (08-RESEARCH Pattern 3, D-09, D-13). Kurz sa zobrazuje, zadáva aj ukladá **presne v smere, ako ho publikuje zdroj**; nikdy sa neprevracia ani nenormalizuje na „1 jednotku“.

| `direction` | Zdroj (mena subjektu → mena faktúry) | `{base}` | `{quote}` | Vzorec súčtu v mene subjektu |
|-------------|--------------------------------------|----------|-----------|------------------------------|
| `multiply` | NBP (PLN ← EUR, CZK), ČNB (CZK ← EUR, PLN) | mena **faktúry** | mena **subjektu** | `round(total × rate ÷ amount, 2)` |
| `divide` | ECB (EUR ← PLN, CZK) | mena **subjektu** (EUR) | mena **faktúry** | `round(total ÷ rate, 2)` |

Riadok kurzu (jediná šablóna textu pre náhľad, sheet, detail aj PDF): **`{amount} {base} = {rate} {quote}`**, kde `{amount}` je publikovaná jednotka zdroja (1, pri ČNB napr. 100; pri `divide` vždy 1).

Príklady (všetky sú kontraktové):

| Zdroj | Subjekt / faktúra | Riadok kurzu | Súčet |
|-------|-------------------|--------------|-------|
| NBP (`multiply`) | PLN / EUR | „Kurz NBP: 1 EUR = 4,2512 PLN“ | 1 000,00 € → 4 251,20 zł (`1000,00 × 4,2512`) |
| ČNB (`multiply`) | CZK / EUR | „Kurz ČNB: 1 EUR = 24,3350 CZK“ → pri publikovanej jednotke 100: „Kurz ČNB: 100 HUF = 4,2000 CZK“ | `total × rate ÷ amount` |
| **ECB (`divide`)** | **EUR / PLN** | **„Kurz ECB: 1 EUR = 4,3945 PLN“** | 4 394,50 zł → 1 000,00 € (`4394,50 ÷ 4,3945`) |

Pravidlá zobrazenia kurzu (jeden spoločný formátovač `FxFormat::rateLine()`/`FxFormat::rate()` pre náhľad, sheet, detail aj PDF; žiadna iná cesta):
1. **Natívne desatinné miesta tak, ako ich publikuje zdroj.** Koncové nuly sa zachovajú (NBP „4,4090“, nie „4,409“), nič sa nedopĺňa na 8 miest (stĺpec `numeric(18,8)` sa na zobrazenie nepoužíva). Do snapshotu `fx` sa preto ukladá aj `rate_display` (publikovaný reťazec); detail a PDF čítajú `rate_display`, nie `fx_rate`. Ručný kurz sa zobrazí tak, ako ho používateľ zadal (po normalizácii čiarky na bodku a odstránení zbytočných úvodných núl), bez dopĺňania núl.
2. Desatinný oddeľovač podľa jazyka (UI = SK čiarka; PDF podľa jazyka dokladu), bez oddeľovača tisícov v kurze.
3. Jednotka `{amount}` sa zobrazí vždy tak, ako ju zdroj publikuje („100 HUF = 4,2000 CZK“); **nikdy** sa nezobrazí normalizovaný („1 HUF = 0,0420 CZK“) ani prevrátený kurz.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 (nepoužité) |
| Icon library | žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`); stav nikdy iba ikonou |
| Font | systémový stack z `app.css`, čísla `tabular-nums`; PDF font podľa Phase 6 (latin-ext, pokrýva zł, Kč, €) |

Reuse (neprepisovať): `.app-card`, `.app-main`, `.master-kv`, `.master-sticky-actions`, `.inv-summary`, `.inv-warn`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `HtmxCsrf`, `responseHandling` pre 422 a 409, `fragments/alert.php`, `.htmx-indicator` + `.spinner-border-sm`, `.st-badge`.

Nové triedy v `app.css` (nová verzia súboru v názve cez `asset_url`; prefix `.fx-*`):

| Trieda | Účel |
|--------|------|
| `.fx-card` | blok kurzu v náhľade (derivát `.app-card`/`.inv-summary`, vždy plná šírka, `mb-3`) |
| `.fx-rate` | riadok kurzu „Kurz NBP: 1 EUR = 4,2512 PLN“ (16px/600, `tabular-nums`, `overflow-wrap:anywhere`) |
| `.fx-meta` | 12px muted druhý riadok („z 28. 10. 2026 · tab. 209/A/NBP/2026 · …“) |
| `.fx-total` | **iba** prepočítaný súčet v náhľade („Spolu v {mena subjektu}“, Display 28px/600; ohnisko bloku). V zoznamoch a na detaile sa nepoužíva |
| `.fx-manual` | `<details>` s ručným formulárom kurzu (kontajner polí; otvorenie natívnym `<details>`, žiadne `d-none`, žiadny JS) |
| `.fx-badge` | odznak pôvodu kurzu („Ručný kurz“), Bootstrap `badge text-bg-warning` + text |
| `.fx-sub` | druhá suma pod hlavnou sumou v zoznamoch a na detaile (12px muted, `tabular-nums`, vpravo) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 7 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera medzi `.fx-rate` a `.fx-meta`, hint pod poľom kurzu |
| sm | 8px | medzi tlačidlami v `.fx-card`, medzi odznakom a textom |
| md | 16px | padding `.fx-card`, odsadenie polí (`mb-3`), okraje na mobile |
| lg | 24px | medzi `.fx-card` a súhrnom/zoznamom riadkov náhľadu |
| xl | 32px | medzi skupinami krajín na stránke Kurz mien |
| 2xl | 48px | horné odsadenie prázdnych stavov (existujúce) |
| 3xl | 64px | výška sticky lišty a spodná rezerva obsahu (existujúce) |

Exceptions:
- Dotykové ciele min **44px**: `<summary>` „Zadať ručne“, „Použiť kurz“, „Zahodiť ručný kurz“, „Skúsiť znova“, pole ručného kurzu, pole dátumu, `select` zdroja/základu/pravidla.
- Žiadne horizontálne posúvanie: `.fx-rate`, `.fx-meta` a číslo tabuľky majú `overflow-wrap:anywhere`; žiadne tabuľky v UI (iba zoznamy riadkov a `dl.master-kv`).

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 až 7, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (pole kurzu, tlačidlá, riadky zoznamov) | 16px | 400 | 1.5 |
| Label / meta (popisky, hint, `.fx-meta`, `.fx-sub`, odznak, `invalid-feedback`, upozornenia) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2` sekcií, nadpis karty „Kurz“, nadpis alertu B2) | 20px | 600 | 1.2 |
| Display (`.fx-total` v náhľade, Celkom v hlavičke detailu, existujúce) | 28px | 600 | 1.2 |

Hrúbka 600 sa v tejto fáze používa **iba** pre: `h1`/`h2`, `.fx-rate` (16px), `.fx-total` (28px) a názvy skupín/krajín. Všetko ostatné je 400. Druhá suma v zoznamoch a na detaile je `.fx-sub` (12px/400), nie `.fx-total`.

PDF: pozri E (používa existujúcu typografiu šablóny `inv-2`, žiadne nové veľkosti).

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, `.fx-card`, riadky, sheet, sticky pätka |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | iba chybové stavy (`alert-danger`, `is-invalid`); v tejto fáze žiadna destruktívna akcia |

Accent reserved for (výlučne, jedna hlavná akcia na obrazovku):
1. Primárne tlačidlo hlavnej akcie obrazovky: **Vystaviť faktúru** (existujúce v náhľade/potvrdzovacom sheete; v tejto fáze sa nemení, len je pri chýbajúcom kurze `disabled`), **Uložiť** (stránka Kurz mien)
2. Aktívna položka navigácie (existujúce)
3. Focus ring polí a tlačidiel
4. Textové odkazy v tele (existujúce; odkaz „Zmeniť zdroj a dátum kurzu v nastaveniach“)
5. Zaškrtnuté checkbox/radio (Bootstrap predvolené)

Accent sa **nepoužíva** pre kurz, odznaky, upozornenia ani hranice `.fx-card`. Tlačidlá „Použiť kurz“ a „Skúsiť znova“ sú `btn-outline-secondary`, „Zahodiť ručný kurz“ je `btn-link`. Prepočítaná suma nie je zvýraznená farbou, iba veľkosťou/hrúbkou.

### Odznaky a upozornenia kurzu (vždy text, nikdy iba farba)

| Stav | Trieda | Text |
|------|--------|------|
| Kurz stiahnutý z oficiálneho zdroja | `.fx-meta` bez odznaku (stav je štandard) | „z 28. 10. 2026 · tab. 209/A/NBP/2026 · …“ |
| Kurz zadaný ručne | `badge text-bg-warning` (`.fx-badge`) | Ručný kurz |
| API nedostupné / kurz sa nenašiel | `alert alert-warning` | viď B2 (variant 1) |
| Dvojica mien zdroj nepodporuje | `alert alert-warning` | viď B2 (variant 2) |
| Chybný vstup kurzu | `is-invalid` + `invalid-feedback` | viď Copywriting |
| Faktúra v mene subjektu | blok kurzu sa **nevykresľuje** (bez prázdneho stavu) | |

`alert-danger` sa používa len pre pokus o vystavenie bez kurzu (422), pre `fx_overflow` a pre `fx_invalid` (viď C a Copywriting); nedostupné API je `alert-warning` (D-12).

---

## Trasy a vstupy (podľa 08-RESEARCH; žiadne nové trasy pre kurz v náhľade)

| Obrazovka | Trasa | Zmena |
|-----------|-------|-------|
| Náhľad faktúry | existujúce `GET /fragments/invoice-preview` (`hx-get`, `hx-target="#inv-preview"`, `hx-swap="outerHTML"`, `hx-push-url`) | `PreviewInput::FIELDS` sa rozšíri o `fx_mode` (`manual` alebo prázdne = auto), `fx_rate`, `fx_date`, `fx_anchor` (kotviaci dátum, pre ktorý bol ručný kurz zadaný) a jednorazové `fx_retry` (`1`). Whitelist + validácia formátu v `PreviewInput` |
| Vystavenie | existujúce `POST /invoices/issue` | `IssueInput::SCALARS` dostane `fx_mode`, `fx_rate`, `fx_date` (skryté polia `#inv-issue-form` vykreslené z aktuálneho `Draft`, vedľa existujúceho tokenu náhľadu). Server nikdy neprijíma zdroj, tabuľku ani sumu z POST; `manual` sa vždy ukladá ako `fx_source = 'manual'` |
| Nastavenie kurzu | nový `GET/POST /settings/fx` (`Settings/FxSettings`, owner filter + CSRF ako ostatné `Settings/*`) | viď F |

Žiadny `GET invoicing/fx`, žiadny `POST invoicing/fx/manual`. Ručné zadanie nie je samostatný POST: ide o ten istý `hx-get` náhľadu s `fx_mode=manual`; náhľad sa prekreslí a vráti nový `previewToken`.

---

## A. Blok kurzu v náhľade faktúry (CUR-02, D-07, D-08)

Umiestnenie: `invoicing/_preview.php`, `.fx-card` **nad** súhrnom súm a pod výberom klienta/dátumov (kurz závisí od dátumov, preto je za nimi). Vykreslí sa **iba** ak mena faktúry != mena subjektu. Pri rovnakej mene nič (správanie Phase 5 bez zmeny).

### A1. Obsah `.fx-card` (zhora nadol), stav „kurz načítaný“

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | `h2` 20px | „Kurz“ |
| 2 | `.fx-rate` | „Kurz {zdroj}: {amount} {base} = {rate} {quote}“ (smer podľa `RateQuote.direction`, pozri úvod). NBP: „Kurz NBP: 1 EUR = 4,2512 PLN“. **ECB: „Kurz ECB: 1 EUR = 4,3945 PLN“ (EUR subjekt, PLN faktúra)**. ČNB so stovkou: „Kurz ČNB: 100 HUF = 4,2000 CZK“. Formát cez `FxFormat` v jazyku UI (SK), nie v jazyku dokladu |
| 3 | `.fx-meta` | „z {dátum} · tab. {číslo} · {základ a pravidlo dňa z nastavenia}“. NBP: „z 28. 10. 2026 · tab. 209/A/NBP/2026 · podľa dátumu dodania, predchádzajúci pracovný deň“. ČNB: „z 29. 10. 2026 · tab. 208 · …“ (číslo je `order`). ECB: „z 29. 10. 2026 · …“ **bez** čísla tabuľky (ECB ho nemá). Pri ČNB sa jednotka (`amount`) zobrazuje iba v `.fx-rate` v publikovanej podobe; žiadny prepočet na 1 jednotku |
| 4 | `.fx-total` | 12px label „Spolu v {mena subjektu}“ nad hodnotou „4 251,20 zł“ (Display 28px). Pod ním `.fx-meta` s popisom výpočtu podľa smeru: `multiply` „z 1 000,00 € prepočítané kurzom (násobením), zaokrúhlené na 2 desatinné miesta“; `divide` „z 4 394,50 zł prepočítané kurzom (delením), zaokrúhlené na 2 desatinné miesta“ |
| 5 | `<details class="fx-manual">` | `<summary>` „Zadať ručne“ (44px, `btn btn-outline-secondary`-vzhľad, na mobile plná šírka). Obsah pozri B1. Atribút `open` sa vykreslí serverom, keď je aktívny ručný kurz alebo kurz chýba (B2) |
| 6 | Odkaz 12px | „Zmeniť zdroj a dátum kurzu v nastaveniach“ (len ak má používateľ prístup; vedie na `/settings/fx`, F) |

### A2. Stavy

- **Loading** (zmena dátumu dodania/vystavenia, klienta, ručný kurz, „Skúsiť znova“): náhľad je jeden htmx request (`GET /fragments/invoice-preview`), kurz sa sťahuje na serveri v rámci neho (timeout 3 s, D-07). Počas requestu existujúci `.htmx-indicator` v `#inv-preview` zobrazí 12px „Načítavam kurz…“ + `.spinner-border-sm` a tlačidlo „Vystaviť faktúru“ je `disabled` (`hx-disabled-elt`), aby sa nevystavilo so starým tokenom.
- **Načítané (štandard)**: viď A1.
- **Kurz z cache**: vizuálne rovnaký ako načítaný (cache je implementačný detail, D-07), žiadny odznak.
- **Zmena dátumu**: kurz sa znova vyhľadá (D-08), `previewToken` sa zmení. Polia dátumu a klienta v `hx-include` zahŕňajú aj skryté `#fx-state` (`fx_mode`, `fx_rate`, `fx_date`, `fx_anchor`); ak `fx_mode=manual` a `fx_anchor` nesedí s novým kotviacim dátumom, ručný kurz **sa zahodí**, zobrazí sa nový stiahnutý a vykreslí sa 12px `alert alert-secondary` „Dátum sa zmenil, kurz bol načítaný znova.“ **[predpoklad]** ručný kurz je viazaný na dátum, inak by sa vystavilo s kurzom pre iný deň.
- **API nedostupné / dvojica nepodporovaná**: viď B2.
- **Prístupnosť**: `.fx-card` je `role="region"` s `aria-labelledby` nadpisu; `aria-live="polite"` na `.fx-rate` a `.fx-total`.

---

## B. Ručné zadanie kurzu a nedostupné API (CUR-02, CUR-03, D-11 až D-13)

### B1. Ručné zadanie (`<details class="fx-manual">`)

Rozbalenie je natívne (`<details>`/`<summary>`), bez JavaScriptu a bez Alpine (CSP-bezpečné, žiadny konfliktný `d-none`). Polia sú v `<details>` mimo formulára vystavenia; „Použiť kurz“ je `type="button"` s `hx-get="/fragments/invoice-preview"` a `hx-include` na polia `fx_rate`, `fx_date`, skryté `fx_mode=manual` a `fx_anchor` v `<details>` plus parametre náhľadu. Odpoveď prekreslí `#inv-preview` a vráti nový token.

| Prvok | Špecifikácia |
|-------|--------------|
| Popisok | `label` „Kurz: {amount=1} {base} = ___ {quote}“. Tvar: `{base}` ako text pred poľom („1 EUR =“), `{quote}` ako `input-group-text` za poľom („PLN“). Smer podľa `direction` zdroja: NBP/ČNB „1 EUR = ___ PLN“ (`{base}` = mena faktúry); ECB (EUR subjekt) „1 EUR = ___ PLN“ alebo „1 EUR = ___ CZK“ (`{base}` = EUR = mena subjektu, `{quote}` = mena faktúry). Pri dvojici bez podporovaného zdroja (B2 variant 2) **[predpoklad]** `multiply`: „1 {mena faktúry} = ___ {mena subjektu}“ |
| Pole | `input type="text" inputmode="decimal"`, 16px, 44px, `autocomplete="off"`, placeholder = stiahnutý kurz v natívnom formáte (ak existuje) alebo príklad „4,2512“. Akceptuje čiarku aj bodku; server: string cez bcmath, regex `^\d{1,10}(\.\d{1,8})?$`, > 0 (D-13) |
| Dátum platnosti kurzu | `input type="date"`, predvolene vypočítaný deň podľa D-05; label „Kurz platí ku dňu“; `max` = dnes; `min` = dnes − 1 rok **[predpoklad, RESEARCH pasca 15 voliteľne]** |
| Hint 12px | „Zadajte kurz v rovnakom smere, aký uvádza {zdroj}: {amount} {base} = … {quote}.“ Dôvod sa nevyžaduje (D-11) |
| Tlačidlá | **„Použiť kurz“** (`btn-outline-secondary`, 44px; primárny accent ostáva „Vystaviť faktúru“). Keď je ručný kurz aktívny: **„Zahodiť ručný kurz“** (`btn-link`, 44px; `hx-get` bez `fx_*` polí, vráti stiahnutý kurz, ak existuje; nahrádza generické „Zrušiť“ aj samostatné „Použiť stiahnutý kurz“). Zatvorenie bez zmeny: opätovný klepnutie na `<summary>` |
| Výsledok | `.fx-card` sa prekreslí: `.fx-rate` „Kurz: 1 EUR = 4,3000 PLN“ (bez zdroja, v smere zdroja), `.fx-badge` „Ručný kurz“, `.fx-meta` „platí ku dňu 28. 10. 2026 · zadané ručne“. Ak bol k dispozícii stiahnutý kurz, 12px „Stiahnutý kurz bol NBP 4,2512 z 28. 10. 2026.“ (zdroj + natívny kurz; D-11, pôvodný kurz ide do snapshotu) |
| Chyby 422 | `is-invalid` + `invalid-feedback`, focus na prvé neplatné pole (texty v Copywriting): neplatný kurz, príliš veľa desatinných miest, neplatný dátum, dátum v budúcnosti, dátum starší ako rok |

### B2. Kurz sa nepodarilo získať (CUR-03) — dva rozlíšené varianty

Spoločné pre oba varianty: `.fx-card` namiesto `.fx-rate` zobrazí `alert alert-warning` s nadpisom „Kurz zatiaľ nie je k dispozícii“ (20px/600, rola Heading, ten istý text ako empty-state heading) a pod ním 12px telo variantu. `<details class="fx-manual">` je **rozbalené** serverom (`open`, jeden krok k zadaniu), polia prázdne. `.fx-total` sa nezobrazí; v súhrne náhľadu je 12px muted „Spolu v {mena subjektu}: bude známe po zadaní kurzu.“ „Vystaviť faktúru“ je `disabled` a pod ním 12px hint „Vystavenie vyžaduje kurz. Zadajte ho ručne alebo skúste načítanie znova.“ (pri variante 2 bez „alebo skúste načítanie znova“). Aplikácia sa nezablokuje: ostatné časti náhľadu fungujú.

1. **Variant 1: nepodarilo sa načítať** (timeout, 4xx/5xx, prázdny rozsah; D-12). Telo: „Kurz {zdroj} sa nepodarilo načítať. Zadajte kurz ručne alebo skúste načítanie znova.“ Bez technických detailov (chyba je v logu). Tlačidlá „Skúsiť znova“ (`btn-outline-secondary`, 44px) a ručné zadanie.
   - **„Skúsiť znova“ obchádza negatívnu cache (~60 s, RESEARCH pasca 7):** posiela `hx-get` náhľadu s `fx_retry=1`; server pri ňom záznam negatívnej cache zmaže a sieťové volanie zopakuje (timeout 3 s). Tlačidlo je počas requestu `disabled` s `.spinner-border-sm`. `fx_retry` sa nikdy nezahrnie do tokenu ani do skrytých polí. Ďalšie bežné re-rendery (zmena dátumu) negatívnu cache rešpektujú.
2. **Variant 2: dvojica mien neexistuje** (zdroj profilu túto kombináciu nepodporuje, `unsupported_pair`; napr. SK subjekt v PLN so zdrojom ECB, alebo mena nie je v zdroji). Telo: „Zdroj {zdroj} neposkytuje kurz pre túto dvojicu mien ({mena subjektu} a {mena faktúry}). Kurz bude vždy nutné zadať ručne.“ **Žiadne „Skúsiť znova“** (opakovanie nepomôže), iba ručné zadanie.

---

## C. Potvrdenie vystavenia v cudzej mene (D-03, D-14)

- Sheet `invoicing/_confirm_sheet.php` sa rozšíri o dva riadky `dl.master-kv` pre cudziu menu (bez nového sheetu a bez dodatočného potvrdenia): „Kurz“: `{amount} {base} = {rate} {quote}` so zdrojom a dňom (napr. „1 EUR = 4,2512 PLN (NBP, z 28. 10. 2026)“; ECB: „1 EUR = 4,3945 PLN (ECB, z 29. 10. 2026)“) a „Spolu v {mena subjektu}“: „4 251,20 zł“. Pri ručnom kurze dodatok odznak `.fx-badge` „Ručný kurz“.
- Primárne tlačidlo „Vystaviť faktúru“ sa nemení (accent, v spodnej polovici sheetu, jedna ruka).
- **Zastaraný náhľad** (zmenený kurz alebo dátum, `previewToken` nesedí): existujúci mechanizmus Phase 5, `alert alert-warning` „Údaje sa medzitým zmenili. Skontrolujte náhľad a vystavte faktúru znova.“ (existujúci text), blok kurzu je prekreslený novými hodnotami.
- **Chýbajúci kurz** pri POST (422 `fx_missing`, nahrádza `currency_mismatch`, D-03): `alert alert-danger` „Faktúra je v inej mene ako subjekt. Pred vystavením je potrebný kurz. Načítajte ho alebo ho zadajte ručne.“
- **Neplatný ručný kurz** pri POST (422 `fx_invalid`): `alert alert-danger` „Ručne zadaný kurz nie je platný. Skontrolujte kurz a dátum a skúste to znova.“
- **Prekročený rozsah** (422 `fx_overflow`, súčet v mene subjektu presahuje `numeric(12,2)`, RESEARCH pasca 11): `alert alert-danger` „Súčet v {mena subjektu} je príliš vysoký na uloženie. Skontrolujte kurz alebo rozdeľte faktúru.“ Zobrazí sa aj v náhľade (blokuje vystavenie, `.fx-total` sa nevykreslí).
- **Úspech**: flash existujúci „Faktúra {číslo} bola vystavená.“ (Phase 5/7); žiadny samostatný text pre kurz.
- **Audit**: vystavenie s ručným kurzom sa zapíše do `audit_log` (D-11); v UI sa neukazuje (AUD-02 je Phase 11).

---

## D. Detail a zoznam faktúr — druhá suma (D-14)

Dáta z `invoices.total_entity_ccy`, snapshotu `fx` (`rate_display`, `amount`, `direction`, `source`, `valid_for`, `table_no`) a stĺpcov `fx_*` (nikdy zo živej cache). Riadky kurzu používajú `FxFormat` (pravidlá v úvode).

- **Zoznam (`invoices/_list.php`)**: pod sumou vpravo `.fx-sub` s prepočítanou sumou v mene subjektu (napr. „4 251,20 zł“, formát cez `MoneyFormat`, bez „≈“ a bez predpony, kód meny je súčasťou formátu). Ak riadok už má zostatok z Phase 7, `.fx-sub` je pod ním (odstup xs). Faktúry v mene subjektu druhý riadok nemajú.
- **Detail (`invoices/show.php`)**: hlavička ostáva (Celkom 28px v mene faktúry). Pod ňou **jediné** zobrazenie súčtu v mene subjektu: `.fx-sub` „Spolu v {mena subjektu} 4 251,20 zł“ (duplicitný riadok „Spolu v …“ v karte „Údaje“ sa **nevykresľuje**). V karte „Údaje“ (`dl.master-kv`) nové riadky:
  - „Kurz“: `{amount} {base} = {rate} {quote}` (napr. „1 EUR = 4,2512 PLN“; ECB „1 EUR = 4,3945 PLN“; ČNB „100 HUF = 4,2000 CZK“) + `.fx-badge` „Ručný kurz“ pri ručnom,
  - „Zdroj kurzu“: „NBP, tab. 209/A/NBP/2026“, „ČNB, tab. 208“, „ECB“ (bez tabuľky) alebo „zadané ručne“,
  - „Dátum kurzu“: „28. 10. 2026 (podľa dátumu dodania)“.
  Pri ručnom kurze s pôvodným stiahnutým kurzom 12px riadok „Pôvodný kurz NBP: 1 EUR = 4,2512 PLN z 28. 10. 2026“ (z kópie údajov, D-11, rovnaký smer a formát).
- Sekcia „Zostatok“ a úhrady (Phase 7) ostávajú v mene faktúry; „uhradené v mene subjektu“ je mimo rozsahu.
- Odznak mien v zozname: už existujúci kód meny pri sume (bez zmeny).

---

## E. PDF — riadok kurzu pod súčtom (D-15)

Šablóna `app/Views/pdf/invoice_inv2.php` (blok `fx`), iba ak `fx` v snapshote existuje. Riadky položiek ostávajú len v mene faktúry. Texty sa **nikdy** nevykresľujú zo živých nastavení: všetko je predformátované v snapshote (`fx.text`: `rate_line`, `date_line`, `total_line`) v jazyku dokladu (SK, PL, CS, EN) cez `LABEL_KEYS`, `FxFormat` a `MoneyFormat`/`PdfFormat`.

Pod riadkom „Spolu“ (zarovnané vpravo ako súčet, rovnaká šírka stĺpca súm):

```
Spolu:                 1 000,00 €
Kurz NBP 1 EUR = 4,2512 PLN
z 28. 10. 2026, tab. 209/A/NBP/2026
Spolu v PLN:           4 251,20 zł
```

| Element | Špecifikácia |
|---------|--------------|
| Riadok „Kurz …“ a „z … tab. …“ | 9pt muted (veľkosť `.small` šablóny, nie nová veľkosť), zarovnané vpravo pod „Spolu“. Tvar `Kurz {zdroj} {amount} {base} = {rate} {quote}` |
| Riadok „Spolu v {kód meny subjektu} …“ | rovnaká váha a veľkosť ako riadok „Spolu“ (bold), suma `tabular-nums`/zarovnaná na rovnakú pravú hranu |
| Ručný kurz | namiesto zdroja: „Ručne zadaný kurz 1 EUR = 4,3000 PLN“ a „platí ku dňu 28. 10. 2026“ (bez tab. čísla) |
| ČNB s `amount` > 1 | „Kurz ČNB 100 HUF = 4,2000 CZK“ (jednotka ako publikuje zdroj, nikdy normalizovaná) |
| **ECB (`divide`)** | **Spolu 4 394,50 zł, „Kurz ECB 1 EUR = 4,3945 PLN“, „z 29. 10. 2026“ (bez tab.), „Spolu v EUR: 1 000,00 €“.** Smer ako publikuje ECB, nikdy prevrátený (D-09) |
| Desatinné miesta kurzu | z `rate_display` (natívne, koncové nuly zachované: „4,4090“), rovnaký `FxFormat` ako UI |
| Zalamovanie | bloky sú samostatné riadky bez tabuľkového prelomu, dlhé číslo tabuľky nesmie pretiecť stranu |
| Bez kurzu | faktúra v mene subjektu: blok sa nevykreslí (správanie Phase 6) |

**Registrácia popiskov (povinná, inak PDF vráti surový kľúč, RESEARCH pasca 14):** doplniť do `SnapshotBuilder::LABEL_KEYS` a do `app/Language/{sk,pl,cs,en}/InvoicePdf.php` (všetky štyri jazyky, `LangKeysTest` a test úplnosti prekladov v `tests/unit/I18n`):

| Kľúč | SK | PL | CS | EN |
|------|----|----|----|----|
| `fxRate` | Kurz | Kurs | Kurz | Exchange rate |
| `fxFrom` | z | z dnia | ze dne | of |
| `fxTable` | tab. | tabela | tab. | table |
| `fxTotalIn` | Spolu v | Razem w | Celkem v | Total in |
| `fxManual` | Ručne zadaný kurz | Kurs wprowadzony ręcznie | Ručně zadaný kurz | Manually entered rate |
| `fxManualFrom` | platí ku dňu | obowiązuje na dzień | platí ke dni | valid on |

Zdroj sa v PDF nepreklada (zostáva „NBP“, „ECB“, „ČNB“). **[predpoklad]** PL, CS a EN preklady sú predbežné do potvrdenia vlastníkom (rovnako ako Phase 7). Staré faktúry si nesú vlastné uložené `labels`, nič sa im nemení.

---

## F. Nastavenia → Kurz mien (podľa krajiny) (CUR-05, D-05)

Umiestnenie: **samostatná stránka** `GET/POST /settings/fx` (`Settings/FxSettings`, nad tabuľkou `fx_country_settings` kľúčovanou krajinou, nie právnym profilom). Nastavenie sa upravuje raz pre krajinu (SK, PL, CZ), nie v každom formulári právneho profilu. Odkazy: položka „Kurz mien“ na stránke Viac (`/more`) vedľa Zákonných textov a 12px odkaz „Kurz mien“ pod úvodom `settings/legal_texts/index.php`. Zmeny idú do `audit_log` (`AuditLogger::recordChange('fx_settings', …)`, pred/po, rovnaký vzor ako ostatné nastavenia).

Rozloženie (jedna stránka, jeden formulár, jedno primárne tlačidlo):
- `h1` „Kurz mien“ + 12px muted úvod „Určuje, odkiaľ sa berie kurz a ku ktorému dňu pri faktúre v inej mene, ako je mena subjektu. Zmena platí iba pre faktúry vystavené po uložení.“
- Tri `fieldset` bloky (SK, PL, CZ; `legend` 20px/600, odstup xl), každý s tromi poľami:

| Pole | Špecifikácia |
|------|--------------|
| Zdroj kurzu | `select`: Národná banka Poľska (NBP), Európska centrálna banka (ECB), Česká národná banka (ČNB) |
| Dátum, ku ktorému sa kurz hľadá | `select`: „Dátum dodania“ (predvolené), „Dátum vystavenia“ |
| Pravidlo dňa | `select`: „Posledný kurz pred dátumom“ (`previous_business_day`), „Kurz platný v daný deň, inak posledný pred ním“ (`on_or_before`) |

- Vysvetlivka 12px pod každým `select` (jedna veta, napr. pod pravidlom dňa „Podľa API zdroja; víkendy a sviatky sa preskočia automaticky.“).
- **Podporované dvojice (12px, statický zoznam pod poslednou krajinou, nie tabuľka):** „NBP poskytuje kurz pre subjekty vedené v PLN (faktúry v EUR, CZK). ČNB pre subjekty v CZK (faktúry v EUR, PLN). ECB pre subjekty v EUR (faktúry v PLN, CZK).“ Plus `alert alert-warning` (12px) pod každým výberom zdroja: „Pre kombináciu, ktorú zdroj nepodporuje (napr. ECB pri subjekte vedenom v PLN), bude kurz vždy nutné zadať ručne.“ Mapa zdroj → mena subjektu je dátový fakt o API (RESEARCH Pattern 3), nie ďalšie nastavenie; krajina neurčuje menu subjektu, preto sa kombinácia neodmieta pri uložení (422 iba pre neznámu hodnotu), ale pri faktúre sa zobrazí B2 variant 2.
- Predvolené 12px muted: „Predvolené: PL NBP, dátum dodania, posledný kurz pred dátumom · SK ECB, dátum dodania, posledný kurz pred dátumom · CZ ČNB, dátum dodania, kurz platný v daný deň.“
- Upozornenie 12px (`alert alert-secondary`): „Pravidlá sú predbežné. Overte ich s účtovníčkou.“ (jediná verzia, bez interných označení rizík).
- Sticky lišta `.master-sticky-actions`: „Uložiť“ (`btn-primary`, vpravo), „Späť“ (`btn-outline-secondary`, vľavo).

Správanie: `select` 44px, 16px. Odoslanie bežným POST (+ CSRF, PRG); skryté `revision` pre každú krajinu (`fx_country_settings.revision`). Úspech: flash „Nastavenia boli uložené.“ 422 (neznáma hodnota): „Vyberte platnú hodnotu.“ **409** (súbeh `revision`, vzor Phase 7): „Nastavenie kurzu medzitým niekto zmenil. Načítajte stránku znova a zopakujte úpravu.“ (htmx aj PRG variant zobrazí ten istý text v `alert alert-warning`).

---

## G. Zoznam mien (CUR-01)

V1 nemá UI na pridávanie mien (deferred). Kontrakt pre UI:
- Všade, kde sa dnes ponúka výber meny (formulár klienta, subjektu, bankového účtu, alokácie), `select` číta z tabuľky `currencies` (`active = true`, poradie podľa `sort_order`), nie z pevného zoznamu EUR/PLN/CZK; text voľby „EUR“, „PLN“, „CZK“ (kód meny, bez zmeny vzhľadu).
- Počet desatinných miest meny z `currencies` ovláda `MoneyFormat` (nie natvrdo 2). Pridanie meny (migrácia/seed) nevyžaduje zmenu šablón.
- Neaktívna mena sa v `select` neponúka, ale existujúce záznamy s ňou ostanú zobraziteľné.

---

## Copywriting Contract

Neutrálne vety bez oslovenia v UI, vecný tón. Texty iba cez `lang()` (`Currency.php`, `Invoicing.php`: `errorFxMissing`, `errorFxUnavailable`, `errorFxInvalid`, `errorFxOverflow`; nahrádza `errorCurrencyMismatch`).

| Element | Copy |
|---------|------|
| Primary CTA (existujúce, nemení sa) | Vystaviť faktúru |
| Sekundárne | Zadať ručne, Použiť kurz, Zahodiť ručný kurz, Skúsiť znova, Späť |
| Nadpis bloku | Kurz |
| Riadok kurzu (podľa smeru) | Kurz {zdroj}: {amount} {base} = {rate} {quote} |
| Príklad NBP (`multiply`) | Kurz NBP: 1 EUR = 4,2512 PLN |
| Príklad ECB (`divide`, EUR subjekt, PLN faktúra) | Kurz ECB: 1 EUR = 4,3945 PLN |
| Príklad ČNB so stovkou | Kurz ČNB: 100 HUF = 4,2000 CZK |
| Súčet v mene subjektu | Spolu v {mena subjektu} |
| Popis výpočtu (násobenie) | z {suma faktúry} prepočítané kurzom (násobením), zaokrúhlené na 2 desatinné miesta |
| Popis výpočtu (delenie) | z {suma faktúry} prepočítané kurzom (delením), zaokrúhlené na 2 desatinné miesta |
| Loading | Načítavam kurz… |
| Empty state / B2 nadpis alertu | Kurz zatiaľ nie je k dispozícii |
| B2 variant 1, telo (nepodarilo sa načítať) | Kurz {zdroj} sa nepodarilo načítať. Zadajte kurz ručne alebo skúste načítanie znova. |
| B2 variant 2, telo (dvojica neexistuje) | Zdroj {zdroj} neposkytuje kurz pre túto dvojicu mien ({mena subjektu} a {mena faktúry}). Kurz bude vždy nutné zadať ručne. |
| Empty state (súhrn bez kurzu) | Spolu v {mena subjektu}: bude známe po zadaní kurzu. |
| Hint pri disabled „Vystaviť faktúru“ | Vystavenie vyžaduje kurz. Zadajte ho ručne alebo skúste načítanie znova. (variant 2: Vystavenie vyžaduje kurz. Zadajte ho ručne.) |
| Error (vystavenie bez kurzu, 422 `fx_missing`) | Faktúra je v inej mene ako subjekt. Pred vystavením je potrebný kurz. Načítajte ho alebo ho zadajte ručne. |
| Error (neplatný ručný kurz pri vystavení, 422 `fx_invalid`) | Ručne zadaný kurz nie je platný. Skontrolujte kurz a dátum a skúste to znova. |
| Error (`fx_overflow`) | Súčet v {mena subjektu} je príliš vysoký na uloženie. Skontrolujte kurz alebo rozdeľte faktúru. |
| Error (neplatný kurz) | Zadajte kurz ako kladné číslo. |
| Error (príliš veľa desatinných) | Kurz môže mať najviac 8 desatinných miest. |
| Error (neplatný dátum kurzu) | Zadajte platný dátum platnosti kurzu. |
| Error (dátum kurzu v budúcnosti) | Dátum platnosti kurzu nemôže byť v budúcnosti. |
| Error (dátum kurzu príliš starý) | Dátum platnosti kurzu nemôže byť starší ako rok. |
| Hint (smer kurzu) | Zadajte kurz v rovnakom smere, aký uvádza {zdroj}: {amount} {base} = … {quote}. |
| Info (zmena dátumu) | Dátum sa zmenil, kurz bol načítaný znova. |
| Info (pôvodný kurz po prepise) | Stiahnutý kurz bol {zdroj} {kurz} z {dátum}. |
| Odznak | Ručný kurz |
| Nastavenia (nadpis stránky a odkazu) | Kurz mien |
| Nastavenia (úvod) | Určuje, odkiaľ sa berie kurz a ku ktorému dňu pri faktúre v inej mene, ako je mena subjektu. Zmena platí iba pre faktúry vystavené po uložení. |
| Nastavenia (upozornenie) | Pravidlá sú predbežné. Overte ich s účtovníčkou. |
| Nastavenia (nepodporovaná kombinácia) | Pre kombináciu, ktorú zdroj nepodporuje (napr. ECB pri subjekte vedenom v PLN), bude kurz vždy nutné zadať ručne. |
| Nastavenia (chyba, 422) | Vyberte platnú hodnotu. |
| Nastavenia (súbeh, 409) | Nastavenie kurzu medzitým niekto zmenil. Načítajte stránku znova a zopakujte úpravu. |
| Destructive confirmation | žiadna destruktívna akcia v tejto fáze („Zahodiť ručný kurz“ je vratné a iba lokálne v náhľade); vystavenie je potvrdené existujúcim sheetom Phase 5 (rozšíreným o riadky kurzu), bez dodatočného potvrdenia |

PDF texty (SK; PL/CS/EN podľa tabuľky v E): „Spolu“, „Kurz NBP 1 EUR = 4,2512 PLN“, „Kurz ECB 1 EUR = 4,3945 PLN“, „z 28. 10. 2026, tab. 209/A/NBP/2026“, „Spolu v PLN“, „Ručne zadaný kurz“, „platí ku dňu {dátum}“.

---

## Interaction and State Contract

- **Stavy bloku kurzu**: načítavam, načítaný, ručný, nedostupný (variant 1), dvojica nepodporovaná (variant 2), chybný vstup, `fx_overflow`, zastaraný náhľad. Každý má textový popis, nikdy iba farbu.
- **Sieť mimo transakcie**: kurz sa sťahuje výhradne v requeste náhľadu (D-07), nikdy pri vystavení ani pod zámkom čítača. Vystavenie číta kurz len z cache (auto) alebo z validovaného ručného vstupu; sieť nevolá.
- **Nemennosť**: vystavená faktúra zobrazuje kurz iba z vlastných stĺpcov a kópie údajov; neskoršia zmena kurzu v zdroji, cache alebo nastavení krajiny ju nemení. Žiadne tlačidlo na úpravu kurzu po vystavení.
- **Token**: zmena kurzu, zdroja alebo dátumu zmení `previewToken`; formulár vystavenia vždy odosiela token a skryté `fx_*` polia z posledného prekreslenia.
- **Ručné zadanie bez nového POST**: náhľad je už htmx (`hx-get`, push-url); „Použiť kurz“ aj „Skúsiť znova“ sú ten istý `hx-get` s `fx_*` parametrami. Toggle ručného formulára je natívny `<details>`; Alpine sa na kurze nepoužíva (ak by sa pridal, len pomenované metódy/gettery CSP buildu, nikdy inline výrazy).
- **Mobil jednou rukou**: `.fx-card` v jednom stĺpci, tlačidlá `w-100` 44px, primárne „Vystaviť faktúru“ ostáva v sticky pätke (spodná polovica), 16px písmo (iOS zoom), bez horizontálneho posúvania; zadanie ručného kurzu najviac 3 klepnutia (Zadať ručne, pole + klávesnica, Použiť kurz).
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; všetky rozmery cez triedy v `app.css`.
- **Prístupnosť**: `label for`, `aria-describedby` pri chybách, `role="alert"` pre chybové upozornenia, `aria-live="polite"` pre prekreslený kurz a súčet, focus na prvé neplatné pole, kontrast min 4.5:1.
- **Offline**: stránky ani fragmenty kurzu sa nekešujú (SW nikdy nekešuje autentifikované HTML ani `/fragments/invoice-preview`).
- **Mimo rozsahu**: prehľady a limity z `total_entity_ccy` (Phase 10/11), dobropis so zdedeným kurzom (Phase 9), kurzové rozdiely pri úhradách, UI na pridávanie mien, KSeF `KursWalutyZ`.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| UX kurzu v náhľade | `.fx-card` nad súhrnom, `Zadať ručne` ako natívny `<details>`, stav načítania cez htmx indikátor |
| Smer a text kurzu | `{base}`/`{quote}` z `RateQuote.direction`, jeden `FxFormat`, natívne desatinné miesta zo `rate_display` |
| Zobrazenie druhej sumy na detaile a v zozname | `.fx-sub` pod sumou (zoznam), podsumma pod hlavičkou (detail), kurz a zdroj v „Údaje“, z `total_entity_ccy` |
| Ručný kurz po zmene dátumu | zahodí sa a načíta sa nový (viazaný na `fx_anchor`) **[predpoklad]** |
| Dôvod ručného prepisu | nevyžaduje sa (D-11), iba odznak a záznam v `audit_log` |
| Predbežné sťahovanie (cron) | nie, iba na požiadanie v náhľade |
| UI pre meny | žiadne, `select` číta z `currencies` |
| Konfigurácia kurzu | samostatná stránka „Kurz mien“ po krajinách (`fx_country_settings`), jedno „Uložiť“, revízie a 409 |
| „Skúsiť znova“ vs negatívna cache | obchádza ju cez `fx_retry=1` |
| PDF | riadok pod súčtom podľa D-15, bez nových veľkostí písma, popisky v `LABEL_KEYS` a `InvoicePdf.php` v 4 jazykoch |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Žiadne nové balíky ani CDN. Externé sú iba serverové volania na API NBP/ECB/ČNB (nie UI závislosť).

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: FLAG (non-blocking)
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: FLAG (non-blocking)
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-10-30 (gsd-ui-checker, revision 1; 4 non-blocking FLAGs)
