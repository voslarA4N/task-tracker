---
phase: 7
slug: doru-enie-hrady-a-upomienky
status: approved
reviewed_at: 2026-10-29
shadcn_initialized: false
preset: none
created: 2026-10-29
---

# Phase 7 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 7 (Doručenie, úhrady a upomienky). Dedí tokeny z `02-UI-SPEC.md` až `06-UI-SPEC.md` a nemení ich (spacing, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk UI je slovenčina, všetky texty iba cez `lang()` (nové súbory `app/Language/sk/Deliveries.php`, `Payments.php`, `EmailTemplates.php` plus rovnaké kľúče v `en`; doplnky do `Invoices.php`, `Home.php`, `Entities.php`). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build, htmx `allowEval=false`.
> Zdroje: 07-CONTEXT.md (D-01 až D-29), 06-UI-SPEC.md, 05-UI-SPEC.md, CLAUDE.md, existujúce `app/Views/invoices/*`, `invoicing/_confirm_sheet.php`, `home/_body.php`, `public/assets/css/app.css`. Vlastník nechce interaktívnu diskusiu, predpoklady sú označené **[predpoklad]**.
> Rozsah: (A) odoslanie, opätovné odoslanie a upomienka (bottom sheet), (B) detail faktúry: stav, história odoslaní, úhrady, storno, (C) zápis úhrady, (D) stavové odznaky a filter v zozname, (E) dashboard „Po splatnosti“, (F) Nastavenia → Šablóny e-mailov, (G) identita odosielateľa pri subjekte, (H) e-mail samotný (šablóna).

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 |
| Icon library | žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`); stavy nikdy iba ikonou |
| Font | systémový stack z `app.css`, čísla `tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.app-main`, `.master-row`, `.master-actions`, `.master-kv`, `.master-sticky-actions`, `.work-sheet` + Bootstrap `offcanvas-bottom` (vzor `invoicing/_confirm_sheet.php`), `.inv-docs`, `.inv-summary`, `.inv-warn`, `.lt-group`, `.lt-row`, `.lt-badge`, `.lt-body`, `.lt-check`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `HtmxCsrf`, `responseHandling` pre 422 a 409, `fragments/alert.php`.

Nové triedy v `app.css` (nová verzia súboru v názve cez `asset_url`; prefixy `.dl-*` doručenie, `.pay-*` úhrady, `.st-*` stavy, `.ov-*` po splatnosti, `.et-*` šablóny e-mailov):

| Trieda | Účel |
|--------|------|
| `.st-badge` | odznak stavu faktúry (Bootstrap `badge`, vždy text), kontajner `.st-badges` (flex, `gap` 8px, wrap) pre stav + „Po splatnosti“ |
| `.dl-sheet` | bottom sheet odoslania (derivát `.work-sheet`, max-height 90vh, vnútro scrolluje, sticky pätka s tlačidlami) |
| `.dl-field` | pole adresátov (`label` + `input`/`textarea`), 16px |
| `.dl-chips` | zoznam adries ako riadky (adresa + tlačidlo „Odobrať“ 44px), nie malé čipy |
| `.dl-attach` | zoznam príloh (názov súboru + veľkosť + sha256 skrátený), len na čítanie |
| `.dl-history` | zoznam histórie odoslaní na detaile (derivát `.master-row`, min 64px) |
| `.pay-list` | zoznam úhrad na detaile (derivát `.master-row`), `.pay-void` riadok stornovanej úhrady (`text-decoration: line-through` na sume a dátume, nie na dôvode) |
| `.pay-balance` | blok „Zostatok“ (Display 28px/600 + riadky Celkom, Uhradené) |
| `.ov-row` | riadok dashboardu „Po splatnosti“ (celý riadok `<a>` na detail, min 64px; akcie pod ním ako samostatné tlačidlá) |
| `.ov-actions` | dvojica tlačidiel „Upomienka“ + „Úhrada“ (flex, `gap` 8px, každé min 44px, na mobile `w-50`) |
| `.et-vars` | zoznam povolených placeholderov (kód `{{number}}` + popis, 12px, každý klikateľný `button` vloží do `textarea` cez Alpine CSP, min 44px) |
| `.et-preview` | náhľad vykresleného e-mailu (`.app-card` s `pre-wrap`, 16px) |
| `.sender-card` | karta „Odosielanie e-mailov“ vo formulári subjektu |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 6 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera popisok a hodnota, hint pod poľom, medzi kód a popis placeholderu |
| sm | 8px | medzi odznakmi, medzi tlačidlami `.ov-actions`, medzi riadkami adresátov |
| md | 16px | padding sheetu, `.dl-history`, `.pay-list`, `.ov-row`; odsadenie polí (`mb-3`); okraje na mobile |
| lg | 24px | medzi kartami detailu a medzi sekciami sheetu |
| xl | 32px | medzi sekciami detailu (Odoslanie, Úhrady, Dokumenty) |
| 2xl | 48px | horné odsadenie prázdneho stavu |
| 3xl | 64px | výška sticky lišty / pätky sheetu a spodná rezerva obsahu |

Exceptions:
- Dotykové ciele min **44px**: všetky tlačidlá, polia, `select`, `textarea`, tlačidlo „Odobrať adresu“, položky `.et-vars`, riadky histórie a úhrad (min 64px), filter stavu (44px).
- Sheet odoslania: pätka s tlačidlami je sticky (64px), primárne tlačidlo v spodnej polovici obrazovky (jedna ruka). Na PC (>= 768px) sa sheet zobrazí ako centrovaný modálny panel `max-width: 640px`, nie na celú šírku.
- Žiadne horizontálne posúvanie: názvy príloh a adresy `overflow-wrap:anywhere`; tabuľky nie sú, iba zoznamy riadkov.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 a 6, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, riadky zoznamov, text e-mailu v náhľade, názov prílohy) | 16px | 400 (číslo faktúry, suma v riadku, názov šablóny 600) | 1.5 |
| Label / meta (popisky, hint, 2. riadok riadku, odznaky, dni po splatnosti, `invalid-feedback`, zoznam placeholderov, dátum a SMTP odpoveď v histórii) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2` sekcií, nadpis sheetu) | 20px | 600 | 1.2 |
| Display (Zostatok na detaile, Celkom v hlavičke, existujúce) | 28px | 600 | 1.2 |

E-mail (H) nemá vlastnú typografiu: textová časť je čistý text, HTML časť používa predvolený štýl klienta (bez CSS, bez obrázkov, D-13).

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, riadky, sheet, sticky pätka, hlavička |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | iba storno úhrady / storno ručného označenia a chybové stavy |

Accent reserved for (výlučne, jedna hlavná akcia na obrazovku):
1. Primárne tlačidlo hlavnej akcie obrazovky alebo sheetu: **Odoslať klientovi** (detail neodoslanej faktúry a sheet odoslania), **Zapísať úhradu** (sheet úhrady), **Uložiť šablónu** (úprava šablóny), **Uložiť** (formulár subjektu, existujúce)
2. Aktívna položka navigácie (existujúce)
3. Focus ring polí a tlačidiel
4. Textové odkazy v tele („Upraviť šablónu“, „Zobraziť faktúru“, riadok `.ov-row`)
5. Zaškrtnuté checkbox/radio (Bootstrap predvolené)

Accent sa **nepoužíva** pre odznaky stavu, upozornenia, nadpisy ani hranice kariet. Na detaile už odoslanej faktúry je hlavná akcia podľa stavu: pri zostatku > 0 **Zapísať úhradu** (accent), „Odoslať znova“ je `btn-outline-secondary`; pri uhradenej faktúre nie je primárne tlačidlo (iba `btn-outline-secondary`).

### Odznaky stavu faktúry (vždy text, nikdy iba farba; priorita D-22)

| Stav | Trieda | Text |
|------|--------|------|
| `issued` | `text-bg-secondary` | Vystavená |
| `sent` | `text-bg-light border` | Odoslaná |
| `partially_paid` | `text-bg-warning` | Čiastočne uhradená |
| `paid` | `text-bg-success` | Uhradená |
| `external` (existujúce) | `text-bg-secondary` | Externá |
| príznak po splatnosti (navyše k stavu) | `text-bg-danger` | Po splatnosti · {n} dní |
| `cancelled` | rezervované pre Phase 9 (`text-bg-dark`, „Stornovaná“), v tejto fáze sa nevykresľuje | |

Pravidlá: odznak „Po splatnosti“ sa pridá vedľa stavu pre `issued`, `sent` aj `partially_paid`, nikdy pre `paid` a `external`. Sklonovanie „1 deň / 2 dni / 5 dní“ cez `lang()` plural kľúče. Text odznaku `text-bg-warning` a `text-bg-success` má dostatočný kontrast (Bootstrap predvolené, min 4.5:1).

Výstrahy: `alert alert-warning` (12px) a `alert-danger` (chyby) rovnako ako v Phase 6. Destruktívne tlačidlo `btn-danger` len vo sheete storna (jediné miesto v tejto fáze).

---

## A. Odoslanie, opätovné odoslanie, upomienka (bottom sheet `.dl-sheet`)

Jedna komponenta, tri režimy (`kind`): `invoice`, `resend`, `reminder`. Načíta sa htmx GET fragmentom do `#dl-sheet` a otvorí ako Bootstrap offcanvas-bottom (mobil) alebo modál (PC). Trasy: `GET invoices/{id}/send` (formulár), `POST invoices/{id}/send`, `GET/POST invoices/{id}/resend`, `GET/POST invoices/{id}/remind`.

### A1. Obsah sheetu (zhora nadol)

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | Nadpis `h2` 20px | „Odoslať klientovi“ / „Odoslať faktúru znova“ / „Poslať upomienku“; pod ním 12px muted „{číslo faktúry} · {klient} · jazyk e-mailu: {sk/pl/cs/en}“ |
| 2 | Adresáti | `.dl-field` „Komu“ (`.dl-chips` zoznam riadkov, každý s tlačidlom „Odobrať“) + pole „Pridať adresu“ (`type="email"`, `inputmode="email"`, 16px) + tlačidlo „Pridať“ (`btn-outline-secondary`, 44px). Pole „Kópia (CC)“ rovnako. Predvyplnenie: `invoice` a `reminder` = `invoice_email` (Komu) + `cc_emails` (CC); `resend` = adresa klienta ako predvolená, ľubovoľná adresa pridateľná. Max 10 adries spolu, validácia `filter_var` + bez CR/LF na serveri. Pridanie adresy: htmx alebo Alpine CSP, funguje aj bez JS ako opakovaný POST (**[predpoklad]** fallback: jedno textové pole „Komu“ s adresami oddelenými čiarkou, ak JS nie je) |
| 3 | Odosielateľ a BCC (read-only, 12px muted) | „Od: {meno} <{adresa}> · Odpovedať na: {reply-to} · Skrytá kópia: {bcc alebo „žiadna“}“ z nastavení subjektu (D-27). Odkaz „Zmeniť v nastaveniach subjektu“ |
| 4 | Predmet | `input` „Predmet“, predvyplnený zo šablóny, `maxlength` 200, editovateľný (D-17) |
| 5 | Telo | `textarea` „Text správy“ (`rows` 10, 16px, `.lt-body`), predvyplnená vykreslená šablóna, editovateľná; 12px hint „Úprava platí len pre tento e-mail. Šablónu zmeníte v Nastavenia, Šablóny e-mailov.“ |
| 6 | Prílohy `.dl-attach` | `invoice` a `resend`: „Faktura_{číslo}.pdf“ + „Vykaz_{číslo}.pdf“ (ASCII názvy podľa jazyka dokladu, D-07), 12px muted veľkosť a prvých 12 znakov SHA-256. `reminder`: iba faktúra. Faktúra schema 1: iba faktúra, hint „Výkaz pre túto faktúru neexistuje.“ Bez ovládacieho prvku na odobratie alebo regeneráciu (bajty sú nemenné) |
| 7 | Upozornenie (podmienečné) | 12px `alert alert-warning` „Pozor: žiadna z adries nie je fakturačná adresa klienta. Faktúra sa odošle, ale stav ostane „Vystavená“, kým ju neoznačíte ako odoslanú.“ (D-29). Zobrazí sa Alpine CSP podľa zhody adries; server rozhoduje nezávisle |
| 8 | Skrytý `request_key` (uuid) | + CSRF; dvojklik nič nepošle dvakrát (D-11) |
| 9 | Pätka (sticky) | primárne tlačidlo vpravo, „Zrušiť“ (`btn-outline-secondary`) vľavo |

Primárne tlačidlo: „Odoslať klientovi“ (`invoice`), „Odoslať“ (`resend`), „Poslať upomienku“ (`reminder`). Na PC rovnaké poradie, panel centrovaný.

### A2. Stavy a správanie

- **Loading**: tlačidlo `disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva; všetky polia `readonly` počas odosielania. Zablokuje zatvorenie klikom mimo sheet (iba „Zrušiť“).
- **Úspech**: sheet sa zatvorí, detail faktúry sa obnoví (`HX-Trigger` + swap sekcie Odoslanie), flash `role="alert" aria-live="polite"`: „E-mail bol odoslaný na {adresy}.“ (pri `log` transporte na staging: „E-mail bol zaznamenaný (testovacie prostredie, nič sa neodoslalo).“, **[predpoklad]** odlíšenie podľa `transport = 'log'`, aby vlastník nebol zmätený).
- **Zlyhanie SMTP**: sheet ostane otvorený, nad pätkou `alert alert-danger` „E-mail sa nepodarilo odoslať. Faktúra aj PDF ostali bez zmeny. Skúste to znova.“ + tlačidlo „Skúsiť znova“ (rovnaký `request_key`, ak server nič nezapísal); nezapíše sa nič do histórie (D-10). Detail chyby sa neukazuje (iba v logu).
- **Validácia 422**: `is-invalid` + `invalid-feedback` + `aria-describedby`, focus na prvé neplatné pole. Chyby: viď Copywriting.
- **Dvojklik / opakovaný kľúč**: server vráti predchádzajúci výsledok, UI ukáže rovnaký úspech (bez druhého e-mailu).
- **Zlyhanie načítania PDF príloh** (chýba/sha mismatch): `alert-danger` „Prílohy sa nepodarilo načítať. E-mail sa neodoslal. Skúste to znova; ak chyba pretrvá, obráťte sa na správcu.“ Nikdy regenerácia.
- **Nedostupné**: externé faktúry nemajú tlačidlá odoslania ani upomienky; upomienka sa neponúka pri zostatku 0.
- **Prístupnosť**: sheet `role="dialog"`, `aria-labelledby` nadpisu, focus trap (Bootstrap), po zatvorení fokus späť na spúšťacie tlačidlo, Esc zatvára.

### A3. Prvé odoslanie po vystavení (D-05)

Po úspešnom vystavení (Phase 5 flash) detail faktúry hneď zobrazí `alert alert-secondary` s textom „Faktúra {číslo} je vystavená. Odošlite ju klientovi.“ a primárnym tlačidlom **„Odoslať klientovi“** (otvorí sheet `invoice`). Žiadne automatické odoslanie ani otvorenie sheetu bez kliknutia (**[predpoklad]**, jedno klepnutie navyše je v poriadku a bráni omylu). Na detaile neodoslanej faktúry tlačidlo ostáva trvalo.

---

## B. Detail faktúry (`invoices/show.php`) — doplnky

Poradie kariet zhora (mobil, jeden stĺpec; od >= 768px `max-width: 960px` centrované, nič nové v rozložení):

1. **Hlavička** (existujúca): číslo, klient, Celkom (Display 28px), `.st-badges` so stavom + „Po splatnosti · {n} dní“. Akcie `.master-actions` podľa stavu (jedna primárna, viď Color):
   - `issued` bez odoslania: **Odoslať klientovi** (primárne), „Označiť ako odoslanú“ (`btn-outline-secondary`), „Zapísať úhradu“ (outline).
   - `sent`/`partially_paid`: **Zapísať úhradu** (primárne), „Poslať upomienku“ (`btn-outline-secondary`; červená je len pre storno, zvýraznenie „po splatnosti“ nesie odznak), „Odoslať znova“ (outline).
   - `paid`: „Odoslať znova“ (outline); žiadne primárne.
   - Akcie sa rendrujú v poradí primárne → outline, na mobile `w-100` pod sebou, 44px.
2. **Platba a zostatok** (`.pay-balance`, karta `.app-card`): `h2` „Úhrady“; Display 28px „Zostatok {suma s menou}“; 12px muted riadky „Celkom {suma} · Uhradené {suma} · Splatnosť {dátum}“. Pri zostatku 0: „Zostatok 0,00 €“ + odznak Uhradená. Pod tým `.pay-list`.
3. **Odoslanie a história** (`.dl-history`): `h2` „Odoslanie“. Zhrnutie 12px muted „Odoslaná {dátum} · {n}× odoslaná · posledná upomienka {dátum alebo „žiadna“}“. Zoznam záznamov od najnovšieho; riadok: 1. riadok (16px/600) typ „Odoslanie“ / „Opätovné odoslanie“ / „Upomienka“ / „Ručne označená ako odoslaná“; 2. riadok 12px muted „{dátum a čas} · {adresy Komu/CC} · {predmet skrátený}“ (skratka `text-truncate` na PC, na mobile zalamovanie); vpravo odznak `text-bg-light border` „Klient“ ak adresa zodpovedala fakturačnému e-mailu klienta (zmysel D-29), inak „Iná adresa“. Detail riadku (`<details>` alebo htmx rozbalenie, min 44px): Od, Reply-To, BCC, `message_id`, SMTP odpoveď, transport (12px, `overflow-wrap:anywhere`). Ručné označenie nesie dôvod/poznámku a tlačidlo „Zrušiť označenie“ (viď B4). Prázdny stav: viď Copywriting.
4. **Dokumenty** (existujúca karta `.inv-docs` z Phase 6, nezmenená).
5. **Údaje** `dl.master-kv` (existujúce).

### B1. `.pay-list` — zoznam úhrad

Riadok: 1. riadok „{suma s menou}“ (16px/600, `tabular-nums`) + „{dátum úhrady}“; 2. riadok 12px muted „Zapísané {dátum a čas} · {poznámka skrátená}“; vpravo ikonové-textové tlačidlo „Stornovať“ (`btn-link`, text, 44px, nie červené; červená je až v potvrdení). Stornovaná úhrada `.pay-void`: suma a dátum prečiarknuté, 12px muted „Stornované {dátum}: {dôvod}“, žiadna akcia (D-28). Súčet „Uhradené“ nezahŕňa stornované. Zoradené od najnovšej úhrady po najstaršiu.

### B2. Ručné označenie ako odoslanej (D-29)

Tlačidlo „Označiť ako odoslanú“ otvorí malý sheet (rovnaká kostra `.dl-sheet`, bez príloh): `h2` „Označiť ako odoslanú“, hint 12px „Použite, ak ste faktúru odovzdali inak ako e-mailom z aplikácie. E-mail sa neodošle.“, `input` „Dátum odoslania“ (predvolene dnes z `Clock`, nie v budúcnosti), `textarea` „Poznámka (nepovinné)“ (max 200), primárne „Označiť ako odoslanú“. Úspech: flash „Faktúra je označená ako odoslaná.“

### B3. Dostupnosť pre ukončené subjekty

Opätovné odoslanie, úhrady, upomienky a storno ostávajú dostupné pri faktúrach ukončeného subjektu (D-08, ENT-04); bez upozornenia, žiadny skrytý stav.

### B4. Storno úhrady a storno ručného označenia (destruktívna akcia, D-28/D-29)

Tlačidlo „Stornovať“ (úhrada) alebo „Zrušiť označenie“ (ručné odoslanie) otvorí potvrdzovací sheet: `h2` „Stornovať úhradu“ / „Zrušiť označenie“, 12px popis záznamu („{suma} · {dátum}“), **povinné** `textarea` „Dôvod“ (max 200, 16px, 44px+), výstraha 12px „Úhrada sa nevymaže. Ostane v zozname ako stornovaná a zostatok sa prepočíta.“ Pätka: „Späť“ (`btn-outline-secondary`) vľavo, **„Stornovať úhradu“** (`btn-danger`) vpravo; prázdny dôvod = 422 „Zadajte dôvod storna.“ Po úspechu flash „Úhrada bola stornovaná.“ a obnovenie sekcií Úhrady a stav.

---

## C. Zápis úhrady (PAY-01, PAY-03; sheet `.dl-sheet`)

Spúšťače: detail („Zapísať úhradu“) a dashboard („Úhrada“). Trasa `GET/POST invoices/{id}/payments`.

| Prvok | Špecifikácia |
|-------|--------------|
| Nadpis `h2` | „Zapísať úhradu“, 12px muted „{číslo} · {klient} · zostatok {suma s menou}“ |
| Dátum úhrady | `input type="date"` „Dátum úhrady“, predvolene dnes (`Clock`), `max` = dnes, povinné |
| Suma | `input inputmode="decimal"` „Suma ({mena faktúry})“ (nepovinné). Placeholder = zostatok (napr. „1 080,00“). Hint 12px „Nechajte prázdne pre úhradu celého zostatku ({zostatok}).“ Prázdne = server uloží zostatok (D-18). Desatinná čiarka aj bodka prijatá, uloží sa string cez bcmath |
| Poznámka | `textarea` „Poznámka (nepovinné)“, 2 riadky, `maxlength` 200 |
| Skryté | `request_key`, CSRF |
| Náhľad dopadu (12px muted, Alpine CSP alebo server pri `change`) | „Po zápise: zostatok {suma}, stav {Čiastočne uhradená / Uhradená}.“ Server je zdroj pravdy, náhľad je informatívny |
| Pätka | **„Zapísať úhradu“** (`btn-primary`) vpravo, „Zrušiť“ vľavo |

Správanie: úspech zatvorí sheet, obnoví detail alebo riadok dashboardu (faktúra, ktorá sa stala uhradenou, zmizne zo sekcie „Po splatnosti“), flash „Úhrada {suma} bola zapísaná.“ / pri plnej úhrade „Faktúra je uhradená.“ Chyby 422: suma vyššia ako zostatok „Suma je vyššia ako zostatok ({zostatok}). Zadajte menšiu sumu alebo preplatok uveďte v poznámke.“ (D-Discretion), suma <= 0 „Suma musí byť väčšia ako 0.“, dátum v budúcnosti „Dátum úhrady nemôže byť v budúcnosti.“, externá faktúra: tlačidlo sa nevykreslí. Dvojklik: `request_key`.

---

## D. Zoznam faktúr (`invoices/_list.php`)

- Každý riadok dostane `.st-badges` (stav + „Po splatnosti“) vedľa existujúceho odznaku, 12px; sumy zostávajú vpravo. Pri čiastočne uhradenej 12px muted „zostatok {suma}“ pod sumou.
- Filter podľa stavu: `select` „Stav“ (44px) v hlavičke zoznamu, hodnoty: Všetky, Vystavená, Odoslaná, Čiastočne uhradená, Uhradená, Po splatnosti (htmx `hx-get` + `hx-push-url`, funguje aj ako GET formulár). Kombinuje sa s existujúcim filtrom klienta/subjektu. „Odoslaná“ vyberie faktúry v stave `sent`; „Po splatnosti“ vyberie príznak bez ohľadu na stav.
- Prázdny výsledok filtra: viď Copywriting.

---

## E. Dashboard „Po splatnosti“ (PAY-02)

Sekcia v `home/_body.php` **nad** existujúcimi skupinami (je to akčná položka), `app-card p-0`, `h2` „Po splatnosti“ + `badge text-bg-danger` s počtom (rovnaký vzor ako `h2` + badge počtu v skupinách). Zoradené od najstaršej splatnosti. Ak nič nie je po splatnosti, sekcia sa **nezobrazí** (nie prázdny stav, D-23). Rešpektuje filter klienta dashboardu (`DashboardFilter`). Zoznam obmedzený na 10 riadkov, pod ním odkaz „Zobraziť všetky“ na `invoices?status=overdue`.

Riadok `.ov-row` (celý odkaz na detail faktúry, min 64px):
- 1. riadok: „{číslo}“ (16px/600) + „{klient}“ + vpravo zostatok „{suma s menou}“ (16px/600, `tabular-nums`).
- 2. riadok 12px muted: „Splatnosť {dátum} · {n} dní po splatnosti“ + `.st-badge` stavu (Čiastočne uhradená, ak platí).
- Pod odkazom `.ov-actions`: **„Upomienka“** (`btn-outline-secondary`) a **„Úhrada“** (`btn-outline-secondary`), každé 44px, na mobile 50/50. Obe otvoria sheet (A reminder / C) bez opustenia dashboardu a po úspechu obnovia sekciu (`HX-Trigger`).
- Posledná upomienka, ak existuje, 12px muted „Posledná upomienka {dátum}“.

Na dashboarde nie je accent tlačidlo v tejto sekcii (existujúca hlavná akcia dashboardu ostáva jediná). Hranice a farba riadka nezvýrazňujú „po splatnosti“ iba farbou, nesie ju text „n dní po splatnosti“.

---

## F. Nastavenia → Šablóny e-mailov (MAIL-04, D-14 až D-17)

Položka „Šablóny e-mailov“ v `app/Views/more/index.php` v sekcii Nastavenia (riadok 44px, popis „Texty e-mailov s faktúrou a upomienkou“). Trasy `/settings/email-templates` (zoznam), `/settings/email-templates/{id}/edit` (úprava). Vzor a triedy z Phase 6 (Zákonné texty), layout `layouts/app`, mobile first, od >= 768px `max-width: 960px` centrované. Mazanie a pridávanie sa neponúka (seed pokrýva 2 druhy × 4 jazyky = 8 šablón, D-14).

### F1. Zoznam

1. `h1` „Šablóny e-mailov“, 12px muted „Text sa použije pri odoslaní faktúry a upomienky v jazyku dokladu. Pred odoslaním ho môžete upraviť pre konkrétny e-mail.“
2. Dve skupiny `.lt-group`: `h2` „Faktúra“ a `h2` „Upomienka“. V každej `.lt-row` pre jazyky v poradí sk, pl, cs, en: 1. riadok „Slovenčina“ (resp. Poľština, Čeština, Angličtina) + kód jazyka, 2. riadok 12px muted „Predmet: {predmet skrátený} · revízia {n} · upravené {dátum}“. Celý riadok je odkaz na úpravu.
3. Info 12px (`alert alert-secondary`): „Ak šablóna pre jazyk chýba, použije sa anglická.“ (D-14 fallback).

### F2. Úprava (`/edit`)

Karta `.app-card`, zhora:
1. `h1` „Upraviť šablónu“, 12px muted „{Druh} · {jazyk} · revízia {n}“ (`dl.master-kv`: Druh, Jazyk, Revízia, Naposledy upravené).
2. `input` „Predmet“ (povinné, `maxlength` 200, 16px).
3. `.lt-body` `textarea` „Text správy“ (povinné, `maxlength` 4000, `rows` 12, 16px).
4. `.et-vars` „Dostupné premenné“: zoznam `{{number}}` (číslo faktúry), `{{total}}` (suma s menou), `{{balance}}` (zostatok), `{{currency}}`, `{{issue_date}}`, `{{due_date}}`, `{{billing_month}}`, `{{variable_symbol}}`, `{{iban}}`, `{{client_name}}`, `{{entity_name}}`, `{{days_overdue}}`; klepnutie vloží premennú na pozíciu kurzora v poli v ktorom je fokus (Alpine CSP; bez JS sa premenné píšu ručne). 12px hint „Použiť možno iba tieto premenné. Iný text v zátvorkách {{ }} sa neuloží.“
5. `.et-preview` „Náhľad na vzorovej faktúre“: tlačidlo „Zobraziť náhľad“ (`btn-outline-secondary`, htmx POST bez uloženia) vykreslí predmet a telo na vzorových hodnotách (označené 12px „Vzorové hodnoty“); používa tú istú službu vykreslenia ako ostré odoslanie.
6. 12px muted „Zmena sa týka len e-mailov odoslaných po uložení.“
7. Sticky lišta `.master-sticky-actions`: **„Uložiť šablónu“** (`btn-primary`) vpravo, „Späť na zoznam“ (`btn-outline-secondary`) vľavo; počas odoslania `disabled` + spinner.

Odoslanie htmx POST + CSRF s fallbackom na PRG. Úspech: `HX-Location` na zoznam s flashom „Šablóna bola uložená (revízia {n}).“ 422: neznámy placeholder „Šablóna obsahuje neznámu premennú {{x}}. Použite iba zobrazené premenné.“, prázdny predmet/telo „Zadajte predmet a text správy.“ 409 (súbeh `revision`): „Šablónu medzitým niekto upravil. Načítajte stránku znova a zopakujte úpravu.“ Úprava šablóny nie je destruktívna (audit pred/po, bez potvrdenia).

---

## G. Identita odosielateľa pri subjekte (D-27)

Nová karta `.sender-card` vo formulári úpravy subjektu (Nastavenia → Subjekty), `h2` „Odosielanie e-mailov“, 12px muted „Platí pre e-maily s faktúrami a upomienkami tohto subjektu. Hodnoty sa čítajú v čase odoslania.“ Polia (16px, 44px):

| Pole | Špecifikácia |
|------|--------------|
| Meno odosielateľa | `input`, predvolene obchodné meno subjektu |
| Adresa odosielateľa (Od) | `input type="email"`, predvolene `noreply@vostack.dev`; hint 12px „Doména musí patriť k nášmu mailovému serveru: {zoznam povolených domén}.“ Server odmietne inú doménu |
| Odpovedať na | `input type="email"`, predvolene e-mail subjektu |
| Skrytá kópia (BCC) | `textarea` 2 riadky, adresy po jednej na riadok alebo oddelené čiarkou, predvolene e-mail subjektu, prázdne = bez kópie; max 5 adries; hint „Archív odoslaných e-mailov. Prázdne pole = bez skrytej kópie.“ |

Chyby 422: „Adresa odosielateľa musí byť na doméne {domény}, inak ju príjemca môže odmietnuť.“ a „Zadajte platnú e-mailovú adresu.“ Zmena sa audituje (pred/po) v transakcii ako pri ostatných poliach subjektu; karta sa ukladá spolu s existujúcim tlačidlom „Uložiť“ formulára (žiadne druhé primárne tlačidlo). Ukončený subjekt: polia čitateľné, bez zmeny správania (existujúce pravidlá Phase 3).

---

## H. E-mail samotný (šablóna odoslaná klientovi)

- Formát multipart text + HTML (HTML = automatická konverzia textu: odseky, `esc()`, žiadne obrázky ani CSS ani externé zdroje, D-13).
- Predmet vždy obsahuje číslo faktúry (seed: „Faktúra {{number}}“ / „Upomienka: faktúra {{number}}“ vo vlastných jazykoch).
- Prílohy len ASCII názvy; upomienka bez výkazu.
- Seedovaný text: vecný a zdvorilý, bez hrozieb a úrokov, upomienka jedna úroveň. Odporúčaná kostra (SK, ostatné jazyky analogicky, PL a CS predbežné preklady do potvrdenia vlastníkom, **[predpoklad]**):

Faktúra (SK):
```
Dobrý deň,

v prílohe posielame faktúru {{number}} za {{billing_month}} na sumu {{total}} a k nej výkaz práce.
Splatnosť: {{due_date}}. Variabilný symbol: {{variable_symbol}}. IBAN: {{iban}}.

Ďakujeme.
{{entity_name}}
```

Upomienka (SK):
```
Dobrý deň,

dovoľujeme si pripomenúť faktúru {{number}} so splatnosťou {{due_date}}. K dnešnému dňu evidujeme neuhradený zostatok {{balance}} ({{days_overdue}} dní po splatnosti).
Faktúra je v prílohe. Ak ste už platili, správu prosím považujte za bezpredmetnú.

Ďakujeme.
{{entity_name}}
```

---

## Copywriting Contract

Neutrálne vety bez oslovenia v UI, vecný tón. Texty iba cez `lang()`.

| Element | Copy |
|---------|------|
| Primary CTA (odoslanie) | Odoslať klientovi |
| Primary CTA (opätovné odoslanie, sheet) | Odoslať |
| Primary CTA (upomienka, sheet) | Poslať upomienku |
| Primary CTA (úhrada) | Zapísať úhradu |
| Primary CTA (šablóny) | Uložiť šablónu |
| Sekundárne | Odoslať znova, Poslať upomienku, Upomienka, Úhrada, Označiť ako odoslanú, Stornovať, Zrušiť označenie, Zobraziť náhľad, Skúsiť znova, Pridať, Odobrať, Zrušiť, Späť, Späť na zoznam, Zobraziť všetky |
| Empty state heading (história odoslaní) | Zatiaľ neodoslané |
| Empty state body (história odoslaní) | Faktúra ešte nebola odoslaná klientovi. Použite tlačidlo „Odoslať klientovi“. |
| Empty state heading (úhrady) | Zatiaľ žiadna úhrada |
| Empty state body (úhrady) | Keď klient zaplatí, zapíšte úhradu tlačidlom „Zapísať úhradu“. Zostatok sa prepočíta. |
| Empty state (filter stavu bez výsledku) | Žiadne faktúry v tomto stave. Zmeňte filter alebo obdobie. |
| Empty state (dashboard Po splatnosti) | sekcia sa nezobrazí (bez textu) |
| Error (SMTP zlyhal) | E-mail sa nepodarilo odoslať. Faktúra aj PDF ostali bez zmeny. Skúste to znova. |
| Error (prílohy) | Prílohy sa nepodarilo načítať. E-mail sa neodoslal. Skúste to znova; ak chyba pretrvá, obráťte sa na správcu. |
| Error (neplatná adresa) | Zadajte platnú e-mailovú adresu. |
| Error (žiadny adresát) | Zadajte aspoň jednu adresu v poli Komu. |
| Error (príliš veľa adries) | Najviac 10 adries v jednom e-maile. |
| Error (predmet/telo prázdne) | Zadajte predmet a text správy. |
| Error (suma > zostatok) | Suma je vyššia ako zostatok ({zostatok}). Zadajte menšiu sumu alebo preplatok uveďte v poznámke. |
| Error (suma <= 0) | Suma musí byť väčšia ako 0. |
| Error (dátum v budúcnosti) | Dátum úhrady nemôže byť v budúcnosti. |
| Error (storno bez dôvodu) | Zadajte dôvod storna. |
| Error (neznáma premenná) | Šablóna obsahuje neznámu premennú {{x}}. Použite iba zobrazené premenné. |
| Error (doména odosielateľa) | Adresa odosielateľa musí byť na doméne {domény}, inak ju príjemca môže odmietnuť. |
| Error (súbeh šablóny, 409) | Šablónu medzitým niekto upravil. Načítajte stránku znova a zopakujte úpravu. |
| Warning (adresa mimo klienta) | Pozor: žiadna z adries nie je fakturačná adresa klienta. Faktúra sa odošle, ale stav ostane „Vystavená“, kým ju neoznačíte ako odoslanú. |
| Info (po vystavení) | Faktúra {číslo} je vystavená. Odošlite ju klientovi. |
| Hint (šablóna vs. e-mail) | Úprava platí len pre tento e-mail. Šablónu zmeníte v Nastavenia, Šablóny e-mailov. |
| Hint (úhrada, prázdna suma) | Nechajte prázdne pre úhradu celého zostatku ({zostatok}). |
| Hint (ručné označenie) | Použite, ak ste faktúru odovzdali inak ako e-mailom z aplikácie. E-mail sa neodošle. |
| Success flash | E-mail bol odoslaný na {adresy}. · E-mail bol zaznamenaný (testovacie prostredie, nič sa neodoslalo). · Úhrada {suma} bola zapísaná. · Faktúra je uhradená. · Úhrada bola stornovaná. · Faktúra je označená ako odoslaná. · Šablóna bola uložená (revízia {n}). · Nastavenia odosielania boli uložené. |
| Destructive confirmation (storno úhrady) | Stornovať úhradu: Úhrada sa nevymaže. Ostane v zozname ako stornovaná a zostatok sa prepočíta. Povinný dôvod. Tlačidlo „Stornovať úhradu“ |
| Destructive confirmation (storno ručného označenia) | Zrušiť označenie: Označenie sa nevymaže, ostane v histórii ako zrušené a stav faktúry sa prepočíta. Povinný dôvod. Tlačidlo „Zrušiť označenie“ |
| Destructive confirmation (odoslanie e-mailu) | bez dodatočného potvrdenia: sheet s predvyplneným obsahom je samotné potvrdenie (jedno klepnutie na tlačidlo v sheete, D-17) |

Popisky stavov: Vystavená, Odoslaná, Čiastočne uhradená, Uhradená, Po splatnosti · {n} deň/dni/dní, Externá. Typy záznamov histórie: Odoslanie, Opätovné odoslanie, Upomienka, Ručne označená ako odoslaná.

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid, disabled, loading (`disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva). Všetko funguje aj ako klasický POST + PRG bez JS; Alpine CSP len pre pridávanie/odoberanie adries, vkladanie premenných, náhľad dopadu úhrady a podmienený alert adresy.
- **Sheety**: htmx fragment do jedného cieľa, otvorenie Bootstrap offcanvas-bottom (mobil) / modál (PC); po úspechu `HX-Trigger` obnoví relevantnú sekciu (detail, riadok dashboardu), nie celú stránku; Esc a „Zrušiť“ zatvárajú, počas odosielania sa nedá zavrieť.
- **Idempotencia**: každý POST odoslania a úhrady nesie `request_key`; opakovaný POST vráti pôvodný výsledok bez duplicitného zápisu a bez druhého e-mailu.
- **E-mail mimo transakcie**: odoslanie je samostatný request po commite; zlyhanie nemení faktúru ani nezapisuje `invoice_sends` (UI to hovorí výslovne).
- **Stav je odvodený**: UI nikdy neposiela „stav“; po akcii sa stav načíta znova z `invoice_status_v`. „Po splatnosti“ sa počíta z `Clock` (Europe/Bratislava), nie z času prehliadača.
- **Nemennosť**: PDF prílohy sú bajty z `invoice_documents` (sha256 overené), žiadne tlačidlo na úpravu ani regeneráciu; úhrady a označenia sa nemažú ani neupravujú, iba storno s dôvodom.
- **Mobil jednou rukou**: primárna akcia v sticky pätke sheetu (spodná polovica), 44px ciele, 16px písmo (iOS zoom), bez horizontálneho posúvania, `textarea` na plnú šírku; opätovné odoslanie z iPhonu je najviac 2 klepnutia (Odoslať znova, Odoslať).
- **Staging**: transport `log` sa v UI správa rovnako ako úspech (D-02), odlíšený textom flashu a poľom „transport“ v detaile záznamu histórie.
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; prečiarknutie `.pay-void` a všetky rozmery iba triedami v `app.css`.
- **Prístupnosť**: `label for`, `aria-describedby` pri chybách, `role="alert" aria-live="polite"` pre flash, focus na prvé neplatné pole, stavy vždy textom, kontrast min 4.5:1.
- **Audit**: odoslania, úhrady, storná, označenia, úpravy šablón a nastavení odosielateľa zapisuje služba do `audit_log` v tej istej transakcii; v UI sa denník nezobrazuje (AUD-02 je Phase 11).
- **Offline**: stránky ani sheety sa nekešujú (SW nikdy nekešuje autentifikované HTML).
- **Mimo rozsahu**: stav „Stornovaná“ (Phase 9), „uhradené v mene subjektu“ a prehľady (Phase 8/11), automatické upomienky a úroky, spracovanie bounce, balík pre účtovníčku (Phase 11).

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Rozloženie panelov odoslania | Jeden sheet `.dl-sheet` pre `invoice`/`resend`/`reminder`, bottom sheet na mobile, modál 640px na PC |
| Prvé odoslanie po vystavení | Alert + tlačidlo na detaile, bez automatického otvorenia sheetu |
| Správanie pri preplatení | Server odmietne sumu > zostatok, správa navádza na poznámku |
| Limit adries | Spolu max 10 (Komu + CC), BCC max 5; predmet 200, telo 4000 znakov |
| Telo v `invoice_sends` | Predvolene iba predmet a hash tela (telo je v BCC archíve); UI v histórii telo nezobrazuje |
| Resend na inú adresu | Zapisuje sa ako `resend` s adresátmi; odznak „Iná adresa“ |
| Odznak „po splatnosti“ | Samostatný `text-bg-danger` odznak navyše k stavu, text s počtom dní |
| Zdôraznenie upomienky pri „po splatnosti“ | Iba odznakom a dňami v riadku; tlačidlo „Upomienka“ ostáva neutrálne (červená len pre storno) |
| Zoznam „Po splatnosti“ na dashboarde | Nad skupinami, max 10 riadkov + odkaz na zoznam s filtrom |
| Šablóny e-mailov | 2 druhy × 4 jazyky, bez pridávania a mazania, premenné klikateľné, náhľad na vzorových hodnotách |
| Texty tlačidiel a toastov | Podľa tabuľky Copywriting |

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
