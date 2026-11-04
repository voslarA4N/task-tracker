---
phase: 11-preh-ady-exporty-a-audit
plan: 11
subsystem: export
tags: [csv, xlsx, invoices, rpt-02]
requires: [11-02, 11-09, 11-10]
provides:
  - GET /invoices.csv and /invoices.xlsx (filtered, sorted, unlimited)
  - Export buttons and hint in the invoice list fragment
affects: []
key-files:
  created:
    - tests/feature/Invoicing/InvoicesExportTest.php
  modified:
    - app/Controllers/Invoices.php
    - app/Config/Routes/invoices.php
    - app/Views/invoices/_list.php
key-decisions:
  - "Both formats share one private Invoices::exportTable(); it uses InvoiceFilter + InvoiceQueries::list(null, ...) + DocumentListExport::table(rows, false), so filters, sort and columns cannot diverge from the screen."
  - "Filename entity part is the literal 'vsetky' for no entity filter (lang 'Export.allEntities' would slug to vsetky-subjekty)."
requirements-completed: [RPT-02]
completed: 2026-11-04
---

# Phase 11 Plan 11: Invoice list export Summary

The invoice list exports to CSV (`;` + BOM) and XLSX (numeric cells, no formulas) with the current filter and sort and without the 100-row screen limit. File name: `faktury-<subjekt|vsetky>-<date>.<ext>`. Buttons sit above the list inside `#invoice-list` (href refreshes with htmx filter change); a hint is shown when the screen list is truncated; hidden on an empty unfiltered list.

## Tasks
1. Endpoints, routes, feature tests - db436e1.
2. Export buttons in `_list.php` plus tests - 748d8cb.

## Verification
- InvoicesExportTest: 9 tests green; tests/feature/Invoicing + I18n + ViewsCsp: 216 tests green.
- `composer stan`: no errors (forbiddenFloat=20 unchanged).
- Full suite: OK (2297 tests, 26732 assertions).

## Deviations from Plan
- Entity part of the filename for "all" is a literal `vsetky` rather than the lang string (plan test expects `faktury-vsetky-...`).
- Tests written together with implementation (no separate RED commit); the hint assertion lives in Task 2.
- Fixtures need `IssueFixtures` (uuid4) for credit notes.

## Known Stubs
None.

## Pending human check
Staging: filter the list, download CSV and XLSX, open in Excel (SK locale), compare with screen.

## Self-Check: PASSED
