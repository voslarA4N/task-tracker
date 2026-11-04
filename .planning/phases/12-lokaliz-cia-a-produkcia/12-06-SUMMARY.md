---
phase: 12-lokaliz-cia-a-produkcia
plan: 06
subsystem: i18n
tags: [translations, pl]
requires: [12-01]
provides:
  - "PL preklad Entities, Validation, Pwa, Demo, Invoicing, Invoices, Limits, Tasks, Users, DocumentSettings, Login"
affects: [12-07, 12-12]
tech-stack:
  added: []
  patterns: ["PL terminologia podľa UI-SPEC B"]
key-files:
  created:
    - app/Language/pl/Entities.php
    - app/Language/pl/Validation.php
    - app/Language/pl/Pwa.php
    - app/Language/pl/Demo.php
    - app/Language/pl/Invoicing.php
    - app/Language/pl/Invoices.php
    - app/Language/pl/Limits.php
    - app/Language/pl/Tasks.php
    - app/Language/pl/Users.php
    - app/Language/pl/DocumentSettings.php
    - app/Language/pl/Login.php
key-decisions:
  - "Validation.php v pl je prázdne pole, hlášky dodáva codeigniter4/translations (obsahuje pl)"
  - "ICU plural pre dni po termíne má PL vetvy one/few/many/other"
requirements-completed: []
duration: 15min
completed: 2026-11-04
---

# Phase 12 Plan 06: Polish translation, first half Summary

11 súborov `app/Language/pl/*.php` s rovnakými kľúčmi a zástupnými symbolmi ako sk, terminológia Zadanie / Faktura / Faktura korygująca / Zestawienie pracy / Podmiot / Termin płatności / Data wykonania usługi. Všetky preklady sú **na prezretie vlastníkom**.

## Commits
- feat(12-06): entities, validation, pwa, demo
- feat(12-06): invoicing and invoices
- feat(12-06): limits, tasks, users, document settings, login

## Tests
`tests/unit/I18n` + `tests/feature/Settings/LocaleTest.php`: 25 testov, 2898 assertions OK. `php -l` všetkých 11 súborov čistý.

## Na kontrolu vlastníkom
- Entities: skratky IČO, DIČ, IČ DPH ponechané (SK/CZ pojmy), NIP/REGON/CEIDG pre PL; `errorVatPrefix` použité „Numer VAT UE“.
- Entities `errorSeriesSame`: príklad predpony „KOR-“ namiesto „DB-“.
- Invoicing `qrReason*`, `paymentNote*`, `zbp*` a Invoices `bannerCancelled`/`bannerCorrects` majú charakter textov dokladu; jazyk dokladov sa tu nemení.
- Entities `registryHintSk`: „Rejestr działalności gospodarczej, urząd okręgowy, numer“.
- Neosobný tvar pre odhlásenie („Nastąpiło wylogowanie“) kvôli rodu.

## Deviations from Plan
None - plan executed as written. Validation: súbor vracia `[]`; overené, že `vendor/codeigniter4/translations/Language/pl/Validation.php` existuje (v kontajneri) a obsahuje poľské hlášky (`Pole {field} jest wymagane`); priame volanie `lang()` nebolo spustené samostatne.

## Known Stubs
None.

## Self-Check: PASSED
