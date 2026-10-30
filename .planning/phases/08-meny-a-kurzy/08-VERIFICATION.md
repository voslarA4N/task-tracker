---
phase: 08-meny-a-kurzy
verified: 2026-10-30T14:00:00Z
status: human_needed
score: 4/4 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Na staging (work-tracker-stage.vostack.dev) vystav faktúru PL subjektu s klientom v EUR; v náhľade over automaticky načítaný NBP kurz (posledný pracovný deň pred dátumom dodania), zdroj a číslo tabuľky"
    expected: "Kurz sa načíta živým API (CR-01 oprava reálneho CURLRequest klienta sa v PHPUnit nevolá na živé API), karta kurzu ukazuje kurz, dátum a súčet v PLN"
    why_human: "Živé NBP/ECB/ČNB API sa v testoch nevolá"
  - test: "V náhľade ručne prepíš kurz, vystav, otvor PDF a detail faktúry"
    expected: "Obe sumy, kurz, zdroj/ručné označenie a dátum sú na PDF aj v detaile rovnaké; neskoršia zmena nastavení či cache doklad nezmení"
    why_human: "Rozloženie PDF a vizuálna zhoda"
  - test: "Simuluj nedostupné API (alebo budúci bound dátum) a skontroluj výzvu na ručné zadanie a tlačidlo Retry"
    expected: "Zobrazí sa výzva (pri budúcom dátume bez Retry), ručné zadanie umožní vystavenie"
    why_human: "Správanie CR-02 (cache finalita) a WR-01 na reálnom čase a sieti; UI na iPhone (dotykové ciele, bez horizontálneho posúvania)"
  - test: "Zmeň v Nastavenia > Kurzy dátum kurzu a zdroj pre krajinu, over na iPhone"
    expected: "Zmena sa prejaví v novom náhľade; vystavené faktúry ostávajú nezmenené"
    why_human: "UI na mobile"
  - test: "Rozhodnutie vlastníka k WR-03: varovanie pri odchýlke ručného kurzu >10 % je len upozornenie, nie blokujúce potvrdenie"
    expected: "Vlastník potvrdí alebo požiada o blokujúci krok"
    why_human: "Produktové rozhodnutie"
---

# Phase 8: Meny a kurzy Verification Report

**Phase Goal:** Vlastník vystaví faktúru v inej mene než je mena subjektu s kurzom z oficiálneho zdroja alebo ručne zadaným, uloženým vo faktúre a použitým všade jednotne.
**Status:** human_needed (no code gaps found)
**Re-verification:** No

## Observable Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Náhľad s inou menou načíta kurz (NBP/ECB/ČNB) a dá sa ručne prepísať | VERIFIED | `app/Libraries/Fx/` obsahuje providery NBP, ECB, CNB, `FxService::context/resolve`, `FxInput`, `_fx_card.php`. Ručný režim spracuje `InvoiceIssuer` (r. 176-183). `CurlRateHttpClient` po CR-01 používa reálny `CURLRequest`, otestované proti zatvorenému portu a mocku |
| 2 | Pri nedostupnom API výzva na ručné zadanie, vystavenie neblokované | VERIFIED | `resolve()` hádže `RateUnavailable`/`RateNotFound`, výzva v karte, ručný kurz vystavenie povolí; vystavenie číta iba `cached()` (bez siete). Testy `InvoiceManualFxTest`, `InvoicingFxPreviewTest` zelené |
| 3 | Kurz, zdroj, dátum, prepočítaná suma v kópii údajov a obe sumy na PDF; zmena zdroja doklad nezmení | VERIFIED | `SnapshotBuilder::fxBlock` ukladá blok `fx` (core, total, predformátovaný text) a `invoice_inv2.php` ho tlačí zo snapshotu (r. 131-134). Zoznam a detail čítajú entity_currency zo snapshotu (WR-05). Stĺpce `fx_*` + CHECK migrácia |
| 4 | Dátum kurzu nastaviteľný v právnom profile krajiny; meny rozšíriteľné bez zmeny modelu | VERIFIED | `FxSettingsService`, trasy `settings/fx` (GET/POST), `FxBoundDate::anchor/bound`; zoznam mien (CUR-01) v tabuľke, test `CurrencyListTest` |

**Score:** 4/4

## Review fixes (CR-01, CR-02, WR-01) vs. goal

- CR-01: reálny klient sa konštruuje cez `single_service('curlrequest')`, iba `HTTPException` sa mapuje na `http_error`; nerozbíja cieľ, naopak ho umožňuje.
- CR-02: `FINAL_SQL` finalizuje riadok iba ak bol stiahnutý po bound dni; nefinálne riadky sa obnovujú a upsert ich smie prepísať. Vystavené faktúry čítajú len snapshot, takže nemennosť nie je dotknutá. Zmena kurzu medzi náhľadom a vystavením končí `preview_stale` (zámerné).
- WR-01: budúci bound sa odmieta pre všetky zdroje bez siete (`RateNotFound not_yet_published`), používateľ zadá kurz ručne. Zadanie ručne umožňuje vystavenie, cieľ ostáva splnený. Pre dátum dodania v budúcnosti sa automatický kurz nenačíta, čo zodpovedá cieľu "alebo ručne zadaným".

## Behavioral Spot-Checks

| Súbor testov | Výsledok |
|---|---|
| tests/unit/Fx | OK (101 tests, 231 assertions) |
| tests/integration/Fx | OK (30 tests, 132 assertions) |
| tests/integration/Invoicing | OK (219 tests, 1096 assertions) |
| tests/feature/Invoicing | OK (143 tests, 575 assertions) |

Plný `make test` som nespúšťal; podľa REVIEW-FIX posledný beh 1798 zelených.

## Requirements Coverage

| ID | Status | Evidence |
|---|---|---|
| CUR-01 | SATISFIED | zoznam mien v DB, rozšíriteľný, test CurrencyListTest |
| CUR-02 | SATISFIED | providery, ručný prepis, snapshot fx blok, PDF riadky (vizuálne potvrdiť človekom) |
| CUR-03 | SATISFIED | výzva na ručné zadanie, vystavenie neblokované |
| CUR-05 | SATISFIED | nastavenie date_basis/day_rule/source na krajinu (`settings/fx`) |

CUR-04 patrí Phase 10 (nie orphan tejto fázy). Checkboxy CUR-02/03/05 v REQUIREMENTS.md sú zámerne pending do uzavretia fázy.

## Anti-Patterns

Žiadne TODO/FIXME/XXX v `app/Libraries/Fx`, `_fx_card.php`, `settings/fx`.

Otvorené info položky z review: IN-02 (stĺpec `currencies.decimals`) a IN-04 (kombinácie zdroj/krajina) zámerne nerešené, nie sú blokujúce.

## Gaps Summary

Žiadne gaps. Zostáva len ľudské overenie na stagingu (živé API, PDF layout, iPhone UI) a potvrdenie rozhodnutia WR-03.

_Verifier: Claude (gsd-verifier)_
