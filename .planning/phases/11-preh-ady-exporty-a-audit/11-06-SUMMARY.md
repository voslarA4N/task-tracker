---
phase: 11-preh-ady-exporty-a-audit
plan: 06
subsystem: reporting
tags: [reports, rpt-01, year-view, htmx]
requires: [11-01, 11-04]
provides:
  - GET /reports/year (12 months + client breakdown + year total)
  - Mesiac / Rok switch in report header
affects: [11-08, 11-09]
key-files:
  created:
    - app/Views/reports/year.php
    - app/Views/reports/_year_body.php
    - tests/feature/Reports/ReportsYearPageTest.php
  modified:
    - app/Controllers/Reports.php
    - app/Config/Routes/reports.php
    - app/Views/reports/_header.php
    - app/Views/reports/_month_body.php
    - app/Language/sk/Reports.php
    - app/Language/en/Reports.php
key-decisions:
  - "Entity selection extracted to private Reports::context(), shared by month() and year()."
  - "Header takes view/monthKey/yearKey; tab links and entity select keep entity and period/year."
requirements-completed: [RPT-01]
completed: 2026-11-04
---

# Phase 11 Plan 06: Yearly report Summary

`/reports/year` shows months 1-12 (future months 0.00) with invoiced and paid, a year total row, and the per-client breakdown; all sums come from `ReportCalculator::forPeriod(ReportPeriod::year())`. The header has Mesiac / Rok nav-pills with `aria-current`, month rows link to the monthly view, and the monthly view links to its year.

## Tasks
1. Year view, route, controller, views, lang, feature test - b6202d5.
2. Switch in header and year link in month body - b2ce393.

## Verification
- Reports feature, I18n, ViewsCsp: 25 tests green. PHPStan (incl. views) clean, php-cs-fixer applied.
- Full suite: OK (2219 tests, 26442 assertions).

## Deviations from Plan
None of substance. The switch test lives in the task 1 test file (committed with task 1), and the header is used by the year view, so the header changes landed in the second commit while the year view already referenced the new header variables only after commit 2; commit 1 alone is not independently green for the switch test.

## Known Stubs
None.

## Self-Check: PASSED
