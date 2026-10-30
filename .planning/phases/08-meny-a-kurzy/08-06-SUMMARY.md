---
phase: 08-meny-a-kurzy
plan: 06
subsystem: invoicing
tags: [fx, preview, htmx, i18n, csp]
requires: [08-05, 08-10]
provides:
  - "InvoiceDraftService resolves the rate in the preview request (FxService::resolve), Draft->fx state ok/unavailable/unsupported/overflow"
  - "_fx_card partial, .fx-* CSS, Currency lang files (sk/en), fx rows in confirm sheet"
  - "errorFxMissing/Unavailable/Invalid/Overflow; currency_mismatch and assertCurrency removed"
affects: [08-07, 08-09]
key-files:
  created:
    - app/Views/invoicing/_fx_card.php
    - app/Language/sk/Currency.php
    - app/Language/en/Currency.php
    - tests/integration/Invoicing/InvoiceDraftFxTest.php
    - tests/feature/Invoicing/InvoicingFxPreviewTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceDraftService.php
    - app/Libraries/Invoicing/Draft.php
    - app/Libraries/Invoicing/InvoiceGuards.php
    - app/Libraries/Invoicing/IssueRejected.php
    - app/Controllers/Invoicing.php
    - app/Views/invoicing/_preview.php
    - app/Views/invoicing/_confirm_sheet.php
    - public/assets/css/app.css
    - app/Language/{sk,en}/Invoicing.php
key-decisions:
  - "fx state is filled before SnapshotBuilder::build so the card still shows when build fails (e.g. no bank account in invoice currency)"
  - "Without a quote the snapshot is built without fx for display only and previewToken stays null (canIssue false); fx_overflow goes to errors"
  - "fxRetry exists on draft() but is not wired to a controller param; the retry UI and fx_retry input belong to 08-07"
patterns-established:
  - "Preview date inputs carry hx-indicator=#inv-fx-loading and hx-disabled-elt=#inv-issue-btn"
requirements-completed: []
completed: 2026-10-30
---

# Phase 8 Plan 06: FX in preview Summary

The invoice preview now fetches the rate (or reads the cache) for a foreign-currency invoice, shows an `.fx-card` with source, date, table, basis and the total in the entity currency, and carries a preview token identical to the issuer's, so issuing from the preview makes no HTTP call.

Commits: `fc9f405` (draft service, Draft->fx, guards), `9a0e48e` (card, sheet rows, texts, CSS, feature tests). 8 integration tests (InvoiceDraftFxTest), 6 feature tests (InvoicingFxPreviewTest), plus a token-scope test for a rate change.

## Deviations from Plan
- Existing tests that asserted `currency_mismatch` (InvoiceDraftServiceTest, InvoicingPreviewTest, InvoicingIssueTest) were rewritten to the new fx behaviour and inject a FakeRateHttpClient so no live API is ever called.
- Added lang key `Currency.confirmRateSource` (not in the plan list) for the sheet row "expression (NBP, z date)".
- `make cs-fix` reformatted unrelated files; those changes were reverted and not committed.
- Retry (`fx_retry`) is not plumbed through PreviewInput/controller (planned for 08-07 with the manual-entry UI).
- Tests were written together with implementation, no separate RED commits.

## Known Stubs
None. The manual-entry `<details class="fx-manual">` form and "Skusit znova" are intentionally left to 08-07 (CSS classes `.fx-manual`, `.fx-badge` are defined here).

## Self-Check: PASSED
Targeted tests, `make stan` OK; full `make test`: OK (1727 tests, 24358 assertions).
