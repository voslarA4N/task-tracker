---
phase: 11-preh-ady-exporty-a-audit
plan: 08
subsystem: export
tags: [csv, export, rpt-02, formula-guard]
requires: [11-06]
provides:
  - ExportCell / ExportTable / CsvWriter / FormulaGuard / ExportFilename / ExportResponse / ReportTable
  - GET /reports/month.csv and /reports/year.csv
  - Export.php lang (sk/en) for the whole phase
affects: [11-09, 11-10, 11-11]
key-files:
  created:
    - app/Libraries/Export/ExportCell.php
    - app/Libraries/Export/ExportTable.php
    - app/Libraries/Export/FormulaGuard.php
    - app/Libraries/Export/CsvWriter.php
    - app/Libraries/Export/ExportFilename.php
    - app/Libraries/Export/ExportResponse.php
    - app/Libraries/Export/ReportTable.php
    - app/Views/reports/_export.php
    - app/Language/sk/Export.php
    - app/Language/en/Export.php
    - tests/unit/Export/FormulaGuardTest.php
    - tests/unit/Export/CsvWriterTest.php
    - tests/unit/Export/ExportFilenameTest.php
    - tests/feature/Reports/ExportEndpointsTest.php
    - tests/integration/Reporting/ReportTotalsMatchTest.php
  modified:
    - app/Controllers/Reports.php
    - app/Config/Routes/reports.php
    - app/Views/reports/_month_body.php
    - app/Views/reports/_year_body.php
key-decisions:
  - "Typed cells (text/money/decimal) instead of heuristics: only text goes through FormulaGuard, so negative sums are never prefixed."
  - "Year CSV mirrors the screen (12 month rows with 'all clients', then client rows, then total); no month x client matrix."
  - "CSV endpoints throw PageNotFoundException for invalid period/year (unlike the screens, which redirect)."
requirements-completed: [RPT-02, RPT-01]
completed: 2026-11-04
---

# Phase 11 Plan 08: CSV export of reports Summary

Monthly and yearly reports download as CSV (UTF-8 BOM, `;`, decimal comma, formula-guarded text, filename with entity slug and period). All sums come from `ReportCalculator` via `ReportTable`, which only maps results. The shared export model is ready for XLSX (11-09), bundle (11-10) and invoice export (11-11).

## Tasks
1. Export model, FormulaGuard, CsvWriter, ExportFilename with unit tests - 60d6e92.
2. ReportTable, ExportResponse, CSV endpoints, export buttons, Export lang, feature test - 3ab5a1d.
3. D-05 totals match test (report = SQL sum = TurnoverCalculator = CSV, month and year) - baacc64.

## Verification
- Reports feature, Export unit, I18n, ViewsCsp: 53 tests green; ReportTotalsMatchTest 4 tests green. PHPStan (incl. views) clean, php-cs-fixer applied.
- Full suite: OK (2265 tests, 26555 assertions).

## Deviations from Plan
- Credit notes inherit the original's delivery date, so a negative per-client CSV row cannot arise from real data; the "negative sum without apostrophe" case is covered in the CsvWriter unit test, and the endpoint test asserts a netted credit note and no `'-` prefix.
- In the totals test a payment on a credited invoice is rejected by PaymentService (cancelled), so payments are placed on a different invoice.
- No calculator or table bug was found by the D-05 test.

## Known Stubs
None.

## Self-Check: PASSED
