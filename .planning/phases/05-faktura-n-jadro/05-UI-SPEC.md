---
phase: 5
slug: faktura-n-jadro
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-26
reviewed_at: 2026-10-26
---

# Phase 5 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 5 (Fakturačné jadro). Dedí všetky tokeny z `02-UI-SPEC.md`, `03-UI-SPEC.md` a `04-UI-SPEC.md` a nemení ich. Jazyk UI je slovenčina, všetky texty iba cez `lang()` (nové súbory `app/Language/sk/Invoicing.php`, `Invoices.php` plus rovnaké kľúče v `en`; PL/CZ až Phase 12). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build.
> Zdroje: 05-CONTEXT.md (D-01 až D-24), 05-RESEARCH.md (stack), 04-UI-SPEC.md, 03-UI-SPEC.md, CLAUDE.md. Majiteľ nechce interaktívnu diskusiu, predpoklady sú označené **[predpoklad]**.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11 (náhľad, presun časti, vystavenie), Alpine.js CSP build 3.17.5 (zobrazenie varovania a povolenie tlačidla po potvrdení, ovládanie panelu) |
| Icon library | žiadna. Iba inline SVG `aria-hidden="true"`, `currentColor`: zámok (zamknutá časť), šípka „späť“. Stavy sa vyjadrujú textovými badge |
| Font | systémový stack z `app.css`; všetky čísla (hodiny, sumy, dátumy, čísla faktúr) s `font-variant-numeric: tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.app-header`, `.app-main`, `.app-tabbar`, `#app-alert`, `.master-row`, `.master-actions`, `.master-kv`, `.master-sticky-actions`, `.master-fixed`, `.btn-touch`, `.work-task`, `.work-alloc`, `.work-sheet`, `.work-month-pick`, `.work-group`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, modal vzor z Phase 2/3, `HtmxCsrf`, `responseHandling` pre 422 a 409.

Nové triedy v `app.css` (prefix `.inv-*`, nová verzia súboru v názve cez `asset_url`):

| Trieda | Účel |
|--------|------|
| `.inv-picker` | karta výberu na obrazovke „Fakturácia mesiaca“: Klient (`select`), Mesiac (`input type=month`), Subjekt (`select`); na mobile jeden stĺpec, od 768px tri stĺpce. GET formulár (funguje bez JS), s htmx obnovou `#inv-preview` pri zmene (`hx-trigger="change"`) |
| `.inv-summary` | hlavička náhľadu/faktúry: `dl.master-kv` (Subjekt, Klient, Mena, Bankový účet, Dátumy) + **Celkom** ako Display 28px/600 nad zoznamom položiek, vždy s menou („1 080,00 €“) |
| `.inv-line` | riadok položky (derivát `.work-alloc`, min-height 64px): 1. riadok názov úlohy (16px/600, max 2 riadky, `overflow-wrap:anywhere`), 2. riadok 12px muted „40 h × 27,00 € / h · DPH: zw“, vpravo suma riadku (16px/600, zarovnaná vpravo). V náhľade pod 2. riadkom text-link „Presunúť do iného mesiaca“ (44px) |
| `.inv-total` | pätka zoznamu položiek: „Celkom“ a suma (20px/600), kontrolný riadok „{n} položiek · {h} h“ (12px muted); suma sa musí presne rovnať súčtu riadkov a hlavičke `.inv-summary` |
| `.inv-dates` | trojica polí dátumov (Dátum vystavenia, Dátum dodania, Splatnosť) v `row g-3`, `input type=date`, 44px |
| `.inv-warn` | varovanie: Bootstrap `alert alert-warning` s ikonou textom „Pozor“ + ovládaním potvrdenia (`form-check` 44px) |
| `.inv-row` | riadok zoznamu faktúr (derivát `.work-task`, celý riadok `<a>`, min 64px): 1. riadok číslo faktúry (16px/600) + badge druhu vpravo, 2. riadok 12px muted „{Klient} · {dátum vystavenia}“, 3. riadok suma (16px/400) vpravo/pod nadpisom |
| `.inv-pdf-actions` | dvojica „Zobraziť PDF“ + „Stiahnuť PDF“ v `.master-actions` (na mobile v stĺpci, `w-100`) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 4 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera popisok a hodnota, medzi hintom a poľom |
| sm | 8px | medzi badge, medzi tlačidlami, medzi riadkami položiek (deliaca čiara + padding) |
| md | 16px | padding `.inv-line`, `.inv-picker`, odsadenie polí (`mb-3`), okraje na mobile |
| lg | 24px | medzi kartami (výber, náhľad, dátumy), padding karty na desktope |
| xl | 32px | medzi sekciami detailu faktúry (Hlavička, Položky, Údaje) |
| 2xl | 48px | horné odsadenie prázdneho stavu |
| 3xl | 64px | výška spodnej lišty a spodná rezerva obsahu (`.app-main`) |

Exceptions:
- Dotykové ciele min **44px** (výška aj šírka): všetky tlačidlá, polia, riadky zoznamu (min 64px), checkbox potvrdenia spätného dátumu (celý riadok je `label`, 44px), odkaz „Presunúť do iného mesiaca“.
- Sticky akčná lišta na obrazovke „Fakturácia mesiaca“ (`.master-sticky-actions`, výška 64px, nad tabbarom) obsahuje primárne tlačidlo „Vystaviť faktúru“ a živý **Celkom**, takže hlavná akcia a suma sú vždy v dosahu palca. **[predpoklad]** Na detaile faktúry sticky lišta nie je.
- Nový kód používa len tokeny vyššie.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 2 až 4, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, názov úlohy, suma riadku, hodnoty) | 16px | 400 (názov položky a suma riadku 600) | 1.5 |
| Label / meta (popisky polí, hint, 2. riadok, badge, `invalid-feedback`, kontrolný riadok) | 12px | 400 | 1.5 |
| Heading (`h1` názov stránky, `h2` sekcie, „Celkom“ v pätke) | 20px | 600 | 1.2 |
| Display (Celkom v hlavičke náhľadu a na detaile faktúry: jedno číslo na obrazovku) | 28px | 600 | 1.2 |

Pravidlá:
- Display 28px sa používa **len** pre súčet faktúry (jedno číslo na obrazovku, na obrazovke „Fakturácia mesiaca“ v `.inv-summary`; sticky lišta ho opakuje v 16px/600).
- Číslo faktúry (`202610001`) je `h1` 20px/600, tabular-nums.
- Hodiny `40 h`, `8,5 h`, sumy cez `NumberFormatter` v mene faktúry (`1 080,00 €`). Vždy s menou.
- Dátumy cez `IntlDateFormatter` (`26. 10. 2026`), v poliach `type=date`.

---

## Color

Dedí sa bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, riadky, hlavička, tabbar, sticky lišta, spodný panel |
| Accent (10%) | `#0d6efd` | viď zoznam nižšie |
| Destructive | `#dc3545` | iba chybové stavy; v tejto fáze **žiadna destruktívna akcia** (vystavená faktúra sa nedá zmazať ani zmeniť) |

Accent reserved for (výlučne):
1. Primárne tlačidlo jedinej hlavnej akcie obrazovky (`btn-primary`): **Vystaviť faktúru** (náhľad), **Zobraziť PDF** (detail faktúry), **Fakturovať mesiac** (zoznam faktúr), **Presunúť** (panel presunu)
2. Aktívna položka navigácie (`aria-current="page"`: Faktúry na `/invoices*`)
3. Focus ring polí a tlačidiel
4. Textové odkazy v tele („Presunúť do iného mesiaca“, „Zobraziť detail úlohy“, odkaz na dokument tretej osoby)
5. Radio/checkbox v zaškrtnutom stave (Bootstrap predvolené)

Accent sa **nepoužíva** pre badge, sumy, zámok, nadpisy ani hranice kariet.

Sémantické badge (vždy text, nikdy iba farba):

| Stav | Trieda | Text |
|------|--------|------|
| Vlastná vystavená faktúra | `text-bg-light border` | Vystavená |
| Historická externá faktúra (D-22) | `text-bg-secondary` | Externá |
| Zamknutá časť s číslom faktúry | `text-bg-secondary` + zámok | Zamknuté · FA {číslo} (nahrádza hint z Phase 4; pri externej „Zamknuté · externá faktúra“) |

Varovanie spätného dátumu (D-05): `alert-warning` (Bootstrap), text slovom „Pozor“, nikdy len farbou. Chyby vystavenia (409, 422, odmietnutá mena, chýbajúci zákonný text, prekročená maximálna sekvencia): `alert-danger` nad náhľadom.

Tmavý režim mimo rozsahu.

---

## Obrazovky a rozloženie

Všetky obrazovky sú za `authgate` v layoute `layouts/app`. Mobile first, jeden stĺpec; od >= 768px centrované `max-width: 960px` (náhľad a detail) alebo `720px` (výber). Bez horizontálneho posúvania (názvy a URL `overflow-wrap:anywhere`). Tabuľky sa nepoužívajú (zoznamy riadkov, plné PC tabuľky a filtre sú Phase 11).

### Navigácia

`_nav_items.php`: položka **Faktúry** sa stane aktívnym odkazom na `/invoices` (zrušiť `aria-disabled`), `aria-current="page"` na `/invoices*`. Poradie nezmenené: Prehľad, Úlohy, Faktúry, Viac. Nový lang kľúč netreba (`App.navInvoices`, ak neexistuje, doplniť).

### 1. Zoznam faktúr (`/invoices`)

- `h1` „Faktúry“, 12px muted „{n} dokladov“. Primárne tlačidlo **„Fakturovať mesiac“** (`btn-primary`, vedie na `/invoices/new`) v hlavičke; na mobile `w-100` pod nadpisom (nie FAB, aby sa neviazal na Phase 4 „+“ a aby bola akcia pomenovaná).
- Minimálny zoznam `.inv-row`, zoradený podľa dátumu vystavenia zostupne, potom čísla zostupne. Riadok: číslo (u externej voliteľné textové číslo, inak „Externá faktúra“) + badge, „{Klient} · {dátum}“, suma s menou. Celý riadok je odkaz na detail. Bez stránkovania, **[predpoklad]** limit 100 riadkov s hintom „Zobrazených prvých 100. Plný prehľad s filtrami bude neskôr.“
- Filtre nie sú (Phase 11).
- Externé faktúry (WR-07) sú v zozname zreteľne označené badge „Externá“, bez čísla z vlastného radu.

### 2. Fakturácia mesiaca (`/invoices/new`) — kľúčová obrazovka

Poradie zhora nadol:
1. `h1` „Fakturácia mesiaca“, 12px muted „Náhľad sa nezapisuje a nemá číslo.“
2. **Výber** `.inv-picker` (D-01):
   - **Klient** (`select`, len nearchivovaní, predvolený posledný použitý cez `LastClient`).
   - **Mesiac** (`input type=month`; predvolený: najstarší mesiac s nezamknutými časťami vybraného klienta, inak predchádzajúci mesiac; **[predpoklad]** rozsah min = aktuálny − 24, max = aktuálny + 1).
   - **Subjekt** (`select`; predvyplnený podľa dátumu vystavenia (subjekt aktívny k tomu dátumu), možno zmeniť aj na prelome subjektov, ukončený subjekt sa nenaponúka). Pod poľom 12px hint „Predvolené podľa dátumu vystavenia.“
   - Zmena ľubovoľného poľa obnoví `#inv-preview` cez htmx (`hx-get="/fragments/invoice-preview"`, `hx-push-url="true"`, `Vary: HX-Request`); bez JS tlačidlo „Zobraziť náhľad“ (`btn-outline-secondary`).
3. **Náhľad** `#inv-preview` (jeden fragment, ktorý sa nahrádza celý):
   a. `.inv-summary`: Subjekt, Klient, Mena, Bankový účet (podľa meny), a **Celkom** (Display 28px) s riadkom „{n} položiek · {h} h“.
   b. **Dátumy** `.inv-dates`: Dátum vystavenia (predvolene dnes), Dátum dodania (posledný deň mesiaca), Splatnosť (vystavenie + `due_days` klienta, inak subjektu). Všetky upraviteľné (D-04). Zmena dátumu vystavenia prepočíta subjekt, splatnosť a varovanie (htmx obnova fragmentu).
   c. **Varovanie spätného dátumu** `.inv-warn` (len ak je dátum vystavenia skorší ako dátum vystavenia poslednej faktúry v rade, D-05): text „Pozor: dátum vystavenia {dátum} je skorší ako posledná faktúra v rade ({číslo}, {dátum}). Číslo sa pridelí podľa dátumu vystavenia a poradie dátumov nebude zodpovedať poradiu čísel.“ + povinný checkbox „Rozumiem, vystaviť so spätným dátumom“ (44px). Tlačidlo Vystaviť faktúru je `disabled`, kým nie je zaškrtnutý; server toto potvrdenie vyžaduje nezávisle (skrytý/odoslaný `confirm_backdate=1`, bez neho 422).
   d. **Položky** (`h2` „Položky“): zoznam `.inv-line` v poradí podľa dátumu vytvorenia úlohy, potom id časti; pätka `.inv-total`. Každá položka má text-link „Presunúť do iného mesiaca“ (44px), ktorý otvorí `.work-sheet` (D-03): `.work-month-pick` (predvolene **Ďalší**) + `input type=month` pri „Iný…“, tlačidlá **„Presunúť“** (`btn-primary`) a „Zrušiť“. Po úspechu `HX-Trigger: allocationChanged`, náhľad sa obnoví a položka zmizne; flash „Časť bola presunutá do {mesiac}.“ Pri 409 (časť už zamknutá/neexistuje) alert v paneli.
   e. Zamknuté časti sa v náhľade **nezobrazujú** (len nezamknuté); ak niektorá časť klienta v danom mesiaci už je zamknutá, pod zoznamom 12px muted hint „Časti tohto mesiaca na už vystavených faktúrach sú zamknuté a v náhľade sa nezobrazujú.“ (kontext pri čiastočnom fakturovaní).
   f. Ak sú v náhľade časti s rôznymi menami, alebo mena ≠ mena subjektu, alebo chýba zákonný text: `alert-danger` s konkrétnou správou (Copywriting), položky sa stále zobrazia, tlačidlo Vystaviť je `disabled` a stav je aj serverovo odmietnutý (D-10, D-18).
4. **Sticky lišta** (`.master-sticky-actions`): vľavo „Celkom {suma}“ (16px/600), vpravo **„Vystaviť faktúru“** (`btn-primary`). Počas odoslania `disabled` + spinner (`.htmx-indicator`, text ostáva) = prvá línia ochrany pred dvojklikom; druhá línia je serverová (D-06). Tlačidlo je `disabled` aj keď nie je žiadna položka.
5. **Potvrdenie vystavenia na mobile** (D-Claude's Discretion): kliknutie na „Vystaviť faktúru“ otvorí `.work-sheet` (spodný panel) s rekapituláciou: „Vystaviť faktúru pre {Klient}?“, riadky Subjekt, Mesiac, Dátum vystavenia, Celkom, „{n} položiek“, 12px hint „Po vystavení sa faktúra a jej PDF nedajú zmeniť. Číslo sa pridelí pri vystavení.“ (+ pri spätnom dátume zopakované varovanie, ak nebolo potvrdené). Tlačidlá `.master-actions`: **„Vystaviť faktúru“** (`btn-primary`, odošle POST) a „Zrušiť“ (`btn-outline-secondary`). Je to jediný potvrdzovací krok (nie druhý modal).
6. **Po úspechu**: odpoveď `HX-Location` na `/invoices/{id}` s flashom „Faktúra {číslo} bola vystavená.“ Pri opakovanom odoslaní (409, časti sú už zamknuté) panel zobrazí alert „Časti už sú na vystavenej faktúre.“ s odkazom „Zobraziť faktúru {číslo}“ (alebo presmerovanie na ňu, D-06).

Prázdne stavy (výber bez nezamknutých častí): pozri Copywriting. Tlačidlo Vystaviť v takom prípade nie je vykreslené.

### 3. Detail faktúry (`/invoices/{id}`)

Karty zhora nadol, 32px medzi sekciami:
1. **Hlavička**: `h1` číslo faktúry (`202610001`), badge „Vystavená“, 12px muted „{Klient} · vystavená {dátum}“. `.inv-summary` s **Celkom** (Display 28px, s menou). `.inv-pdf-actions`: **„Zobraziť PDF“** (`btn-primary`, otvára uložené PDF v novej karte `target=_blank rel="noopener noreferrer"`, `Content-Type: application/pdf`, `Content-Disposition: inline`) a **„Stiahnuť PDF“** (`btn-outline-secondary`, `attachment`). Obe vždy vracajú uložené bajty, nikdy neregenerujú (D-16). Pod nimi 12px muted „Uložené PDF, kontrolný súčet SHA-256: {prvých 16 znakov}…“ (celý hash v `title`/rozbaľovacom riadku `Údaje`).
2. **Položky** (`h2` „Položky“): `.inv-line` (iba na čítanie, bez presunu), `.inv-total` s rovnakou sumou ako hlavička. Názov úlohy je odkaz „Zobraziť detail úlohy“ na `/tasks/{task_id}`, ak úloha ešte existuje (zdroj je len informatívny, bez FK; ak neexistuje, text bez odkazu).
3. **Údaje** (`dl.master-kv`): Subjekt (názov a IČO/identifikátor z kópie), Klient (z kópie), Dátum vystavenia, Dátum dodania, Splatnosť, Mena, Bankový účet, Forma úhrady („prevod“), DPH režim a kód oslobodenia, Zákonný text (z kópie, 12px), Verzia šablóny (`inv-1`), SHA-256.
4. Banner `alert-secondary` (nie warning): „Vystavená faktúra je nemenná. Zmeny subjektu, klienta, sadzby ani meny ju neovplyvnia.“ (12px). Žiadne tlačidlá Upraviť ani Zmazať. Kým neexistujú dobropisy (Phase 9), nie je žiadna akcia opravy.
5. Mimo rozsahu tejto fázy a **nevykresľuje sa** (žiadne neaktívne zástupné tlačidlá): odoslať e-mailom, úhrady, výkaz práce, dobropis, QR.

### 4. Detail historickej externej faktúry (`/invoices/{id}`, druh „externá“; D-22)

Rovnaká šablóna, rozdiely: `h1` „Externá faktúra“ (alebo voliteľné textové číslo), badge „Externá“ (`text-bg-secondary`), 12px muted hint pod hlavičkou „Historická faktúra mimo evidencie tejto aplikácie. Nezapočítava sa do obratu, limitov ani prehľadov.“ Odkaz na doklad tretej osoby (ak je zadaný) ako text-link „Otvoriť doklad ↗“ (`target=_blank rel="noopener noreferrer"`, len http/https). **Bez** tlačidiel PDF a bez SHA-256 (D-22: bez PDF). Súhrn hodín a suma z položiek (časti 09/2026) sú zobrazené rovnako.

### 5. Zamknutá časť (read-only; doplnenie Phase 4 obrazoviek, D-21)

- **Detail úlohy** (`.work-alloc`): zamknutá časť ukazuje zámok + „Zamknuté · FA {číslo}“, kde číslo je **odkaz** na `/invoices/{id}` (text-link 44px, accent); pri externej „Zamknuté · externá faktúra“ (odkaz na jej detail). Riadok nemá žiadny ovládací prvok (ani `disabled`), stále bez „Upraviť“, hint z Phase 4 ostáva.
- **Dashboard a zoznam úloh**: badge „Čiastočne“/„Vyfakturované“ teraz reálne vychádzajú z `task_billing_v`; žiadna zmena rozloženia. Zapracovaná úloha so zamknutou časťou už neponúka „Označiť ako zapracovanú“ pre zamknutú časť a „Zmazať úlohu“ je skrytá (existujúce pravidlo Phase 4).
- **Panel časti** pri pokuse o úpravu zamknutej časti (priamy POST): 409 s textom z Phase 4 „Časť je na vystavenej faktúre a nedá sa meniť ani presunúť. Zmena je možná až po dobropise.“
- Importované časti zamknuté na externú faktúru (09/2026, 11 častí) sa správajú rovnako ako zamknuté časti, bez zmeny textu (číslo faktúry je „externá faktúra“).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, vecný tón. Texty iba cez `lang()`.

| Element | Copy |
|---------|------|
| Primary CTA (náhľad) | Vystaviť faktúru |
| Primary CTA (zoznam faktúr) | Fakturovať mesiac |
| Primary CTA (detail) | Zobraziť PDF |
| Sekundárne | Stiahnuť PDF, Zobraziť náhľad, Presunúť, Presunúť do iného mesiaca, Zrušiť, Zobraziť faktúru {číslo}, Zobraziť detail úlohy |
| Empty state heading (zoznam faktúr) | Zatiaľ žiadne faktúry |
| Empty state body (zoznam faktúr) | Vystavené faktúry sa zobrazia tu. Začnite tlačidlom „Fakturovať mesiac“. |
| Empty state heading (náhľad bez častí) | Nič na fakturáciu |
| Empty state body (náhľad bez častí) | Klient {klient} nemá v mesiaci {mesiac} žiadnu nezamknutú časť. Zvoľte iný mesiac alebo označte úlohu ako zapracovanú. |
| Empty state heading (bez klienta) | Najprv pridajte klienta |
| Empty state body (bez klienta) | Faktúra sa vystavuje klientovi. Pridajte klienta v časti Viac, Klienti. |
| Empty state heading (bez subjektu k dátumu) | Žiadny aktívny subjekt |
| Empty state body (bez subjektu k dátumu) | K dátumu {dátum} nie je aktívny žiadny subjekt. Zmeňte dátum vystavenia alebo vyberte subjekt. |
| Varovanie spätného dátumu | Pozor: dátum vystavenia {dátum} je skorší ako posledná faktúra v rade ({číslo}, {dátum}). Číslo sa pridelí podľa dátumu vystavenia a poradie dátumov nebude zodpovedať poradiu čísel. |
| Checkbox potvrdenia | Rozumiem, vystaviť so spätným dátumom |
| Potvrdenie vystavenia | Vystaviť faktúru pre {klient}? Po vystavení sa faktúra a jej PDF nedajú zmeniť. Číslo sa pridelí pri vystavení. |
| Success flashe | „Faktúra {číslo} bola vystavená.“ · „Časť bola presunutá do {mesiac}.“ |
| Error (mena častí) | Časti majú rôzne meny ({meny}). Jedna faktúra môže mať len jednu menu. Presuňte časti inej meny do iného mesiaca. |
| Error (mena ≠ mena subjektu, D-10) | Faktúra v mene {mena} sa nedá vystaviť zo subjektu s menou {mena_subjektu}. Faktúry v cudzej mene podporí neskoršia verzia. Vyberte iný subjekt. |
| Error (chýba zákonný text, D-18) | Chýba zákonný text pre {jazyk}. Doplňte ho v Nastavenia, Subjekty a vystavte znova. |
| Error (maximálna sekvencia) | Číslovací rad {rad} dosiahol najvyššie možné poradie. Upravte formát radu v Nastavenia, Číselné rady. |
| Error (spätný dátum bez potvrdenia, 422) | Potvrďte vystavenie so spätným dátumom. |
| Error (duplicitné odoslanie, 409) | Časti už sú na vystavenej faktúre {číslo}. Nová faktúra sa nevytvorila. |
| Error (časť už zamknutá pri presune, 409) | Časť je na vystavenej faktúre a nedá sa presunúť. |
| Error (dátumy) | Zadajte platný dátum. Splatnosť nemôže byť skôr ako dátum vystavenia. |
| Error (mesiac) | Zvoľte mesiac v rozsahu od {od} do {do}. |
| Error (všeobecný zlyhaný zápis) | Faktúru sa nepodarilo vystaviť. Nič sa nezapísalo a číslo ostáva voľné. Skúste to znova. |
| Error (PDF sa nenašlo/zlyhalo) | PDF faktúry sa nepodarilo načítať. Skúste to znova; ak chyba pretrvá, obráťte sa na správcu. |
| Error (404) | Faktúra sa nenašla. Vráťte sa na zoznam faktúr. |
| Error (sieť / server / CSRF) | existujúce `App.errorNetwork`, `App.errorServer`, `errors/csrf_403.php` (nemeniť) |
| Hint (nemennosť) | Vystavená faktúra je nemenná. Zmeny subjektu, klienta, sadzby ani meny ju neovplyvnia. |

Popisky polí (`Invoicing.php`, `Invoices.php`): Klient, Mesiac, Subjekt, Mena, Bankový účet, Dátum vystavenia, Dátum dodania, Splatnosť, Položky, Celkom, Forma úhrady, DPH režim, Kód oslobodenia, Zákonný text, Verzia šablóny, SHA-256, Externá faktúra, Vystavená.

### Destruktívne a nevratné akcie

| Akcia | Potvrdenie |
|-------|-----------|
| Vystaviť faktúru (nevratná, nemení sa ani nemaže) | Vystaviť faktúru: „Vystaviť faktúru pre {klient}? Po vystavení sa faktúra a jej PDF nedajú zmeniť. Číslo sa pridelí pri vystavení.“ (spodný panel, tlačidlá „Vystaviť faktúru“ a „Zrušiť“; pri spätnom dátume navyše povinný checkbox) |
| Presun časti do iného mesiaca | bez potvrdenia, nie je destruktívna (vratná presunom späť, kým nie je časť zamknutá) |
| Zmazať / upraviť faktúru | **neexistuje** (nemenné); UI ani nenaznačuje takúto akciu |

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid (`is-invalid` + `invalid-feedback`, `aria-describedby`), disabled, loading (`disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva). Všetko funguje ako klasický GET (výber, náhľad) a POST + PRG (vystavenie) bez JS, okrem živého povolenia tlačidla po checkboxe (bez JS je tlačidlo aktívne a server vynúti potvrdenie). `422` swapuje fragment s chybami (existujúci `responseHandling`), `409` zobrazí alert v paneli/nad náhľadom. Úspešný POST cez htmx vráti `HX-Location`, nie 302.
- **Náhľad = vystavenie**: súčet v hlavičke, v pätke zoznamu, v sticky lište a v potvrdzovacom paneli je tá istá hodnota z `InvoiceCalculator` (D-02, D-08). Náhľad ani jeho dátumy nikdy nezapisujú a nemajú číslo; číslo sa v UI nezobrazí pred vystavením.
- **Obnova fragmentu**: nesmie posunúť scroll ani zavrieť otvorený panel; po zmene dátumu sa zachová zaškrtnutý checkbox len vtedy, ak je varovanie stále platné (inak sa zruší).
- **Ochrana pred dvojitým odoslaním**: tlačidlo `disabled` po prvom kliknutí + serverová 409/presmerovanie (D-06). Tlačidlo sa neodomkne po úspechu.
- **Nemennosť v UI**: vystavená faktúra a jej položky nemajú žiadne ovládacie prvky na úpravu. UI nikdy nespolieha na skrytie tlačidla, server a DB sú nezávislé línie (D-20, D-21).
- **Mena**: každá suma so symbolom meny; viac mien sa nikdy nesčítava (D-10 odmieta zmiešané meny).
- **PDF**: otváranie a sťahovanie vracia uložené bajty (D-16), `Cache-Control: private, no-store` (autentifikovaný doklad), názov súboru `faktura-{číslo}.pdf`. Chyba načítania nikdy nespustí regeneráciu.
- **Rozloženie jednou rukou**: primárna akcia a Celkom v sticky lište, potvrdenie v spodnom paneli, všetky ovládacie prvky v spodnej polovici obrazovky, 44px ciele.
- **Prístupnosť**: každé pole `<label for>`, `fieldset/legend` pre `.work-month-pick`, panel s `aria-labelledby`, správny fokus (prvé pole pri otvorení, späť na spúšťač pri zatvorení), `Esc` zatvára, varovanie `role="alert"` pri zobrazení, flash `role=alert aria-live=polite`, kontrast min 4.5:1, farba nikdy sama nenesie stav (badge a varovanie majú text). Zmena Celkom po obnove náhľadu je v `aria-live="polite"` oblasti.
- **Mobilné klávesnice**: `type=month`, `type=date`; polia 16px (iOS zoom).
- **Chyby prístupu**: neexistujúce ID faktúry alebo časť nepatriaca klientovi = 404; všetky zápisy len POST + CSRF (`HtmxCsrf`).
- **Audit v UI**: denník sa nezobrazuje (AUD-02 je Phase 11).
- **Offline**: stránky ani PDF sa nekešujú (SW nikdy nekešuje autentifikované HTML ani PDF).
- **Mimo rozsahu**: e-mail, úhrady, stavy platieb, výkaz práce, QR platba, finálny vzhľad PDF (Phase 6), kurzy (Phase 8), dobropisy (Phase 9), limity a predkontrola limitu (Phase 10), filtre a PC tabuľky (Phase 11). Spúšťanie WR-07 príkazu je CLI bez UI.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Predvolený mesiac | Najstarší mesiac s nezamknutými časťami vybraného klienta, inak predchádzajúci mesiac |
| Predvolený klient | Posledný použitý (`LastClient`) |
| Poradie položiek | Dátum vytvorenia úlohy, potom id časti |
| Rozsah zoznamu a detailu | Minimálny zoznam `.inv-row` (limit 100), detail s PDF; filtre a PC tabuľky Phase 11 |
| Forma potvrdenia na mobile | Spodný panel `.work-sheet` s rekapituláciou (jeden krok) |
| Spúšťač obrazovky | Tlačidlo „Fakturovať mesiac“ v zozname faktúr (`/invoices/new`), nie FAB |
| Varovanie spätného dátumu | `alert-warning` + povinný checkbox, server vyžaduje `confirm_backdate` |
| Externá faktúra | Rovnaký detail, badge „Externá“, bez PDF a SHA-256, hint o nezapočítavaní |
| Zobrazenie zamknutej časti | Zámok + odkaz „Zamknuté · FA {číslo}“ na detail faktúry |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Žiadne nové balíky ani CDN.

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: FLAG (non-blocking: „Zrušiť“, „Presunúť“ labels)
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-10-26
