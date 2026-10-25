---
phase: 3
slug: master-d-ta-subjekty-a-klienti
status: approved
reviewed_at: 2026-10-25
shadcn_initialized: false
preset: none
created: 2026-10-25
---

# Phase 3 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 3 (Master dáta: subjekty a klienti). Dedí všetky tokeny z `02-UI-SPEC.md` a nemení ich. Jazyk UI je slovenčina, všetky texty iba cez `lang()` (`app/Language/sk/Entities.php`, `Clients.php`, `Audit.php`, plus rovnaké kľúče v `en`). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`).
> Zdroje: 03-CONTEXT.md (D-01 až D-18, Claude's Discretion rozhodnuté tu), 03-RESEARCH.md (Pattern 2, 3, 6, 7, 8, 9), 02-UI-SPEC.md, existujúci `public/assets/css/app.css`, `app/Views/more/index.php`, `app/Views/settings/*`. Rozhodnutia v rámci voľnosti plánovača sú zapísané v tabuľke nižšie.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (shadcn sa nepoužíva, stack je PHP + Bootstrap, locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 (len ak je to nevyhnutné, formuláre musia fungovať bez JS) |
| Icon library | žiadna. Iba inline SVG `aria-hidden="true"`, `currentColor`, a to len tam, kde nesú význam. Stavy sa vyjadrujú textovými badge, nie ikonami |
| Font | systémový stack z `app.css` (`-apple-system, system-ui, sans-serif`), numerické hodnoty (IBAN, IČO, sadzba) s `font-variant-numeric: tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.app-header`, `.app-main`, `#app-alert`, `.security-card + .security-card` rytmus, `.btn-touch`, `.more-item`, `.device-row`/`.event-row` vzor riadku zoznamu, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`.

Nové triedy v `app.css` (prefix `.master-*`, nová verzia súboru v názve cez `asset_url`):

| Trieda | Účel |
|--------|------|
| `.master-row` | riadok zoznamu subjektov/klientov/účtov/verzií: `display:flex; gap:8px; padding:16px 0; border-bottom:1px solid var(--app-border); min-height:48px`; posledný bez okraja. Celý riadok je odkaz (`<a>`) na detail |
| `.master-actions` | skupina tlačidiel: na < 576px stĺpec na plnú šírku (`flex-direction:column; gap:8px`), od 576px riadok zarovnaný vpravo |
| `.master-kv` | dvojica „popisok – hodnota“ (`dl`): popisok 12px muted nad hodnotou 16px; od 768px dvojstĺpcová mriežka (`grid-template-columns: 200px 1fr`) |
| `.master-fixed` | nemenná hodnota zobrazená ako text v poli formulára (krajina, mena): `min-height:44px; display:flex; align-items:center; padding:0 16px; background:var(--app-bg); border:1px solid var(--app-border); border-radius:6px` + 12px pomocný text „Nedá sa zmeniť“ |
| `.master-changed` | zvýraznenie zmeneného poľa v histórii verzií: ľavý okraj `border-left:4px solid #6b7280` (muted, nie accent) + text „Zmenené“ v badge `text-bg-secondary`, aby accent ostal vyhradený (viď Color) |
| `.master-iban` | monospace (`ui-monospace`), 16px, `overflow-wrap:anywhere` (IBAN sa nikdy nepresúva mimo obrazovku) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera popisok a hodnota v `.master-kv`, medzera badge a text |
| sm | 8px | medzera medzi tlačidlami, medzi badge v riadku, medzi riadkami hint textu |
| md | 16px | odsadenie polí (`mb-3`), padding `.master-row`, medzera medzi kartami (`.security-card + .security-card`), okraje na mobile |
| lg | 24px | padding karty na desktope, odstup medzi skupinami polí vo formulári (`mb-4`) |
| xl | 32px | odstup medzi sekciami detailu subjektu (Údaje, Bankové účty, Číselný rad, História) |
| 2xl | 48px | horné odsadenie prázdneho stavu (ilustračný blok bez obrázka) |
| 3xl | 64px | spodná rezerva obsahu nad tabbarom (už zabezpečuje `.app-main`) |

Exceptions:
- Dotykové ciele min **44px** (výška aj šírka): riadky zoznamov (48px), tlačidlá, polia, `form-check` riadky, ikonové tlačidlá.
- Sticky lišta s primárnou akciou formulára na mobile: výška 64px (rovnako ako tabbar, token 3xl), `position: sticky; bottom: calc(var(--app-tabbar-h) + env(safe-area-inset-bottom))`, biele pozadie, horný okraj `var(--app-border)`, padding 8px 16px. Pomáha ovládať jednou rukou dlhé formuláre. Na ≥ 768px sa lišta nelepí, tlačidlá idú na koniec formulára.
- Nový kód používa len tokeny vyššie (nie legacy 10/12/14px).

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 2, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, hodnoty, riadky zoznamov, názov subjektu/klienta v riadku) | 16px | 400 | 1.5 |
| Label / meta (popisky polí, hint pod poľom, 2. riadok v zozname, badge, hlavička tabuľky histórie, `invalid-feedback`) | 12px | 400 | 1.5 |
| Heading (`h1` názov stránky, `h2` názov karty/sekcie) | 20px | 600 | 1.2 |
| Display | 28px | 600 | 1.2 |

Pravidlá:
- Display (28px) sa v tejto fáze **nepoužíva** (deklarovaný len kvôli zhode so Phase 2, žiadna obrazovka ho nepotrebuje). Nepridávať ďalšie veľkosti.
- Názov subjektu/klienta v riadku zoznamu: 16px, hrúbka 600 (Body veľkosť, druhá povolená hrúbka). Druhý riadok (krajina · mena · stav) 12px muted.
- `.form-label` zostáva 12px/600 (existujúce pravidlo Phase 2 pre labely), hodnoty v poliach 16px/400.
- Hodnoty IBAN, IČO, DIČ, sadzba: 16px/400, `tabular-nums`; IBAN monospace `.master-iban`.

---

## Color

Dedí sa z Phase 2 bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, riadky zoznamov, hlavička, tabbar, sticky lišta |
| Accent (10%) | `#0d6efd` | viď zoznam nižšie |
| Destructive | `#dc3545` | iba destruktívne a chybové |

Accent reserved for (výlučne):
1. Primárne tlačidlo jedinej hlavnej akcie na obrazovke (`btn-primary`): Vytvoriť subjekt, Uložiť novú verziu, Uložiť subjekt, odoslanie formulára bankového účtu („Pridať bankový účet“ / „Uložiť účet“, len na stránke formulára; odkaz „Pridať bankový účet“ na detaile subjektu je `btn-outline-secondary`), Uložiť číselný rad, Pridať klienta, Uložiť klienta
2. Aktívna položka navigácie (`aria-current="page"`, „Viac“ v tabbare počas Phase 3 stránok)
3. Focus ring polí a tlačidiel
4. Textové odkazy v tele (napr. „Zobraziť históriu verzií“, „Nová verzia z tejto“, odkaz „Upraviť účet“ pri bankovom účte)
5. Radio/checkbox v zaškrtnutom stave (Bootstrap predvolené `#0d6efd`)

Nepoužívať accent pre badge, nadpisy, ikony, zvýraznenie zmenených polí ani hranice kariet.

Sémantické badge (vždy text, nikdy iba farba):

| Stav | Trieda | Text |
|------|--------|------|
| Subjekt aktívny dnes | `text-bg-success` | Aktívny |
| Subjekt ukončený (`active_to` < dnes) | `text-bg-secondary` | Ukončený |
| Subjekt s `active_from` v budúcnosti | `text-bg-light border` | Pripravený |
| Verzia platná dnes | `text-bg-success` | Platí dnes |
| Verzia s `valid_from` v budúcnosti | `text-bg-light border` | Budúca |
| Zmenené pole voči predchádzajúcej verzii | `text-bg-secondary` | Zmenené |
| Predvolený účet | `text-bg-secondary` | Predvolený |
| Archivovaný klient / účet | `text-bg-secondary` | Archivovaný |
| Predbežný právny text | `text-bg-warning` | Predbežné znenie |

Destructive (`#dc3545`) iba pre: Ukončiť subjekt (`btn-outline-danger`, modal potvrdenie `btn-danger`), Archivovať klienta (`btn-outline-danger`), Archivovať bankový účet (`btn-outline-danger`), chybové hlášky (`alert-danger`, `invalid-feedback`). Obnoviť klienta a Nastaviť ako predvolený sú `btn-outline-secondary`.

Tmavý režim mimo rozsahu.

---

## Obrazovky a rozloženie

Všetky obrazovky sú za `authgate` v layoute `layouts/app`, cesty podľa 03-RESEARCH Pattern 9. Mobile first, jeden stĺpec. Od `≥ 768px` sa obsah centruje do `max-width: 960px` (zoznamy, detail) alebo `720px` (formuláre) a karty ostávajú jednostĺpcové. Od `≥ 768px` sa dvojice kratkých polí (PSČ + mesto, IBAN + SWIFT) skladajú vedľa seba cez `row g-3`/`col-md-*`. Pod 576px vždy jeden stĺpec.

### Navigácia (vstup)

`app/Views/more/index.php`: nad „Zabezpečenie“ pribudnú dva riadky `list-group-item more-item` v poradí **Subjekty**, **Klienti**, Zabezpečenie, Odhlásiť sa. Každý 48px. Stránky Phase 3 majú nadpis `h1` a odkaz „‹ Viac“ (text-link, 44px cieľ) vľavo hore ako spätnú navigáciu; v tabbare ostáva zvýraznené „Viac“. Hlavička stránky: `h1` vľavo, primárna akcia (Pridať) nie je v hlavičke, je na spodku zoznamu na mobile (plná šírka) a v pravom hornom rohu karty od 768px.

### 1. Zoznam subjektov (`/settings/entities`)

- Karta `.app-card` s riadkami `.master-row`. Riadok: 1. riadok názov (`name_internal`, 600) + badge stavu vpravo; 2. riadok (12px muted) „SK · EUR · neplatiteľ DPH“ a obdobie „od 1. 9. 2026“ alebo „1. 9. 2026 – 31. 12. 2027“. Celý riadok je odkaz na detail.
- Poradie: aktívne dnes (podľa `active_from` zostupne), potom pripravené, potom ukončené. Ukončené sa **nikdy nevyradia** zo zoznamu (ENT-04), len majú badge „Ukončený“.
- Pod zoznamom `btn-primary w-100` „Pridať subjekt“ (od 768px `w-auto`).
- Prázdny stav: viď Copywriting.

### 2. Nový subjekt (`/settings/entities/new`)

Jeden formulár v troch skupinách (nadpisy `h2` 20px v rámci jednej karty, odstup 24px):

1. **Základ (nemenné po vytvorení)**: Krajina (`select`: Slovensko, Poľsko, Česko), Mena (`select`: EUR, PLN, CZK), DPH režim (`select`, možnosti podľa krajiny). Výber krajiny obnoví Menu a DPH režim cez `hx-get` fragment (`hx-trigger="change"`, `hx-target="#entity-regime-fields"`, `hx-swap="innerHTML"`), predvyplní EUR/PLN/CZK a režim (SK: „Neplatiteľ DPH“; PL: „Zwolnienie podmiotowe, art. 113 ust. 1“ a „... ust. 9“; CZ: „Neplatiteľ DPH“). Bez JS: stránka funguje s predvolenou kombináciou a serverová validácia odmietne neplatnú kombináciu krajina/mena/režim (422).
   Nad skupinou `alert alert-info` (12px): „Krajinu a menu už nebude možné zmeniť. Pre inú krajinu alebo menu vytvorte nový subjekt.“
2. **Identita (verzované údaje)**: Interný názov (povinné, nie je súčasťou verzie, mení sa len cez stránku „Upraviť subjekt“, obrazovka 3a), Obchodné meno, Adresa sídla (`address_line1`, `address_line2`, `postal_code`, `city`), IČO (SK, CZ) alebo NIP a REGON (PL) podľa krajiny, DIČ, IČ DPH / VAT-UE (voliteľné), Zápis v registri (SK: „Živnostenský register, okresný úrad, číslo“, PL: „CEIDG“, textarea 3 riadky), E-mail, Telefón, Jazyk dokladov (`select`: SK, PL, CZ, EN; predvolený podľa krajiny), Predvolená splatnosť v dňoch (`inputmode=numeric`, predvolené 14).
3. **Obdobie pôsobenia**: „Aktívny od“ (`type=date`, predvolene dnes, povinné), „Aktívny do“ (`type=date`, voliteľné). Pod poľom 12px hint: „Prvá verzia údajov platí od tohto dátumu.“ a druhý 12px hint „Dátum možno neskôr len posunúť na neskorší.“ (`Entities.activeFromLaterHint`).

Po úspechu PRG na detail subjektu a `alert-success` „Subjekt bol vytvorený. Vytvorili sa aj číselné rady faktúr a dobropisov.“ Tlačidlo „Vytvoriť subjekt“ je v sticky lište (mobil).

### 3. Detail subjektu (`/settings/entities/{id}`)

Karty `.app-card` zhora nadol, medzera 32px medzi sekciami:

1. **Hlavička**: `h1` interný názov, badge stavu, 12px muted riadok „Krajina: Slovensko · Mena: EUR“ (nemenné, zobrazené cez `.master-fixed` text s hintom „Nedá sa zmeniť“, bez inputu). Akcie `.master-actions`: „Nová verzia údajov“ (`btn-primary`), „Upraviť subjekt“ (`btn-outline-secondary`, vedie na obrazovku 3a), „Ukončiť subjekt“ (`btn-outline-danger`, len ak `active_to` je prázdne alebo v budúcnosti).
2. **Aktuálne údaje** (verzia platná dnes alebo najnovšia, ak žiadna): `dl.master-kv` so všetkými poľami D-03; hore riadok „Platí od {dátum}“ (12px). Prázdne voliteľné hodnoty sa zobrazia ako „—“.
3. **Právny profil (len na čítanie)**: kód oslobodenia (monospace, napr. `ART_113_1`), znenie textu v jazyku subjektu, badge „Predbežné znenie“, 12px hint „Znenie potvrdí účtovníčka. Úprava bude dostupná neskôr.“ Žiadne tlačidlo úpravy (SET-01 je Phase 6).
4. **Bankové účty**: zoznam `.master-row` (názov účtu, `.master-iban`, 2. riadok „EUR · SWIFT XXXXXX · Predvolený“). Tlačidlá na riadku v `.master-actions`: „Upraviť účet“, „Nastaviť ako predvolený“ (iba ak nie je predvolený), „Archivovať“. Pod zoznamom „Pridať bankový účet“ (`btn-outline-secondary w-100`; primary je na stránke už „Nová verzia údajov“). Archivované účty sú zbalené pod odkazom „Zobraziť archivované ({n})“ (htmx fragment, bez JS fallback je samostatný query `?archived=1`).
5. **Číselný rad**: dve karty-riadky „Faktúry“ a „Dobropisy“: formát v monospace (`RRRRMM###`), „Ukážka: 202610001“, „Počiatočné číslo: 1“, „Poradie sa nuluje: každý mesiac / každý rok“. Tlačidlo „Upraviť rad“ (`btn-outline-secondary`). Ak `isFormatLocked` je true: tlačidlo je `disabled` a pod ním hint „Formát sa nedá zmeniť, kým rad obsahuje vystavené doklady.“ (v tejto fáze nenastane, ale text a stav existujú).
6. **História verzií** (`h2` „História verzií“): zoznam od najnovšej (`.master-row`): 1. riadok „Platí od {dátum}“ + badge „Platí dnes“ / „Budúca“; 2. riadok (12px muted) „Zmenili ste: Adresa sídla, E-mail“ (zoznam zmenených polí z `VersionDiff`; pre prvú verziu „Prvá verzia“). Klik rozbalí riadok (Bootstrap collapse cez `data-bs-toggle`, bez JS) na `dl.master-kv` s hodnotami verzie, kde zmenené polia majú `.master-changed` a badge „Zmenené“. V rozbalenom riadku tlačidlo „Nová verzia z tejto“ (`btn-outline-secondary`) vedie na formulár novej verzie predvyplnený hodnotami tejto verzie.
   Žiadne tlačidlo Upraviť ani Zmazať pri verzii (insert-only).

**Fokálny bod:** `h1` interný názov s badge stavu v hlavičke; jediná primárna akcia je „Nová verzia údajov“.

### 3a. Upraviť subjekt (`/settings/entities/{id}/edit`)

- Upravuje **iba neverzované polia**: Interný názov (povinné), Aktívny od (`type=date`, povinné, pod poľom 12px hint „Dátum možno neskôr len posunúť na neskorší.“), Aktívny do (`type=date`, voliteľné; prázdne = subjekt neukončený, takto sa ukončenie aj zruší).
- Krajina a mena hore ako `.master-fixed` s hintom „Nedá sa zmeniť“; verzované údaje (obchodné meno, adresa, IČO…) sa tu **nevykreslia** a 12px hint pod formulárom odkáže: „Obchodné meno, adresu a ďalšie údaje dokladov meníte cez Nová verzia údajov.“
- Validácia (422, `invalid-feedback`): „Aktívny do“ nesmie byť skôr ako „Aktívny od“ (existujúci text v Copywriting); „Aktívny od“ nesmie byť **skôr** ako „Platné od“ prvej verzie (inak by vzniklo obdobie bez verzie; RESEARCH Pattern 2 bod 1, plán 03-06): „Dátum „Aktívny od“ nemôže byť skôr ako začiatok platnosti prvej verzie ({dátum}).“
- Sticky lišta: „Uložiť subjekt“ (`btn-primary`), „Späť na detail“ (`btn-outline-secondary`, odkaz). Po úspechu PRG na detail a `alert-success` „Subjekt bol uložený.“
- Zmena sa zapíše do audit logu (AUD-01) rovnako ako ostatné zápisy.

### 4. Nová verzia údajov (`/settings/entities/{id}/versions/new[?from={versionId}]`)

- Formulár s poľami identity z Nového subjektu (len verzované polia, bez krajiny a meny, tie sú vo vrchnej karte ako `.master-fixed`).
- Prvé pole **„Platné od“** (`type=date`, povinné, predvolene dnes, `min` = `active_from` subjektu, `max` = dnes + 366 dní). Hint 12px: „Nová verzia platí od tohto dňa až do ďalšej verzie. Staršie doklady ostanú nezmenené. Rovnaký dátum ako existujúca verzia nie je možný, zvoľte iný deň.“
- Ak je zvolený dátum v budúcnosti, pod poľom sa objaví `alert alert-info`: „Táto verzia začne platiť až {dátum}. Do tej doby sa používajú súčasné údaje.“ (serverový render pri 422 aj živo cez `hx-get` na zmenu poľa, `hx-trigger="change"`, fragment cieli len upozornenie).
- Ak existuje neskoršia verzia: `alert alert-warning`: „Po {dátum} už platí iná verzia. Nová verzia sa uplatní len do tohto dňa.“
- Prázdna zmena (nič sa nezmenilo voči verzii platnej k dátumu): 422 „Žiadne údaje sa nezmenili.“
- Sticky lišta: „Uložiť novú verziu“ (`btn-primary`), „Zrušiť“ (`btn-outline-secondary`, odkaz späť).
- Oprava preklepu používa ten istý postup: „Nová verzia z tejto“, dátum dnes. Pod formulárom 12px vysvetlenie „Opravu preklepu uložíte tiež ako novú verziu. Pôvodná verzia ostáva v histórii.“
- Pole krajina a mena sa na tomto formulári **nikdy nevykreslia ako input** (ani skryté).

### 5. Formulár bankového účtu (`/settings/entities/{id}/bank-accounts/new`, `.../{accId}/edit`)

Polia: Názov účtu (napr. „Hlavný EUR“), IBAN (`autocapitalize=characters`, `autocomplete=off`, `spellcheck=false`, `.master-iban`; zadanie sa medzery tolerujú a normalizujú), SWIFT/BIC (voliteľné), Mena (`select` EUR/PLN/CZK, pri úprave `.master-fixed`), checkbox „Predvolený účet“ (44px riadok; pri prvom účte zaškrtnutý a zamknutý). Primary: „Pridať bankový účet“ / „Uložiť účet“. Zmena IBAN pri úprave zobrazí 12px hint „Vystavené doklady ostanú nezmenené, nesú vlastnú kópiu účtu.“

### 6. Formulár číselného radu (`/settings/entities/{id}/series/{kind}/edit`)

- Polia: Formát (`.master-iban`, 16px), Počiatočné číslo (`inputmode=numeric`, min 1).
- Pod poľom Formát: **živá ukážka** (`hx-get` na zmenu/`keyup changed delay:300ms`, fragment `#series-preview`): „Ukážka: 202610001, 202610002 · Nuluje sa: každý mesiac“. Neplatný formát zobrazí v tom istom fragmente `invalid-feedback` s konkrétnou chybou. Bez JS ukážku vykreslí server pri 422/uložení.
- Pomocná karta „Značky formátu“ (12px, `dl`): `RRRR` rok štyrmi číslicami, `RR` rok dvoma, `MM` mesiac, `###` poradové číslo (počet `#` = počet číslic), ostatné znaky sa vypíšu doslova. Príklady: „RRRRMM### → 202610001“, „DB-RRRRMM### → DB-202610001“.
- Primary „Uložiť číselný rad“.

### 7. Zoznam klientov (`/settings/clients`)

- Rovnaký vzor ako zoznam subjektov. Riadok: názov (600) + (ak archivovaný) badge „Archivovaný“; 2. riadok (12px muted) „Slovensko · 27,00 € / h · tuzemský“ (sadzba cez `NumberFormatter` v mene klienta; režim fakturácie slovom).
- Hore nad zoznamom: prepínač `nav nav-pills`-like s dvoma odkazmi „Aktívni“ / „Archivovaní“ (query `?archived=1`, odkazy 44px, aktívny `aria-current="page"` s `btn-outline-secondary` štýlom, nie accent). Hľadanie vo fáze 3 nie je.
- Triedenie podľa názvu (`sk-SK` ICU, bez `COLLATE`).
- „Pridať klienta“ `btn-primary w-100` pod zoznamom.

### 8. Formulár klienta (`/settings/clients/new`, `.../{id}/edit`)

Skupiny v jednej karte, `h2` 20px nad každou, medzera 24px:

1. **Základné údaje**: Názov (povinné), Adresa (`address_line1`, `address_line2`, `postal_code` + `city`), Krajina (`select`; tu sa dá meniť, klient nie je verzovaný).
2. **Identifikácia a DPH**: Typ identifikátora (radio skupina v 44px riadkoch: IČO / NIP, EÚ VAT ID, Iné ID, Žiadne), Hodnota identifikátora (placeholder podľa typu), DIČ, IČ DPH / VAT ID, Režim fakturácie (radio: Tuzemský, EÚ B2B, Mimo EÚ). Pre „EÚ B2B“ pod poľom IČ DPH 12px hint „Povinné pre EÚ B2B, tlačí sa na faktúru.“ a pole sa označí povinné. Pre „Žiadne“ sa pole hodnoty vypne (Alpine CSP `x-bind:disabled`; serverová validácia je rozhodujúca, bez JS pole ostáva zapnuté a server ignoruje prázdnu hodnotu a odmietne neprázdnu s typom „Žiadne“).
3. **Fakturácia**: Hodinová sadzba (`inputmode=decimal`, desatinná čiarka aj bodka, vždy stringom; vedľa `select` Mena EUR/PLN/CZK), Jazyk dokladov (`select`: „Podľa subjektu (predvolené)“, SK, PL, CZ, EN), Splatnosť v dňoch (voliteľné, placeholder „Podľa subjektu“), checkbox „Započítavať do limitu obratu“ (predvolene zaškrtnutý, 44px riadok).
4. **E-maily**: E-mail na faktúry (`type=email`, povinné), E-maily v kópii (`textarea` 3 riadky, hint „Oddeľte čiarkou alebo novým riadkom. Najviac 5 adries.“; po uložení sa normalizujú a zobrazia ako zoznam).
5. **Portál úloh**: Názov portálu (napr. „Taskman“), Vzor odkazu (`type=url`, hint „Použite {id} na mieste čísla úlohy, napr. https://taskman.example/task/{id}. Povolené je len http a https.“) a pod ním živá ukážka s príkladom ID 123 (`hx-get`, rovnaký mechanizmus ako ukážka radu; odkaz sa v ukážke vypíše ako text, nie ako klikateľný odkaz).

Sticky lišta: „Uložiť klienta“ (primary), „Zrušiť“. Pri úprave pod formulárom oddelená `.app-card` „Archivácia“: text „Archivovaný klient sa nezobrazuje pri tvorbe nových úloh. Jeho doklady a údaje ostanú zachované.“ + „Archivovať klienta“ (`btn-outline-danger`, modal) alebo pri archivovanom „Obnoviť klienta“ (`btn-outline-secondary`, bez modalu).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, tón vecný (rovnako ako Phase 2). Dátumy cez `IntlDateFormatter` (`2. 11. 2026`), sumy cez `NumberFormatter` (`27,00 €`).

### Kľúčové texty

| Element | Copy |
|---------|------|
| Primary CTA (subjekt, vytvorenie) | Vytvoriť subjekt |
| Primary CTA (nová verzia) | Uložiť novú verziu |
| Primary CTA (bankový účet) | Pridať bankový účet (pri úprave: Uložiť účet) |
| Primary CTA (číselný rad) | Uložiť číselný rad |
| Primary CTA (klient) | Pridať klienta (pri úprave: Uložiť klienta) |
| Sekundárne | Zrušiť, Nová verzia z tejto, Nastaviť ako predvolený, Obnoviť klienta, Zobraziť archivované ({n}) |
| Empty state heading (subjekty) | Zatiaľ nemáte žiadny subjekt |
| Empty state body (subjekty) | Subjekt je firma alebo živnosť, ktorá vystavuje faktúry. Pridajte prvý subjekt, aby bolo možné vytvoriť klientov a faktúry. |
| Empty state heading (klienti) | Zatiaľ nemáte žiadneho klienta |
| Empty state body (klienti) | Pridajte klienta s hodinovou sadzbou. Úlohy a faktúry sa budú viazať naňho. |
| Empty state heading (archivovaní klienti) | Žiadni archivovaní klienti |
| Empty state body (archivovaní klienti) | Archivovaných klientov nájdete tu. Archivácia je vratná. |
| Empty state heading (bankové účty) | Subjekt nemá bankový účet |
| Empty state body (bankové účty) | Pridajte aspoň jeden účet. Faktúra použije účet v mene faktúry, inak predvolený. |
| Empty state (história, prvá verzia) | Prvá verzia údajov platí od {dátum}. Ďalšie zmeny sa tu zobrazia ako nové verzie. |
| Error (všeobecná validácia) | Formulár obsahuje chyby. Opravte označené polia a uložte znova. (`alert-danger` nad formulárom, fokus na prvé chybné pole) |
| Error (duplicitný dátum verzie) | Verzia k tomuto dátumu už existuje. Zvoľte iný dátum „Platné od“. |
| Error (žiadna zmena) | Žiadne údaje sa nezmenili. Upravte aspoň jedno pole alebo zrušte zmenu. |
| Error (dátum mimo rozsahu) | Dátum musí byť medzi {od} a {do}. Skontrolujte rok. |
| Error (neplatný IBAN) | IBAN nie je platný. Skontrolujte číslo, kontrolné číslice nesedia. |
| Error (neplatný SWIFT) | SWIFT/BIC má 8 alebo 11 znakov, napr. SUBASKBX. |
| Error (neplatný formát radu) | Formát musí obsahovať rok (RRRR alebo RR) a jedno poradové číslo (###). Povolené sú písmená, číslice a znaky - _ / . |
| Error (rovnaký formát faktúr a dobropisov) | Faktúry a dobropisy musia mať rôzny formát, napr. DB-RRRRMM###. |
| Error (zamknutý formát) | Formát sa nedá zmeniť, kým rad obsahuje vystavené doklady. |
| Error (neplatný vzor odkazu) | Vzor odkazu musí začínať http:// alebo https:// a obsahovať {id} práve raz. |
| Error (e-maily v kópii) | Niektorá z adries v kópii nie je platná: {adresa}. Opravte ju alebo ju odstráňte. |
| Error (príliš veľa kópií) | Najviac 5 adries v kópii. |
| Error (EÚ B2B bez IČ DPH) | Pre fakturáciu EÚ B2B je IČ DPH povinné. |
| Error (sadzba) | Zadajte sadzbu ako číslo, napr. 27 alebo 27,50. |
| Error (zmena krajiny/meny, podvrhnutý POST) | Krajinu a menu subjektu nemožno zmeniť. Vytvorte nový subjekt. (422, bez zmeny údajov) |
| Error (účet bez zhody v mene) | Subjekt nemá účet v mene {mena}. Použije sa predvolený účet. (len informačný riadok pri výbere v budúcich fázach, tu iba kľúč v lang) |
| Error (zhoda dátumov subjektu) | Dátum „Aktívny do“ nesmie byť skôr ako „Aktívny od“. |
| Error (Aktívny od pred prvou verziou, 3a) | Dátum „Aktívny od“ nemôže byť skôr ako začiatok platnosti prvej verzie ({dátum}). |
| Hint (Aktívny od, Nový subjekt aj 3a) | Dátum možno neskôr len posunúť na neskorší. |
| Error (neexistujúci záznam, 404) | Záznam sa nenašiel. Vráťte sa na zoznam. |
| Error (sieť / server / CSRF) | existujúce `App.errorNetwork`, `App.errorServer`, `errors/csrf_403.php` (nemeniť) |
| Success flashe | „Subjekt bol vytvorený. Vytvorili sa aj číselné rady faktúr a dobropisov.“ · „Nová verzia platná od {dátum} bola uložená.“ · „Subjekt bol ukončený k {dátum}.“ · „Bankový účet bol uložený.“ · „Predvolený účet bol zmenený.“ · „Bankový účet bol archivovaný.“ · „Číselný rad bol uložený.“ · „Klient bol uložený.“ · „Klient bol archivovaný.“ · „Klient bol obnovený.“ |

### Popisky polí (kľúče v `Entities.php` a `Clients.php`)

Subjekt: Interný názov, Obchodné meno, Adresa sídla, Adresa (doplnok), PSČ, Mesto, Krajina, Mena, DPH režim, IČO, NIP, REGON, DIČ, IČ DPH / VAT-UE, Zápis v registri, E-mail, Telefón, Jazyk dokladov, Predvolená splatnosť (dni), Aktívny od, Aktívny do, Platné od.
Hodnoty DPH režimu: „Neplatiteľ DPH (SK)“, „Zwolnienie podmiotowe, art. 113 ust. 1 (PL)“, „Zwolnienie podmiotowe, art. 113 ust. 9 (PL)“, „Neplatiteľ DPH (CZ)“.
Klient: Názov, Adresa, Krajina, Typ identifikátora (IČO / NIP, EÚ VAT ID, Iné ID, Žiadne), Hodnota identifikátora, DIČ, IČ DPH / VAT ID, Režim fakturácie (Tuzemský, EÚ B2B, Mimo EÚ), Hodinová sadzba, Mena, Jazyk dokladov („Podľa subjektu“), Splatnosť (dni, „Podľa subjektu“), Započítavať do limitu obratu, E-mail na faktúry, E-maily v kópii, Názov portálu, Vzor odkazu na portál.
Bankový účet: Názov účtu, IBAN, SWIFT/BIC, Mena, Predvolený účet. Číselný rad: Formát, Počiatočné číslo, Ukážka, Nuluje sa (každý mesiac / každý rok).

### Destruktívne a nevratné akcie

Modal vzor z Phase 2 (Bootstrap `data-bs-*`, fokus späť na spúšťač, `Esc` zatvára, tlačidlá 44px, na mobile v stĺpci s „Zrušiť“ naspodku, potvrdzovacie tlačidlo `btn-danger`).

| Akcia | Potvrdenie |
|-------|-----------|
| Ukončiť subjekt | Ukončiť subjekt: „Subjekt {názov} bude ukončený k {dátum}. Nové faktúry sa naň nepredvyplnia, jeho doterajšie doklady môžete naďalej zobraziť, odoslať a stornovať. Pole „Aktívny do“ sa dá neskôr zmeniť. Ukončiť?“ (modal obsahuje pole dátumu „Aktívny do“, predvolene dnes; tlačidlo „Ukončiť subjekt“) |
| Archivovať klienta | Archivovať klienta: „Klient {názov} sa nebude ponúkať pri nových úlohách. Doklady a údaje ostanú zachované a klienta môžete obnoviť. Archivovať?“ (tlačidlo „Archivovať klienta“) |
| Archivovať bankový účet | Archivovať bankový účet: „Účet {IBAN} sa nebude ponúkať na nových faktúrach. Vystavené doklady ostanú nezmenené. Ak ide o predvolený účet, predvolený sa stane najstarší aktívny. Archivovať?“ (tlačidlo „Archivovať účet“) |
| Zmena formátu číselného radu | bez modalu, ukážka formátu je potvrdením; pri uložení flash. Pri už existujúcich dokladoch je akcia nedostupná |
| Nová verzia údajov | bez modalu, nie je destruktívna (insert-only) |
| Mazanie subjektu, klienta, účtu, verzie | **neexistuje** (žiadne tlačidlo ani route) |

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid (`is-invalid` + `invalid-feedback`, `aria-describedby`), disabled, loading. Odoslanie: tlačidlo `disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva (ochrana pred dvojitým odoslaním). Všetky formuláre fungujú ako klasický POST + PRG bez JS. htmx len pre: výber krajiny (polia meny a režimu), živá ukážka radu a odkazu na portál, upozornenie k dátumu verzie, rozbalenie archivovaných. `422` swapuje formulár s chybami (existujúci `responseHandling`), `Vary: HX-Request` pre fragmenty.
- **Verzovanie, pravidlá UI**: žiadna úprava verzie na mieste; „Nová verzia z tejto“ je jediný spôsob opravy. Rovnaký `valid_from` nikdy neprejde (serverový unikát, 422). Krajina a mena sú vždy text.
- **Rozloženie jednou rukou**: primárna akcia formulára vo sticky lište nad tabbarom (viď Spacing), sekundárna pod ňou, nie vedľa seba pod 576px. Zoznamy majú celé riadky ako cieľ 48px. Žiadne horizontálne posúvanie: dlhý IBAN a e-maily `overflow-wrap:anywhere`, tabuľky sa nepoužívajú (história je zoznam kariet na všetkých šírkach, `dl` namiesto tabuľky).
- **Prístupnosť**: každé pole `<label for>`, skupiny radio v `<fieldset><legend>` (legend 12px/600), chyby `aria-describedby`, flash `role=alert`/`aria-live=polite`, kontrast min 4.5:1, farba nikdy sama nenesie stav (badge majú text), `lang` podľa stránky, `autocomplete` atribúty: `organization`, `street-address`, `postal-code`, `address-level2`, `email`, `tel` tam, kde to sedí (subjekt a klient), `off` pre IBAN.
- **Mobilné klávesnice**: `inputmode=numeric` (IČO, NIP, REGON, splatnosť, počiatočné číslo), `inputmode=decimal` (sadzba), `type=email`, `type=tel`, `type=url`, `type=date`. Veľkosť polí 16px (iOS zoom).
- **Stránkovanie**: nepoužíva sa (jeden používateľ, desiatky záznamov); zoznamy bez limitu.
- **Chyby prístupu**: neexistujúce ID alebo účet nepatriaci subjektu v URL = 404 stránka s odkazom späť; všetky zápisy len POST + CSRF (`HtmxCsrf`).
- **Audit v UI**: v tejto fáze sa denník nezobrazuje (AUD-02 je Phase 11). Na detaile subjektu a klienta nie je odkaz na denník.
- **Offline**: stránky Phase 3 sa nekešujú (SW nikdy nekešuje HTML s autentifikáciou).

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Pýta formulár pri každom uložení „Platné od“? | Áno, povinné pole, predvolene dnes, vždy prvé v „Novej verzii“. Pri samotnom vytvorení subjektu je to „Aktívny od“ |
| Ako UI uľahčí opravu preklepu | tlačidlo „Nová verzia z tejto“ predvyplní formulár a dátum dnes |
| Budúce obdobie | `alert-info` s dátumom nástupu platnosti + badge „Budúca“ v histórii |
| Bankové účty | samostatná sekcia na detaile subjektu s archiváciou (nie súčasť verzie), podľa 03-RESEARCH Pattern 3 |
| Syntax odkazu na portál | zástupný symbol `{id}` práve raz, len http/https, živá ukážka |
| E-maily v kópii | jedno textové pole, čiarka/bodkočiarka/nový riadok, max 5, normalizácia po uložení |
| Archivovaní klienti | filter „Aktívni / Archivovaní“ na zozname (query `?archived=1`) |
| Rozloženie | jednostĺpcové karty na mobile aj PC, sticky akčná lišta na mobile, vstup cez Viac → Subjekty / Klienti |
| Úprava neverzovaných polí (interný názov, Aktívny od/do) | samostatná obrazovka 3a „Upraviť subjekt“, bez verzovania; ukončenie cez modal je skratka na pole Aktívny do |
| Úprava právneho kódu a textov | len na čítanie s badge „Predbežné znenie“, úprava je Phase 6 |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Žiadne nové balíky ani CDN.

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: FLAG (non-blocking, addressed)
- [x] Dimension 2 Visuals: FLAG (non-blocking, addressed)
- [x] Dimension 3 Color: FLAG (non-blocking, addressed)
- [x] Dimension 4 Typography: PASS
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-10-25 (gsd-ui-checker: 0 blocking, 5 FLAGs; FLAGs on Upraviť labels, accent list, focal point and the missing „Upraviť subjekt“ screen resolved by orchestrator)
