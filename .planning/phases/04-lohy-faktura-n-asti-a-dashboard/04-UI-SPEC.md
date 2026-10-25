---
phase: 4
slug: lohy-faktura-n-asti-a-dashboard
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-25
reviewed_at: 2026-10-25
---

# Phase 4 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 4 (Úlohy, fakturačné časti a dashboard). Dedí všetky tokeny z `02-UI-SPEC.md` a `03-UI-SPEC.md` a nemení ich. Jazyk UI je slovenčina, všetky texty iba cez `lang()` (nové súbory `app/Language/sk/Tasks.php`, `Allocations.php`, `Dashboard.php` plus rovnaké kľúče v `en`; texty PL/CZ až Phase 12). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine len CSP build.
> Zdroje: 04-CONTEXT.md (D-01 až D-20, Claude's Discretion rozhodnuté tu), 04-RESEARCH.md (stack), 03-UI-SPEC.md, 02-UI-SPEC.md, `docs/mockups/mobile-home.html` (variant B), `docs/mockups/task-flows.html`, existujúci `public/assets/css/app.css`, `app/Views/layouts/_nav_items.php`. Majiteľ nechce interaktívnu diskusiu, predpoklady sú označené **[predpoklad]**.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (shadcn sa nepoužíva, stack PHP + Bootstrap je locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11, Alpine.js CSP build 3.17.5 (živý prepočet sumy, ovládanie panelu mesiaca; formuláre fungujú aj bez JS) |
| Icon library | žiadna. Iba inline SVG `aria-hidden="true"`, `currentColor`: znak „+“ v plávajúcom tlačidle, zámok pri zamknutej časti. Stavy sa vyjadrujú textovými badge |
| Font | systémový stack z `app.css`; všetky čísla (hodiny, sumy, mesiace) s `font-variant-numeric: tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.app-header`, `.app-main`, `.app-tabbar`, `#app-alert`, `.master-row`, `.master-actions`, `.master-kv`, `.master-sticky-actions`, `.master-check`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, modal vzor z Phase 2/3, `HtmxCsrf`, `responseHandling` pre 422.

Nové triedy v `app.css` (prefix `.work-*`, nová verzia súboru v názve cez `asset_url`):

| Trieda | Účel |
|--------|------|
| `.work-tile` | dlaždica dashboardu v mriežke `.work-tiles` (`display:grid; grid-template-columns: 1fr 1fr; gap:8px`): popisok 12px muted, suma 20px/600, 12px riadok hodín. Pod 360px jeden stĺpec. Celá dlaždica je odkaz (cieľ >= 44px, reálne min 88px) na filtrovaný zoznam |
| `.work-group` | nadpis skupiny dashboardu (`h2` 20px/600) + počet v badge `text-bg-secondary`, odstup nad skupinou 24px |
| `.work-task` | riadok úlohy (derivát `.master-row`, min-height 64px, celý riadok je `<a>` na detail): 1. riadok názov (16px/600, max 2 riadky, `overflow-wrap:anywhere`), 2. riadok 12px muted „40 h · 1 080,00 €“, vpravo badge stavu |
| `.work-progress` | ukazovateľ priebehu: Bootstrap `.progress` výšky 8px v dvoch segmentoch, vyfakturované `#6b7280` (muted) a naplánované `#adb5bd`, zvyšok `#e3e5e8` (`--app-border`). Segmenty nikdy neprekročia 100 % šírky (prečerpanie sa ukazuje textom, nie šírkou). `role="img"` s `aria-label` slovami („Vyfakturované 16 h, naplánované 8 h, zostáva 16 h z 40 h“). Pod ukazovateľom vždy textová legenda, farba nenesie význam sama |
| `.work-fab` | plávajúce tlačidlo „+“: 56x56px, kruh, accent `#0d6efd`, biely znak, `position: fixed; right: 16px; bottom: calc(var(--app-tabbar-h) + env(safe-area-inset-bottom) + 16px)`, `aria-label` z `lang('Tasks.new')`, bez textu (jediná výnimka z pravidla „žiadne ikonové tlačidlo bez textu“ je zdôvodnená mockupom variantu B). Na >= 768px sa skryje, namiesto neho je primárne tlačidlo „Nová úloha“ v hlavičke zoznamu |
| `.work-alloc` | riadok fakturačnej časti: 1. riadok „Október 2026“ (16px/600), 2. riadok „16 h · 432,00 €“; vpravo zámok + číslo faktúry (zamknutá) alebo odkaz „Upraviť“ (nezamknutá) |
| `.work-sheet` | spodný panel (bottom sheet): Bootstrap `offcanvas offcanvas-bottom` (bez vlastného JS), max-výška 85dvh, zaoblenie horných rohov 16px, padding 16px, safe-area spodný padding `calc(env(safe-area-inset-bottom) + 16px)`, tlačidlá v `.master-actions` |
| `.work-month-pick` | trojica „Aktuálny / Ďalší / Iný…“ ako radio skupina v segmentovom vzhľade (`btn-check` + `btn-outline-secondary`), každý segment min 44px výšky, rovnaká šírka; zvolený segment `btn-secondary` (tmavo šedý, nie accent) |
| `.work-delta` | rozdiel nacenených a fakturovaných hodín: 12px/600 text vo farbe `--app-muted`, nikdy červený a nikdy farba ako jediný nositeľ (pozri Color) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 a 3 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera popisok a hodnota v dlaždici, medzera segmentu a legendy |
| sm | 8px | medzera medzi dlaždicami, medzi badge, medzi tlačidlami, medzi riadkami hintu |
| md | 16px | padding `.work-task`, padding dlaždice, odsadenie polí (`mb-3`), okraje na mobile, vzdialenosť FAB od okraja |
| lg | 24px | odstup medzi skupinami dashboardu, medzi skupinami polí vo formulári (`mb-4`), padding karty na desktope |
| xl | 32px | odstup medzi sekciami detailu úlohy (Súhrn, Časti, Údaje) |
| 2xl | 48px | horné odsadenie prázdneho stavu |
| 3xl | 64px | výška spodnej lišty a spodná rezerva obsahu (`.app-main`) |

Exceptions:
- Dotykové ciele min **44px** (výška aj šírka): riadky zoznamov (min 64px), tlačidlá, polia, segmenty `.work-month-pick`, odkazy, ikonové ovládacie prvky. FAB 56x56px (násobok 4, > 44px).
- Výška `.work-progress` 8px (token sm, ide o vizuálnu čiaru, nie dotykový cieľ).
- Sticky akčná lišta formulárov (`.master-sticky-actions`, existujúca), výška 64px, nad tabbarom. **FAB sa na obrazovkách so sticky lištou (formuláre) nevykresľuje.** Na zozname a dashboarde sticky lišta nie je.
- Nový kód používa len tokeny vyššie.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 2 a 3, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, názov úlohy v riadku, hodnoty) | 16px | 400 | 1.5 |
| Label / meta (popisky polí, hint, 2. riadok úlohy, badge, popisok dlaždice, legenda, `invalid-feedback`) | 12px | 400 | 1.5 |
| Heading (`h1` názov stránky, `h2` názov skupiny/sekcie, suma v dlaždici) | 20px | 600 | 1.2 |
| Display (veľké číslo „Na fakturáciu“ v hlavičke detailu úlohy: nacenená suma) | 28px | 600 | 1.2 |

Pravidlá:
- Display 28px sa používa **len** na detaile úlohy pre nacenenú sumu (jedno číslo na obrazovku). Dlaždice dashboardu používajú Heading 20px. Žiadna iná veľkosť.
- Názov úlohy v riadku 16px/600; druhý riadok 12px/400 muted. Badge 12px, text vždy slovom.
- Hodiny sa píšu `40 h`, `8,5 h` (desatinná čiarka, max 2 miesta, nuly na konci sa vypúšťajú len pri celých číslach), sumy cez `NumberFormatter` v mene úlohy: `1 080,00 €`. **Vždy s menou a s popiskom** (D-05): „Nacenené“, „Vyfakturované“, „Naplánované“, „Zostáva“.
- `.form-label` zostáva 12px/600.

---

## Color

Dedí sa z Phase 2 a 3 bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky |
| Secondary (30%) | `#ffffff` | `.app-card`, riadky úloh, dlaždice, hlavička, tabbar, sticky lišta, spodný panel |
| Accent (10%) | `#0d6efd` | viď zoznam nižšie |
| Destructive | `#dc3545` | iba destruktívne a chybové |

Accent reserved for (výlučne):
1. Primárne tlačidlo jedinej hlavnej akcie obrazovky (`btn-primary`): Uložiť úlohu, Potvrdiť (panel Zapracované), Uložiť časť, Pridať časť (panel)
2. Plávajúce tlačidlo „+“ (`.work-fab`), jediná hlavná akcia dashboardu a zoznamu na mobile
3. Aktívna položka navigácie (`aria-current="page"`: Prehľad na `/`, Úlohy na `/tasks*`)
4. Focus ring polí a tlačidiel
5. Textové odkazy v tele (URL na portál „Otvoriť v Taskman“, „Zobraziť všetky úlohy“, „Upraviť“ pri časti)
6. Radio/checkbox v zaškrtnutom stave (Bootstrap predvolené)

Accent sa **nepoužíva** pre badge, ukazovateľ priebehu, nadpisy, sumy v dlaždiciach, zvolený segment mesiaca, ani hranice kariet.

Sémantické badge (vždy text, nikdy iba farba):

| Stav | Trieda | Text |
|------|--------|------|
| Úloha v práci (`in_progress`) | `text-bg-light border` | V práci |
| Zapracovaná, nevyfakturovaná (`done`, žiadna zamknutá časť) | `text-bg-success` | Zapracované |
| Čiastočne vyfakturovaná | `text-bg-warning` | Čiastočne |
| Vyfakturovaná | `text-bg-secondary` | Vyfakturované |
| Zamknutá časť | `text-bg-secondary` + zámok | Zamknuté · FA {číslo} (kým faktúry neexistujú, v tejto fáze sa nezobrazí) |
| Nezamknutá časť | `text-bg-light border` | Naplánované |
| Archivovaný klient pri úlohe | `text-bg-secondary` | Archivovaný |

Rozdiel hodín (`.work-delta`): text „+6 h nad nacenené“ / „−2 h pod nacenené“ / „v rámci nacenených“, 12px/600, farba `--app-muted`; **nikdy** nie červená (D-05: prečerpanie nie je chyba). `text-bg-warning` badge „Nad nacenené“ sa použije len v detaile, ak súčet častí presiahne nacenené hodiny.

Destructive (`#dc3545`) iba pre: Zmazať úlohu (`btn-outline-danger`, modal `btn-danger`), Zmazať časť (`btn-outline-danger` v paneli, `btn-danger` potvrdenie), chybové hlášky (`alert-danger`, `invalid-feedback`). „Vrátiť do stavu V práci“ je `btn-outline-secondary`.

Tmavý režim mimo rozsahu.

---

## Obrazovky a rozloženie

Všetky obrazovky sú za `authgate` v layoute `layouts/app`. Mobile first, jeden stĺpec. Od >= 768px sa obsah centruje do `max-width: 960px` (zoznam, detail, dashboard) alebo `720px` (formuláre). Pod 576px vždy jeden stĺpec, bez horizontálneho posúvania (názvy a URL `overflow-wrap:anywhere`). Tabuľky sa nepoužívajú (zoznamy riadkov aj na PC, **[predpoklad]** rozloženie PC zoznamu úloh = rovnaké riadky, od 768px so 2. riadkom doplneným o stĺpcovo zarovnané hodiny a sumy cez `flex` a pevné šírky 96px).

### Navigácia

`_nav_items.php`: poradie **Prehľad** (`/`), **Úlohy** (`/tasks`, aktívny `aria-current="page"` na `/tasks*`), Faktúry (stále `aria-disabled`, Phase 5), Viac. Nový lang kľúč netreba, `App.navTasks` existuje. Pre `/tasks*` je zvýraznená „Úlohy“, pre `/` „Prehľad“.

### 1. Dashboard / Prehľad (`/`, variant B)

Poradie zhora nadol:
1. **Hlavička prehľadu**: `h1` „{Mesiac} {rok}“ (napr. „Október 2026“, aktuálny kalendárny mesiac podľa `Clock`), pod ním 12px muted „{Klient} · sadzba 27,00 € / h“ (pri „Všetci“: „Všetci klienti“). Vpravo `btn-outline-secondary` „Zmeniť“ (44px) otvorí `.work-sheet` s voľbou mesiaca (predchádzajúci / aktuálny / ďalší / Iný… cez `input type=month`) a klienta (`select`: posledný použitý predvolený, možnosť „Všetci“). Voľba je v URL (`/?month=2026-10&client=3`), bez JS funguje ako GET formulár.
2. **Dlaždice** `.work-tiles`: *Na fakturáciu tento mesiac* (suma a „{n} h zapracovaných“ z nezamknutých častí vybraného mesiaca; odkaz na `/tasks?month=2026-10&state=done`) a *V práci* (suma a „{n} h nacenených“ nezapracovaných úloh; odkaz na `/tasks?state=in_progress`). Pri viacerých menách **jedna dlaždica na menu** (popisok doplnený o kód meny), nikdy sa nesčítavajú, riadok dlaždice má vždy symbol meny pri sume.
3. **Skupiny** `.work-group` (skupina sa vynechá, ak je prázdna; poradie pevné): **Zapracované, do faktúry** (úlohy `done` s nezamknutou časťou vybraného mesiaca; badge s mesiacom „Okt“), **Čiastočne vyfakturované** (s `.work-progress`), **V práci** (nezapracované). Každá skupina max 10 riadkov, pod nimi „Zobraziť všetky úlohy“ (text-link 44px) na `/tasks` s príslušným filtrom.
4. `.work-fab` „+“ vedie na `/tasks/new`.

Dlaždice a skupiny sú fragment `#dashboard-body` (`hx-get="/fragments/dashboard"`, `hx-trigger="allocationChanged from:body"`, `Vary: HX-Request`). Bez JS sa zobrazia pri načítaní stránky. Obnova nesmie posunúť scroll ani zrušiť otvorený panel.

Fokálny bod: dlaždica „Na fakturáciu tento mesiac“ (vľavo hore, najväčšie číslo).

### 2. Zoznam úloh (`/tasks`, „Aktuálne úlohy“)

- `h1` „Aktuálne úlohy“, 12px muted „{n} úloh“. Obsah: úlohy nezapracované **alebo** s nezamknutými časťami (D-06).
- **Filtre** (jedna `.app-card` nad zoznamom, GET formulár, stav v URL): Klient (`select`, „Všetci klienti“), Stav (`select`: Aktuálne, V práci, Zapracované, Čiastočne vyfakturované, Vyfakturované, Všetky), Fakturačný mesiac (`input type=month`, voliteľný), Hľadať (`type=search`, placeholder „Názov, odkaz alebo poznámka“). Pod 576px sú filtre zbalené do `collapse` s tlačidlom „Filtre ({n})“ (44px; {n} = počet aktívnych filtrov); od 768px viditeľné v dvoch stĺpcoch. Hľadanie sa spúšťa po 300 ms (`hx-trigger="keyup changed delay:300ms, change"`), `hx-target="#task-list"`, `hx-push-url="true"`, indikátor `.htmx-indicator`. Tlačidlo „Použiť filtre“ je tu pre variant bez JS (`btn-outline-secondary`), „Vymazať filtre“ je text-link.
- **Riadok** `.work-task`: názov + badge stavu; 2. riadok „All4Net · 40 h · 1 080,00 €“; ak má časti, tretí riadok 12px „Vyfakturované 16 h · Naplánované 8 h · Zostáva 16 h“ a `.work-progress`. Celý riadok je odkaz na detail.
- Poradie: nezapracované a čiastočne podľa `created_at` zostupne, potom zapracované (**[predpoklad]**).
- Nový záznam: `.work-fab` na mobile, `btn-primary` „Nová úloha“ v hlavičke od 768px.
- Prázdne stavy: viď Copywriting. Zoznam je jeden agregačný dotaz (bez N+1) a bez stránkovania (desiatky až stovky riadkov, **[predpoklad]** limit 200 riadkov, nad ním hint „Zobrazených prvých 200. Zúžte filter.“).

### 3. Nová / upraviť úlohu (`/tasks/new`, `/tasks/{id}/edit`)

Jedna karta, polia v poradí podľa makety (ide o 30 s cieľ G5):
1. **Klient** (`select` len nearchivovaní, predvolený posledný použitý; pri úprave s existujúcou časťou `.master-fixed` s hintom „Klienta nemožno zmeniť, kým má úloha fakturačné časti.“)
2. **Odkaz na úlohu v portáli**: `type=url`, hint z klienta („Vložte celý odkaz alebo len číslo úlohy.“ ak má klient vzor odkazu; prijaté sa len http/https), pod poľom živá ukážka výsledného odkazu (`hx-get`, fragment `#portal-preview`, text, nie klikateľný odkaz). Pole nie je povinné.
3. **Názov** (povinné, `autocapitalize=sentences`, 1 riadok `input`)
4. dvojica `row g-3`: **Nacenené hodiny** (`inputmode=decimal`, `numeric(7,2)`, čiarka aj bodka, max 2 desatinné) a **Sadzba** (`inputmode=decimal`, predvyplnená sadzbou klienta v mene klienta, s priloženým 12px kódom meny, napr. „EUR / h“; zmena klienta cez `hx-get` obnoví sadzbu a menu, pozri nižšie)
5. **Nacenená suma**: `.master-fixed` riadok „Nacenená suma: 243,00 €“ (živý prepočet cez Alpine CSP pri písaní; server vždy prepočíta, hodnote z klienta neverí, hint pod riadkom „Suma je len orientačná, konečnú počíta server.“)
6. **Poznámka** (`textarea` 3 riadky, nepovinné)

Sticky lišta: „Uložiť úlohu“ (`btn-primary`), „Zrušiť“ (odkaz `btn-outline-secondary`). Po úspechu PRG na detail úlohy (cez htmx `HX-Location`) a flash „Úloha bola uložená.“ Pri **Uložiť a pridať ďalšiu** **[predpoklad]** sa nerobí (nespĺňa jedno tlačidlo = jedna akcia). Validácia 422 cez `invalid-feedback`, fokus na prvé chybné pole.

### 4. Detail úlohy (`/tasks/{id}`)

Karty zhora nadol, 32px medzi sekciami:
1. **Hlavička**: `h1` názov úlohy, pod ním 12px muted „{Klient} · Vytvorená {dátum}“, badge stavu, odkaz „Otvoriť v {portál} ↗“ (text-link 44px, `target=_blank rel="noopener noreferrer"`, zobrazí sa len pri platnej http/https URL). Akcie `.master-actions`: **„Označiť ako zapracovanú“** (`btn-primary`, len ak stav `in_progress`; otvorí `.work-sheet`), „Upraviť úlohu“ (`btn-outline-secondary`), „Vrátiť do stavu V práci“ (`btn-outline-secondary`, len ak `done`; modal ako nižšie), „Zmazať úlohu“ (`btn-outline-danger`, modal; **nevykreslí sa**, ak úloha má zamknutú časť, a namiesto toho sa zobrazí 12px hint „Úloha má vyfakturované časti, nedá sa zmazať.“).
2. **Súhrn hodín**: `.app-card` s `dl.master-kv`: **Nacenené** (28px, „40 h · 1 080,00 €“), **Vyfakturované** („16 h · 432,00 €“), **Naplánované** („8 h · 216,00 €“), **Zostáva** („16 h“, nikdy pod 0, v ukazovateli), `.work-progress` + legenda, `.work-delta` pod nimi.
3. **Fakturačné časti** (`h2` „Fakturačné časti“): zoznam `.work-alloc` zoradený podľa mesiaca; zamknutá časť má zámok, „Zamknuté · FA {číslo}“, nie je odkaz a nemá ovládacie prvky, 12px hint pod zoznamom (ak existuje zamknutá) „Zamknuté časti (na vystavenej faktúre) sa nedajú meniť ani presúvať.“; nezamknutá časť má odkaz „Upraviť“ otvárajúci `.work-sheet`. Pod zoznamom `btn-outline-secondary w-100` **„+ Pridať časť do mesiaca“** (ALC-05, dostupné aj pri nezapracovanej úlohe).
4. **Údaje**: `dl.master-kv`: Klient, Sadzba (v mene), Poznámka („—“ ak prázdna), Vytvorená, Zapracovaná (`done_at` alebo „—“), Odkaz.

Všetky zmeny časti vyvolajú `HX-Trigger: allocationChanged` a fragment `#task-summary` + `#task-allocations` sa nahradí jedným swapom (`hx-swap-oob`).

### 5. Panel „Označiť ako zapracovanú“ (`.work-sheet`, D-11)

Spodný panel (offcanvas-bottom), obsah v poradí:
1. Nadpis `h2` „Označiť ako zapracovanú“, pod ním 12px muted „{názov úlohy} · {n} h“.
2. **Fakturovať v mesiaci**: `.work-month-pick` segmenty **Aktuálny (október 2026)** / **Ďalší (november 2026)** / **Iný…**; predvolený Aktuálny. „Iný…“ ukáže `input type=month` (44px, `min` = aktuálny mesiac − 12, `max` = + 24 **[predpoklad]**).
3. **Hodín na fakturáciu**: `inputmode=decimal`, predvolené = zostatok (nacenené − všetky existujúce časti, min 0) a upraviteľné.
4. **Suma**: `.master-fixed` „Suma: 540,00 €“ s živým prepočtom (hodiny x sadzba úlohy, Alpine CSP, server prepočíta).
5. 12px hint „Ak sa reálne odpracovalo viac alebo menej, upravte hodiny. Zostatok: {n} h.“ Ak zostatok <= 0, hint „Nacenené hodiny sú už rozdelené. Pri 0 h sa nevytvorí nová časť.“
6. Tlačidlá `.master-actions`: **„Potvrdiť“** (`btn-primary`) a „Zrušiť“ (`btn-outline-secondary`).

Po potvrdení: jeden POST, jedna transakcia (stav + časť + audit), odpoveď `HX-Location` na detail s flashom „Úloha je zapracovaná. Časť {hodiny} h sa fakturuje v {mesiac}.“ (alebo „Úloha je zapracovaná.“ pri 0 h). Tlačidlo je počas odoslania `disabled` + spinner (ochrana pred dvojklikom). Panel sa po úspechu zatvára, po 422 ostáva otvorený s chybami.

### 6. Panel časti (pridať / upraviť / presunúť / zmazať)

Rovnaký `.work-sheet`: **Mesiac** (`.work-month-pick`), **Hodiny** (`decimal`, `CHECK > 0`), **Sadzba** (predvolená sadzbou úlohy, prepísateľná), **Suma** (`.master-fixed`, živý prepočet), pri úprave pod nimi hint „Meníte len nezamknutú časť.“ Primárne tlačidlo „Pridať časť“ / „Uložiť časť“; pri úprave navyše „Zmazať časť“ (`btn-outline-danger`) otvorí potvrdenie v rovnakom paneli (nie druhý modal): text a tlačidlá „Zmazať časť“ (`btn-danger`) a „Zrušiť“ (D-10). Presun do iného mesiaca = zmena poľa Mesiac. Server vráti 409 pri zamknutej časti (správa nižšie) a panel zostane otvorený.

### 7. Prechod do stavu „V práci“ a mazanie úlohy

Obe cez Bootstrap modal vzoru z Phase 2/3 (fokus späť na spúšťač, `Esc` zatvára, tlačidlá 44px, na mobile v stĺpci, „Zrušiť“ naspodku).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, tón vecný (ako Phase 2 a 3). Dátumy cez `IntlDateFormatter`, sumy cez `NumberFormatter`.

### Kľúčové texty

| Element | Copy |
|---------|------|
| Primary CTA (úloha) | Uložiť úlohu |
| Primary CTA (zapracovaná) | Potvrdiť |
| Primary CTA (časť) | Pridať časť (pri úprave: Uložiť časť) |
| Otvorenie panelu | Označiť ako zapracovanú, + Pridať časť do mesiaca |
| Nová úloha | Nová úloha (od 768px tlačidlo; na mobile aria-label FAB) |
| Sekundárne | Zrušiť, Upraviť úlohu, Upraviť, Zmeniť, Vymazať filtre, Použiť filtre, Zobraziť všetky úlohy, Vrátiť do stavu V práci |
| Empty state heading (zoznam úloh) | Žiadne aktuálne úlohy |
| Empty state body (zoznam úloh) | Všetky úlohy sú zapracované a vyfakturované. Pridajte novú úlohu tlačidlom „+“. |
| Empty state heading (filter bez výsledkov) | Nič nezodpovedá filtru |
| Empty state body (filter bez výsledkov) | Zmeňte alebo vymažte filtre. |
| Empty state heading (bez klienta) | Najprv pridajte klienta |
| Empty state body (bez klienta) | Úloha sa viaže na klienta so sadzbou. Pridajte klienta v časti Viac, Klienti. (odkaz na `/settings/clients/new`; formulár úlohy sa nevykreslí) |
| Empty state heading (dashboard) | Zatiaľ nič na fakturáciu |
| Empty state body (dashboard) | V mesiaci {mesiac} nie je žiadna zapracovaná časť. Pridajte úlohu a po zapracovaní ju označte tlačidlom „Označiť ako zapracovanú“. |
| Empty state (detail, časti) | Úloha zatiaľ nemá fakturačné časti. Pridajte časť do mesiaca, v ktorom ju budete fakturovať. |
| Popisky súhrnu | Nacenené, Vyfakturované, Naplánované, Zostáva |
| Dlaždice | Na fakturáciu tento mesiac, V práci |
| Skupiny dashboardu | Zapracované, do faktúry · Čiastočne vyfakturované · V práci |
| Error (všeobecná validácia) | Formulár obsahuje chyby. Opravte označené polia a uložte znova. |
| Error (hodiny) | Zadajte hodiny ako číslo väčšie ako 0, najviac na 2 desatinné miesta, napr. 8 alebo 8,5. |
| Error (sadzba) | Zadajte sadzbu ako číslo, napr. 27 alebo 27,50. |
| Error (názov) | Zadajte názov úlohy. |
| Error (odkaz) | Odkaz musí začínať http:// alebo https://. |
| Error (klient archivovaný / neexistuje) | Vybraný klient nie je dostupný. Vyberte iného klienta. |
| Error (zamknutá časť, 409) | Časť je na vystavenej faktúre a nedá sa meniť ani presunúť. Zmena je možná až po dobropise. |
| Error (zmazať úlohu so zamknutou časťou, 409) | Úloha má vyfakturované časti a nedá sa zmazať. |
| Error (zmena klienta s časťami, 422) | Klienta nemožno zmeniť, kým má úloha fakturačné časti. |
| Error (mesiac) | Zvoľte mesiac v rozsahu od {od} do {do}. |
| Error (sieť / server / CSRF) | existujúce `App.errorNetwork`, `App.errorServer`, `errors/csrf_403.php` (nemeniť) |
| Error (404) | Úloha sa nenašla. Vráťte sa na zoznam úloh. |
| Success flashe | „Úloha bola uložená.“ · „Úloha je zapracovaná. Časť {h} h sa fakturuje v {mesiac}.“ · „Časť bola pridaná.“ · „Časť bola uložená.“ · „Časť bola zmazaná.“ · „Úloha bola zmazaná.“ · „Úloha je opäť v stave V práci.“ |
| Hint (nacenená suma) | Suma je len orientačná, konečnú počíta server. |
| Hint (mena) | Mena úlohy je mena klienta: {mena}. |

### Popisky polí (kľúče `Tasks.php`, `Allocations.php`, `Dashboard.php`)

Úloha: Klient, Odkaz na úlohu, Názov, Nacenené hodiny, Sadzba, Nacenená suma, Poznámka, Stav, Vytvorená, Zapracovaná. Filtre: Klient, Stav, Fakturačný mesiac, Hľadať. Časť: Fakturovať v mesiaci, Hodiny, Sadzba, Suma, Mena. Hodnoty filtra Stav: Aktuálne, V práci, Zapracované, Čiastočne vyfakturované, Vyfakturované, Všetky.

### Destruktívne a nevratné akcie

| Akcia | Potvrdenie |
|-------|-----------|
| Zmazať úlohu | Zmazať úlohu: „Úloha {názov} a jej naplánované časti ({n}) budú zmazané. Vyfakturované časti úloha nemá. Akciu nemožno vrátiť. Zmazať?“ (tlačidlo „Zmazať úlohu“, modal, len ak nie je zamknutá žiadna časť) |
| Zmazať časť | Zmazať časť: „Časť {mesiac}, {h} h, {suma} bude zmazaná z úlohy {názov}. Akciu nemožno vrátiť. Zmazať?“ (tlačidlo „Zmazať časť“, potvrdenie v spodnom paneli, D-10) |
| Vrátiť do stavu V práci | Vrátiť do stavu V práci: „Úloha {názov} sa opäť bude zobrazovať ako rozpracovaná. Existujúce časti ostanú zachované. Vrátiť?“ (tlačidlo „Vrátiť do stavu V práci“, modal, zapíše sa do auditu) |
| Označiť ako zapracovanú | bez potvrdzovacieho modalu (panel je potvrdením); je vratné cez „Vrátiť do stavu V práci“ |
| Presun časti do iného mesiaca | bez modalu, nie je destruktívna |

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid (`is-invalid` + `invalid-feedback`, `aria-describedby`), disabled, loading (tlačidlo `disabled` + `.spinner-border-sm` v `.htmx-indicator`, text ostáva). Všetky formuláre fungujú ako klasický POST + PRG bez JS. htmx len pre: filter zoznamu, živú ukážku odkazu, obnovu sadzby po zmene klienta, obsah panelov, obnovu dashboardu a súhrnu úlohy. `422` swapuje formulár s chybami (existujúci `responseHandling`), `409` zobrazí alert v paneli. Úspešný POST cez htmx vráti `HX-Location`/`HX-Redirect`, nie 302 (D-15). Fragmenty majú `Vary: HX-Request`.
- **Živý prepočet sumy** (Alpine CSP): zdroj je string hodín a sadzby, výpočet len zobrazuje, nikdy neukladá. Zaokrúhlenie half-up na 2 miesta, rovnako ako server (`Money`). Pri neplatnom vstupe sa zobrazí „—“ (nie 0,00).
- **Nezamknuté a zamknuté časti**: zamknuté riadky nemajú žiadny ovládací prvok (ani `disabled`), serverový 409 je nezávislá línia. UI nikdy nespolieha na skrytie tlačidla.
- **Mena**: každá suma sa píše so symbolom meny; viac mien sa nikdy nesčítava (dlaždice podľa meny, súhrny podľa meny).
- **Rozloženie jednou rukou**: primárna akcia formulára vo sticky lište; FAB vpravo dole na zozname a dashboarde; panely zospodu s tlačidlami v dosahu palca. Cieľ G5: pridanie a zapracovanie úlohy na iPhone do 30 s (jedna karta, poradie polí podľa makety, predvolený klient a mesiac, predvolené hodiny).
- **Prístupnosť**: každé pole `<label for>`, skupiny radio v `<fieldset><legend>`, panel s `aria-labelledby`, správny fokus pri otvorení (prvé pole) a zatvorení (späť na spúšťač), `Esc` zatvára, chyby `aria-describedby`, flash `role=alert` s `aria-live=polite`, kontrast min 4.5:1 (`#6b7280` na `#fff` spĺňa; `#adb5bd` slúži len ako výplň ukazovateľa, informácia je vždy aj textom), farba nikdy sama nenesie stav. `.work-progress` má textový ekvivalent.
- **Mobilné klávesnice**: `inputmode=decimal` (hodiny, sadzba), `type=url`, `type=month`, `type=search`. Veľkosť polí 16px (iOS zoom).
- **Chyby prístupu**: neexistujúce ID alebo úloha, časť nepatriaca úlohe = 404; všetky zápisy len POST + CSRF (`HtmxCsrf`).
- **Audit v UI**: denník sa v tejto fáze nezobrazuje (AUD-02 je Phase 11), zmeny sa zapisujú cez `AuditLogger` v tej istej transakcii.
- **Offline**: stránky Phase 4 sa nekešujú (SW nikdy nekešuje HTML s autentifikáciou).
- **Import**: `spark import:excel` je CLI, nemá UI. Importované úlohy sa v UI správajú ako ručne zadané (zapracované s nezamknutou časťou alebo V práci).
- **Mimo rozsahu**: dlaždica po splatnosti, limity, faktúry, odkazy na faktúry (číslo faktúry pri zamknutej časti sa zobrazí až v Phase 5), prehliadač denníka.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka z CONTEXT.md | Rozhodnutie |
|---------------------|-------------|
| Dashboard, mesiac a klient | Predvolene aktuálny kalendárny mesiac a posledný použitý klient; prepínač v paneli „Zmeniť“, voľba „Všetci“, stav v URL |
| Viac mien | Samostatné dlaždice podľa meny, nikdy súčet |
| Forma ukazovateľa priebehu | Dvojsegmentový `.progress` 8px (vyfakturované + naplánované), max 100 %, text pod ním |
| Rozloženie PC zoznamu | Rovnaké riadky ako na mobile, od 768px s pevnými stĺpcami hodín a súm, bez tabuľky |
| Mechanizmus posledného klienta | Dohodnuté na strane implementácie (nastavenie používateľa alebo odvodenie z poslednej úlohy); UI len predvolí hodnotu v `select` |
| Text tlačidiel a toastov | Definované v Copywriting Contract |
| Zapracovaná bez zostatku | Hodiny 0 sa akceptujú, nevytvorí sa časť; hint v paneli |
| Návrat do V práci | `btn-outline-secondary` + modal, existujúce časti ostávajú |
| Odstránenie časti | Potvrdenie v tom istom spodnom paneli, nie druhý modal |
| Zmena klienta s časťami | Pole klient sa zamkne v UI a server odmietne (422) |
| Dátumové rozsahy mesiaca (**[predpoklad]**) | Panel mesiaca: aktuálny mesiac − 12 až + 24 |

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
