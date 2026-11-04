---
phase: 12-lokaliz-cia-a-produkcia
plan: 12
subsystem: i18n
tags: [i18n, parity, tests, pl, cs]
requires: [12-06, 12-07, 12-08, 12-09]
provides:
  - "LangKeysTest: úplná parita súborov (32) a kľúčov pre všetky supportedLocales, strážca nepreložených kópií sk (SAME_AS_SK_ALLOWED), kontrola validačných hlášok pl/cs, report dĺžky textov"
  - "LocalizedPagesTest: render 8 kľúčových obrazoviek v pl a cs plus /login z Accept-Language bez surového kľúča a bez anglického fallbacku"
affects: [12-13]
tech-stack:
  added: []
  patterns: ["allow-list výnimiek s kontrolou zastaranosti", "text z HTML bez atribútov a skriptov pre hľadanie surových kľúčov"]
key-files:
  created:
    - tests/feature/I18n/LocalizedPagesTest.php
  modified:
    - tests/unit/I18n/LangKeysTest.php
    - app/Language/cs/InvoicePdf.php
key-decisions:
  - "SAME_AS_SK_ALLOWED má 74 položiek pre pl a 282 pre cs (endonymá, skratky, IČO/NIP/IBAN, spoločné slová ako Klient, Poznámka, Stav, Hodiny, názvy produktu, poľské právne texty v cs)"
  - "Slugy názvov súborov exportu (prehlad, faktury, balik) ostávajú v allow-liste, nemeniť kvôli existujúcim testom a stabilným názvom"
  - "app.css sa nemení: nowrap je iba na číslach (.cn-line-amount, .lim-pct, .dt-num), žiadne tlačidlo ani odznak s lang() textom nemá text-nowrap"
requirements-completed: [UI-02]
duration: 25min
completed: 2026-11-05
---

# Phase 12 Plan 12: Uzavretie lokalizácie Summary

Parita štyroch jazykov (32 súborov, rovnaké kľúče a zástupné symboly) a zákaz nepreložených kópií sk v pl a cs sú trvalo strážené testom; kľúčové obrazovky sa v PL a CS vyrenderujú bez surového kľúča a anglického fallbacku.

## Commits
- fed54fd test(12-12): full four-language parity and untranslated copy guard
- ad0a113 test(12-12): key screens render fully translated in polish and czech

## Tests
- `tests/unit/I18n`, `tests/feature/I18n`, `tests/unit/Security/ViewsCspTest.php`: OK (44 testov, 5450 asercií)
- `composer stan`: OK

## Preklady opravené
- `app/Language/cs/InvoicePdf.php`: `qrZbp` bolo „Kod QR ZBP“ (poľský pravopis), teraz „Kód QR ZBP“. Ostatné zhody so sk sú legitímne.

## Dĺžka textov (UI-SPEC D)
Report vypisuje na STDERR hodnoty pl dlhšie ako 140 % sk (desiatky, hlavne dlhšie vety a nadpisy dobropisu, napr. CreditNotes.hintReason 72 znakov, Currency.ruleOnOrBefore 68, Entities.seriesTokenMonth 77); v cs iba InvoicePdf.cnTitle (21). Nie sú to tlačidlá s nowrap, Bootstrap ich zalamuje. CSS sa preto nemenilo.

## Pre vlastníka (UAT)
Mobilná kontrola 375 px v Polski a Čeština (prihlásenie, Prehľad, Úlohy, Fakturácia mesiaca, Faktúry, Prehľady, Limity, Zabezpečenie) a prezretie PL/CS prekladov vlastníkom ostávajú ručné kroky; pripomienky zapísať do UAT.

## Deviations from Plan
- `LocalizedPagesTest` používa trasu `/invoices/new` pre Fakturáciu mesiaca (trasa `/invoicing` neexistuje) a prvky tab baru `App.navInvoices` namiesto `tabOverview`/`navInvoicing` (kľúče s takým názvom neexistujú).
- Porovnanie dĺžky v teste používa celočíselnú aritmetiku (pravidlo PHPStan app.forbiddenFloat) a `addToAssertionCount`.

## Self-Check: PASSED
