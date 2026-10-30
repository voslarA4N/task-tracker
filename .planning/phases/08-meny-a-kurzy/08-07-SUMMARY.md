---
phase: 08-meny-a-kurzy
plan: 07
subsystem: invoicing
tags: [fx, manual-rate, htmx, validation, audit]
requires: [08-06, 08-10]
provides:
  - "FxInput: whitelist and bcmath validation of manual rate, date, anchor and retry"
  - "InvoiceDraftService fx states manual/invalid, anchor_changed, fields/errors/fetched"
  - "InvoiceIssuer manual-rate path (cached original rate stored in snapshot, audit fx_fetched_rate)"
  - "details.fx-manual UI, Try again (fx_retry), hidden fx_* issue fields, manual badge in confirm sheet"
affects: [08-09]
key-files:
  created:
    - app/Libraries/Fx/FxInput.php
    - tests/unit/Fx/FxInputTest.php
    - tests/integration/Invoicing/InvoiceManualFxTest.php
    - tests/feature/Invoicing/FxManualEntryTest.php
  modified:
    - app/Libraries/Invoicing/{PreviewInput,IssueInput,IssueCommand,InvoiceDraftService,InvoiceIssuer,Draft}.php
    - app/Controllers/Invoicing.php
    - app/Views/invoicing/{_fx_card,_preview,_confirm_sheet}.php
    - app/Language/{sk,en}/Currency.php
    - tests/_support/IssueFixtures.php
key-decisions:
  - "FxInput reuses FxConverter::assertRate for range/positivity (stan allows bcmath only in Fx converter/quote) and Clock::parseDate for dates"
  - "Fx buttons use hx-push-url=false so fx_retry and manual rate never land in the pushed URL"
  - "Prefilled manual date is min(bound, today) to avoid a spurious date_future error when bound is in the future"
  - "Manual quote in issuer takes the original rate from FxService::cached (no network), same row the draft resolved, so tokens match"
requirements-completed: []
completed: 2026-10-30
---

# Phase 8 Plan 07: Manual FX rate Summary

Owner can override a fetched rate or enter one manually when the API is down; the manual rate is stored with `fx_source = 'manual'`, the originally fetched rate in the snapshot and in the audit (`fx_manual`, `fx_fetched_rate`), and "Skusit znova" bypasses the negative cache.

Commits: `b0e5c17` (FxInput, inputs, draft and issuer logic, tests), `0c83be3` (UI, controller, lang, feature tests, stan fixes).

## Deviations from Plan
- **[Rule 3 - Blocking]** stan forbids `bccomp` and `new DateTimeImmutable` in FxInput: switched to `FxConverter::assertRate` and `service('clock')->parseDate`.
- `IssueFixtures::issueCommand` gained an `fx` override; `InvoicingPreviewTest` refresh-include assertion updated for the new hidden-field ids.
- `FxInput` got extra `rawRate`/`rawDate` properties to echo invalid input back into the form.
- `make cs-fix` changes applied only to files touched by this plan.
- Tests written together with implementation (no separate RED commits).

## Known Stubs
None.

## Human check (not run)
Staging iPhone PWA: Zadat rucne -> 4,30 -> Pouzit kurz -> Vystavit within 3 taps, 44 px targets, no horizontal scroll.

## Self-Check: PASSED
Targeted tests, `make stan` OK; full `make test`: OK (1764 tests, 24504 assertions).
