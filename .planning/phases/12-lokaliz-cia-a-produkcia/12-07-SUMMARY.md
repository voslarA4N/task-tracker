---
phase: 12-lokaliz-cia-a-produkcia
plan: 07
subsystem: i18n
tags: [translations, pl]
requires: [12-01]
provides:
  - "PL preklad Deliveries, Clients, CreditNotes, Allocations, Bundle, PasswordReset, LegalTexts, Currency, AuditLog, EmailTemplates, TwoFactor, Dashboard, Payments, FxSettings, Notify, Reports, Export"
affects: [12-12]
tech-stack:
  added: []
  patterns: ["PL terminologia podľa UI-SPEC B", "ICU plural PL one/few/many/other"]
key-files:
  created:
    - app/Language/pl/Deliveries.php
    - app/Language/pl/Clients.php
    - app/Language/pl/CreditNotes.php
    - app/Language/pl/Allocations.php
    - app/Language/pl/Bundle.php
    - app/Language/pl/PasswordReset.php
    - app/Language/pl/LegalTexts.php
    - app/Language/pl/Currency.php
    - app/Language/pl/AuditLog.php
    - app/Language/pl/EmailTemplates.php
    - app/Language/pl/TwoFactor.php
    - app/Language/pl/Dashboard.php
    - app/Language/pl/Payments.php
    - app/Language/pl/FxSettings.php
    - app/Language/pl/Notify.php
    - app/Language/pl/Reports.php
    - app/Language/pl/Export.php
key-decisions:
  - "Export filePrefix* ostali rovnaké ako v sk (prehlad, faktury, balik)"
  - "Notify predmety majú prefix Work Tracker:"
requirements-completed: []
duration: 20min
completed: 2026-11-04
---

# Phase 12 Plan 07: Polish translation, second half Summary

17 súborov `app/Language/pl/*.php` s rovnakými kľúčmi a zástupnými symbolmi ako sk; `pl` má teraz všetkých 32 súborov. Všetky preklady sú **na prezretie vlastníkom**.

## Commits
- ee42cc2 feat(12-07): deliveries, clients, credit notes, allocations, bundle, password reset
- 1df05be feat(12-07): legal texts, currency, audit log, email templates, two factor
- 6cf83fa feat(12-07): dashboard, payments, fx, notify, reports, export

## Tests
`tests/unit/I18n` (13 testov, 3660 assertions) a `tests/feature/Settings/LocaleTest.php` (12 testov) OK; `php -l` všetkých 17 súborov čistý.

## Na kontrolu vlastníkom
- EmailTemplates: `kindReminder` = „Wezwanie do zapłaty“, `var_variable_symbol` = „symbol zmienny“.
- LegalTexts: názvy skupín (niepłatnik VAT, art. 113 ust. 1 / ust. 9) a `bodyHint`; ide o UI nastavení, texty dokladov sa nemenia.
- Clients: `taxId` = „NIP“, `vatId` = „Numer VAT UE“, `typeLocalId` ponechané „IČO / NIP“.
- Deliveries/Payments: „Wezwanie do zapłaty“ pre upomienku, „Wpłata“ pre úhradu, „storno“ ponechané.
- Dashboard `overdueMeta`: ICU plural s vetvami one/few/many/other.
- PasswordReset a Notify: neosobné/neutrálne tvary kvôli rodu („Jeśli to nie Ty“).

## Deviations from Plan
None - plan executed as written.

## Known Stubs
None.

## Self-Check: PASSED
