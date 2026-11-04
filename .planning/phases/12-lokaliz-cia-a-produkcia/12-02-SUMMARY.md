---
phase: 12-lokaliz-cia-a-produkcia
plan: 02
subsystem: i18n
tags: [intl, formats, dates, locale, invoice-immutability]
requires: []
provides:
  - "DateFormat::date (IntlDateFormatter MEDIUM podla jazyka UI)"
  - "Test tabuľky formátov sk, pl, cs, en"
  - "Dôkaz nezávislosti vystaveného dokladu od jazyka UI"
affects: [12-06, 12-07, 12-08, 12-09]
tech-stack:
  added: []
  patterns: ["ICU je zdroj pravdy pre UI formáty", "jazyk dokladu iba zo snapshotu"]
key-files:
  created:
    - app/Libraries/Platform/DateFormat.php
    - tests/unit/Platform/MoneyFormatLocaleTest.php
    - tests/unit/Platform/DateFormatTest.php
    - tests/integration/Invoicing/LocaleIndependentDocumentTest.php
  modified:
    - app/Libraries/Master/EntityInput.php
    - app/Controllers/Settings/Entities.php
    - app/Controllers/Settings/EntityVersions.php
key-decisions:
  - "MoneyFormat, Hours, LimitMath, BillingMonth nemenené: test pre 4 jazyky prešiel bez zásahu"
requirements-completed: []
duration: 25min
completed: 2026-11-04
---

# Phase 12 Plan 02: Formáty podľa jazyka rozhrania Summary

Helper DateFormat (ICU MEDIUM) nahradil natvrdo zapísané dátumové formáty v UI a testy v kontajneri php dokazujú správne sumy, percentá, hodiny, mesiace a dátumy v sk, pl, cs, en a nemennosť dokladu voči jazyku UI.

## Commits
- 30f5972 test: formáty súm, percent, hodín a mesiacov v 4 jazykoch
- fa942f2 feat: DateFormat a EntityInput::fmt cez neho
- cb49df2 fix: DateFormat bez zakázaného gmmktime (PHPStan app.forbiddenTime)
- 77c7ef7 test: LocaleIndependentDocumentTest
- 0f8193e fix: formatDate v Settings/Entities a EntityVersions

## Tests
tests/unit, tests/feature/Settings, tests/feature/Master: 1077 testov OK. LocaleIndependentDocumentTest (4) OK, EntityVersionTest OK, PHPStan bez chýb. ICU dáta v kontajneri majú cs aj pl (Kč, listopad).

## Audit tvrdých formátov

| Nález | Rozhodnutie |
|-------|-------------|
| EntityInput::fmt `j. n. Y` | opravené, DateFormat::date |
| Settings/Entities::formatDate a EntityVersions::formatDate (vzor `d. M. yyyy`, minul ho grep) | opravené, DateFormat::date |
| errors/html/error_exception `date('H:i:s')` | ponechať (vývojárska chybová stránka) |
| LimitMath::formatPercent, Hours::separator | ponechať, test pre 4 jazyky prešiel |
| PdfFormat, FxFormat, TemplateRenderer, CsvWriter | ponechať (jazyk dokladu/exportu, UI-SPEC C) |
| `format('Y-m-d')`, `'Y'`, `'n'`, `'Y-m'` vo Fx*, PeriodResolver, InvoiceDraftService, *Input, Clock, AuditFilter, InvoiceFilter, HistoryPlanner, BillingMonth::current, Security, TwoFactor, _fx_card | ponechať (kľúče, výpočty, DB hodnoty, nie zobrazenie) |

## Deviations from Plan

**1. [Rule 1 - Bug] Dva ďalšie controllery s tvrdým vzorom dátumu**
- Audit z plánu ich nezachytil (IntlDateFormatter so vzorom `d. M. yyyy`); `Entities::formatDate` a `EntityVersions::formatDate` teraz volajú DateFormat::date. Commit 0f8193e.

**2. [Rule 1 - Bug] PHPStan zakazuje gmmktime**
- DateFormat formátuje priamo DateTimeImmutable s jeho časovou zónou. Commit cb49df2.

**3.** Integračný test porovnáva HTML šablóny `pdf/invoice_inv2` zo snapshotu (mPDF bajty nie sú deterministické) a uložené bajty a sha256 z DocumentStore. Šablóna nezávisí od locale, nebola potrebná oprava.

## Known Stubs
None.

## Self-Check: PASSED
