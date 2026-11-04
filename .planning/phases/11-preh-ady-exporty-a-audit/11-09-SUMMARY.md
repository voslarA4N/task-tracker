---
phase: 11-preh-ady-exporty-a-audit
plan: 09
subsystem: export
tags: [xlsx, openspout, export, rpt-02, formula-guard]
requires: [11-08]
provides:
  - XlsxWriter::render(ExportTable): string
  - XlsxNumber::fromCell (only place with a (float) cast)
  - GET /reports/month.xlsx and /reports/year.xlsx
affects: [11-10, 11-11]
key-files:
  created:
    - app/Libraries/Export/XlsxNumber.php
    - app/Libraries/Export/XlsxWriter.php
    - tests/_support/XlsxReader.php
    - tests/_support/PhpStan/Fixtures/app/Libraries/Export/XlsxNumber.php
    - tests/integration/Export/XlsxWriterTest.php
  modified:
    - composer.json
    - composer.lock
    - tests/_support/PhpStan/ForbiddenCallsRule.php
    - app/Controllers/Reports.php
    - app/Config/Routes/reports.php
    - app/Views/reports/_export.php
    - app/Views/reports/_month_body.php
    - app/Views/reports/_year_body.php
    - tests/feature/Reports/ExportEndpointsTest.php
    - tests/integration/Reporting/ReportTotalsMatchTest.php
key-decisions:
  - "Text is always a StringCell (inline string), never Cell::fromValue, so '=...' cannot become a formula; sheet xml has no <f>."
  - "CSV and XLSX actions share one private Reports::export() so the formats cannot diverge."
  - "PHPStan float-cast exemption limited to the exact file XlsxNumber.php (FLOAT_CAST_ALLOWED_FILES); float literals, / and bcmath remain forbidden."
requirements-completed: [RPT-02]
completed: 2026-11-04
---

# Phase 11 Plan 09: XLSX export of reports Summary

Monthly and yearly reports download as XLSX via openspout/openspout v5.13.0 (streamed to a temp file in writable/exports, removed in `finally`). Sums are numeric cells with format `#,##0.00`, header is bold, first row frozen, column widths set, and no formulas are ever written. The D-05 totals test now also compares XLSX values to the report.

## Tasks
1. openspout dependency, XlsxNumber, ForbiddenCallsRule exemption + selftest fixture - 8c79c4f.
2. XlsxWriter, XlsxReader test helper, XlsxWriterTest - 921f9f0.
3. XLSX endpoints, button, feature and totals-match tests - 52a049d.

## Verification
- Reports feature, Reporting, Export, ViewsCsp: 92 tests green. PHPStan incl. selftest unchanged (forbiddenFloat=20).
- Full suite: OK (2274 tests, 26618 assertions).

## Deviations from Plan
- `#,##0.00` is built-in number format id 4 in OpenSpout/XLSX, so it is not listed in `numFmts`; the test asserts `numFmtId="4"` in styles.xml instead (custom `0.0000####` is listed).
- Test avoids `(float)`/float literals (PHPStan rule applies to tests), comparing decimals via Money and string prefix.
- php-cs-fixer realigned a constant in ForbiddenCallsRule, committed with Task 2.

## Known Stubs
None.

## Self-Check: PASSED
