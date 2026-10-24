---
phase: 2
slug: identita-a-skore-nasadenie
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-24
---

# Phase 2 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 2 (Identita a skoré nasadenie). Jazyk UI je slovenčina, všetky texty iba cez `lang()` (`app/Language/sk/*`, doplniť `en`). Žiadne inline `style=""`, inline skripty ani `onclick` (prísna CSP, `ViewsCspTest`, `ViewsHardcodedTextTest`).
> Zdroje: CONTEXT.md (D-01 až D-24), CLAUDE.md (locked stack), existujúci základ `public/assets/css/app.css` a `app/Views/layouts/app.php` (Phase 1).

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (shadcn sa nepoužíva, projekt nie je React/Next/Vite, stack je PHP + Bootstrap, locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (`public/assets/vendor/bootstrap-5.3.8.min.css`, `bootstrap.bundle-5.3.8.min.js`), htmx 2.0.11, Alpine.js CSP build 3.17.5 |
| Icon library | žiadna knižnica, žiadne icon fonty. Iba inline SVG v `<svg aria-hidden="true">` (jednofarebné, `currentColor`), minimálne a iba tam, kde nesú význam (stav zariadenia, inštalačný panel). Ikona aplikácie viď PWA identita |
| Font | systémový stack z `app.css`: `-apple-system, system-ui, sans-serif` (žiadne webfonty, rýchlosť na mobile) |

Reuse zo Phase 1 (neprepisovať): `--app-bg #f5f6f8`, `--app-border #e3e5e8`, `--app-muted #6b7280`, `--app-primary #0d6efd`, `.app-card`, `.app-header`, `.app-main`, `.app-tabbar`, `#app-alert` + `fragments/alert.php`, `.btn/.form-control { min-height: 44px }`, `input { font-size: 16px }` (zabraňuje iOS zoomu pri focuse).

Nové layouty a súbory:

| Súbor | Účel |
|-------|------|
| `app/Views/layouts/auth.php` | Jednoduchý layout bez navigácie a bez tabbaru pre login, 2FA, reset hesla. Rovnaké `<head>` ako `app.php` (CSP, htmx-config, theme-color, safe-area), `lang` z `Accept-Language` (D-23) |
| `app/Views/auth/*` | login, totp_setup, totp_verify, backup_codes, forgot, reset |
| `app/Views/settings/security.php` + parciály | Nastavenia → Zabezpečenie (D-20) |
| `app/Views/pwa/offline.php` | Offline stránka (samostatný minimálny HTML dokument bez htmx a Alpine, iba `app.css` a Bootstrap CSS z cache SW) |
| `app/Views/layouts/_install_hint.php` | Zatvárateľný inštalačný panel (D-24) |
| `public/assets/css/app.css` | Doplniť triedy s prefixom `.auth-*`, `.qr-*`, `.code-*`, `.install-hint`. Nová verzia súboru = nová verzia v názve (cache busting cez `asset_url`) |

Layout `auth.php`: obsah centrovaný v `.auth-card` (max-width 400px, `margin: 0 auto`), na šírke < 576px pri okrajoch 16px, na výšku `min-height: 100dvh`, karta zarovnaná zhora s horným odsadením 48px (nie vertikálne na stred, aby sa pri otvorení iOS klávesnice formulár neposúval mimo obrazovky). Safe area: `padding-top: calc(env(safe-area-inset-top) + 48px)`, `padding-bottom: calc(env(safe-area-inset-bottom) + 24px)`. Nad kartou textové logo „Work Tracker“ (ikona 48x48 SVG + názov, `h1`).

---

## Spacing Scale

Declared values (multiples of 4):

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | medzera ikona a text, medzera label a pomocný text |
| sm | 8px | medzera medzi tlačidlami v riadku, vnútorný padding badge, medzera medzi riadkami zoznamu |
| md | 16px | predvolené odsadenie polí formulára (`mb-3` = 16px), okraje karty na mobile, padding `.auth-card` |
| lg | 24px | padding karty na desktope, odstup sekcií v Zabezpečení |
| xl | 32px | odstup medzi blokmi (QR a tajomstvo, kódy a potvrdenie) |
| 2xl | 48px | horné odsadenie auth layoutu |
| 3xl | 64px | výška spodnej lišty (`--app-tabbar-h`), spodná rezerva obsahu |

Exceptions:
- Dotykové ciele (tlačidlá, polia, odkazy v zozname, checkbox riadok, zatvárací × v paneli): minimálne **44px** výška aj šírka (`min-height: 44px`, pri ikonovom tlačidle `min-width: 44px`).
- Existujúce hodnoty z Phase 1 (`10px`, `12px`, `14px` v `.app-header`, `.app-main`, `.app-card`) sa nemenia, sú legacy. **Nový kód Phase 2 používa iba tokeny vyššie.**
- Bootstrap utility `mb-3` (16px), `mb-4` (24px), `gap-2` (8px), `p-3` (16px), `p-4` (24px) sú povolené, lebo zodpovedajú tokenom. Nepoužívať `*-1` (4px) mimo xs a `*-5` (48px) mimo 2xl.
- Safe area (`env(safe-area-inset-*)`) sa pripočítava navyše k tokenom.

---

## Typography

Iba 4 veľkosti a 2 hrúbky pre všetky obrazovky Phase 2.

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, text, položky zoznamov) | 16px | 400 | 1.5 |
| Label / meta (popisky polí, pomocný text, IP, čas, UA, tabbar 11px sa nemení, legacy) | 12px | 400 | 1.5 |
| Heading (názov stránky `h1`, nadpis karty/sekcie `h2`) | 20px | 600 | 1.2 |
| Display (iba šesťčíslicový TOTP kód pri vstupe a tajomstvo/záložné kódy v monospace) | 28px | 600 | 1.2 |

Pravidlá:
- Hrúbky iba 400 a 600. Prepísať Bootstrap predvolenú 500 pre `h1`–`h6` a `.btn` na 600 / 400 (`.btn { font-weight: 600 }`, `.form-label { font-weight: 600 }` vyžaduje 12px a 600 len pre labely, inak 400).
- Polia vstupu majú vždy 16px (iOS zoom). Pole TOTP kódu: 28px, `letter-spacing: .25em`, `font-variant-numeric: tabular-nums`, `inputmode="numeric"`, `autocomplete="one-time-code"`, `pattern="[0-9]*"`, `maxlength=6`, zarovnané na stred.
- Tajomstvo na ručné zadanie a záložné kódy: `font-family: ui-monospace, SFMono-Regular, Menlo, monospace`; tajomstvo 16px (zobrazené po skupinách po 4 znakoch, `user-select: all`), záložné kódy 20px/600 vo formáte `XXXXX-XXXXX` (mriežka 2 stĺpce na mobile, 10 kódov = 5 riadkov). Display 28px sa používa iba pri vstupe TOTP.
- Veľkosti zodpovedajú Bootstrap `rem`: 12px = `.small`, 16px = základ, 20px = `.h5`, 28px = vlastná trieda `.auth-code-input`.

---

## Color

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` (`--app-bg`) | pozadie stránky (body), pozadie `auth` layoutu |
| Secondary (30%) | `#ffffff` | `.app-card`, `.auth-card`, hlavička, tabbar, tabuľky a zoznamy v Zabezpečení |
| Accent (10%) | `#0d6efd` (`--app-primary`) | viď zoznam nižšie |
| Destructive | `#dc3545` (Bootstrap `danger`) | iba destruktívne akcie a chybové stavy |

Text: `#1f2328` hlavný, `#6b7280` (`--app-muted`) pomocný (kontrast na `#fff` 4.8:1, splňa AA). Okraje `#e3e5e8`. Úspech `#198754` (Bootstrap `success`) iba pre stav „2FA aktívna“, „úspešné prihlásenie“ v denníku (vždy aj s textom, nikdy iba farba).

Accent reserved for (výlučne):
1. Primárne tlačidlo jednej akcie na obrazovke (`btn-primary`): Prihlásiť sa, Overiť kód, Aktivovať 2FA, Pokračovať (po záložných kódoch), Nastaviť nové heslo, Uložiť heslo
2. Aktívna položka navigácie (`aria-current="page"`)
3. Focus ring polí a tlačidiel (Bootstrap predvolený odtieň `#0d6efd`)
4. Textové odkazy „Zabudnuté heslo?“ a „Použiť záložný kód“
5. Pozadie ikony aplikácie (`#0d6efd`) a `theme-color`

Sekundárne akcie (Stiahnuť .txt, Zatvoriť, Naspäť na prihlásenie) = `btn-outline-secondary`. Nepoužívať accent pre ostatné tlačidlá, badge ani nadpisy.

Destructive (`#dc3545`) iba pre: tlačidlo Odvolať zariadenie (`btn-outline-danger`), Pregenerovať záložné kódy (`btn-outline-danger`, lebo ruší staré kódy), chybové hlášky (`alert-danger`, `invalid-feedback`), badge „Neúspešné“ a „Zámok“ v denníku.

Tmavý režim: mimo rozsahu (`data-bs-theme="light"` pevne).

---

## Copywriting Contract

Všetky reťazce v `app/Language/sk/Auth.php`, `Security.php`, `Pwa.php`. Tykanie nie, vykanie nie: neutrálne vety bez oslovenia, tón vecný.

### Kľúčové texty

| Element | Copy |
|---------|------|
| Primary CTA (login) | Prihlásiť sa |
| Primary CTA (2FA overenie) | Overiť kód |
| Primary CTA (aktivácia 2FA) | Aktivovať 2FA |
| Primary CTA (po záložných kódoch) | Pokračovať (deaktivované, kým nie je zaškrtnuté „Uložil som si ich“) |
| Primary CTA (reset hesla, žiadosť) | Poslať odkaz na obnovu |
| Primary CTA (reset hesla, nové heslo) | Nastaviť nové heslo |
| Empty state heading (zoznam zariadení) | Žiadne dôveryhodné zariadenia |
| Empty state body (zoznam zariadení) | Zapamätať zariadenie môžete pri ďalšom prihlásení. Dovtedy sa kód 2FA vyžaduje pri každom prihlásení. |
| Empty state heading (denník) | Zatiaľ žiadne prihlásenia |
| Empty state body (denník) | Pokusy o prihlásenie sa tu zobrazia hneď po prvom prihlásení. |
| Error (neplatné údaje) | E-mail alebo heslo nie sú správne. Skontrolujte ich a skúste znova. (rovnaká hláška pre neexistujúci účet aj zlé heslo, bez prezradenia, či účet existuje) |
| Error (neplatný kód) | Kód nie je správny alebo už bol použitý. Zadajte aktuálny kód z aplikácie alebo záložný kód. |
| Error (zámok) | Príliš veľa nesprávnych pokusov. Skúste to znova o 15 minút. |
| Error (reláciou vypršala / CSRF 403) | Platnosť formulára vypršala. Obnovte stránku a skúste znova. (existujúci `errors/csrf_403.php` a `#app-alert`) |
| Error (sieť / server) | existujúce `App.errorNetwork`, `App.errorServer`, nemeniť |
| Error (reset token neplatný) | Odkaz na obnovu hesla je neplatný alebo vypršal. Vyžiadajte si nový. |
| Info (reset odoslaný) | Ak účet s týmto e-mailom existuje, poslali sme naň odkaz na obnovu hesla. (vždy rovnaká odpoveď) |
| Info (po resete hesla) | Heslo bolo zmenené. Na prihlásenie bude potrebný aj kód 2FA. |

### Prihlasovacia obrazovka (`/login`)

- Nadpis: Prihlásenie. Polia: „E-mail“ (`type=email`, `autocomplete=username`, `autocapitalize=none`), „Heslo“ (`autocomplete=current-password`).
- Checkbox „Zapamätať zariadenie“ je na tejto obrazovke **vynechaný**, je až na kroku 2FA (D-17: dôvera sa viaže na zadanie TOTP).
- Odkaz: „Zabudnuté heslo?“. Žiadna registrácia ani odkaz na vytvorenie účtu (D-09).
- Pri 422 sa chyba zobrazí v `alert-danger` nad formulárom a pole zostáva vyplnené (okrem hesla), fokus na prvé chybné pole.

### Nastavenie 2FA pri prvom prihlásení (`/2fa/setup`)

- Nadpis: Nastavenie dvojfázového overenia. Krok text: „Dvojfázové overenie je povinné. Naskenujte QR kód v aplikácii Google Authenticator alebo Microsoft Authenticator.“
- QR: inline SVG generované lokálne, 200x200px (max 100% šírky karty), biele pozadie s okrajom 16px, `role="img"` a `aria-label="QR kód pre aplikáciu na overenie"`. Žiadny `<img src>` ani externá služba.
- Pod ním: „Nedá sa naskenovať? Zadajte tajomstvo ručne:“ + tajomstvo v monospace a tlačidlo „Kopírovať“ (`btn-outline-secondary`, po kliknutí na 2 s text „Skopírované“; Alpine CSP, iba pomocné, výber textu cez `user-select: all` funguje aj bez JS).
- Pole „Šesťčíslicový kód z aplikácie“ + CTA „Aktivovať 2FA“. TOTP sa aktivuje až po overení kódu (D-10).

### Overenie kódu pri prihlásení (`/2fa/verify`)

- Nadpis: Overenie kódom. Text: „Zadajte šesťčíslicový kód z aplikácie na overenie.“
- Pole kódu (Display štýl), CTA „Overiť kód“. Fokus automaticky v poli.
- Checkbox (predvolene zaškrtnutý, D-16): „Zapamätať si toto zariadenie na 30 dní“, pod ním 12px pomocný text: „Heslo ani kód 2FA sa na tomto zariadení nebudú pýtať 30 dní. Nezapínajte na cudzom zariadení.“ Je to jediné miesto s touto voľbou (platí pre kód z aplikácie aj záložný kód).
- Prepínač: odkaz „Použiť záložný kód“ prepne pole (htmx swap formulára alebo samostatná URL `/2fa/backup`) na „Záložný kód“ (`autocomplete=off`, formát `XXXXX-XXXXX`, 16px monospace) s odkazom „Použiť kód z aplikácie“. Po úspešnom použití záložného kódu flash info: „Záložný kód bol použitý. Zostáva ich {n}. Pregenerujte ich v Nastaveniach, ak ich je málo.“ (pri n ≤ 3 `alert-warning`).
- Odkaz „Zrušiť a odhlásiť“ (text-link, 44px cieľ) ukončí stav „čaká na 2FA“.

### Záložné kódy (po aktivácii a po pregenerovaní)

- Nadpis: Záložné kódy. Upozornenie `alert-warning`: „Tieto kódy uvidíte iba teraz. Každý funguje raz. Uložte si ich na bezpečné miesto.“
- Mriežka 10 kódov, tlačidlo „Stiahnuť .txt“ (`btn-outline-secondary`, odkaz s `download="work-tracker-zalozne-kody.txt"`, obsah generuje server jednorazovo z `data:` URL alebo POST odpovedi, `Cache-Control: no-store`; súbor obsahuje názov aplikácie, e-mail, dátum a 10 kódov).
- Potvrdenie: checkbox „Uložil som si ich“ (44px riadok), CTA „Pokračovať“ je `disabled` a aktivuje sa po zaškrtnutí (Alpine CSP, a serverovo sa vyžaduje POST s potvrdením). Obrazovka nemá odkaz preč, kým nie je potvrdené (AUTH-09 a D-11). Po opustení sa kódy nezobrazia nikdy.

### Zabezpečenie (`Nastavenia → Zabezpečenie`, D-20)

Jedna stránka, karty `.app-card` zhora nadol, medzera medzi nimi 16px:

1. **Heslo**: nadpis „Heslo“, polia „Súčasné heslo“, „Nové heslo“, „Nové heslo znova“, CTA „Zmeniť heslo“. Pomocný text: „Min. 12 znakov.“ (pravidlo dĺžky potvrdí planner). Po úspechu `alert-success`: „Heslo bolo zmenené. Ostatné zariadenia boli odhlásené.“
2. **Dvojfázové overenie**: nadpis „Dvojfázové overenie“, badge `text-bg-success` „Aktívne“, riadok „Zostáva záložných kódov: {n} z 10“, tlačidlo „Pregenerovať záložné kódy“ (`btn-outline-danger`).
3. **Dôveryhodné zariadenia**: nadpis „Dôveryhodné zariadenia“, zoznam kariet (nie tabuľka, kvôli mobilu): riadok 1 názov zariadenia (parsované z UA, napr. „iPhone, Safari“) + badge „Toto zariadenie“ (`text-bg-secondary`) ak ide o aktuálne; riadok 2 (12px muted) „Prvé použitie {dátum}, naposledy {dátum}, platí do {dátum}“; tlačidlo „Odvolať“ vpravo (`btn-outline-danger btn-sm` s min 44x44).
4. **Posledné prihlásenia**: nadpis „Posledné prihlásenia“, podnadpis (12px) „Zobrazuje sa posledných 50 záznamov.“ Zoznam kariet na mobile (čas a stav v prvom riadku, v druhom „IP · zariadenie · dôvod“), tabuľka `table-sm` od `≥ 768px`. Stav ako badge s textom: „Úspešné“ (`text-bg-success`), „Neúspešné“ (`text-bg-danger`), „Zámok“ (`text-bg-danger`). Dôvody (lang): „Nesprávne heslo“, „Nesprávny kód 2FA“, „Použitý záložný kód“, „Dočasný zámok“, „Odhlásené po nečinnosti“, „Obnova hesla“. IP v monospace 12px. Nikdy sa nezobrazuje token, kód ani heslo (pasca 18).

Čas formátovať cez `IntlDateFormatter` v lokálnom jazyku (`2. 11. 2026 14:05`), časová zóna z nastavení (predvolene `Europe/Bratislava`).

### Destruktívne akcie

| Akcia | Potvrdenie |
|-------|-----------|
| Odvolať zariadenie | Odvolať zariadenie: „Na zariadení {názov} sa bude pri ďalšom prihlásení vyžadovať heslo aj kód 2FA. Odvolať?“ Potvrdenie v Bootstrap modale (`modal` s tlačidlami „Zrušiť“ a „Odvolať zariadenie“ v `btn-danger`). Ak je zariadenie aktuálne, doplniť: „Budete odhlásení.“ |
| Pregenerovať záložné kódy | Pregenerovať záložné kódy: „Všetky doterajšie záložné kódy prestanú platiť. Nové kódy uvidíte iba raz. Pokračovať?“ (modal, „Zrušiť“ a „Pregenerovať kódy“ v `btn-danger`). Vyžaduje zadanie aktuálneho hesla v modale (re-auth). Potom obrazovka Záložné kódy |
| Zmena hesla | bez modalu (formulár vyžaduje súčasné heslo) |

Modaly: Bootstrap 5 modal cez `data-bs-*` atribúty (bez inline JS), fokus sa vracia na spúšťač, `Esc` a klik mimo zatvárajú, tlačidlá v päte min 44px a na mobile v stĺpci na plnú šírku (spodné tlačidlo „Zrušiť“).

### Offline stránka (`/offline`)

- Nadpis: Ste offline. Text: „Pripojenie nie je dostupné. Work Tracker potrebuje internet, aby ukázal aktuálne údaje.“ CTA „Skúsiť znova“ (`btn-primary`, `location.reload` cez `data-` atribút a `app.js`/externý súbor, nie inline). Zobrazí sa iba pri zlyhaní navigácie (SW). Neobsahuje žiadne dáta používateľa (SW nikdy necachuje autentifikované HTML).

### Inštalačný panel (D-24)

- Zobrazí sa iba ak: iOS Safari (UA/`navigator.standalone === false`, `display-mode` nie je standalone) a nebol zatvorený (`localStorage` kľúč `wt_install_hint_dismissed`). Panel je `alert` v hornej časti `.app-main` (nad obsahom, nie fixed), `alert-light` s okrajom, vnútorný padding 16px.
- Text: „Pridajte si Work Tracker na plochu: ťuknite na Zdieľať, potom Pridať na plochu.“ Malá SVG ikona zdieľania (inline, 20px) pred textom. Zatvárací tlačidlo „×“ 44x44 s `aria-label="Zavrieť"`. V standalone režime sa nevykreslí vôbec.
- Zobrazuje sa na prihlasovacích aj prihlásených stránkach (jednotný partial), ale nie na offline stránke.

### Ostatné texty

| Element | Copy |
|---------|------|
| Odkaz späť | Späť na prihlásenie |
| Odhlásenie po nečinnosti (2 h, nedôveryhodné) | Boli ste odhlásený po 2 hodinách nečinnosti. Prihláste sa znova. |
| Reset hesla, nadpis | Obnova hesla; pole „E-mail“ |
| Reset hesla, krok 2 | Nové heslo; polia „Nové heslo“, „Nové heslo znova“ |
| Zámok účtu (info pod formulárom) | Účet je dočasne zamknutý. Skúste to znova o {n} min. |
| Názov stránky (`<title>`) | „{Stránka} · Work Tracker“ |

---

## Interaction and State Contract

- **Stavy formulára**: default, focus (accent ring), invalid (`is-invalid` + `invalid-feedback` pod poľom, 12px), disabled, loading. Pri odoslaní cez htmx: tlačidlo dostane `disabled` a spinner `.spinner-border-sm` (`.htmx-indicator`), text ostáva (pozor na `includeIndicatorStyles=false`, viď existujúce `.htmx-indicator` v `app.css`). Prihlasovacie a 2FA formuláre fungujú aj bez JS (klasický POST + redirect, htmx iba vylepšenie), pretože vyžadujú CSRF cez `HtmxCsrf`.
- **Odpovede**: úspech login = redirect (PRG), 422 = swap formulára s chybami (`responseHandling` z layoutu už 422 swapuje), 403 CSRF = `#app-alert`. `Vary: HX-Request` pre fragmenty.
- **Spinner/disabled** na všetkých tlačidlách odosielajúcich POST (ochrana pred dvojitým kliknutím).
- **Fokus a klávesnica**: pri načítaní prvé pole dostane `autofocus` (na iOS bez efektu je to v poriadku), poradie tabulátora logické, `Enter` odošle formulár, viditeľný focus ring nikdy nevypínať.
- **Prístupnosť**: každé pole má `<label for>`, chyby cez `aria-describedby`, `#app-alert` `role=alert`, stav zámku a úspechu `aria-live=polite`, kontrast min. 4.5:1, farba nikdy nie je jediný nositeľ významu, `lang` atribút podľa jazyka stránky.
- **Mobil (iPhone, jedna ruka)**: primárne tlačidlo na plnú šírku (`w-100`, 48px výška cez `btn-lg` pri auth), sekundárne pod ním, nie vedľa seba (< 576px). Žiadne horizontálne posúvanie (`overflow-x` neskrývať, riešiť skladaním do stĺpca, tabuľka denníka iba od 768px, pod tým karty). Safe-area odsadenia.
- **Navigácia**: v prihlásenom layoute pribudne v spodnej lište a v hornej navigácii položka „Viac“ (už je v `_nav_items.php` ako disabled `span`) ako odkaz na `/settings/security` s položkou „Zabezpečenie“ a „Odhlásiť sa“. Na mobile jednoduchý zoznam na stránke `/more` (dve riadky, každý min 48px), nie dropdown. Odhlásenie je POST formulár (CSRF), nie GET odkaz. Tabbar má 4 stĺpce, nemeniť.
- **Auth layout**: bez navigácie, bez tabbaru, bez hlavičky; iba logo a karta.
- **Relácia**: odhlásenie po nečinnosti (D-18) presmeruje na `/login` s info hláškou z tabuľky vyššie.

---

## PWA identita a iOS

| Položka | Hodnota |
|---------|---------|
| `name` a `short_name` | „Work Tracker“ (D-21), `apple-mobile-web-app-title` rovnako |
| `display` | `standalone`, `start_url` `/`, `scope` `/`, `lang` `sk` |
| `theme_color` | `#0d6efd` (zhoda s `<meta name="theme-color">` v `app.php`) |
| `background_color` | `#f5f6f8` (zodpovedá bg, biele bliknutie pri štarte sa tak vyhne) |
| Ikona (D-22) | jednoduchá SVG: biele písmená „WT“ (600, zaoblená geometria) vycentrované na štvorci `#0d6efd`; bezpečná zóna pre maskable = obsah vo vnútornom kruhu 80% (písmená do 60% šírky). Zdroj `public/assets/icons/icon.svg`, odvodené PNG: `apple-touch-icon` 180, 192, 512, `maskable-512` (plné pozadie bez zaoblenia, iOS si rohy zaoblí sám) |
| iOS meta | `apple-mobile-web-app-capable=yes`, `apple-mobile-web-app-status-bar-style=default` (svetlá lišta, biela hlavička), `viewport-fit=cover` (už je) |
| Service worker | `/sw.js` na koreni, `Cache-Control: no-cache`; precache iba statika (`app.css`, vendor JS/CSS, ikony, offline stránka); **nikdy** nekešuje HTML ani odpovede s autentifikáciou; navigácia pri zlyhaní siete vráti `/offline` |
| Offline vzhľad | `.auth-card` štýl, ikona offline (inline SVG, `currentColor`, 48px, muted), text a CTA z kapitoly Copywriting |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/` s verziou v názve a `SHA256SUMS`. Žiadne CDN.

---

## Checker Sign-Off

- [x] Dimension 1 Copywriting: FLAG (non-blocking)
- [x] Dimension 2 Visuals: FLAG (non-blocking)
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: FLAG (non-blocking)
- [x] Dimension 5 Spacing: PASS
- [x] Dimension 6 Registry Safety: PASS

**Approval:** approved 2026-10-24 (gsd-ui-checker, 3 non-blocking FLAGs)
