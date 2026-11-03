---
phase: 11
slug: prehlady-exporty-a-audit
status: draft
shadcn_initialized: false
preset: none
created: 2026-11-04
---

# Phase 11 — UI Design Contract

> Vizuálny a interakčný kontrakt pre Phase 11 (Prehľady, exporty a audit: RPT-01, RPT-02, RPT-03, AUD-02, UI-03). Dedí tokeny z Phase 2 až 10 bez zmeny (spacing násobky 4, 4 veľkosti písma, 2 hrúbky, farby 60/30/10, 44px ciele). Jazyk UI je slovenčina, texty iba cez `lang()` (nové súbory `app/Language/sk/Reports.php`, `Export.php`, `AuditLog.php`, `Users.php` + rovnaké kľúče v `en`; doplnky do `Invoices.php`, `Tasks.php`; PL/CS preklady Phase 12). Žiadne inline `style=""`, inline skripty ani `onclick` (`ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`). Alpine sa v tejto fáze nepoužíva, htmx `allowEval=false`.
> Zdroje: 11-CONTEXT.md (D-01 až D-16), 10-UI-SPEC.md, 04-UI-SPEC.md, CLAUDE.md, existujúce `app/Views/invoices/index.php` + `_list.php`, `more/index.php`, `public/assets/css/app.css` (`.app-main` max 960px od 992px, `.work-wide`, `.inv-row`, `.lim-*`). Vlastník nechce interaktívnu diskusiu; kroky mimo CONTEXT sú označené **[voľnosť]** (Claude's discretion).
> Rozsah: (A) Prehľady mesiac/rok, (B) export CSV/XLSX, (C) Balík pre účtovníčku, (D) Denník zmien, (E) PC tabuľky úloh a faktúr (UI-03), (F) Používatelia (read-only), (G) menu „Viac“.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (PHP + Bootstrap locked v CLAUDE.md). Registry safety gate: not applicable |
| Preset | not applicable |
| Component library | Bootstrap 5.3.8 (lokálne), htmx 2.0.11 |
| Icon library | žiadna, iba inline SVG `aria-hidden="true"` (`currentColor`); stav nikdy iba ikonou |
| Font | systémový stack z `app.css`, čísla `tabular-nums` |

Reuse (neprepisovať): `.app-card`, `.work-wide`, `.work-group`, `.master-kv`, `.btn-touch`, `.btn/.form-control/.form-select { min-height: 44px }`, `input { font-size: 16px }`, `.dl-sheet`, `.more-item`, `.inv-row`, `.st-badge`, `data-submit-once`, `HtmxCsrf`, `fragments/alert.php`, `.htmx-indicator`, vzor `hx-get` + `hx-push-url` + `hx-target` z `invoices/index.php`, `Table` Bootstrap (`.table`).

Nové triedy v `app.css` (nová verzia súboru v názve cez `asset_url`; prefix `.rpt-*`, `.aud-*`, `.dt-*`), bez nových farieb a veľkostí:

| Trieda | Účel |
|--------|------|
| `.work-table` | široký kontajner pre stránky s tabuľkou: na ≥ 992px `max-width: 1200px` a prebíja 960px z `.app-main` (`.app-main:has(.work-table)` sa nepoužíva kvôli kompatibilite; namiesto toho stránka s tabuľkou pridá triedu `app-main--wide` cez sekciu layoutu **[voľnosť]**, alebo `.work-table` s negatívnym okrajom; plán zvolí jednoduchšiu cestu) |
| `.dt-table` | tabuľka na PC (derivát `.table .table-sm`): 16px, riadok min-height 44px, `font-variant-numeric: tabular-nums`, číselné stĺpce `text-end`, hlavička sticky nie |
| `.dt-sort` | odkaz v `<th>` pre triedenie (`d-inline-flex align-items-center`, min-height 44px, `text-decoration:none`, farba `inherit`) so šípkou ▲/▼ ako text/SVG a `aria-sort` na `<th>` |
| `.dt-only-lg` / `.dt-only-sm` | zobrazenie len na ≥ 992px / len pod 992px (`display:none` v media query); rovnaký fragment obsahuje obe reprezentácie, nikdy dva dotazy (D-14) |
| `.rpt-tot` | súčtový riadok (`fw-semibold` 600, horný okraj 2px `--app-border`) |
| `.rpt-card` | mobilná karta klienta v prehľade (derivát `.app-card`, `p-3`, `overflow-wrap:anywhere`) |
| `.rpt-switch` | lišta navigácie obdobia (predchádzajúce / aktuálne / ďalšie), `d-flex align-items-center justify-content-between gap-2` |
| `.aud-diff` | blok pred/po v detaile záznamu (12px, `tabular-nums`, `overflow-wrap:anywhere`, pozadie `#f5f6f8`, padding 8px 12px, radius 4px) |
| `.aud-changed` | zvýraznenie zmeneného poľa: pozadie `--bs-warning-bg-subtle` + text „zmenené“ (nie iba farba) |

---

## Spacing Scale

Rovnaká stupnica ako Phase 2 až 10 (násobky 4):

| Token | Value | Usage v tejto fáze |
|-------|-------|--------------------|
| xs | 4px | medzera medzi hodnotou a menou, hint pod poľom |
| sm | 8px | medzi tlačidlami exportu, odznakmi, medzi filtrami na PC, padding buniek `.dt-table` (vertikálne) |
| md | 16px | padding `.rpt-card`, buniek tabuľky (horizontálne), `mb-3` polí, okraje mobilu |
| lg | 24px | medzi sekciami (Súhrn / Po klientoch / Export) |
| xl | 32px | medzi blokmi roka (ročný rozpad po klientoch) |
| 2xl | 48px | horné odsadenie prázdnych stavov |
| 3xl | 64px | spodná rezerva nad tab barom (existujúce) |

Exceptions:
- Dotykové ciele min **44px**: tlačidlá CSV/XLSX/Stiahnuť, navigácia obdobia, `select`, `input`, odkaz v `<th>` (`.dt-sort`), riadok denníka (celý je odkaz/`summary`), `.more-item`.
- Šípka triedenia a ľavé okraje 4px sú dekoratívne.
- **Horizontálne posúvanie:** na mobile (< 992px) sa nepoužíva žiadna tabuľka (D-14): prehľad aj denník používajú karty/riadky; zoznamy úloh a faktúr ostávajú dnešné karty. Tabuľky sa zobrazujú výlučne od 992px, kde sa vojdú do 1200px kontajnera bez posúvania (stĺpce sa zalamujú, `overflow-wrap:anywhere`); bezpečnostne `.table-responsive` obal sa nepoužíva, aby nevznikla druhá os posúvania.

---

## Typography

Rovnaké 4 veľkosti a 2 hrúbky ako Phase 5 až 10, žiadne nové:

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body (polia, tlačidlá, bunky tabuliek, názvy klientov, texty riadkov) | 16px | 400 | 1.5 |
| Label / meta (popisky filtrov, hint, hlavičky stĺpcov `th`, meta riadok, `.aud-diff`, odznaky, `invalid-feedback`) | 12px | 400 | 1.5 |
| Heading (`h1`, `h2`, súčtový riadok/hodnota súčtu, nadpis sheetu, aktuálne obdobie v `.rpt-switch`) | 20px | 600 | 1.2 |
| Display (nepoužité) | 28px | 600 | 1.2 |

Hrúbka 600 iba pre: `h1`/`h2`, `.rpt-tot` (súčtový riadok, 16px 600 je povolené: hrúbka 600 pri veľkosti 16px je výnimka len pre súčty) a aktuálne obdobie. Hlavičky stĺpcov sú 12px 400 s `text-body-secondary`. Čísla `tabular-nums`.

---

## Color

Dedené bez zmeny:

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#f5f6f8` | pozadie stránky, `.aud-diff` |
| Secondary (30%) | `#ffffff` | `.app-card`, `.rpt-card`, tabuľka (`.dt-table` na bielom), sheet |
| Accent (10%) | `#0d6efd` | viď zoznam |
| Destructive | `#dc3545` | iba chybové hlásenia a stav „Stornovaný“ v texte; žiadna deštruktívna akcia v tejto fáze |
| Warning (existujúca) | `--bs-warning` | zvýraznenie zmeneného poľa v denníku (subtílne pozadie), upozornenie o veľkom období ZIP |

Accent reserved for (výlučne):
1. Primárne tlačidlo **Stiahnuť balík** (obrazovka Balík pre účtovníčku)
2. Primárne tlačidlo **Zobraziť** vo filtri denníka a **Použiť filtre** pri noscript fallbacku (jedno primárne na formulár)
3. Aktívna položka navigácie a aktívny prepínač Mesiac/Rok (`nav-pills`, Bootstrap predvolené)
4. Focus ring polí a tlačidiel
5. Textové odkazy v tele (odkaz na doklad z prehľadu/denníka)
6. Zaškrtnuté checkbox/radio

Tlačidlá exportu CSV a XLSX sú `btn-outline-secondary` (nie accent: export je sekundárna akcia nad tabuľkou). Súčtové riadky, zmenené polia a odznaky accent nepoužívajú.

---

## Trasy a vstupy

| Obrazovka | Trasa | Poznámka |
|-----------|-------|----------|
| Prehľad mesiaca | `GET /reports/month?entity={id}&period=2026-10` | stránka; pri `HX-Request` vráti iba fragment `#report-body` |
| Prehľad roka | `GET /reports/year?entity={id}&year=2026` | rovnako |
| Export prehľadu | `GET /reports/month.csv`, `/reports/month.xlsx`, `/reports/year.csv`, `/reports/year.xlsx` s rovnakým query | `Content-Disposition: attachment`, názov `prehlad-2026-10-{subjekt}.xlsx`; odkazy sú obyčajné `<a>` (nie htmx) |
| Export faktúr | `GET /invoices.csv`, `/invoices.xlsx` s aktuálnymi filtrami (status, hľadanie, triedenie) | |
| Balík pre účtovníčku | `GET /reports/accountant` formulár, `GET /reports/accountant/download?entity=&from=2026-10&to=2026-10` | stiahne ZIP (`balik-{subjekt}-{od}-{do}.zip`) |
| Denník zmien | `GET /settings/audit` | fragment `#audit-list` pri `HX-Request`; detail `GET /settings/audit/{id}` do `#sheet` (`.dl-sheet`, bez JS samostatná stránka) |
| Používatelia | `GET /settings/users` | len čítanie |
| Zoznam úloh / faktúr | existujúce `GET /tasks`, `GET /invoices` | rovnaký fragment, vždy s query stringom (`sort`, `dir`, filtre) |

Prístup: Prehľady, Balík a exporty vyžadujú `reports.read` (`accountant` ho má už teraz, ale UI pre accountant je v2); Denník a Používatelia iba `admin`. Neoprávnený prístup vracia existujúcu `errors/forbidden_403`.

---

## A. Prehľady (RPT-01, D-01 až D-05)

Layout `.work-table` (PC) / jeden stĺpec (mobil). Spoločná hlavička stránky (mesiac aj rok):

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | `h1` 20px „Prehľady“ + 12px muted úvod | „Vyfakturované podľa dátumu dodania, uhradené podľa dátumu úhrady. Sumy v mene subjektu.“ |
| 2 | Prepínač Mesiac / Rok | `nav nav-pills` 2 položky, 44px, odkazy (nie JS), zachová subjekt a obdobie |
| 3 | Výber subjektu | `select` (aktívne subjekty), zobrazí sa **iba ak je subjektov viac ako 1**, predvolene aktívny subjekt; `hx-get`, `hx-trigger="change"`, `hx-target="#report-body"`, `hx-push-url`. Pri jednom subjekte sa zobrazí iba 12px muted „{subjekt} · {mena}“. Sumy rôznych mien sa nikdy nesčítajú (D-03) |
| 4 | `.rpt-switch` | tlačidlo „‹ Predchádzajúci“ · aktuálne obdobie 20px 600 („október 2026“ / „2026“) · „Ďalší ›“ (`btn-outline-secondary btn-touch`; „Ďalší“ je `disabled` s `aria-disabled` pre budúce obdobie) ; mesiac cez `IntlDateFormatter` |
| 5 | Export | riadok pod `.rpt-switch`: „Exportovať:“ 12px muted + `CSV` a `XLSX` (`btn-outline-secondary btn-touch`, odkazy na `.csv`/`.xlsx`, `download`) |
| 6 | `#report-body` | súhrn + rozpad podľa nižšie |

### A1. Mesačný pohľad

Súhrn (kartou `.app-card`, `.master-kv`): „Vyfakturované {suma}“, „Uhradené {suma}“, „Neuhradené z mesiaca {suma}“ **[voľnosť]** (rozdiel len pre informáciu, vypočítaný serverom z rovnakej triedy, nie v šablóne). Pod ním rozpad po klientoch:

- **PC (≥ 992px)**: `.dt-table` so stĺpcami Klient · Vyfakturované · Uhradené (číselné `text-end`), zoradené podľa vyfakturovanej sumy zostupne, posledný riadok `.rpt-tot` „Spolu“. Názov klienta je odkaz na zoznam faktúr s filtrom klient + obdobie.
- **Mobil (< 992px)**: `.rpt-card` pre klienta: názov (16px 400), pod ním dvojica „Vyfakturované {suma}“ / „Uhradené {suma}“ (`.master-kv`), na konci `.rpt-card.rpt-tot` „Spolu“.
- Dobropisy sa zobrazujú záporne v mesiaci svojho dátumu dodania (D-01); pod tabuľkou 12px muted vysvetlenie „Dobropisy sú odpočítané v mesiaci svojho dátumu dodania.“
- Externé faktúry sa započítavajú (voľnosť z CONTEXT); 12px muted „Zahŕňa externé faktúry.“ zobrazené iba ak obdobie nejaké obsahuje.

### A2. Ročný pohľad

- Súhrn roka (rovnaké tri hodnoty).
- Sekcia „Po mesiacoch“ (`h2`): PC `.dt-table` riadky = mesiace 1 až 12 (Mesiac · Vyfakturované · Uhradené) + `.rpt-tot` „Spolu za rok {rok}“; mobil zoznam 12 riadkov (`.rpt-card` kompaktný, mesiac vľavo, sumy vpravo/pod sebou). Riadok mesiaca je odkaz na mesačný pohľad.
- Sekcia „Po klientoch“ (`h2`): rovnaká štruktúra ako A1 pre celý rok.
- Budúce mesiace roka sa zobrazujú s „0,00“ (nie prázdne), aby riadkov bolo vždy 12.

### A3. Stavy

- **Prázdne obdobie**: v `#report-body` `h2` „Žiadne doklady za obdobie“, text „V tomto období nie je vyfakturovaná ani uhradená žiadna suma. Zvoľte iné obdobie.“, bez tlačidla exportu aktívneho (exporty ostávajú dostupné, vyexportujú tabuľku s nulovým súčtom). Odsadenie 48px.
- **Loading**: prepnutie obdobia/subjektu cez htmx; `.htmx-indicator` (text „Načítavam…“ 12px) a `aria-busy` na `#report-body`.
- **Chyba (500)**: `alert alert-danger` „Prehľad sa nepodarilo načítať. Skúste to znova.“ (`role="alert"`).
- **Žiadny aktívny subjekt**: `alert alert-secondary` „Najprv vytvorte subjekt v Nastaveniach.“ + odkaz na `/settings/entities`.
- **Neplatné obdobie v URL**: presmerovanie na aktuálny mesiac/rok.
- Súčty sú výlučne zo servisnej triedy (NFR-04, D-05); šablóna iba formátuje reťazce cez `MoneyFormat`, nikdy nepočíta, žiadny `float`.

---

## B. Exporty CSV / XLSX (RPT-02, D-06 až D-08)

- Tlačidlá `CSV` a `XLSX` (12px muted popisok „Exportovať:“) nad tabuľkou na obrazovkách: mesačný prehľad, ročný prehľad, zoznam faktúr (PC aj mobil). Na zozname faktúr exportujú **aktuálne filtre a triedenie**; po zmene filtra htmx aktualizuje aj `href` exportu (odkazy sú súčasťou fragmentu, ktorý sa prekresľuje, **[voľnosť]**: blok exportu je vo fragmente `#invoice-list`/`#report-body`).
- Stiahnutie je priame (`<a download>`); počas stiahnutia sa nezobrazuje spinner (odpoveď je stream). Odpoveď 500 vráti HTML stránku chyby; export nikdy nevráti čiastočný súbor so statusom 200 po výnimke pred prvým bajtom.
- Stĺpce CSV/XLSX prehľadu: Klient · Vyfakturované · Uhradené · Mena (hlavička 1 riadok, súčtový riadok „Spolu“ na konci). Ročný: Obdobie (mesiac) · Klient · Vyfakturované · Uhradené · Mena. Zoznam faktúr: stĺpce podľa D-12 (číslo, typ, klient, dátum vystavenia, dátum dodania, splatnosť, mena, suma, kurz, suma v mene subjektu, stav, dátum úhrady).
- CSV: `;`, UTF-8 BOM, desatinná čiarka, bez oddeľovača tisícov; XLSX: číselné bunky `#,##0.00`, hlavička 600, šírky stĺpcov nastavené, zmrazený prvý riadok. Ochrana pred vzorcami (D-07). Žiadne UI ju neukazuje; test pokrýva.
- Pri zozname faktúr sa nad exportom neukazuje obmedzenie počtu riadkov; ak `truncated` (existujúci príznak zoznamu) je pravda, export je napriek tomu **úplný** a 12px muted hint pri tlačidlách „Export obsahuje všetky riadky podľa filtrov.“

---

## C. Balík pre účtovníčku (RPT-03, D-09 až D-12)

`GET /reports/accountant`, layout `.work-wide`, jeden stĺpec. Zhora nadol:

| # | Prvok | Špecifikácia |
|---|-------|--------------|
| 1 | Odkaz „Späť na Viac“ | `d-inline-block py-2` |
| 2 | `h1` „Balík pre účtovníčku“ + úvod 12px muted | „ZIP s PDF faktúr a dobropisov a CSV zoznamom dokladov. Doklady sa vyberajú podľa dátumu dodania. Výkazy práce balík neobsahuje.“ |
| 3 | Subjekt | `select` aktívnych subjektov (predvolene aktívny), 44px |
| 4 | Obdobie od / do | dva `input type="month"` (16px, 44px) predvolene predchádzajúci mesiac pre „od“ aj „do“ **[voľnosť]** (účtovníčka dostáva uzavretý mesiac); hint „Jeden mesiac alebo rozsah najviac 12 mesiacov.“ |
| 5 | Náhľad obsahu | karta `.app-card` `role="status"`, aktualizovaná htmx (`hx-get="/reports/accountant/preview"`, `hx-trigger="change"`, `hx-target="#bundle-preview"`): „{n} dokladov: {a} faktúr, {b} dobropisov“ · „z toho {c} stornovaných“ · „{d} externých bez PDF“ · „Obrat za obdobie {suma}“ (rovnaká suma ako v prehľade) |
| 6 | Tlačidlo | primárne **Stiahnuť balík** (`btn-primary btn-touch w-100` mobil, `data-submit-once`), `disabled` ak je v období 0 dokladov alebo je rozsah neplatný |

Stavy:
- **0 dokladov**: náhľad „V zvolenom období nie sú žiadne doklady.“, tlačidlo `disabled`.
- **Rozsah > 12 mesiacov**: `invalid-feedback` „Zvoľte obdobie najviac 12 mesiacov.“; „od“ po „do“: „Dátum „od“ nesmie byť neskôr ako „do“.“
- **Externé faktúry bez PDF**: v náhľade riadok „{d} externých faktúr nemá PDF, uvedené sú iba v CSV.“ (`alert alert-secondary`, 12px).
- **Chýba uložené PDF** (integrita, nemalo by nastať): `alert alert-danger role="alert"` „Pre {n} dokladov chýba uložené PDF: {čísla}. Balík sa nevytvoril.“ (nikdy sa PDF negeneruje nanovo, D-11).
- **Generovanie**: sťahovanie je dlhšie než bežná odpoveď, preto po kliknutí tlačidlo `disabled` + `.spinner-border-sm` a text „Pripravujem balík…“ (reset po 10 s alebo pri návrate na stránku **[voľnosť]**, keďže stiahnutie súboru neposkytuje htmx udalosť).
- **Chyba (500/timeout)**: „Balík sa nepodarilo vytvoriť. Skúste kratšie obdobie alebo to zopakujte.“
- **Obsah ZIP** (kontrakt pre názvy): `doklady/{DocumentFilename}.pdf` a `zoznam-dokladov.csv` v koreni; rovnaké stĺpce ako D-12.
- Žiadne potvrdenie ani destruktívna akcia; read-only operácia.

---

## D. Denník zmien (AUD-02, D-13)

`GET /settings/audit`, `.work-table`, len `admin`. Zhora nadol: odkaz „Späť na Viac“, `h1` „Denník zmien“, úvod 12px muted „Záznamy sa len pridávajú, nedajú sa upraviť ani zmazať. Citlivé údaje sú v zázname skryté.“

### D1. Filtre

`form` (`method=get`, `hx-get="/settings/audit"`, `hx-trigger="change, submit"`, `hx-target="#audit-list"`, `hx-swap="outerHTML"`, `hx-push-url="true"`). Mobil: polia pod sebou na šírku; PC (≥ 992px): mriežka 4 stĺpce, medzera 8px. Polia (všetky 44px, 16px, `label for` 12px):

| Pole | Typ | Hodnoty |
|------|-----|---------|
| Entita | `select` | Všetky · Faktúra · Dobropis · Úloha · Klient · Subjekt · Limit · Číselný rad · Šablóna · Používateľ · … (zoznam z `audit_log.entity_type` s prekladmi, neznáme typy ako surový kľúč) |
| Akcia | `select` | Všetky · Vytvorené · Zmenené · Zmazané · Vystavené · Odoslané · Uhradené · Stornované … (z existujúcich hodnôt `action`, neznáme ako surový kľúč) |
| Používateľ | `select` | Všetci · zoznam používateľov (meno alebo e-mail) |
| Od / Do | dva `input type="date"` | časový rozsah podľa `occurred_at`, „do“ vrátane celého dňa |

Pod poľami tlačidlá: **Zobraziť** (`btn-primary`, iba v `noscript` a po manuálnom odoslaní; filtre sa aplikujú aj zmenou) a „Zrušiť filtre“ (`btn-outline-secondary`, odkaz na `/settings/audit`, zobrazený len pri aktívnom filtri).

### D2. Zoznam `#audit-list`

- Nad zoznamom 12px muted „{n} záznamov“ a stránkovanie.
- **PC**: `.dt-table` stĺpce Čas (Dátum a čas, `IntlDateFormatter`) · Používateľ · Akcia (odznak `text-bg-light border`) · Entita (typ + ID, odkaz na doklad/detail tam, kde existuje) · Zhrnutie (napr. počet zmenených polí, 12px). Riadok má tlačidlo „Detail“ (`btn-outline-secondary btn-touch`, otvára sheet do `#sheet`, `hx-get`).
- **Mobil**: riadky ako `.inv-row`: čas (12px muted) · akcia + entita (16px) · používateľ (12px muted); celý riadok je odkaz na detail (min 44px).
- Zoradené `occurred_at DESC` (najnovšie hore), fixné (triedenie sa nemení). Stránkovanie server-side: 50 záznamov, `nav` s „‹ Novšie“ / „Staršie ›“ (`btn-outline-secondary btn-touch`) a „Strana {n}“; bez počtu všetkých stránok (lacné `LIMIT+1`).
- **Prázdne**: `h2` „Žiadne záznamy“, text „Pre zvolené filtre sa nenašli žiadne zmeny. Upravte filtre alebo ich zrušte.“ (pri prázdnom denníku bez filtrov: „Zatiaľ nebola zapísaná žiadna zmena.“).

### D3. Detail záznamu (sheet `.dl-sheet`, PC modál, mobil offcanvas-bottom; samostatná stránka bez JS)

- `h2` „Detail zmeny“; meta 12px muted „{čas} · {používateľ} · {akcia} · {entita} #{id}“.
- Tabuľka/zoznam polí „Pole · Pred · Po“: PC 3 stĺpce, mobil každé pole ako blok (názov 12px muted, pod ním „Pred: …“ a „Po: …“). Zmenené polia majú `.aud-changed` + 12px text „zmenené“ vedľa názvu; nezmenené polia sa zobrazia bez zvýraznenia (`text-body-secondary`). Hodnoty v `.aud-diff` (zalamovanie, bez posúvania); prázdna hodnota „—“; skryté citlivé polia „skryté“.
- Vytvorenie: stĺpec „Pred“ je „—“; zmazanie: stĺpec „Po“ je „—“.
- Pätka: „Zavrieť“ (`btn-outline-secondary`). Žiadne tlačidlo úpravy ani vrátenia.
- Neexistujúce ID: 404 stránka (existujúca).
- **Chyba**: „Denník sa nepodarilo načítať. Skúste to znova.“

---

## E. PC tabuľky úloh a faktúr (UI-03, D-14, D-15)

**Princíp:** jeden fragment (`tasks/_list.php`, `invoices/_list.php`), jeden dotaz; fragment obsahuje dve reprezentácie: existujúce karty/riadky v obale `.dt-only-sm` a nové `.dt-table` v obale `.dt-only-lg`. Nijaké nové URL, žiadny druhý dotaz, žiadna JS knižnica. Hranica `lg` = 992px (zhodná s `.app-topnav`).

### E1. Zoznam faktúr (`/invoices`, `#invoice-list`)

- Kontajner na PC `.work-table` (až 1200px).
- Filtre nad tabuľkou: dnešný stav (select) + **[voľnosť]** pridané na PC: Klient (`select`), Hľadať číslo (`input type="search"`, `name="q"`, 16px), Od/Do dátum vystavenia; všetko jedným GET formulárom s `hx-get` ako teraz. Na mobile ostáva len filter stavu (dnešné správanie), ostatné polia `.dt-only-lg`.
- Stĺpce `.dt-table` (triediteľné odkazy `.dt-sort` v `th`): Číslo · Klient · Vystavená · Dodanie · Splatnosť · Mena · Suma (`text-end`) · V mene subjektu (`text-end`) · Stav (odznak ako v kartách) . Netriediteľné: Stav. Predvolené triedenie: dátum vystavenia zostupne, rovnaké ako dnes.
- Riadok je celý odkaz na `/invoices/{id}` (číslo ako `<a>`, riadok `hover` Bootstrap `table-hover`, oblasť klepnutia min 44px); externá faktúra odznak „Externá“, dobropis odznak „Dobropis“ a suma záporná; po splatnosti riadok nemení farbu, pridáva sa odznak „Po splatnosti“ (`text-bg-danger`) zhodný s kartami.
- Nad tabuľkou blok Export (sekcia B).

### E2. Zoznam úloh (`/tasks`, `#task-list` alebo existujúci cieľ fragmentu)

- Rovnaký princíp. Stĺpce: Úloha (názov, odkaz) · Klient · Stav (odznak) · Hodiny (`text-end`) · Nacenenie / suma (`text-end`) · Dátum dokončenia · Fakturačný stav. Konkrétne stĺpce sa odvodia z poľa, ktoré dnešné `_task_row.php` už zobrazuje (nepridávajú sa nové dáta bez dotazu; **[voľnosť]** plán doplní triedenie podľa existujúcich stĺpcov `TaskQueries`).
- Filtre: dnešné (`TaskFilter::fromQuery()`) zostávajú, na PC sa zobrazujú v jednom riadku nad tabuľkou (mriežka, medzera 8px) namiesto stohu.
- Existujúce skupinové súhrny a hromadné akcie z mobilnej verzie sa **zachovajú**; ak ich dnešný zoznam má (`_summary.php`, výber úloh na fakturáciu), na PC sa tabuľka dopĺňa o stĺpec s checkboxom (44px cieľ), nikdy nie o novú logiku. Plán overí, či sa výber úloh v tabuľke zhoduje s dnešným správaním; ak by sa nezhodoval, výber ostáva v kartách a tabuľka je len na čítanie (**[voľnosť]**, zdokumentovať).

### E3. Triedenie (spoločné)

- `th` s `aria-sort="ascending|descending|none"`; odkaz `.dt-sort` je `GET` s `?sort={stĺpec}&dir={asc|desc}` zachovávajúcim ostatné filtre; kliknutie prepína asc/desc; htmx `hx-get`, `hx-target` rovnaký ako formulár, `hx-push-url="true"`; bez JS funguje ako obyčajný odkaz.
- Server vyberá stĺpec z **whitelistu** (neznáme hodnoty sa ignorujú, predvolené triedenie); žiadne triedenie podľa nepovolených stĺpcov.
- Aktívny stĺpec: šípka ▲/▼ (text, `aria-hidden`) a `aria-sort`; stav nikdy iba farbou.
- Stránkovanie/limit: existujúci limit zoznamov ostáva; na PC pri skrátení (`truncated`) existujúci 12px hint „Zobrazených je prvých {n}…“ (nový text sa nevymýšľa).

### E4. Fakturácia mesiaca a nastavenia na PC (D-15)

Žiadne nové obrazovky. Fáza iba **overí** rozloženie od 992px pre: `/invoicing`, subjekty, klienti, číslovanie radov, limity, šablóny, zákonné texty, 2FA a zariadenia (`settings/security`), a opraví zistené chyby (preťahnutie šírky, tlačidlá `w-100` bez zmysluplnej šírky, formuláre naťahované na 960px). Kontrakt: formuláre nastavení na PC majú `max-width: 720px` (trieda `.form-narrow` **[voľnosť]**, ak ešte neexistuje ekvivalent) a tlačidlá akcií `w-auto`; `w-100` zostáva iba pod 992px. Žiadne zmeny farieb ani typografie.

---

## F. Nastavenia → Používatelia (D-16)

`GET /settings/users`, `.work-wide`, len čítanie, len `admin`. `h1` „Používatelia“, úvod 12px muted „Zoznam účtov a ich zabezpečenia. Nový účet pre účtovníčku sa zatiaľ vytvára príkazom na serveri.“ (statický text, bez odkazu na príkaz).

- **PC**: `.dt-table` stĺpce Meno / e-mail · Rola · 2FA · Posledné prihlásenie.
- **Mobil**: `.rpt-card` na používateľa: meno alebo e-mail (16px), pod ním 12px muted „Rola · 2FA · Posledné prihlásenie {čas}“; odznaky v riadku.
- Rola: odznak `text-bg-light border` „Administrátor“ / „Účtovníčka“ (preklad skupín Shield; neznáma skupina surový kľúč).
- 2FA: `text-bg-success` „Zapnuté“ alebo `text-bg-warning` „Vypnuté“ (stav vždy textom).
- Posledné prihlásenie: `IntlDateFormatter` dátum + čas, alebo „nikdy“ (12px muted).
- **Bez** tlačidiel Pridať/Upraviť/Zmazať/Reset 2FA (v2). Prázdny stav sa nemôže vyskytnúť (vždy je aspoň prihlásený admin); pri chybe načítania „Používateľov sa nepodarilo načítať. Skúste to znova.“

---

## G. Menu „Viac“ (UI-03)

Nové položky v `app/Views/more/index.php` ako `.more-item` odkazy, v tomto poradí (prevádzkové pred nastaveniami): **Prehľady** (`/reports/month`) a **Balík pre účtovníčku** (`/reports/accountant`) umiestnené **pred** „Subjekty“; **Denník zmien** (`/settings/audit`) a **Používatelia** (`/settings/users`) za „Limity obratu“ a pred „Šablóny e-mailov“ **[voľnosť]**. Denník a Používatelia sa zobrazujú iba pre `admin`. Každý odkaz má voliteľný 12px muted podnadpis (vzor `EmailTemplates.navHint`): Prehľady „Mesačné a ročné súčty, export do CSV a XLSX“, Balík pre účtovníčku „ZIP s PDF dokladov za obdobie“, Denník zmien „História zmien, len na čítanie“, Používatelia „Účty, role a 2FA“.

Horné PC menu (`layouts/_nav_items.php`): bez zmeny počtu položiek; „Viac“ ostáva jediný vstup (tab bar má 4 až 5 položiek, nepridávať). Aktívnosť položky „Viac“ sa zvýrazní aj na `/reports/*`, `/settings/audit`, `/settings/users` (existujúce pravidlo pre `/settings/*` rozšíriť o `/reports/*`).

---

## Copywriting Contract

Neutrálne vety bez oslovenia, vecný tón. Texty iba cez `lang()`.

| Element | Copy |
|---------|------|
| Primary CTA (Balík) | Stiahnuť balík |
| Primary CTA (Denník) | Zobraziť |
| Sekundárne | Späť na Viac, CSV, XLSX, Exportovať:, Predchádzajúci, Ďalší, Detail, Zavrieť, Zrušiť filtre, Novšie, Staršie |
| Nadpisy stránok | Prehľady · Balík pre účtovníčku · Denník zmien · Používatelia |
| Položky v menu Viac | Prehľady · Balík pre účtovníčku · Denník zmien · Používatelia |
| Prepínač | Mesiac · Rok |
| Stĺpce / hodnoty | Klient · Vyfakturované · Uhradené · Spolu · Spolu za rok {rok} · Mena · Po mesiacoch · Po klientoch |
| Poznámka k prehľadu | Vyfakturované podľa dátumu dodania, uhradené podľa dátumu úhrady. Sumy v mene subjektu. |
| Poznámka dobropisy | Dobropisy sú odpočítané v mesiaci svojho dátumu dodania. |
| Poznámka externé | Zahŕňa externé faktúry. |
| Empty state heading (Prehľad) | Žiadne doklady za obdobie |
| Empty state body (Prehľad) | V tomto období nie je vyfakturovaná ani uhradená žiadna suma. Zvoľte iné obdobie. |
| Empty state heading (Denník) | Žiadne záznamy |
| Empty state body (Denník) | Pre zvolené filtre sa nenašli žiadne zmeny. Upravte filtre alebo ich zrušte. |
| Empty denník bez filtrov | Zatiaľ nebola zapísaná žiadna zmena. |
| Empty Balík | V zvolenom období nie sú žiadne doklady. |
| Error state (prehľad) | Prehľad sa nepodarilo načítať. Skúste to znova. |
| Error state (balík) | Balík sa nepodarilo vytvoriť. Skúste kratšie obdobie alebo to zopakujte. |
| Error state (denník) | Denník sa nepodarilo načítať. Skúste to znova. |
| Error state (používatelia) | Používateľov sa nepodarilo načítať. Skúste to znova. |
| Chýba PDF | Pre {n} dokladov chýba uložené PDF: {čísla}. Balík sa nevytvoril. |
| Rozsah období | Zvoľte obdobie najviac 12 mesiacov. · Dátum „od“ nesmie byť neskôr ako „do“. |
| Export hint | Export obsahuje všetky riadky podľa filtrov. |
| Priebeh balíka | Pripravujem balík… |
| Denník úvod | Záznamy sa len pridávajú, nedajú sa upraviť ani zmazať. Citlivé údaje sú v zázname skryté. |
| Denník detail | Detail zmeny · Pole · Pred · Po · zmenené · skryté · — |
| Používatelia úvod | Zoznam účtov a ich zabezpečenia. Nový účet pre účtovníčku sa zatiaľ vytvára príkazom na serveri. |
| Stav 2FA | Zapnuté · Vypnuté · nikdy |
| Roly | Administrátor · Účtovníčka |
| Destructive confirmation | **Žiadna deštruktívna akcia v tejto fáze** (všetky obrazovky sú na čítanie alebo generujú súbor; nevznikajú žiadne potvrdzovacie sheety) |

Názvy súborov: `prehlad-{RRRR-MM}-{subjekt}.{csv|xlsx}`, `prehlad-{RRRR}-{subjekt}.{csv|xlsx}`, `faktury-{subjekt}-{dátum}.{csv|xlsx}`, `balik-{subjekt}-{od}-{do}.zip` (subjekt cez slug, bez diakritiky; hodnoty z `DocumentFilename`-štýlu sanitizácie).

---

## Interaction and State Contract

- **Jediný zdroj čísla (NFR-04)**: súčty prehľadu, exportov, balíka aj dashboardu prináša jedna servisná trieda s výberom dokladov za obdobie (`TurnoverCalculator`-kompatibilná); UI iba formátuje reťazce cez `MoneyFormat`, bez `float`. Test zhody: prehľad = súčet faktúr = obrat = CSV = XLSX.
- **Fragmenty**: prehľady, denník aj zoznamy sa vracajú ako htmx fragmenty pri `HX-Request` a ako celá stránka inak (existujúci vzor); URL sa aktualizuje cez `hx-push-url` a obdobie/filtre sú reprodukovateľné v URL.
- **Read-only**: Prehľady, Denník, Používatelia nemajú formuláre zápisu, žiadne CSRF-chránené POST; exporty sú GET a nemenia dáta (generovanie ZIP/XLSX zápis do DB nerobí).
- **Responzivita**: pod 992px žiadne tabuľky, jeden stĺpec, tlačidlá 44px, dôležité akcie (Export, Stiahnuť) dosiahnuteľné jednou rukou (`w-100` na mobile v spodnej polovici obrazovky, pre Balík ako posledný prvok formulára); od 992px tabuľky v `.work-table`.
- **Prístupnosť**: `<table>` s `<caption class="visually-hidden">`, `th scope="col"`, `aria-sort`, `label for`, `role="status"` pre náhľad balíka a počty, `role="alert"` pre chyby, `aria-busy` počas htmx načítania, kontrast min 4.5:1, stav nikdy iba farbou ani iba ikonou, focus na prvé neplatné pole, `aria-current="page"` na aktívnom období/prepínači.
- **CSP**: žiadny inline `style`, `onclick` ani inline skript; všetky rozmery a zobrazenia cez triedy v `app.css`; `download` na odkazoch bez JS.
- **Výkon**: prehľad = jeden agregačný SQL dotaz na obdobie (index na `turnover_date`/dátum úhrady); denník využíva index `entity_type, entity_id, occurred_at DESC` a `LIMIT 51`; export XLSX streamovo (OpenSpout), ZIP do dočasného súboru v `writable/` (D-11), p95 bežných stránok pod 100 ms.
- **Offline**: stránky a fragmenty tejto fázy sa nekešujú (SW nekešuje autentifikované HTML ani súbory exportov).
- **Mimo rozsahu**: úprava/vytváranie používateľov v UI, UI pre rolu `accountant`, výkazy práce v ZIP, KSeF export, preklady PL/CZ/EN (Phase 12), grafy.

---

## Rozhodnutia v rámci voľnosti plánovača (zapísané ako kontrakt)

| Otázka | Rozhodnutie |
|--------|-------------|
| Rozloženie tabuliek PC vs mobil | jeden fragment, dve reprezentácie (`.dt-only-lg` tabuľka / `.dt-only-sm` karty), hranica 992px (D-14) |
| Prehľad mesiac vs rok | dve trasy s rovnakou hlavičkou a prepínačom `nav-pills`; ročný pohľad má mesiace 1 až 12 a rozpad po klientoch |
| Subjekt v prehľade | `select` iba pri viac ako 1 subjekte, predvolený aktívny subjekt |
| Triedenie a stránkovanie | server-side cez query string, whitelist stĺpcov, bez JS knižníc; denník fixne najnovšie hore, 50 záznamov na stranu |
| Export tlačidlá | `btn-outline-secondary` (nie accent), obyčajné odkazy so stále aktuálnym query |
| Limit ZIP | najviac 12 mesiacov, náhľad počtov pred stiahnutím, tlačidlo disabled pri 0 dokladov |
| Predvolené obdobie balíka | predchádzajúci mesiac |
| Denník: detail | sheet s tabuľkou Pole / Pred / Po, zmenené polia zvýraznené textom aj pozadím |
| Používatelia | zoznam len čítanie, žiadne akcie, text o `spark` príkaze statický |
| Menu Viac | Prehľady a Balík pred Subjekty; Denník a Používatelia (len admin) za Limity |
| Šírka obsahu na PC | `.work-table` do 1200px len pre stránky s tabuľkou, ostatné ostávajú 960px |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | none (shadcn sa nepoužíva) | not applicable |
| Third-party | none | not applicable |

Externé UI závislosti: iba lokálne súbory z `public/assets/vendor/`. Jediná nová PHP závislosť je `openspout/openspout` (export, nie UI), bez CDN.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
