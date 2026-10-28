---
phase: 6
slug: dokumenty-faktury-a-vykazu
status: approved
reviewed_at: 2026-10-27
shadcn_initialized: false
preset: none
created: 2026-10-27
---

# Phase 6 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 6 (Dokumenty faktúry a výkazu). Dedí tokeny z `02-UI-SPEC.md` až `05-UI-SPEC.md` a nemení ich. Jazyk UI je slovenčina, všetky texty UI iba cez `lang()` (nový súbor `app/Language/sk/LegalTexts.php` plus rovnaké kľúče v `en`; doplnenia do `Invoicing.php` a `Invoices.php`). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build.
> Zdroje: 06-CONTEXT.md (D-01 až D-24), 06-RESEARCH.md (stack: mPDF 8.3, bacon SVG QR, DejaVu), 05-UI-SPEC.md, CLAUDE.md, existujúce `resources/pdf/invoice-inv1.css`. Majiteľ nechce interaktívnu diskusiu, predpoklady sú označené **[predpoklad]**.
> Rozsah: (A) dva PDF doklady `inv-2` a `rep-1`, (B) obrazovka Nastavenia → Zákonné texty, (C) doplnky náhľadu faktúry, (D) tlačidlá PDF na detaile faktúry.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | UI: Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5. PDF: mPDF 8.3.1 (HTML a CSS podmnožina, bez Bootstrapu) |
| Icon library | UI: žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`). PDF: žiadne ikony ani obrázky okrem QR (vektorové SVG z `bacon/bacon-qr-code` cez `<img src="data:image/svg+xml;base64,...">`) |
| Font | UI: systémový stack z `app.css`, čísla `tabular-nums`. PDF: **DejaVu Sans** (`dejavusans`, jediný font, bez substitúcií a `autoLangToFont`; pokrýva sk, pl, cs, en) |

Reuse v UI (neprepisovať): `.app-card`, `.app-main`, `.master-row`, `.master-actions`, `.master-kv`, `.master-sticky-actions`, `.work-sheet`, `.inv-pdf-actions`, `.inv-summary`, `.inv-warn`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `HtmxCsrf`, `responseHandling` pre 422 a 409.

Nové triedy v `app.css` (prefix `.lt-*` pre zákonné texty, `.inv-*` pre doplnky náhľadu; nová verzia súboru v názve cez `asset_url`):

| Trieda | Účel |
|--------|------|
| `.lt-group` | skupina zoznamu textov podľa profilu (nadpis `h2` „Slovensko, neplatiteľ DPH“ + zoznam `.lt-row`) |
| `.lt-row` | riadok textu (derivát `.master-row`, celý riadok `<a>`, min 64px): 1. riadok kľúč (16px/600) + jazyk, 2. riadok 12px muted režim fakturácie a revízia, vpravo badge stavu; nad 768px tretí riadok skrátené znenie (1 riadok, `text-truncate`) |
| `.lt-badge` | badge stavu (Bootstrap `badge`, vždy text) |
| `.lt-effective` | informačný blok „Použije sa pri fallbacku“ (`alert alert-secondary`, 12px) |
| `.lt-body` | `textarea` znenia (16px, min 8 riadkov, `overflow-wrap:anywhere`) |
| `.inv-pay` | karta „Platba a poznámka“ v náhľade (forma úhrady `select` + poznámka `textarea`) |
| `.inv-docs` | dvojica tlačidiel „Faktúra PDF“ + „Výkaz PDF“ na detaile (derivát `.inv-pdf-actions`) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 5 (násobky 4):

| Token | Value | Usage v tejto fáze (UI) |
|-------|-------|-------------------------|
| xs | 4px | medzera popisok a hodnota, hint pod poľom |
| sm | 8px | medzi badge, medzi tlačidlami, medzi riadkami zoznamu textov |
| md | 16px | padding `.lt-row`, `.inv-pay`, odsadenie polí (`mb-3`), okraje na mobile |
| lg | 24px | medzi kartami a medzi skupinami `.lt-group` |
| xl | 32px | medzi sekciami detailu faktúry a formulára textu |
| 2xl | 48px | horné odsadenie prázdneho stavu |
| 3xl | 64px | výška spodnej lišty a rezerva obsahu |

Exceptions:
- Dotykové ciele min **44px**: všetky tlačidlá, polia, `select`, `textarea` (výška riadku aspoň 44px), riadky `.lt-row` (min 64px), checkbox „Potvrdené účtovníčkou“ (celý riadok je `label`, 44px).
- Sticky akčná lišta (`.master-sticky-actions`, 64px) na formulári úpravy textu: primárne tlačidlo **Uložiť znenie**. **[predpoklad]** Na zozname textov a na detaile faktúry sticky lišta nie je.
- **PDF (fyzické jednotky, nie CSS px):** rozmery v sekciách A1 a A2 (okraje 15/15/18 mm, QR 32 mm, rezerva 8 mm, výška riadku 7 mm) sú v mm a pt, násobky 1 mm až 3 mm sú výnimka pre tlač, nepoužívajú sa v UI.

---

## Typography

### UI (Nastavenia, náhľad, detail)

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, kľúč textu, `textarea`) | 16px | 400 (kľúč textu 600) | 1.5 |
| Label / meta (popisky, hint, 2. riadok, badge, revízia, `invalid-feedback`) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2` skupiny) | 20px | 600 | 1.2 |
| Display (Celkom v hlavičke náhľadu a detailu, existujúce, nič nové) | 28px | 600 | 1.2 |

### PDF (oba doklady, rovnaká stupnica)

DejaVu Sans, 4 veľkosti a 2 hrúbky (normal 400, bold 700). DejaVu je široký font, preto sú veľkosti menšie ako v UI:

| Role | Size | Weight | Line Height | Použitie |
|------|------|--------|-------------|----------|
| Body | 9pt | normal | 1.4 | adresy, položky, hodnoty, text poznámky |
| Label | 7pt | normal | 1.4 | popisky polí („Dodávateľ“, „IČO“), päta, zákonná poznámka, URL portálu, „Tytuł płatności“ hint |
| Heading | 12pt | bold | 1.2 | nadpis tabuľky položiek, názov strany odberateľ/dodávateľ, „Celkom“ v súčtoch |
| Display | 16pt | bold | 1.2 | nadpis dokladu („Faktúra 202610001“, „Výkaz práce“) a suma „K úhrade“ |

Pravidlá PDF: sumy a hodiny zarovnané vpravo, formátované výhradne cez `PdfFormat` (bez ICU), z hotových reťazcov snapshotu. Hlavička tabuľky bold 7pt, bez farebného výplnenia okrem `#f0f0f0`. Žiadne kurzívy, žiadne podčiarknutie okrem odkazu na portál vo výkaze.

---

## Color

### UI (dedené bez zmeny)

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, riadky, hlavička, tabbar, sticky lišta, panel |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | iba chybové stavy; v tejto fáze **žiadna destruktívna akcia** (texty sa nemažú, doklady sa nemenia) |

Accent reserved for (výlučne):
1. Primárne tlačidlo jedinej hlavnej akcie obrazovky: **Uložiť znenie** (úprava textu), **Pridať variant** (formulár nového variantu), **Faktúra PDF** (detail faktúry), **Vystaviť faktúru** (náhľad, existujúce)
2. Aktívna položka navigácie (existujúce)
3. Focus ring polí a tlačidiel
4. Textové odkazy v tele („Upraviť znenie“, „Zobraziť faktúru“)
5. Zaškrtnuté checkbox/radio (Bootstrap predvolené)

Accent sa **nepoužíva** pre badge, upozornenia, nadpisy ani hranice kariet.

Sémantické badge zákonných textov (vždy text, nikdy iba farba):

| Stav | Trieda | Text |
|------|--------|------|
| Predbežné (`provisional = true`) | `text-bg-warning` | Predbežné |
| Potvrdené účtovníčkou | `text-bg-light border` | Potvrdené |
| Povinný text, chýba (profil bez `vat_note` pre jazyk) | `text-bg-danger` | Chýba |
| Fallback (text sa použije z iného jazyka alebo režimu) | `text-bg-secondary` | Náhradný |

Upozornenie na predbežné znenie v náhľade: Bootstrap `alert alert-warning` s nevýraznou veľkosťou 12px, text začína slovom „Pozor“, nikdy len farbou, neblokuje vystavenie (D-15).

### PDF (tlač, jedna farba)

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#ffffff` | papier |
| Secondary (30%) | `#f0f0f0` | iba hlavička tabuľky položiek a riadok „K úhrade“ |
| Text | `#000000` (body, hodnoty, nadpisy), `#555555` (label 7pt, URL, päta) | |
| Rules | `#999999` 0,2 mm | deliace čiary tabuľky (zhoda s `inv-1`) |
| Accent | **žiadny** | doklad je čiernobiely, zniesie čiernobielu tlač a fax-kvalitu; QR je čierny na bielom, zákaz farebného pozadia |
| Destructive | **žiadny** | |

Odkaz na portál vo výkaze je čierny podčiarknutý text (nie modrý), aby tlač bola konzistentná. Vzorové PDF (D-23) majú diagonálny/hlavičkový štítok „VZOR“ v 12pt bold `#555555` v hlavičke strany, len v `spark` príkaze, nikdy v ostrých dokladoch (štítok je zapísaný v snapshote ako `sample = true`, ostré doklady ho nemajú).

---

## A. PDF dokumenty

Spoločné: A4 na výšku, okraje 15 mm (vľavo/vpravo), 15 mm hore, 18 mm dole; päta strany 7pt: „{Subjekt} · {číslo dokladu} · Strana {n}/{N}“ (popisok „Strana“ zo snapshotu `labels`). Šablóna číta **iba** `$s` (D-04): žiadne `lang()`, DB, čas, `<style>` ani `style=""` (CSS cez `HEADER_CSS` zo súboru `resources/pdf/invoice-inv2.css`, resp. `report-rep1.css`). Rozloženie cez tabuľky (mPDF), nie flex/grid. Dlhý text sa zalamuje, tabuľka položiek sa môže rozdeliť medzi strany s opakovanou hlavičkou (`repeat header`), riadok sa nerozdeľuje (`page-break-inside: avoid`).

### A1. Faktúra `inv-2` — poradie blokov zhora nadol

| # | Blok | Obsah a pravidlá |
|---|------|------------------|
| 1 | Hlavička | vľavo Display 16pt bold „{Nadpis} {číslo}“ (Faktúra / Faktura / Faktura / Invoice, D-08), pod ním 7pt „Variabilný symbol: {VS}“ (PL: „Tytuł płatności: {číslo}“, D-11); vpravo blok dátumov (tabuľka label 7pt + hodnota 9pt): Dátum vystavenia, Dátum dodania, Splatnosť |
| 2 | Strany | dva stĺpce 50 %: **Dodávateľ** (vľavo) a **Odberateľ** (vpravo). Každý: popisok 7pt, názov 12pt bold, adresa 9pt, potom riadky label 7pt + hodnota 9pt podľa `legal.print` (IČO, DIČ, IČ DPH/VAT ID, NIP, údaj o zápise `registry_note` len dodávateľ). Tlačia sa **iba polia z `legal.print`**; prázdne pole sa nevykreslí (bez prázdneho popisku) |
| 3 | Platobné údaje + QR | dva stĺpce: vľavo tabuľka (Forma úhrady, IBAN, SWIFT/BIC, Banka, Variabilný symbol alebo Tytuł płatności, Suma na úhradu s menou); vpravo **QR 32 × 32 mm** zarovnaný vpravo s popiskom 7pt pod ním („Platba QR kódom“ / „PAY by square“ / „QR Platba“ / „Kod QR ZBP“ podľa štandardu v `payment.qr.standard`). QR sa vykreslí **iba** pri forme `transfer` a existujúcom `payment.qr`; inak pravý stĺpec nie je (ľavý sa roztiahne na 100 %), bez prázdneho miesta a bez textu „QR nie je k dispozícii“ |
| 4 | Položky | tabuľka: stĺpce Popis (zvyšok šírky), Množstvo/Jednotka (hodiny, napr. „16 h“), Cena za jednotku, DPH (kód z riadku, napr. „zw“/„np“, len ak ho profil tlačí), Suma. Hlavička 7pt bold na `#f0f0f0`. Riadky 9pt, výška aspoň 7 mm, názov úlohy sa zalamuje (max 3 riadky, potom pokračuje) |
| 5 | Súčty | vpravo zarovnaná tabuľka: základ, DPH (len ak profil tlačí), **K úhrade / Celkom** 16pt bold na `#f0f0f0` s menou. **Rezervované miesto** pod „K úhrade“ pre Phase 8: prázdny blok výšky 8 mm (podmienečný `fx` riadok „Ekvivalent v {mena subjektu} (kurz {kurz} z {dátum}): {suma}“ 7pt); keď `fx = null`, blok sa nevykreslí a sumy sa neposúvajú (rezerva je v CSS triede, ktorú aktivuje len prítomnosť `fx`) |
| 6 | Zákonná poznámka | `legal.texts.vat_note` v 7pt, plná šírka, nad ním popisok „Poznámka k DPH“ (label 7pt bold); pri art. 113 s kódom oslobodenia v zátvorke ako súčasť znenia, ak je súčasťou textu v snapshote |
| 7 | Poznámka faktúry | voliteľná `payment.note` (max 500 znakov) v 9pt, popisok „Poznámka“; len ak je neprázdna |
| 8 | Päta | viď spoločné (číslovanie strán) |

Dodatočné pravidlá faktúry:
- Nadpis neobsahuje „daňový doklad“ ani „Faktura VAT“ (neplatiteľ).
- PL doklad: dátumy „Data wystawienia“, „Data dostawy/wykonania usługi“, „Termin płatności“; NIP predajcu aj kupujúceho s popiskom „NIP“; podklad oslobodenia je v bloku 6.
- EÚ B2B: Odberateľ má riadok „IČ DPH“/„VAT ID“ s prefixom krajiny; dodávateľ VAT-UE, ak je vyplnené (podľa `legal.print`).
- Hodnoty sa nikdy nepočítajú v šablóne (D-04); ak pole v snapshote chýba, šablóna ho nevykreslí (nepadá), test pokrýva.

### A2. Výkaz práce `rep-1` — poradie blokov

| # | Blok | Obsah a pravidlá |
|---|------|------------------|
| 1 | Hlavička | Display 16pt bold „Výkaz práce“ (Work report / Wykaz pracy / Výkaz práce), pod ním 12pt „{Obdobie: fakturačný mesiac, napr. október 2026}“ (z reťazca v snapshote) a 7pt „Faktúra {číslo}“ (odkaz na číslo faktúry) |
| 2 | Strany | rovnaké bloky Dodávateľ a Odberateľ ako na faktúre, **bez** polí mimo `legal.print` (výkaz má presne tie isté časti ako faktúra, REP-02), skrátené: názov, adresa, identifikátor |
| 3 | Tabuľka riadkov | v poradí riadkov faktúry (D-06). Stĺpce: Portál (klikateľný odkaz: `task_ref` ako text odkazu, inak skrátená URL max 40 znakov s „…“, 7pt podčiarknutý; ak bez odkazu, prázdne), Úloha (názov 9pt, zalamuje sa), Nacenené (hodiny úlohy, napr. „40 h“; prázdne, ak úloha nemá nacenené hodiny), Fakturované („16 z 40 h“; ak nacenené hodiny nie sú, iba „16 h“), Suma (s menou). Sadzba sa zobrazí v stĺpci „Sadzba“ **iba** ak sa sadzby riadkov líšia, inak v hlavičke súčtov |
| 4 | Súčty | riadok súčtu: súčet fakturovaných hodín a súčet súm (bold 9pt, `#f0f0f0`), vždy zo `totals` snapshotu, nie prepočítané. Pod ním 7pt: „Sadzba: {sadzba} {mena}/h“ (ak je jednotná) a `vat_note` zhodne s faktúrou |
| 5 | Päta | rovnaká ako faktúra |

Formát „16 z 40 h“: sk „16 z 40 h“, pl „16 z 40 godz.“, cs „16 z 40 h“, en „16 of 40 h“. Hotový reťazec vzniká pri vystavení a ukladá sa v snapshote (nie šablónou). Riadky tej istej úlohy sa nezlučujú.

### A3. Popisky dokladov (kópia do snapshotu `labels`, D-09)

Kľúče pre `InvoicePdf` a nový `ReportPdf`. Predpoklad: preklady pl a cs sú predbežné do potvrdenia, vlastník ich môže opraviť v jazykových súboroch (platia len pre budúce doklady).

| Kľúč | sk | pl | cs | en |
|------|----|----|----|----|
| document_invoice | Faktúra | Faktura | Faktura | Invoice |
| document_report | Výkaz práce | Wykaz pracy | Výkaz práce | Work report |
| supplier | Dodávateľ | Sprzedawca | Dodavatel | Supplier |
| customer | Odberateľ | Nabywca | Odběratel | Customer |
| issue_date | Dátum vystavenia | Data wystawienia | Datum vystavení | Issue date |
| supply_date | Dátum dodania | Data dostawy/wykonania usługi | Datum dodání | Delivery date |
| due_date | Splatnosť | Termin płatności | Splatnost | Due date |
| payment_method | Forma úhrady | Forma płatności | Forma úhrady | Payment method |
| method_transfer | Prevod | Przelew | Převod | Bank transfer |
| method_cash | Hotovosť | Gotówka | Hotově | Cash |
| method_card | Karta | Karta | Kartou | Card |
| method_other | Iná | Inna | Jiná | Other |
| variable_symbol | Variabilný symbol | Tytuł płatności | Variabilní symbol | Variable symbol |
| iban | IBAN | Numer konta (IBAN) | IBAN | IBAN |
| tax_id_sk (IČO) | IČO | NIP | IČO | Company ID |
| vat_note_heading | Poznámka k DPH | Informacja o VAT | Poznámka k DPH | VAT note |
| note | Poznámka | Uwagi | Poznámka | Note |
| total_due | K úhrade | Do zapłaty | K úhradě | Amount due |
| qr_caption | Platba QR kódom | Płatność kodem QR | Platba QR kódem | Pay by QR code |
| page | Strana | Strona | Strana | Page |
| portal | Portál | Portal | Portál | Portal |
| task | Úloha | Zadanie | Úkol | Task |
| estimated | Nacenené | Wycena | Naceněno | Estimated |
| billed | Fakturované | Fakturowane | Fakturováno | Invoiced |
| rate | Sadzba | Stawka | Sazba | Rate |
| hours_of | {0} z {1} h | {0} z {1} godz. | {0} z {1} h | {0} of {1} h |

---

## B. Nastavenia → Zákonné texty

Trasa `/settings/legal-texts` (zoznam), `/settings/legal-texts/{id}/edit` (úprava), `/settings/legal-texts/new` (pridanie variantu). Položka „Zákonné texty“ pribudne v `app/Views/more/index.php` v sekcii Nastavenia (odkaz s ikonou-textom, 44px riadok, popis „Znenia poznámok na faktúrach“). Za `authgate`, layout `layouts/app`. Mobile first, jeden stĺpec; od >= 768px `max-width: 960px` centrovane.

### B1. Zoznam (`/settings/legal-texts`)

1. `h1` „Zákonné texty“, 12px muted „Znenie sa kopíruje do faktúry pri vystavení. Úprava sa týka len nových faktúr.“
2. Primárne tlačidlo **„Pridať variant“** (`btn-primary`) v hlavičke, na mobile `w-100` pod nadpisom.
3. Ak existuje aspoň jeden text s `provisional = true`: `alert alert-warning` (12px) „Pozor: {n} znení ešte nepotvrdila účtovníčka. Pred ostrým použitím ich skontrolujte.“
4. Skupiny `.lt-group` podľa profilu (krajina subjektu, DPH režim): `h2` napr. „Slovensko · neplatiteľ DPH“, „Poľsko · oslobodenie podľa art. 113“. V skupine zoznam `.lt-row` zoradený: kľúč (`vat_note`, potom `invoice_note`), jazyk (sk, cs, pl, en), režim fakturácie (všeobecný, potom `eu_b2b`, `non_eu`, `domestic`).
5. Riadok `.lt-row`: 1. riadok „Poznámka k DPH“ alebo „Predvolená poznámka faktúry“ + jazyk (napr. „sk“), 2. riadok 12px muted „Režim: všeobecný · revízia 3 · upravené {dátum}“, vpravo badge stavu (Predbežné / Potvrdené / Chýba / Náhradný). Celý riadok je odkaz na úpravu. Pre skupiny s `legal_profiles` sa pod nadpisom skupiny zobrazí 12px read-only `dl.master-kv` s `exemption_code` a `vat_code` (D-16, bez ovládacích prvkov, hint „Kódy sa menia len migráciou.“).
6. Riadok „Chýba“: vygenerovaný zástupný riadok pre povinný `vat_note` bez znenia v jazykoch sk, cs, pl, en pre profil, ktorý ho vyžaduje; kliknutie vedie na `/new` s predvyplneným profilom a jazykom.
7. **Fallback** (D-13): pri riadku s režimom fakturácie alebo jazykom bez vlastného znenia zobrazí `.lt-effective` 12px „Pre jazyk {jazyk} a režim {režim} sa použije znenie: {kľúč, jazyk `en`/režim všeobecný} (revízia {n}).“ Nikdy prázdny text.
8. Mazanie sa neponúka (D-13).

### B2. Úprava textu (`/settings/legal-texts/{id}/edit`)

Karta `.app-card`, zhora:
1. `h1` „Upraviť znenie“, 12px muted „{Profil} · {kľúč} · {jazyk} · revízia {n}“ (read-only `dl.master-kv`: Kľúč, Jazyk, Režim fakturácie, Revízia, Naposledy upravené).
2. `.lt-body` `textarea` „Znenie“ (povinné, `maxlength` 2000, `rows` 8, 16px), `label for`, hint 12px „Používa sa na faktúre ako vyriešený text. Nepoužívajte vymyslené odkazy na zákony, ak ich neviete overiť.“
3. Checkbox „Potvrdené účtovníčkou“ (celý riadok `label`, 44px). Nezaškrtnuté = `provisional = true` (badge Predbežné). Hint: „Zaškrtnite až po potvrdení znenia účtovníčkou.“
4. 12px muted „Táto zmena sa neprejaví na už vystavených faktúrach.“
5. Sticky lišta `.master-sticky-actions`: **„Uložiť znenie“** (`btn-primary`) vpravo, „Späť na zoznam“ (`btn-outline-secondary`) vľavo. Počas odoslania `disabled` + spinner (`.htmx-indicator`, text ostáva).
6. Odoslanie: htmx POST + CSRF s fallbackom na klasický POST + PRG. Úspech: `HX-Location` na zoznam s flashom „Znenie bolo uložené (revízia {n}).“ Chyby 422 swapnú fragment s `is-invalid` + `invalid-feedback` + `aria-describedby`. Súbeh (zmena revízie medzitým): 409 alert „Znenie medzitým niekto upravil. Načítajte stránku znova a zopakujte úpravu.“ (optimistická kontrola `revision`).

### B3. Pridať variant (`/settings/legal-texts/new`)

Polia (všetky `select`/`textarea`, 44px): Profil (krajina a DPH režim, `select` z existujúcich `legal_profiles`), Kľúč (`select`: Poznámka k DPH, Predvolená poznámka faktúry), Jazyk (sk, cs, pl, en), Režim fakturácie (`select`: Všeobecný (bez režimu), Domáci, EÚ B2B, Mimo EÚ), Znenie (`textarea`), Potvrdené účtovníčkou (checkbox, predvolene nezaškrtnuté). Primárne tlačidlo **„Pridať variant“** v sticky lište. Duplicitná kombinácia (profil + kľúč + jazyk + režim): 422 „Pre túto kombináciu už znenie existuje. Upravte ho v zozname.“

### B4. Prístupnosť a bezpečnosť

`label for` pri každom poli, `fieldset/legend` pre skupiny, flash `role="alert" aria-live="polite"`, focus na prvé neplatné pole, kontrast min 4.5:1 (badge `text-bg-warning` má tmavý text), farba nikdy sama nenesie stav. Všetky zápisy POST + CSRF, validácia na serveri (dĺžka, povolená kombinácia), výstup escapovaný (`esc()`), žiadny HTML v znení (renderuje sa ako čistý text s `nl2br` v PDF).

---

## C. Doplnky náhľadu faktúry (`/invoices/new`, fragment `#inv-preview`)

Nová karta `.inv-pay` (`.app-card`, nadpis `h2` 20px „Platba a poznámka“) medzi blokom Dátumy a Položky (poradie: Súhrn, Dátumy, Varovanie spätného dátumu, **Platba a poznámka**, upozornenie na predbežný text, Položky):

| Prvok | Špecifikácia |
|-------|--------------|
| Forma úhrady | `select` s popiskom „Forma úhrady“: Prevod (predvolené), Hotovosť, Karta, Iná. 44px. Hint 12px pod poľom pri „Prevod“: „Na faktúre bude QR kód na platbu, ak je pre klienta a menu k dispozícii.“ Pri iných formách hint „QR kód sa netlačí.“ (Alpine CSP: len prepínanie viditeľnosti hintu, server rozhoduje nezávisle) |
| Poznámka | `textarea` „Poznámka na faktúre (nepovinné)“, 3 riadky, `maxlength` 500, počítadlo 12px „{n} / 500“ (Alpine CSP), predvyplnená z `invoice_note` v jazyku dokladu, ak existuje (hint „Predvyplnené zo zákonných textov. Pre túto faktúru ju môžete upraviť alebo vymazať.“ s odkazom „Upraviť predvolené znenie“ na `/settings/legal-texts`). Prázdne pole = bez poznámky |
| Upozornenie na predbežný text (D-15) | `alert alert-warning` 12px, len ak niektorý použitý text (`vat_note` alebo predvyplnená poznámka) má `provisional = true`: „Pozor: znenie ešte nepotvrdila účtovníčka. Faktúra sa dá vystaviť, ale znenie sa po vystavení nezmení.“ + odkaz „Zobraziť znenie“. Neblokuje tlačidlo Vystaviť, nemá checkbox |

Interakcia: zmena formy úhrady alebo úprava poznámky **neobnovuje** celý náhľad (nemení súčet); hodnoty sa odošlú s POST vystavenia (`payment_method`, `payment_note`). Pri zmene klienta, mesiaca alebo dátumu sa fragment obnoví a poznámka sa predvyplní znova len ak ju používateľ nezmenil (Alpine `dirty` príznak). Potvrdzovací panel (`_confirm_sheet.php`) doplní riadky „Forma úhrady“ a „Poznámka“ (skrátená na 1 riadok, plná v `title`). Server validuje `payment_method` (whitelist) a `payment_note` (max 500 znakov), chyba 422 `is-invalid`. Číslo, VS a QR sa v náhľade **nezobrazujú** (vznikajú pri vystavení).

Chyby pri vystavení (nové, `alert-danger` nad náhľadom): „QR kód pre platbu sa nepodarilo vytvoriť. Faktúra sa nevystavila a číslo ostáva voľné. Skontrolujte IBAN subjektu a skúste to znova.“ a „PDF výkazu sa nepodarilo vytvoriť. Nič sa nezapísalo a číslo ostáva voľné. Skúste to znova.“

---

## D. Detail faktúry — dokumenty

V karte Hlavička `.inv-docs` (v `.master-actions`, na mobile v stĺpci `w-100`, 44px):

| Tlačidlo | Trieda | Správanie |
|----------|--------|-----------|
| **Faktúra PDF** | `btn-primary` | odkaz `invoices/{id}/pdf`, `target="_blank" rel="noopener noreferrer"`, `Content-Disposition: inline`; názov súboru podľa jazyka dokladu (`faktura-{číslo}.pdf`, `faktura-` pl, `invoice-` en) |
| **Výkaz PDF** | `btn-outline-secondary` | odkaz `invoices/{id}/report`, rovnaké atribúty, názov `vykaz-{číslo}.pdf` (`wykaz-`, `report-`) |

Pravidlá:
- Dve tlačidlá majú rovnakú váhu textu, no iba **Faktúra PDF** je accent (jedna primárna akcia na obrazovku). Tlačidlo „Stiahnuť PDF“ z Phase 5 sa nahrádza dvojicou; stiahnutie rieši prehliadač z PDF vieweru. **[predpoklad]** Ak vlastník bude chcieť samostatné sťahovanie, pridá sa v Phase 11 (ZIP balík).
- Faktúra schema 1 (bez výkazu): tlačidlo „Výkaz PDF“ sa nevykreslí, namiesto neho 12px muted „Pre túto faktúru výkaz nebol vytvorený. Vystavená skôr, než sa výkazy zaviedli.“ (žiadne neaktívne tlačidlo).
- Pod tlačidlami 12px muted „Uložené PDF, kontrolný súčet SHA-256: {16 znakov}…“ pre každý dokument (dva riadky „Faktúra“ a „Výkaz“, plný hash v `title`).
- Údaje (`dl.master-kv`) doplnia: Verzia šablóny faktúry (`inv-2`), Verzia šablóny výkazu (`rep-1`), Forma úhrady (z `payment.method`), Poznámka (ak je), Štandard QR (SK PAY by square / CZ QR Platba / PL QR ZBP / bez QR).
- Chyba načítania PDF: existujúci text „PDF faktúry sa nepodarilo načítať...“ a nový „PDF výkazu sa nepodarilo načítať. Skúste to znova; ak chyba pretrvá, obráťte sa na správcu.“ Chyba nikdy nespustí regeneráciu.
- Hlavičky odpovede: `application/pdf`, `Cache-Control: private, no-store`, bez zámku relácie počas čítania (Pasca 11).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, vecný tón. Texty iba cez `lang()`.

| Element | Copy |
|---------|------|
| Primary CTA (Zákonné texty, úprava) | Uložiť znenie |
| Primary CTA (Zákonné texty, zoznam, nový variant) | Pridať variant |
| Primary CTA (detail faktúry) | Faktúra PDF |
| Sekundárne | Výkaz PDF, Späť na zoznam, Upraviť znenie, Upraviť predvolené znenie, Zobraziť znenie |
| Empty state heading (zoznam textov) | Zatiaľ žiadne zákonné texty |
| Empty state body (zoznam textov) | Texty sa vytvárajú pri nasadení. Ak chýbajú, pridajte variant tlačidlom „Pridať variant“. |
| Empty state heading (skupina bez poznámky faktúry) | Bez predvolenej poznámky |
| Empty state body (skupina bez poznámky faktúry) | Predvolená poznámka faktúry (napr. o úrokoch z omeškania) nie je nastavená. Faktúra bude bez poznámky, kým ju nepridáte. |
| Error (chýba povinný text) | Chýba znenie poznámky k DPH pre jazyk {jazyk}. Pridajte ho v Nastavenia, Zákonné texty a vystavte znova. |
| Error (prázdne znenie, 422) | Zadajte znenie textu. Najviac 2000 znakov. |
| Error (duplicita, 422) | Pre túto kombináciu už znenie existuje. Upravte ho v zozname. |
| Error (súbeh, 409) | Znenie medzitým niekto upravil. Načítajte stránku znova a zopakujte úpravu. |
| Error (poznámka faktúry, 422) | Poznámka môže mať najviac 500 znakov. |
| Error (forma úhrady, 422) | Vyberte platnú formu úhrady. |
| Error (QR zlyhal) | QR kód pre platbu sa nepodarilo vytvoriť. Faktúra sa nevystavila a číslo ostáva voľné. Skontrolujte IBAN subjektu a skúste to znova. |
| Error (výkaz zlyhal) | PDF výkazu sa nepodarilo vytvoriť. Nič sa nezapísalo a číslo ostáva voľné. Skúste to znova. |
| Error (výkaz sa nenašiel) | PDF výkazu sa nepodarilo načítať. Skúste to znova; ak chyba pretrvá, obráťte sa na správcu. |
| Warning (predbežné, zoznam) | Pozor: {n} znení ešte nepotvrdila účtovníčka. Pred ostrým použitím ich skontrolujte. |
| Warning (predbežné, náhľad) | Pozor: znenie ešte nepotvrdila účtovníčka. Faktúra sa dá vystaviť, ale znenie sa po vystavení nezmení. |
| Success flash | Znenie bolo uložené (revízia {n}). · Variant bol pridaný. |
| Info (výkaz chýba, schema 1) | Pre túto faktúru výkaz nebol vytvorený. Vystavená skôr, než sa výkazy zaviedli. |
| Hint (nemennosť znenia) | Táto zmena sa neprejaví na už vystavených faktúrach. |
| Destructive confirmation | **žiadna destruktívna akcia v tejto fáze** (texty sa nemažú, doklady sa nemenia). Úprava znenia nie je destruktívna: história je v audite, vystavené doklady majú vlastnú kópiu, preto bez potvrdenia |

Popisky UI (`LegalTexts.php`): Zákonné texty, Poznámka k DPH, Predvolená poznámka faktúry, Znenie, Jazyk, Režim fakturácie, Všeobecný, Domáci, EÚ B2B, Mimo EÚ, Revízia, Naposledy upravené, Predbežné, Potvrdené, Chýba, Náhradný, Potvrdené účtovníčkou, Profil, Kľúč, Platba a poznámka, Forma úhrady (Prevod, Hotovosť, Karta, Iná), Poznámka na faktúre, Štandard QR.

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid, disabled, loading (`disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva). Všetko funguje aj ako klasický POST + PRG bez JS; Alpine CSP len pre počítadlo znakov, dirty príznak poznámky a prepínanie hintu.
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; htmx `allowEval=false`; PDF sa generuje na serveri (CSP sa ho netýka), SVG QR je v PDF, nie na stránke.
- **Mobil jednou rukou**: primárna akcia v sticky lište v spodnej polovici obrazovky, všetky ciele 44px, `textarea` na plnú šírku, 16px písmo (iOS zoom).
- **iOS PDF**: tlačidlá otvárajú PDF v novej karte (`target="_blank"`), nie v standalone okne PWA, nikdy `download` atribút ako jediná cesta.
- **Nemennosť**: UI ukazuje, že znenie platí pre nové doklady; vystavená faktúra a jej dva PDF doklady nemajú ovládací prvok na úpravu, regeneráciu ani zmazanie. Server a DB sú nezávislé línie (D-14, D-20).
- **Konzistencia súm**: Celkom v náhľade, na faktúre, vo výkaze a v snapshote je tá istá hodnota z `InvoiceCalculator`; výkaz číta `totals` zo snapshotu.
- **Audit**: úprava textu zapíše audit (pred a po) v tej istej transakcii; v UI sa denník nezobrazuje (AUD-02 je Phase 11).
- **Offline**: stránky ani PDF sa nekešujú (SW nikdy nekešuje autentifikované HTML ani PDF).
- **Mimo rozsahu**: e-mail a prílohy (Phase 7), kurz a druhá suma (Phase 8, v PDF je len rezerva), dobropis (Phase 9), ZIP pre účtovníčku (Phase 11), úprava `legal_profiles` v UI, logo a návrhár šablón, podpisový záznam.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Vzhľad `inv-2` a `rep-1` | Čiernobiely, A4, DejaVu 9pt, rozloženie cez tabuľky, bloky v poradí z A1 a A2 |
| Miesto pre QR | Vpravo vedľa platobných údajov, 32 x 32 mm, popisok 7pt; bez QR sa blok nevykreslí |
| Miesto pre druhú sumu (Phase 8) | Rezerva 8 mm pod „K úhrade“, aktivuje len prítomnosť `fx` |
| Odkaz na portál vo výkaze | `task_ref` ako text odkazu, inak skrátená URL (40 znakov), podčiarknutý čierny 7pt |
| Zlúčenie riadkov tej istej úlohy | Nie (predvolené D-06) |
| Formát „16 z 40 h“ | sk „16 z 40 h“, pl „16 z 40 godz.“, cs „16 z 40 h“, en „16 of 40 h“ |
| Forma úhrady v UI | `select` s 4 hodnotami, predvolene Prevod |
| Poznámka faktúry | `textarea` 500 znakov, predvyplnená z `invoice_note` |
| Rozloženie Zákonných textov | Skupiny podľa profilu, riadky s badge, formulár so sticky lištou; bez mazania |
| Tlačidlá PDF na detaile | „Faktúra PDF“ (primárne) + „Výkaz PDF“ (sekundárne), `target="_blank"` |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Žiadne nové balíky ani CDN (QR cez už nainštalovaný `bacon/bacon-qr-code`).

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
