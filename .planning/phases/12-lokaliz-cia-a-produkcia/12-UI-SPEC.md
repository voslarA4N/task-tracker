---
phase: 12
slug: lokalizacia-a-produkcia
status: approved
reviewed_at: 2026-11-04
shadcn_initialized: false
preset: none
created: 2026-11-04
---

# Phase 12 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 12 (Lokalizácia a produkcia: UI-02, NFR-01, NFR-05, NFR-09). Dedí tokeny z Phase 2 až 11 bez zmeny (spacing násobky 4, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk tohto dokumentu je slovenčina. Vlastník nechce interaktívnu diskusiu, rozhodnutia sú odvodené z 12-CONTEXT.md (D-01 až D-15), 02-UI-SPEC.md, 11-UI-SPEC.md a CLAUDE.md; kroky mimo CONTEXT sú označené **[odvodené]**.
> Kontrolované v kóde: `app/Config/App.php` (`supportedLocales = ['sk','en']`), `app/Filters/LocaleNegotiation.php`, `app/Controllers/Settings/Security.php`, `app/Views/settings/security.php` + `_password.php`, `app/Libraries/Platform/MoneyFormat.php`, `app/Language/*`.

## Zistenie, ktoré mení rozsah D-01

CONTEXT D-01 uvádza, že „výber jazyka už existuje“. V kóde existuje **iba stĺpec `user_security.locale`, jeho čítanie vo filtri `LocaleNegotiation` a privátny `userLocale()` v kontroleroch**. **Neexistuje žiadna obrazovka ani trasa na zmenu jazyka** (`settings/security` obsahuje iba heslo, 2FA, zariadenia, udalosti; `Routes/settings.php` nemá locale trasu). Fáza preto musí **pridať** kartu „Jazyk rozhrania“ a jeden POST endpoint, nie iba rozšíriť zoznam hodnôt. Plán to musí zahrnúť (nová trasa, validácia whitelistom `supportedLocales`, zápis do `user_security.locale`, test).

---

## Rozsah UI

| Oblasť | Má obrazovku? | Kontrakt |
|--------|---------------|----------|
| A. Výber jazyka (Nastavenia → Zabezpečenie) | áno (nová karta) | sekcia A |
| B. Preklady PL a CS (+ úplná parita EN) | áno (všetky existujúce obrazovky, bez nových) | sekcia B |
| C. Formáty čísel, dátumov, mien | áno (existujúce obrazovky) | sekcia C |
| D. Dĺžka textov a zalamovanie na iPhone | áno | sekcia D |
| E. Zálohy (restic), archív, nemazateľnosť dokladov, výkon, CLI reset 2FA | **nie, iba CLI/ops** | sekcia E (iba texty skriptov/e-mailu) |

Nové obrazovky, farby, veľkosti písma ani komponenty okrem karty A nevznikajú.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (PHP + Bootstrap, CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine CSP (v tejto fáze nepoužité) |
| Icon library | žiadna (inline SVG `aria-hidden` iba ak už existuje); stav nikdy iba ikonou |
| Font | systémový stack z `app.css` (pokrýva latin-ext: ą ę ł ś ż ć ń ó, ě š č ř ž ý á í é ú ů ď ť ň) |

Reuse (neprepisovať): `.work-wide`, `.app-card`, `.security-card`, `h2.h5`, `.btn-touch`, `.form-check`, `data-submit-once`, `fragments/alert.php`, flash `app_notice` (vzor v `revokeDevice`), `.btn/.form-control/.form-select { min-height: 44px }`. Nová CSS trieda: **žiadna povinná**; ak plán potrebuje 44px riadok voľby, použije `.form-check` + Bootstrap `py-2`/`list-group-item` (bez nových farieb, bez inline `style=""`). Ak pribudne trieda, prefix `.lang-*` v `app.css` a nová verzia súboru v názve cez `asset_url`.

---

## Spacing Scale

Rovnaká stupnica (násobky 4): xs 4 · sm 8 · md 16 · lg 24 · xl 32 · 2xl 48 · 3xl 64.

Použitie v tejto fáze: medzi kartami sekcií 24px (existujúce `.security-card`), vnútorný padding karty 16px, medzi voľbami jazyka 8px, medzi poľom a tlačidlom 16px.

Exceptions: dotykový cieľ každej voľby jazyka a tlačidla **min 44px** (celý riadok `label` je klikateľný). Žiadne iné výnimky.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 až 11, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (voľby jazyka, tlačidlá, texty) | 16px | 400 | 1.5 |
| Label / meta (hint pod voľbou, `form-text`, tab bar existujúcich 11px výnimka) | 12px | 400 | 1.5 |
| Heading (`h2.h5` karty „Jazyk rozhrania“ ostáva existujúca veľkosť sekcie, `h1`) | 20px | 600 | 1.2 |
| Display | n/a (nepoužité) | 600 | 1.2 |

Pravidlá pre dlhšie PL/CS texty **[odvodené]**: veľkosti sa nikdy nezmenšujú kvôli dĺžke prekladu (žiadne 14px/13px ako náhrada); riešením je zalomenie (sekcia D). Tab bar ostáva na existujúcich 11px (už prijatá výnimka z Phase 2), preklady jeho štyroch štítkov musia byť jedno slovo.

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card` s voľbou jazyka |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | iba chybové hlásenie (neplatný jazyk); žiadna deštruktívna akcia |

Accent reserved for (nové položky tejto fázy):
1. Primárne tlačidlo **Uložiť jazyk** (jedno primárne tlačidlo karty)
2. Zaškrtnuté radio aktuálne zvoleného jazyka a focus ring
3. (existujúce z predchádzajúcich fáz bez zmeny)

Zvolený jazyk sa nikdy nesignalizuje iba farbou: radio je zaškrtnuté a aktuálny jazyk má 12px text „Aktuálny“ (preložený v jazyku, v ktorom je zobrazený).

---

## A. Výber jazyka (UI-02, D-01)

**Umiestnenie:** `/settings/security`, nová karta `section.app-card.security-card` vložená **ako prvá** (pred heslo): jazyk je najčastejšia a najlacnejšia zmena a zmena sa hneď prejaví. Partial `app/Views/settings/_language.php`, include v `settings/security.php`. Prístup: každý prihlásený používateľ (admin aj accountant). Menu „Viac“ sa nemení (vstup ostáva „Zabezpečenie“).

**Vizuálne ťažisko:** karta „Jazyk rozhrania“ s primárnym tlačidlom „Uložiť jazyk“ je hlavný kotviaci bod stránky `/settings/security` (jediné primárne CTA nad zlomom).

**Štruktúra (zhora nadol):**

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | `h2.h5#sec-language` | „Jazyk rozhrania“ (preložené) |
| 2 | Úvod 12px muted | „Jazyk menu, formulárov, dátumov a súm v aplikácii. Jazyk faktúr, výkazov a e-mailov klientom sa riadi klientom a subjektom a touto voľbou sa nemení.“ |
| 3 | Skupina 4 radio (`fieldset` + `legend.visually-hidden`) | poradie: **Slovenčina · Polski · Čeština · English** (`name="locale"`, hodnoty `sk`, `pl`, `cs`, `en`); každá voľba `.form-check` s `label` min 44px, vedľa 12px muted „Aktuálny“ pri uloženej hodnote |
| 4 | Tlačidlo | primárne `btn btn-primary btn-touch` **Uložiť jazyk** (`data-submit-once`, spinner ako vo `_password.php`); na mobile `w-100`, od 992px `w-auto` |

- **Názvy jazykov sú endonymá** a vždy rovnaké bez ohľadu na aktuálny jazyk UI („Čeština“ ostane „Čeština“ aj v EN), aby sa používateľ vedel vrátiť z jazyka, ktorému nerozumie. V UI sa nezobrazuje „CZ“ ani „CS“. Uložené cez `lang()` kľúče `Account.languageSk|Pl|Cs|En` s **identickou hodnotou vo všetkých štyroch jazykoch** (test parity to dovolí; `ViewsHardcodedTextTest` zostáva spokojný).
- Predvolená voľba = hodnota `user_security.locale` (nie `Accept-Language`).
- **Odoslanie:** `POST /settings/security/locale` (CSRF), serverová validácia `in_array($v, config('App')->supportedLocales, true)`, zápis do `user_security.locale`, `AuditLog` záznam **[odvodené]** (zmena nastavenia účtu, bez citlivých údajov), PRG redirect na `/settings/security`. Flash `app_notice` (typ `success`) sa zobrazí **už v novom jazyku**: „Jazyk rozhrania bol zmenený.“ / „Język interfejsu został zmieniony.“ / „Jazyk rozhraní byl změněn.“ / „Interface language changed.“ Bez htmx (jednoduchý full reload je správny: mení sa celá stránka vrátane `lang` atribútu).
- `<html lang>` sa riadi aktívnym locale (už sa deje v `layouts/auth.php`; plán overí aj `layouts/app.php`) a `<meta>` titulky/PWA názov ostávajú podľa `Pwa.appName`.
- **Stavy:**
  - Neplatná hodnota (manipulovaný POST): `alert alert-danger role="alert"` „Zvolený jazyk nie je podporovaný.“ (HTTP 422), formulár ostane s pôvodnou voľbou.
  - Bez zmeny (odoslané rovnaké): rovnaký flash ako pri úspechu, bez zápisu do audit logu.
  - Chyba zápisu (500): existujúca chybová stránka.
  - Žiadny prázdny stav (vždy sú 4 voľby).
- **Pred prihlásením** sa jazyk vyberá iba cez `Accept-Language` (D-01, D-23 z Phase 2); na prihlasovacej obrazovke sa **neprikladá prepínač jazyka** (mimo rozsahu). Neznámy jazyk prehliadača → slovenčina.
- Prístupnosť: `fieldset`/`legend`, `label for`, `aria-describedby` na úvode, focus po chybe na `fieldset`, `aria-live` iba cez flash `role="status"`.

---

## B. Preklady PL, CS a parita EN (UI-02, D-02, D-03)

Nový obsah: `app/Language/pl/*.php` a `app/Language/cs/*.php` s **rovnakými 32 súbormi a rovnakými kľúčmi ako `sk`** (`InvoicePdf.php` a `ReportPdf.php` už existujú a nemenia sa; doklady sa riadia jazykom klienta/subjektu, nie jazykom UI).

**Kontrakt prekladu (platí pre PL aj CS aj kontrolu EN):**

| Pravidlo | Detail |
|----------|--------|
| Parita kľúčov | každý kľúč zo `sk` existuje v `pl`, `cs`, `en` a naopak, rovnaké zástupné symboly `{0}`, `{1}` v rovnakom počte (test D-03 rozšíriť o porovnanie počtu a čísel zástupných symbolov, nielen existenciu kľúča) |
| Tón | neutrálny, vecný, bez oslovenia, rovnako ako SK (PL: neosobné vety alebo infinitív, nie „Pan/Pani“; CS: vykanie sa nepoužíva, neosobné vety) |
| Terminológia (jednotná vo všetkých súboroch) | Úloha → PL **Zadanie**, CS **Úkol**; Faktúra → PL **Faktura**, CS **Faktura**; Dobropis → PL **Faktura korygująca** (skrátene „Korekta“ iba ak sa nevojde), CS **Dobropis**; Výkaz práce → PL **Zestawienie pracy**, CS **Výkaz práce**; Subjekt → PL **Podmiot**, CS **Subjekt**; Klient → PL **Klient**, CS **Klient**; Splatnosť → PL **Termin płatności**, CS **Splatnost**; Dátum dodania → PL **Data dostawy/wykonania usługi**, CS **Datum plnění**; Zabezpečenie → PL **Zabezpieczenia**, CS **Zabezpečení**; Viac → PL **Więcej**, CS **Více**; Denník zmien → PL **Dziennik zmian**, CS **Protokol změn** |
| Tab bar (jedno slovo, 11px, ~94px na stĺpec) | SK Prehľad/Úlohy/Faktúry/Viac · PL **Przegląd/Zadania/Faktury/Więcej** · CS **Přehled/Úkoly/Faktury/Více** · EN Overview/Tasks/Invoices/More |
| Zástupné symboly, HTML a entity | preklady neobsahujú HTML ani nové zástupné symboly; neprekladajú sa názvy súborov na stiahnutie (`prehlad-…`, `balik-…`), tie ostávajú podľa Phase 11 |
| Chybové hlášky validácie | `Validation.php` preložené v celom rozsahu (CI4 predvolené hlášky pre `pl`/`cs` sa nepoužijú ako náhrada) |
| Diakritika | UTF-8, žiadne ASCII zjednodušenie; PL/CS riadky s dlhším prekladom sa **nikdy neskracujú orezaním reťazca**, skracuje sa preformulovaním |
| Kontrola vlastníkom | prvé PL/CS preklady pripraví Claude a označí v `12-VERIFICATION`/UAT ako „na prezretie vlastníkom“; rozhodnutia vlastníka majú prednosť |

**Test (D-03):** `tests/unit/I18n/LangKeysTest.php` rozšíriť o (1) paritu štyroch jazykov v oboch smeroch, (2) zhodu počtu a indexov `{n}`, (3) prázdny reťazec = chyba, (4) hodnota rovná SK textu v PL/CS je povolená iba na zoznam výnimiek (endonymá jazykov, názvy značiek, skratky ako „PDF“, „CSV“, „XLSX“, „2FA“, „IČ DPH“ ak sa nemení).

---

## C. Formátovanie čísel, dátumov a mien (D-04)

**Kontrakt zobrazenia (jazyk UI → formát, cez intl, nikdy ručne):**

| Hodnota | SK | PL | CS | EN |
|---------|----|----|----|----|
| Suma EUR | `1 080,00 €` | `1 080,00 €` | `1 080,00 €` | `€1,080.00` |
| Suma PLN | `1 080,00 zł` | `1 080,00 zł` | `1 080,00 PLN` | `PLN 1,080.00` |
| Suma CZK | `1 080,00 CZK` | `1 080,00 CZK` | `1 080,00 Kč` | `CZK 1,080.00` |
| Percento | `85,5 %` | `85,5%` | `85,5 %` | `85.5%` |
| Dátum (MEDIUM) | `4. 11. 2026` | `4 lis 2026` | `4. 11. 2026` | `Nov 4, 2026` |

Poznámky: presný text závisí od ICU verzie v PHP obraze; **zdrojom pravdy je `NumberFormatter`/`IntlDateFormatter`, tabuľka je očakávanie pre testy a kontrolu.** Medzera pred symbolom je nezalomiteľná (`U+00A0`, existujúce `MoneyFormat`), tisícový oddeľovač sa musí **taktiež** pri zalomení riadku neoddeliť od čísla (v ICU je to `U+00A0` alebo `U+202F`, nenahrádzať bežnou medzerou). Mena sumy je vždy mena dokladu/subjektu, nie mena UI; mení sa len *tvar* zápisu.

**Audit tvrdo zapísaných formátov (úloha plánu):**

| Miesto | Stav | Rozhodnutie |
|--------|------|-------------|
| `MoneyFormat::format` a `symbol` | už locale-aware | ponechať, doplniť test pre `sk`, `pl`, `cs`, `en` (EUR, PLN, CZK, záporná suma, 0,00) |
| `LimitMath::formatPercent` | locale-aware | doplniť test pre 4 jazyky |
| `invoices/_payments.php`, `_history.php`, `tasks/_list.php`, `home/_overdue.php` | `IntlDateFormatter($locale, …)` | ponechať; **časová zóna `Europe/Bratislava` natvrdo je prijateľná** (jediný vlastník, jedna zóna), `home/_overdue.php` používa `UTC` pre dátum bez času, čo je správne |
| `BillingMonth`, `SecurityNotifier` (`IntlDateFormatter`) | locale-aware | ponechať, overiť názov mesiaca v PL/CS (`listopad`/`lis`, genitív v „október 2026“: použiť samostatný tvar `LLLL y`, nie `MMMM`, kvôli PL/CS skloňovaniu) |
| `PdfFormat`, `FxFormat`, `TemplateRenderer` | stabilné reťazce pre **doklady** (jazyk dokladu), nie UI | **mimo rozsahu** fázy, nemeniť (nemennosť dokladov) |
| `layouts/*`, `errors/html/error_exception.php` (`date('H:i:s')`) | vývojová chybová stránka | nemeniť |
| `invoicing/_fx_card.php` `->format('Y-m-d')` | hodnota `min` pre `input type="date"` (ISO podľa HTML špecifikácie) | **ponechať**, nie je to zobrazovaný formát |
| Hodnoty `input type="date"/"month"` | prehliadač zobrazuje podľa systému zariadenia | ponechať, jazyk UI ich neovplyvňuje |

Plán prejde `grep` na `number_format(`, `->format('d`, `date('`, `sprintf('%.2f`, `,` v ručne skladaných sumách vo `app/Views` a `app/Controllers` a **odstráni každý nález používaný pre UI** (tvrdé „€“, „Kč“, „zł“, ručné nahrádzanie `.` → `,`). Nález sa opraví cez `MoneyFormat`/`IntlDateFormatter`, nie cez nové helpery. Vstupné polia peňazí ostávajú `inputmode="decimal"` a prijímajú čiarku aj bodku (existujúce správanie, nemeniť).

---

## D. Dĺžka textov a zalamovanie na iPhone (UI: bez horizontálneho posúvania)

PL a CS preklady bývajú o 10 až 30 % dlhšie než SK. Kontrakt, ktorý musí platiť na šírke **375px** (iPhone SE/13 mini) aj 390px:

1. **Žiadne horizontálne posúvanie** v žiadnej obrazovke v žiadnom zo 4 jazykov. Dlhé reťazce sa zalamujú (`overflow-wrap: anywhere` už existuje v `.rpt-card`, `.aud-diff`; plán ho rozšíri len na prvky, kde test nájde pretekanie).
2. **Tlačidlá**: text sa zalamuje na 2 riadky (`white-space: normal`), výška rastie nad 44px, nikdy sa neorezáva ani neskracuje „…“. Nikdy nenastavovať `text-nowrap` na tlačidlá s textom z `lang()`.
3. **Tab bar** (4 stĺpce, 11px): štítky sú jedno slovo (viď B); overené na 375px bez zalomenia a bez orezania.
4. **Odznaky a stavy** (`st-badge`, „Po splatnosti“, „Externá“, „Dobropis“): zalomenie povolené, stav vždy textom; najdlhšie PL/CS frázy sa preformulujú (napr. PL „Po terminie“ namiesto „Po terminie płatności“).
5. **Hlavičky stĺpcov na PC** (`.dt-table`, od 992px): zalomenie v `th` povolené, hlavička 12px 400; `.dt-sort` si zachová min-height 44px.
6. **Nadpisy 20px**: zalomenie na 2 riadky povolené (`line-height` 1.2).
7. **Formuláre**: `label` 12px sa zalamuje, nikdy nie `text-truncate`; `invalid-feedback` a `form-text` bez obmedzenia šírky.
8. **PC šírka** nemá nové obmedzenia: `.work-wide` 960px, `.work-table` 1200px.

**Overenie (úloha plánu):** automatický test dĺžky: pre každý kľúč, ktorého `pl`/`cs` hodnota je dlhšia než 140 % SK hodnoty, vypíše varovanie (nie chybu); ručné UAT na iPhone alebo v devtools 375px pre kľúčové obrazovky v PL a CS: prihlásenie + 2FA, Prehľad, Úlohy, detail úlohy, Fakturácia mesiaca, detail faktúry, Prehľady, Limity, Zabezpečenie. Zistené pretekanie sa rieši skrátením prekladu alebo zalamovaním, **nie** zmenšením písma.

---

## E. Zálohy, archív, nemazateľnosť, výkon, CLI (iba ops, bez obrazoviek)

Zálohy (D-05 až D-09, D-15), ročný archív (D-10), nemazateľnosť dokladov (D-11), výkonový hardening (D-12, D-13) a CLI reset 2FA (D-14) **nemajú žiadnu obrazovku, formulár ani trasu v aplikácii**. Nepridáva sa nič do menu „Viac“, žiadna stránka stavu záloh, žiadny tlačidlo „Zálohovať“. Overenie týchto častí je CLI/test/dokumentácia (`docs/ops/*`), nie UI-auditu.

Jediný text viditeľný človekom sú **správy skriptov**, ktoré sa riadia týmito pravidlami **[odvodené]**:

| Element | Kontrakt |
|---------|----------|
| Jazyk | **slovenčina** (vlastník je prevádzkovateľ), nie `lang()`; texty v skriptoch sú ops texty, nie UI, preto ich netestuje `ViewsHardcodedTextTest`. Výnimka: výstup `spark` príkazu archívu a CLI resetu 2FA je výstup v UTF-8 so slovenskou diakritikou |
| E-mail pri zlyhanej zálohe (D-09) | Predmet: `[work-tracker] Záloha zlyhala: {prostredie} {YYYY-MM-DD}`. Telo (čistý text, bez HTML, max ~15 riadkov): 1. riadok „Záloha databázy sa nepodarila.“ · krok, ktorý zlyhal (dump / overenie / restic / forget) · čas a prostredie · posledných 20 riadkov chybového výstupu · riadok „Postup obnovy a kontroly: docs/ops/backup.md“. **Nikdy** heslo repozitára, URL healthchecks.io ani obsah `.env` v tele alebo predmete |
| Ping healthchecks.io | bez tela alebo iba „OK {prostredie}“; URL je tajomstvo v `.env` servera |
| Výstup `spark archive:year` (D-10) | riadky: „Archív roka {rok}: {n} dokladov, {n} PDF, {veľkosť}“ · „Uložené do restic s tagom archive“ · pri chybe „Archív sa nevytvoril: {dôvod}“ a nenulový exit kód. Obsah ZIP: `doklady/{DocumentFilename}.pdf`, `zoznam-dokladov.csv` (stĺpce D-10: číslo, typ, dátum, subjekt, klient, suma, mena, sha256) |
| Chyba nemazateľnosti | pokus o `DELETE`/`TRUNCATE` na doklade zlyhá chybou z DB; aplikácia nemá UI na zmazanie dokladu a **žiadne takéto UI sa nepridáva** |
| Destructive confirmation | **žiadna deštruktívna akcia v UI tejto fázy**; `restic forget --prune` a obnova sú CLI úkony v runbooku s upozornením v dokumente, nie potvrdzovací sheet |

---

## Copywriting Contract

Všetky nové UI texty iba cez `lang()` (SK zdroj + PL + CS + EN). Neutrálne vety.

| Element | SK | PL | CS | EN |
|---------|----|----|----|----|
| Nadpis karty | Jazyk rozhrania | Język interfejsu | Jazyk rozhraní | Interface language |
| Úvod karty | Jazyk menu, formulárov, dátumov a súm v aplikácii. Jazyk faktúr, výkazov a e-mailov klientom sa riadi klientom a subjektom a touto voľbou sa nemení. | Język menu, formularzy, dat i kwot w aplikacji. Język faktur, zestawień i e-maili do klientów zależy od klienta i podmiotu i ta opcja go nie zmienia. | Jazyk nabídek, formulářů, dat a částek v aplikaci. Jazyk faktur, výkazů a e-mailů klientům se řídí klientem a subjektem a touto volbou se nemění. | Language of menus, forms, dates and amounts in the app. The language of invoices, reports and client e-mails follows the client and entity and is not changed here. |
| Primary CTA | Uložiť jazyk | Zapisz język | Uložit jazyk | Save language |
| Štítok aktuálneho | Aktuálny | Aktualny | Aktuální | Current |
| Úspech | Jazyk rozhrania bol zmenený. | Język interfejsu został zmieniony. | Jazyk rozhraní byl změněn. | Interface language changed. |
| Error state | Zvolený jazyk nie je podporovaný. Vyberte jednu z ponúknutých možností. | Wybrany język nie jest obsługiwany. Wybierz jedną z dostępnych opcji. | Zvolený jazyk není podporován. Vyberte jednu z nabízených možností. | The selected language is not supported. Choose one of the listed options. |
| Názvy jazykov (rovnaké vo všetkých jazykoch) | Slovenčina · Polski · Čeština · English | rovnaké | rovnaké | rovnaké |
| Empty state | nepoužité (vždy 4 voľby) | | | |
| Destructive confirmation | **Žiadna deštruktívna akcia v tejto fáze.** Zmena jazyka je vratná a bez potvrdenia | | | |

Preklady zvyšku aplikácie (32 súborov) sa riadia kontraktom v sekcii B; texty sa neopakujú v tomto dokumente.

---

## Interaction and State Contract

- **Zdroj jazyka:** pred prihlásením `Accept-Language` → `supportedLocales` (poradie `sk, pl, cs, en`, predvolený `sk`); po prihlásení `user_security.locale`; hodnota mimo `supportedLocales` sa ignoruje (už v `LocaleNegotiation`).
- **`Config\App::$supportedLocales`** = `['sk', 'pl', 'cs', 'en']`; v UI sa kód `cs` zobrazí ako „Čeština“, nie „CZ“ (D-01).
- **Fallback:** chýbajúci kľúč sa **nesmie** potichu ukázať ako surový kľúč; test parity ho zachytí v CI. Runtime fallback CI4 (`sk`/`en`) zostáva poistkou, nie funkciou.
- **Nemennosť dokladov:** zmena jazyka UI nemení žiadny vystavený doklad ani e-mail klientovi (jazyk dokladu je uložený v kópii údajov dokladu). Test: po zmene `locale` sa PDF generované zo JSONB kópie nezmení.
- **Prístupnosť:** `lang` atribút `<html>` = aktívny locale; `fieldset`/`legend`; kontrast min 4.5:1; focus ring viditeľný; stav nikdy iba farbou.
- **CSP:** žiadny inline `style`, `onclick` ani inline skript; formulár je čistý `POST`, bez JS (`data-submit-once` je existujúci externý skript).
- **Offline/PWA:** service worker nekešuje autentifikované HTML; po zmene jazyka sa nové texty načítajú zo servera. Statické texty `manifest`/`Pwa.appName` ostávajú v jednom jazyku (SK), manifest sa neprekladá (mimo rozsahu).
- **Výkon:** načítanie jazykového súboru je lazy podľa locale (CI4 predvolené); ďalší dotaz do DB sa nepridáva (`user_security.locale` sa už číta vo filtri). Cieľ p95 < 100 ms (NFR-01) platí aj v PL a CS.
- **Mimo rozsahu:** prepínač jazyka na prihlasovacej obrazovke, preklad `manifest.webmanifest`, preklad vystavených dokladov do jazyka UI, UI pre zálohy a archív, nové obrazovky.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka | Rozhodnutie |
|--------|-------------|
| Výber jazyka: kde | karta „Jazyk rozhrania“ ako prvá na `/settings/security` **[odvodené]** |
| Ovládací prvok | 4 radio s endonymami + tlačidlo Uložiť jazyk, POST + PRG, bez htmx a bez auto-submit |
| Názvy jazykov | endonymá, rovnaké vo všetkých jazykoch, v `lang()` |
| Pomenovanie CZ | v UI „Čeština“, kód `cs` |
| Skloňovanie mesiacov | `IntlDateFormatter` s `LLLL y` pre PL/CS |
| Dlhé preklady | zalamovanie a preformulovanie, nikdy zmenšenie písma ani skrátenie bodkami |
| UI pre zálohy/archív | žiadne; iba CLI a e-mail/ping |
| Jazyk ops správ | slovenčina |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Žiadna nová externá UI závislosť, iba lokálne súbory z `public/assets/vendor/`. Nové PHP knižnice tejto fázy (restic je binárka v ops, nie UI) UI nemenia.

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: PASS
- [x] Dimension 2 Visuals: PASS (FLAG vyriešený: doplnené vizuálne ťažisko)
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-11-04 (gsd-ui-checker). PL a CS preklady pripravuje Claude, vlastník ich prezrie v UAT.
