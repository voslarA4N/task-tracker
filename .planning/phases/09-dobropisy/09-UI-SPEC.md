---
phase: 9
slug: dobropisy
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-31
reviewed_at: 2026-10-31
---

# Phase 9 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 9 (Dobropisy, INV-07, INV-10, stav „stornovaná“ z INV-06). Dedí tokeny z `02-UI-SPEC.md` až `08-UI-SPEC.md` a nemení ich (spacing násobky 4, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk UI je slovenčina, texty iba cez `lang()` (nový súbor `app/Language/sk/CreditNotes.php` + rovnaké kľúče v `en`; doplnky do `Invoices.php`, `InvoicePdf.php` vo všetkých 4 jazykoch, `Deliveries.php`/e-mailové šablóny). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build (v tejto fáze sa nepoužíva), htmx `allowEval=false`.
> Zdroje: 09-CONTEXT.md (D-01 až D-20), 08-UI-SPEC.md, 07-UI-SPEC.md, 06-UI-SPEC.md, CLAUDE.md, existujúce `app/Views/invoices/show.php`, `_list.php`, `invoicing/_preview.php`, `_confirm_sheet.php`, `app/Views/pdf/invoice_inv2.php`, `public/assets/css/app.css`. Vlastník nechce interaktívnu diskusiu; kroky mimo CONTEXT sú označené **[predpoklad]** s dôvodom.
> Rozsah: (A) vstup „Vystaviť dobropis“ na detaile faktúry, (B) stránka náhľadu dobropisu (dôvod, typ, dátum, záporné riadky), (C) potvrdzovací sheet, (D) detail pôvodnej faktúry (stornovaná), (E) detail dobropisu, (F) zoznam faktúr a dashboard, (G) PDF dobropisu, (H) odoslanie a e-mailová šablóna, (I) uvoľnené časti po vystavení.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 (nepoužité) |
| Icon library | žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`); stav nikdy iba ikonou |
| Font | systémový stack z `app.css`, čísla `tabular-nums`; PDF font podľa Phase 6 (latin-ext) |

Reuse (neprepisovať): `.app-card`, `.app-main`, `.work-wide`, `.master-kv`, `.master-sticky-actions`, `.inv-summary`, `.inv-warn`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `.dl-sheet` (offcanvas-bottom na mobile, modál na PC, `#sheet` cieľ), `.st-badges` + `.st-badge`, `.fx-rate`, `.fx-meta`, `.fx-sub`, `HtmxCsrf`, `responseHandling` pre 422 a 409, `fragments/alert.php`, `.htmx-indicator` + `.spinner-border-sm`.

Nové triedy v `app.css` (nová verzia súboru v názve cez `asset_url`; prefix `.cn-*`), bez nových farieb a veľkostí:

| Trieda | Účel |
|--------|------|
| `.cn-banner` | pruh na detaile pôvodnej faktúry „Stornovaná dobropisom č. …“ a na detaile dobropisu „Opravuje faktúru č. …“ (derivát `.app-card`, plná šírka, ľavý okraj 4px `--bs-dark`/neutrálny, 16px padding, `mb-3`; nie accent) |
| `.cn-lines` | zoznam záporných riadkov v náhľade a detaile (rovnaká štruktúra ako existujúce riadky položiek, sumy vpravo, `tabular-nums`) |
| `.cn-total` | súčtový riadok dobropisu (Display 28px/600, `tabular-nums`) |
| `.cn-check` | kontrolný riadok „Dobropis + pôvodná faktúra = 0,00 €“ (12px muted) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 8 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera medzi `.cn-banner` nadpisom a odkazom, hint pod poľom |
| sm | 8px | medzi tlačidlami, medzi odznakmi |
| md | 16px | padding `.cn-banner`, `mb-3` polí, okraje na mobile |
| lg | 24px | medzi formulárom (dôvod/typ/dátum) a zoznamom záporných riadkov |
| xl | 32px | medzi kartou „Údaje“ a „Úhrady“ (existujúce) |
| 2xl | 48px | horné odsadenie prázdnych stavov (existujúce) |
| 3xl | 64px | výška sticky lišty a spodná rezerva (existujúce) |

Exceptions:
- Dotykové ciele min **44px**: tlačidlo „Vystaviť dobropis“, `select` typu opravy, `input type="date"`, `textarea` dôvodu, odkazy v `.cn-banner` (`d-inline-block py-2`), tlačidlá sheetu.
- Žiadne horizontálne posúvanie: čísla dokladov a popisy riadkov majú `overflow-wrap:anywhere`; žiadne tabuľky v UI (zoznamy riadkov a `dl.master-kv`).

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 až 8, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, riadky zoznamov, text banneru) | 16px | 400 | 1.5 |
| Label / meta (popisky, hint, `.cn-check`, `.fx-meta`, odznaky, varovania, `invalid-feedback`) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2`, nadpis sheetu, číslo v banneri) | 20px | 600 | 1.2 |
| Display (`.cn-total`, Celkom v hlavičke detailu) | 28px | 600 | 1.2 |

Hrúbka 600 iba pre: `h1`/`h2`, číslo dokladu v `.cn-banner`, `.cn-total`. Všetko ostatné 400.

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, `.cn-banner`, riadky, sheet, sticky pätka |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | potvrdenie vystavenia dobropisu (nevratné storno faktúry) a chybové stavy |

Accent reserved for (výlučne, jedna hlavná akcia na obrazovku):
1. Stránka náhľadu dobropisu: primárne tlačidlo **Pokračovať** (sticky pätka, `disabled`, kým nie je dôvod a typ platný)
2. Detail dobropisu neodoslaného: **Odoslať klientovi** (existujúce správanie Phase 7)
3. Aktívna položka navigácie (existujúce)
4. Focus ring polí a tlačidiel
5. Textové odkazy v tele (odkaz na pôvodnú faktúru, na dobropis, na uvoľnené časti)
6. Zaškrtnuté checkbox/radio (Bootstrap predvolené)

Accent sa **nepoužíva** pre odznaky, banner, upozornenia ani zápornú sumu. **Záporné sumy nie sú červené** (červená = chyba/destrukcia; záporné znamienko je súčasť formátu `MoneyFormat`, nie farby). Na detaile faktúry je „Vystaviť dobropis“ vždy `btn-outline-secondary` (nikdy accent, aby nekonkurovalo „Zapísať úhradu“/„Odoslať klientovi“; vedomé rozhodnutie, storno je zriedkavá akcia). Potvrdzovacie tlačidlo v sheete je **`btn-danger`** **[predpoklad]**: akcia je nevratná a stornuje celý doklad, rovnako ako storno úhrady v 07-UI-SPEC; „Pokračovať“ pred ním je accent.

### Odznaky stavu (vždy text, nikdy iba farba)

Dopĺňa tabuľku z 07-UI-SPEC (rezervovaný `cancelled` sa od tejto fázy vykresľuje):

| Doklad / stav | Trieda | Text |
|---------------|--------|------|
| faktúra `cancelled` | `text-bg-dark` | Stornovaná |
| dobropis (typ, vždy zobrazený ako prvý odznak) | `text-bg-info` | Dobropis |
| dobropis `issued` | `text-bg-secondary` | Vystavený |
| dobropis `sent` | `text-bg-light border` | Odoslaný |

Pravidlá: priorita stavu faktúry `cancelled` > `paid` > `partially_paid` > `sent` > `issued` (D-15). Stornovaná faktúra **nikdy** nemá odznak „Po splatnosti“ (ani pri `overdue` pred storno), nikdy zostatok ani výzvu na úhradu. Dobropis nikdy nemá „Po splatnosti“, „Čiastočne uhradená“ ani „Uhradená“. Odznak „Dobropis“ a „Stornovaná“ sa nikdy nepoužijú pre `external` faktúru (tá sa nedá stornovať, D-14).

---

## Trasy a vstupy

| Obrazovka | Trasa | Poznámka |
|-----------|-------|----------|
| Stránka náhľadu dobropisu | `GET /invoices/{id}/credit-note` (+ `hx-get` fragment `GET /fragments/credit-note-preview/{id}` pre prekreslenie náhľadu po zmene dátumu) | iba vlastná (`origin = issued`), `type = invoice`, nestornovaná; inak 404/redirect s flash (E3) |
| Potvrdenie (sheet) | `GET /invoices/{id}/credit-note/confirm` do `#sheet` | `.dl-sheet`, rovnaký mechanizmus ako `_confirm_sheet` Phase 5 |
| Vystavenie | `POST /invoices/{id}/credit-note` | CSRF, skryté `preview_token`, `request_key` (uuid), `reason`, `correction_type`, `issue_date`; PRG na detail dobropisu |
| Odoslanie dobropisu | existujúce `GET/POST /invoices/{id}/send`, `/mark-sent`, `/resend` na id dobropisu | bez nových trás, šablóna `credit_note` |

Server nikdy neprijíma sumy, riadky, kurz ani strany z POST; všetko sa berie z uloženej pôvodnej faktúry (D-05, D-06, D-11).

---

## A. Vstup „Vystaviť dobropis“ na detaile faktúry (INV-07)

Umiestnenie: `invoices/show.php`, **posledné** tlačidlo v riadku akcií (za „Faktúra PDF“/„Výkaz PDF“), `btn btn-outline-secondary btn-touch`, text „Vystaviť dobropis“, odkaz `href="/invoices/{id}/credit-note"` (funguje bez JS, bežná navigácia, nie sheet; náhľad je dlhý).

| Podmienka | Zobrazenie |
|-----------|------------|
| `origin = issued`, `type = invoice`, nestornovaná (aj uhradená, aj ukončený subjekt) | tlačidlo viditeľné |
| `origin = external` (D-14) | tlačidlo sa **nevykresľuje**, bez vysvetlenia |
| už stornovaná | tlačidlo sa nevykresľuje, namiesto toho `.cn-banner` (D) |
| detail dobropisu | tlačidlo sa nevykresľuje (dobropis sa nedá stornovať, D-02) |

Na mobile `w-100` v stĺpci akcií pod ostatnými, odstup sm; na PC v riadku akcií vpravo.

---

## B. Stránka náhľadu dobropisu (`GET /invoices/{id}/credit-note`)

Jedna stránka, jeden formulár (`#cn-form`), jedno primárne tlačidlo. Layout `.work-wide`, jeden stĺpec na mobile.

Poradie zhora nadol:

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | Odkaz „Späť na faktúru“ | `d-inline-block py-2`, vedie na detail pôvodnej faktúry |
| 2 | `h1` 20px | „Vystaviť dobropis“; pod ním 12px muted „Dobropis opravuje faktúru {číslo} zo dňa {dátum}. Stornuje celú faktúru, čiastočná oprava nie je možná.“ |
| 3 | Varovanie pri úhradách (podmienečné, D-17) | `alert alert-warning` (12px) „Prijaté úhrady {suma}. Vrátenie peňazí klientovi sa rieši mimo aplikácie.“ Zobrazí sa iba ak existujú nestornované úhrady; suma v mene faktúry cez `MoneyFormat` |
| 4 | Varovanie o uvoľnení častí | `alert alert-secondary` (12px) „Po vystavení sa časti z tejto faktúry (počet {n}) uvoľnia a dajú sa vyfakturovať znova.“ |
| 5 | Pole „Typ opravy“ | `label` + `select.form-select` (44px, 16px), povinné, prvá voľba `disabled selected` „Vyberte typ opravy“; voľby v poradí: Chybné údaje odberateľa alebo dodávateľa · Chybná suma, hodiny alebo sadzba · Chybný kurz · Zrušená zákazka alebo plnenie · Iný dôvod (hodnoty `data_error`, `amount_error`, `fx_error`, `order_cancelled`, `other`). Voľba „Chybný kurz“ sa **nezobrazuje** pri faktúre v mene subjektu **[predpoklad]** (nemá kurz) |
| 6 | Pole „Dôvod opravy“ | `textarea` (`rows` 4, 16px, `maxlength` 500), povinné; 12px počítadlo nie je (bez JS) a hint „Dôvod sa zobrazí na dobropise. Najviac 500 znakov.“ |
| 7 | Pole „Dátum vystavenia“ | `input type="date"`, predvolene dnes (`Clock`, Europe/Bratislava), `min` = dátum vystavenia pôvodnej faktúry; zmena spúšťa `hx-get` prekreslenie náhľadu (číslo, obdobie). Spätný dátum: 12px `alert alert-warning` rovnakým textom ako pri faktúre (existujúci kľúč z Phase 5). Skorší ako pôvodná: 422 |
| 8 | Karta „Dobropis“ (read-only, `dl.master-kv`) | Číslo (náhľad čísla z radu dobropisov, napr. „DB-202611001“, 12px muted „Číslo sa pridelí pri vystavení.“; náhľad nič nerezervuje), Opravuje faktúru (číslo, dátum, odkaz), Klient, Subjekt, Mena, Dátum dodania (z pôvodnej, nemenné), Dátum splatnosti (= dátum vystavenia, 12px muted „Dobropis sa neuhrádza.“). Pri cudzej mene dva riadky kurzu rovnako ako 08-UI-SPEC D, ale s hintom „Kurz sa preberá z pôvodnej faktúry.“ a **bez** `<details>` na ručné zadanie, bez „Skúsiť znova“ |
| 9 | `.cn-lines` | h2 „Položky dobropisu“, záporné riadky pôvodnej faktúry: popis (`overflow-wrap:anywhere`), pod ním 12px muted `{hodiny} h × {sadzba}` (znamienko podľa rozhodnutia plánovača, riadok musí zodpovedať CHECK), suma vpravo záporná cez `MoneyFormat` (napr. „−1 080,00 €“). Maximálne 100 riadkov viditeľných, ďalšie za „Zobraziť všetky riadky (n)“ **[predpoklad]** natívny `<details>`, bez JS |
| 10 | Súhrn `.inv-summary` | `.cn-total` „Spolu −1 080,00 €“; pri cudzej mene `.fx-sub` „Spolu v {mena subjektu} −4 251,20 zł“ pod ním; `.cn-check` „Dobropis a pôvodná faktúra spolu: 0,00 €“ (kontrola; pri nenulovom súčte, čo je chyba, blokuje vystavenie a zobrazí `alert-danger`) |
| 11 | Sticky pätka `.master-sticky-actions` | vľavo „Zrušiť“ (`btn-outline-secondary`, späť na detail), vpravo **„Pokračovať“** (`btn-primary`, `disabled`, kým nie je platný typ a neprázdny dôvod; po splnení `hx-get` otvorí sheet C, bez JS fallback odkaz na `/invoices/{id}/credit-note/confirm` ako samostatná stránka) |

### Stavy

- **Loading** (zmena dátumu): `.htmx-indicator` v `#cn-preview` 12px „Načítavam náhľad…“ + `.spinner-border-sm`, „Pokračovať“ `disabled` počas requestu (`hx-disabled-elt`).
- **Validácia** (422): `is-invalid` + `invalid-feedback`, focus na prvé neplatné pole, `aria-describedby`.
- **Už stornovaná** (súbeh, druhý dobropis): 409, `alert alert-warning` „Táto faktúra už bola stornovaná dobropisom {číslo}.“ s odkazom na dobropis; formulár sa nevykreslí.
- **Nedá sa stornovať** (externá, dobropis): `alert alert-warning` „Pre tento doklad sa dobropis nedá vystaviť.“ a odkaz späť.
- **Zastaraný náhľad** (token nesedí): existujúci `alert alert-warning` „Údaje sa medzitým zmenili. Skontrolujte náhľad a vystavte dobropis znova.“ **[predpoklad]** rovnaký vzor ako Phase 5.
- **Prázdny stav** neexistuje (faktúra má vždy aspoň jeden riadok).
- Texty `hx-get`/PRG: pri chybe validácie sa zachovajú zadané hodnoty (`old()`).

---

## C. Potvrdzovací sheet (D-03, D-20, nevratná akcia)

`.dl-sheet` (offcanvas-bottom na mobile, modál na PC), cieľ `#sheet`. Obsah zhora nadol:

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | `h2` 20px | „Vystaviť dobropis“ |
| 2 | `dl.master-kv` | „Opravuje faktúru“ ({číslo} · {dátum}), „Typ opravy“ (preložený názov), „Dôvod“ (text, zalomený), „Dátum vystavenia“, „Spolu“ (záporná suma, v mene faktúry; pri cudzej mene aj „Spolu v {mena subjektu}“ a „Kurz“ rovnako ako 08-UI-SPEC C) |
| 3 | Výstraha 12px `alert alert-warning` | „Dobropis je nemenný doklad. Faktúra {číslo} sa označí ako stornovaná a jej časti sa uvoľnia. Túto akciu nie je možné vrátiť.“ |
| 4 | Skryté polia | CSRF, `preview_token`, `request_key`, `reason`, `correction_type`, `issue_date` |
| 5 | Pätka (sticky) | vľavo „Späť“ (`btn-outline-secondary`), vpravo **„Vystaviť dobropis“** (`btn-danger`, 44px); po kliku `disabled` + `.spinner-border-sm`, dvojklik nič nepošle dvakrát (`request_key`) |

Úspech: PRG na detail dobropisu, flash „Dobropis {číslo} bol vystavený.“ Pod ním (len na detaile ako jednorazový `alert alert-secondary` 12px, rovnaký flash): „Uvoľnené časti: {n}. Môžete ich vyfakturovať znova.“ s odkazom „Prejsť na uvoľnené časti“ (I). Ak odoslanie ešte nebolo, hlavná akcia na detaile je „Odoslať klientovi“ (accent), rovnako ako po vystavení faktúry (Phase 7 A3).

Chyby pri POST: 422 `credit_invalid` (typ/dôvod/dátum, texty pri poliach), 409 `credit_exists` (E3), 409 `credit_stale` (zastaraný náhľad), 422 `credit_not_allowed` (externá/dobropis), 500 `credit_failed` `alert alert-danger` „Dobropis sa nepodarilo vystaviť. Nič sa neuložilo, skúste to znova.“ (transakcia sa vrátila celá, čísla bez medzier).

---

## D. Detail pôvodnej faktúry (`invoices/show.php`) — stornovaná (D-15, D-16)

- V `.st-badges` namiesto stavu platby odznak `text-bg-dark` „Stornovaná“ (priorita D-15). Žiadny „Po splatnosti“.
- Nad hlavičkou karty (pod odkazom „Späť“) **`.cn-banner`**: riadok 16px „Stornovaná dobropisom č. **{číslo}** zo dňa {dátum}.“ + odkaz „Zobraziť dobropis“ (accent text, `d-inline-block py-2`). `role="status"`.
- Hlavička (Celkom 28px) ostáva v pôvodnej (kladnej) sume; pod ňou 12px muted „Po zohľadnení dobropisu: 0,00 €“ **[predpoklad]** (jasne ukáže efekt storna bez zmeny údajov).
- Sekcia „Zostatok“, tlačidlá „Zapísať úhradu“, „Poslať upomienku“ sa **nevykresľujú**; sekcia „Úhrady“ (`.pay-list`) ostáva zobrazená iba na čítanie vrátane stornovaných záznamov, bez tlačidla „Stornovať“ (nové úhrady ani storno úhrady po storne faktúry nie sú možné, D-17). 12px muted pod zoznamom: „Faktúra je stornovaná. Úhrady sa už nedajú meniť.“ (iba ak úhrady existujú).
- Zostáva: „Faktúra PDF“, „Výkaz PDF“ (uložené bajty bez pečiatky, D-16), história odoslaní, „Odoslať znova“ je **dostupné** (`btn-outline-secondary`), aby sa dal klientovi dohľadať pôvodný doklad **[predpoklad]**.
- Nikdy sa neukáže „Vystaviť dobropis“.

---

## E. Detail dobropisu (`invoices/show.php` pre `type = credit_note`)

Rovnaká šablóna, vetva pre dobropis; žiadna nová stránka.

### E1. Hlavička

- `h1` číslo dobropisu. `.st-badges`: „Dobropis“ (`text-bg-info`) + „Vystavený“ alebo „Odoslaný“. 12px muted klient · dátum vystavenia.
- `.cn-banner` hneď pod odkazom „Späť“: „Opravuje faktúru č. **{číslo}** zo dňa {dátum}.“ + odkaz „Zobraziť pôvodnú faktúru“ (accent text). Ak je pôvodná faktúra v ukončenom subjekte, nič iné sa nemení (ENT-04).
- Celkom: Display 28px záporná suma v mene dokladu; `.fx-sub` „Spolu v {mena subjektu} …“ pri cudzej mene.

### E2. Karta „Údaje“ (`dl.master-kv`)

Číslo · Opravuje faktúru (číslo, dátum, odkaz) · **Typ opravy** (preložený) · **Dôvod** (celý text, zalomený) · Rozsah opravy „Celé storno“ · Klient · Subjekt · Mena · Dátum vystavenia · Dátum dodania (z pôvodnej) · Fakturačný mesiac (z pôvodnej) · Dátum splatnosti nie je zobrazený (dobropis sa neuhrádza) **[predpoklad]** · pri cudzej mene Kurz, Zdroj kurzu, Dátum kurzu (rovnaké riadky ako 08-UI-SPEC D, hodnoty z kópie údajov pôvodnej faktúry). Zoznam záporných riadkov `.cn-lines`. Údaje zo snapshotu dobropisu (bloky `entity`, `client`, `bank`, `fx`, `correction`), nikdy zo živých tabuliek.

### E3. Akcie a čo sa nevykresľuje

| Akcia | Zobrazenie |
|-------|------------|
| Odoslať klientovi | `btn-primary` (accent), ak nebol odoslaný; „Označiť ako odoslanú“ `btn-outline-secondary` (Phase 7) |
| Odoslať znova | `btn-outline-secondary` po odoslaní |
| Dobropis PDF | `btn-outline-secondary`, názov „Dobropis_{číslo}.pdf“ (ASCII) |
| Výkaz PDF | **nevykresľuje sa** (výkaz sa k dobropisu negeneruje, D-18) |
| Zapísať úhradu, Poslať upomienku, sekcia Zostatok a Úhrady | **nevykresľujú sa** |
| Vystaviť dobropis | nevykresľuje sa |

---

## F. Zoznam faktúr a dashboard (`invoices/_list.php`, dashboard)

- Riadok dobropisu: v `.st-badges` „Dobropis“ + („Vystavený“/„Odoslaný“); číslo „DB-…“; suma vpravo záporná cez `MoneyFormat` (nie červená); pod číslom 12px muted „opravuje {číslo pôvodnej}“. Pri cudzej mene `.fx-sub` so zápornou sumou v mene subjektu.
- Riadok stornovanej faktúry: odznak „Stornovaná“ (`text-bg-dark`), suma ostáva kladná, pod ňou 12px muted „dobropis {číslo}“. Bez „zostatok“ a bez „Po splatnosti“.
- Filter „Stav“ (existujúci `select`, 44px) dostane voľby **Stornovaná** a **Dobropis** (hodnota `credit_note` = riadky `type = credit_note`); „Všetky“ zahŕňa oboje. Filter „Po splatnosti“ stornované a dobropisy nikdy nevyberie.
- Prázdny výsledok: existujúci text prázdneho filtra (Phase 7).
- Dashboard: „Po splatnosti“, „Neuhradené“ a ďalšie sumy **vylučujú** stornované faktúry a dobropisy z vlastného počtu (D-15). Sumy obratu/limitov sa nemenia tu (Phase 10). **Nevyfakturované hodiny** po uvoľnení častí sa navýšia automaticky (D-13); nový text netreba.

---

## G. PDF dobropisu (D-18; ROADMAP kritérium 4)

Nová šablóna `app/Views/pdf/credit_note_cn1.php` (verzia šablóny `cn-1`, renderer `MpdfCreditNoteCn1Renderer`) vychádza vizuálne z `invoice_inv2.php` (rovnaké fonty, rozloženie hlavičky strán, veľkosti a tabuľka riadkov; žiadne nové veľkosti, žiadne nové farby; PDF je čiernobiele s existujúcimi sivými). Všetko z uloženého snapshotu dobropisu (`labels` uložené v snapshote, nikdy zo živých `lang()` pri regenerácii, regenerácia neexistuje).

Zhora nadol:

| Blok | Obsah |
|------|-------|
| Nadpis dokladu | podľa jazyka dokladu: SK „Dobropis“, PL „Faktura korygująca“, CS „Opravný daňový doklad“, EN „Credit note“; vedľa/pod číslo dobropisu (rovnaká typografia ako nadpis faktúry) |
| Strany | dodávateľ a odberateľ zo snapshotu pôvodnej faktúry (rovnaké bloky ako faktúra), bankový účet sa **neuvádza** pre platbu; **bez QR platby** a bez bloku „Spôsob úhrady“ |
| Metadáta | Dátum vystavenia dobropisu, Dátum dodania (z pôvodnej), **Oprava k faktúre č. {číslo} zo dňa {dátum}** (zvýraznené tučne, samostatný riadok pod nadpisom), Typ opravy (preložený do jazyka dokladu), Dôvod opravy (celý text, zalomený) |
| Riadky | záporné riadky s rovnakými stĺpcami ako faktúra (popis, množstvo/hodiny, sadzba, suma), sumy so znamienkom mínus cez `PdfFormat` |
| Súčet | „Spolu“ záporná suma (bold, rovnaký pravý okraj ako faktúra); pri cudzej mene pod ním rovnaký blok kurzu ako 08-UI-SPEC E (`rate_line`, `date_line`, „Spolu v {mena subjektu}“ záporná), hodnoty z kurzu pôvodnej faktúry |
| Právne texty | z profilu v snapshote (rovnaká revízia ako pôvodná, ak sa dá, rozhodne plán); text oslobodenia/DPH poznámka ako na faktúre |
| Pätka | číslo strany, rovnaká ako faktúra |

Nový preklad popiskov do `SnapshotBuilder::LABEL_KEYS` a `app/Language/{sk,pl,cs,en}/InvoicePdf.php` (povinné vo všetkých 4 jazykoch, inak PDF vráti surový kľúč):

| Kľúč | SK | PL | CS | EN |
|------|----|----|----|----|
| `cnTitle` | Dobropis | Faktura korygująca | Opravný daňový doklad | Credit note |
| `cnCorrects` | Oprava k faktúre č. | Korekta do faktury nr | Oprava k faktuře č. | Correction of invoice no. |
| `cnOfDate` | zo dňa | z dnia | ze dne | dated |
| `cnType` | Typ opravy | Rodzaj korekty | Typ opravy | Correction type |
| `cnReason` | Dôvod opravy | Przyczyna korekty | Důvod opravy | Reason for correction |
| `cnScopeFull` | Celé storno pôvodnej faktúry | Korekta w całości | Úplné storno původní faktury | Full cancellation of the original invoice |
| `cnTypeDataError` | Chybné údaje | Błędne dane | Chybné údaje | Incorrect details |
| `cnTypeAmountError` | Chybná suma, hodiny alebo sadzba | Błędna kwota, godziny lub stawka | Chybná částka, hodiny nebo sazba | Incorrect amount, hours or rate |
| `cnTypeFxError` | Chybný kurz | Błędny kurs | Chybný kurz | Incorrect exchange rate |
| `cnTypeOrderCancelled` | Zrušená zákazka alebo plnenie | Anulowane zlecenie lub świadczenie | Zrušená zakázka nebo plnění | Cancelled order or service |
| `cnTypeOther` | Iný dôvod | Inna przyczyna | Jiný důvod | Other |

**[predpoklad]** PL, CS a EN preklady sú predbežné do potvrdenia účtovníčkou/vlastníkom (rovnako ako Phase 7 a 8; finálne znenie nadpisov ide do `lang()`/legal texts, D-18). Názov súboru na stiahnutie a v prílohe: `Dobropis_{číslo}.pdf` (SK), `Faktura_korygujaca_{číslo}.pdf` (PL), `Opravny_doklad_{číslo}.pdf` (CS), `Credit_note_{číslo}.pdf` (EN), ASCII, rovnaké pravidlo ako Phase 7 D-07.

---

## H. Odoslanie a e-mailová šablóna (D-19)

- Sheet odoslania `.dl-sheet` (Phase 7 A) sa znovupoužíva bez zmeny rozloženia; nadpis „Odoslať dobropis“ / „Odoslať dobropis znova“, 12px muted „{číslo dobropisu} · {klient} · jazyk e-mailu: {sk/pl/cs/en}“. Adresáti predvyplnení `invoice_email` + `cc_emails` zo snapshotu pôvodnej faktúry.
- Príloha `.dl-attach`: **iba** „{Názov}_{číslo}.pdf“ + 12px muted veľkosť a prvých 12 znakov SHA-256; žiadny výkaz. Upozornenie na nezhodu adries a ručné označenie ako odoslanej ako pri faktúre.
- Primárne tlačidlo sheetu: „Odoslať klientovi“ (rovnaký text, rovnaké správanie).
- Nastavenia → Šablóny e-mailov (`settings/email_templates`): nová položka „Dobropis“ vedľa „Faktúra“ a „Upomienka“ pre každý jazyk; rovnaké pole Predmet/Telo, zoznam premenných `.et-vars` rozšírený o `{original_number}` a `{original_date}`; editácia podľa Phase 7 F.
- Predvolený text šablóny (SK, seed; PL/CS/EN analogicky, predbežné):
  - Predmet: „Dobropis {number} k faktúre {original_number}“
  - Telo: „Dobrý deň, v prílohe posielame dobropis {number} zo dňa {issue_date} k faktúre {original_number} zo dňa {original_date}. Dobropis stornuje celú pôvodnú faktúru. S pozdravom {sender_name}“
- Stornovaná faktúra a dobropis nikdy neponúkajú „Poslať upomienku“.

---

## I. Uvoľnené časti po vystavení (D-12, D-13)

- Po vystavení sa uvoľnené časti **nemenia automaticky na mesiac**; ostávajú v pôvodnom fakturačnom mesiaci a v zozname úloh majú fakturačný stav „Nevyfakturované“ (existujúce texty Phase 4/5), takže ich existujúce UI (zoznam úloh, mesačný prehľad, výber pri fakturácii, presun do mesiaca) použije bez zmeny.
- Odkaz „Prejsť na uvoľnené časti“ vo flashi po vystavení vedie na existujúci prehľad fakturácie mesiaca pôvodnej faktúry (`/invoicing?client={id}&month={YYYY-MM}`) **[predpoklad]** ako skratka; presun do iného mesiaca robí používateľ existujúcou funkciou Phase 4, nová obrazovka nevzniká.
- Na detaile stornovanej faktúry v zozname riadkov (zdroj `source_allocation_id`) sa história ponechá bez odkazov na uvoľnené časti (iba text popisu).

---

## Copywriting Contract

Neutrálne vety bez oslovenia v UI, vecný tón. Texty iba cez `lang()` (`CreditNotes.php`, `Invoices.php`).

| Element | Copy |
|---------|------|
| Primary CTA (detail faktúry, vstup) | Vystaviť dobropis |
| Primary CTA (stránka náhľadu) | Pokračovať |
| Primary CTA (sheet, destruktívny) | Vystaviť dobropis |
| Sekundárne | Späť, Zrušiť, Späť na faktúru, Odoslať klientovi, Odoslať znova, Dobropis PDF |
| Nadpis stránky | Vystaviť dobropis |
| Úvod | Dobropis opravuje faktúru {číslo} zo dňa {dátum}. Stornuje celú faktúru, čiastočná oprava nie je možná. |
| Label typ opravy | Typ opravy |
| Voľby typu opravy | Chybné údaje odberateľa alebo dodávateľa · Chybná suma, hodiny alebo sadzba · Chybný kurz · Zrušená zákazka alebo plnenie · Iný dôvod |
| Placeholder výberu | Vyberte typ opravy |
| Label dôvod | Dôvod opravy |
| Hint dôvod | Dôvod sa zobrazí na dobropise. Najviac 500 znakov. |
| Label dátum | Dátum vystavenia |
| Hint číslo | Číslo sa pridelí pri vystavení. |
| Hint splatnosť | Dobropis sa neuhrádza. |
| Hint kurz | Kurz sa preberá z pôvodnej faktúry. |
| Nadpis položiek | Položky dobropisu |
| Súhrn | Spolu |
| Kontrola súčtu | Dobropis a pôvodná faktúra spolu: 0,00 € |
| Varovanie o úhradách | Prijaté úhrady {suma}. Vrátenie peňazí klientovi sa rieši mimo aplikácie. |
| Varovanie o uvoľnení častí | Po vystavení sa časti z tejto faktúry (počet {n}) uvoľnia a dajú sa vyfakturovať znova. |
| Destructive confirmation (sheet) | Vystaviť dobropis: Dobropis je nemenný doklad. Faktúra {číslo} sa označí ako stornovaná a jej časti sa uvoľnia. Túto akciu nie je možné vrátiť. |
| Loading | Načítavam náhľad… |
| Banner na pôvodnej faktúre | Stornovaná dobropisom č. {číslo} zo dňa {dátum}. |
| Odkaz na dobropis | Zobraziť dobropis |
| Banner na dobropise | Opravuje faktúru č. {číslo} zo dňa {dátum}. |
| Odkaz na pôvodnú | Zobraziť pôvodnú faktúru |
| Rozsah opravy | Celé storno |
| Pod hlavičkou stornovanej | Po zohľadnení dobropisu: 0,00 € |
| Pod zoznamom úhrad stornovanej | Faktúra je stornovaná. Úhrady sa už nedajú meniť. |
| Odznaky | Dobropis · Stornovaná · Vystavený · Odoslaný |
| Empty state heading | nie je (faktúra má vždy riadky; zoznam faktúr používa existujúci prázdny stav Phase 7) |
| Empty state body | nie je; prázdny výsledok filtra „Stornovaná“ / „Dobropis“: existujúci text „Žiadne faktúry nezodpovedajú filtru.“ (rovnaký kľúč ako Phase 7) |
| Flash úspech | Dobropis {číslo} bol vystavený. · Uvoľnené časti: {n}. Môžete ich vyfakturovať znova. · Prejsť na uvoľnené časti |
| Error (chýba typ) | Vyberte typ opravy. |
| Error (chýba dôvod) | Zadajte dôvod opravy. |
| Error (dôvod príliš dlhý) | Dôvod môže mať najviac 500 znakov. |
| Error (neplatný dátum) | Zadajte platný dátum vystavenia. |
| Error (dátum skorší ako pôvodná) | Dátum dobropisu nemôže byť skorší ako dátum vystavenia pôvodnej faktúry ({dátum}). |
| Error (už stornovaná, 409) | Táto faktúra už bola stornovaná dobropisom {číslo}. |
| Error (nedá sa stornovať) | Pre tento doklad sa dobropis nedá vystaviť. |
| Error (zastaraný náhľad) | Údaje sa medzitým zmenili. Skontrolujte náhľad a vystavte dobropis znova. |
| Error (nenulový súčet) | Súčet dobropisu a pôvodnej faktúry nie je nula. Dobropis nie je možné vystaviť. Kontaktujte správcu. |
| Error (zlyhanie) | Dobropis sa nepodarilo vystaviť. Nič sa neuložilo, skúste to znova. |

PDF texty: tabuľka v G (4 jazyky). E-mail: sekcia H.

---

## Interaction and State Contract

- **Dvojkrokový tok**: formulár a náhľad (B) -> potvrdzovací sheet (C) -> POST. Server overí `preview_token`, `request_key`, vlastníctvo (`origin = issued`), typ `invoice` a nestornovanosť pod zámkom pôvodnej faktúry (D-02, D-20).
- **Jediná akcia na stránku**: „Pokračovať“ (accent) v B, „Vystaviť dobropis“ (`btn-danger`) v C. Dvojklik nič nepošle dvakrát.
- **Nemennosť**: vystavený dobropis sa zobrazuje iba zo svojich stĺpcov a kópie údajov; neskoršia zmena subjektu, klienta, sadzby, kurzu ani nastavení ho nezmení. Žiadne tlačidlo na úpravu alebo zrušenie dobropisu. Pôvodná faktúra a jej PDF sa nemenia (iba odvodený stav).
- **Stav odvodený**: `cancelled` sa nikdy neukladá, UI vždy číta `invoice_status_v`; stornovaná faktúra nemá platobné ani upomienkové akcie.
- **Mobil jednou rukou**: jeden stĺpec, tlačidlá `w-100` 44px, primárne tlačidlá v sticky pätke (spodná polovica), 16px písmo (iOS zoom), `select` natívny, bez horizontálneho posúvania; vystavenie najviac 4 klepnutia po otvorení detailu (Vystaviť dobropis, typ, dôvod + Pokračovať, Vystaviť dobropis).
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; všetky rozmery cez triedy v `app.css`.
- **Prístupnosť**: `label for`, `aria-describedby` pri chybách, `role="alert"` pre chyby a `role="status"` pre `.cn-banner`, focus na prvé neplatné pole, kontrast min 4.5:1, stav nikdy iba farbou ani iba ikonou, záporná suma vždy so znamienkom.
- **Offline**: stránky a fragmenty dobropisu sa nekešujú (SW nikdy nekešuje autentifikované HTML).
- **Ukončený subjekt**: dobropis je možné vystaviť (ENT-04); UI nezobrazuje varovanie o ukončenom subjekte pri tejto akcii.
- **Mimo rozsahu**: čiastkové dobropisy, evidencia vrátenia peňazí, odpočet od obratu a limity (Phase 10), prehľady a exporty (Phase 11), KSeF export.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Umiestnenie „Vystaviť dobropis“ | posledné `btn-outline-secondary` v akciách detailu faktúry, odkaz na samostatnú stránku (nie sheet) |
| Vzhľad náhľadu | samostatná stránka s formulárom a zápornými riadkami, potvrdenie v `.dl-sheet` s `btn-danger` |
| Farba záporných súm | bez farby (žiadna červená), iba znamienko |
| Označenie stornovanej a dobropisu | `.cn-banner` + odznaky „Stornovaná“ (`text-bg-dark`) a „Dobropis“ (`text-bg-info`) |
| Skratka k uvoľneným častiam | odkaz „Prejsť na uvoľnené časti“ vo flashi na existujúci prehľad mesiaca |
| PDF | šablóna `cn-1` odvodená z `inv-2`, bez QR a výkazu, nadpis podľa jazyka dokladu |
| Odoslanie | existujúci sheet Phase 7 s prílohou iba PDF dobropisu |
| Voľba „Chybný kurz“ | skrytá pri faktúre v mene subjektu |

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
