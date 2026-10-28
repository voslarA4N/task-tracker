---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 03
subsystem: invoicing-pdf
tags: [report, rep-1, trigger, immutability]
requires: [06-02]
provides:
  - migration RequireReportDocument (deferred trigger requires report for snapshot_schema >= 2)
  - InvoiceIssuer stores rep-1 report in the same transaction, IssueRejected report_failed
  - GET invoices/{id}/report, Faktura PDF and Vykaz PDF buttons on invoice detail
  - Tests\Support\OverridingLanguage
affects: []
key-files:
  created:
    - app/Database/Migrations/2026-10-28-000001_RequireReportDocument.php
    - tests/_support/OverridingLanguage.php
    - tests/integration/Invoicing/ReportDocumentTest.php
    - tests/integration/Invoicing/ReportTotalsMatchTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - app/Libraries/Invoicing/IssueRejected.php
    - app/Controllers/Invoicing.php
    - app/Controllers/Invoices.php
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Views/invoices/show.php
    - app/Config/Routes/invoices.php
    - public/assets/css/app.css
key-decisions:
  - "Report is rendered after the invoice PDF inside the issue transaction; any non-DB failure becomes report_failed and rolls everything back (D-20)"
  - "Download button replaced by Vykaz PDF per UI-SPEC; ?download=1 still supported by the controller"
  - "Controller never calls a renderer; missing document is 404 (schema 1) or 500 with critical log (INV-13)"
requirements-completed: [REP-01, REP-02]
duration: ~60 min
completed: 2026-10-27
---

# Phase 6 Plan 03: Report document on issue Summary

Every newly issued invoice now stores an immutable rep-1 PDF report in the same transaction, the DB enforces it, and the invoice detail links both PDFs served from stored bytes.

## Tasks

1. Report at issue, DB-enforced completeness, immutability tests - commit 53c28f0.
2. Report endpoint, detail buttons, endpoint and D-24 re-download tests - commit b6ca3a2.

## Verification

- Full suite (`make test`): **OK, 1150 tests, 7717 assertions**, 0 failures.
- `composer stan` clean, `composer cs` clean (cs-fix applied).
- Migration applied to dev and test DB via `make test-db`; `CreateInvoices` migration untouched.
- `grep -c "render(" app/Controllers/Invoices.php` = 0.

## Deviations from Plan

- **[Rule 3 - Blocking]** `tests/_support/InvoiceFixtures.php` extended with `snapshot_schema` and `with_report` overrides so raw-fixture invoices can be schema 1 (no report) or 2 (with report). Not in the plan's file list.
- The "third part without priced hours" in ReportTotalsMatchTest uses a normal 3 h task (tasks.hours has a > 0 check); the translation test issues one of two parts so a fresh snapshot can still be built.
- Anonymous report redirect test added to `InvoicesListTest` (the endpoint test logs in at setUp).
- External invoice "no report required" test dropped (fixtures require lines); external invoices are exempted by the `origin = 'issued'` branch of the trigger.

## Known Stubs

None.

## Self-Check: PASSED

Commits 53c28f0 and b6ca3a2 exist; created files present; full suite green.
