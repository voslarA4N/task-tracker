---
phase: 11-preh-ady-exporty-a-audit
plan: 01
subsystem: reporting
tags: [reports, rpt-01, money, htmx]
requires: []
provides:
  - ReportPeriod (month/year/range VO)
  - ReportResult VO
  - ReportCalculator::forPeriod (single source of report sums)
  - GET /reports/month screen
affects: [11-04, 11-06, 11-08, 11-09, 11-10]
tech-stack:
  added: []
  patterns: [single SQL aggregation + Money in PHP, htmx fragment #report-body]
key-files:
  created:
    - app/Libraries/Reporting/ReportPeriod.php
    - app/Libraries/Reporting/ReportResult.php
    - app/Libraries/Reporting/ReportCalculator.php
    - app/Controllers/Reports.php
    - app/Config/Routes/reports.php
    - app/Views/reports/month.php
    - app/Views/reports/_header.php
    - app/Views/reports/_month_body.php
    - app/Language/sk/Reports.php
    - app/Language/en/Reports.php
    - tests/_support/ReportFixtures.php
    - tests/unit/Reporting/ReportPeriodTest.php
    - tests/integration/Reporting/ReportCalculatorTest.php
    - tests/feature/Reports/ReportsPageTest.php
  modified:
    - app/Views/more/index.php
    - app/Views/layouts/_nav_items.php
key-decisions:
  - "External invoices (origin=external) are excluded from reports and the page says so."
  - "Calculator returns invoiced (all issued) and counted (counts_toward_limit); screen shows invoiced."
  - "The report header (entity select) lives inside #report-body so period navigation never leaves a stale hidden period."
requirements-completed: [RPT-01]
duration: ~40 min
completed: 2026-11-04
---

# Phase 11 Plan 01: Monthly report screen Summary

Monthly Prehľady (`/reports/month`) shows invoiced amounts per client in the entity currency with a Spolu row, backed by `ReportCalculator`, the single source of report sums (turnover_date, origin=issued, Money only).

## Tasks
1. RED tests (1010a25): period unit tests, calculator integration tests, page feature tests.
2. GREEN calculator (e278f10): ReportPeriod, ReportResult, ReportCalculator.
3. Screen (5b15af0): controller, route, 3 views, sk/en lang, More menu link, nav aria-current.

## Verification
- phpunit tests/unit/Reporting, tests/integration/Reporting, tests/feature/Reports, I18n, ViewsCsp, Layout: green.
- Full suite: OK (2149 tests, 26250 assertions). PHPStan: no errors. php-cs-fixer applied.
- countedTotal equals TurnoverCalculator::turnover for the same period (tested).

## Deviations from Plan

**1. [Rule 1 - Bug] Credit note month semantics.** `CreditNoteIssuer` copies the original invoice's `turnover_date` and `delivery_date`, so a credit note nets in the month of the original (its own delivery date), not in the month of its issue date. The planned test "credit note issued in November makes November negative" was wrong; the test now asserts October nets to 0.00 (1 invoice, 1 credit note) and November is empty. Calculator behaviour is unchanged and consistent with TurnoverCalculator.

**2. [Rule 3 - Blocker] Test fixture amounts.** `TurnoverFixtures::hoursForAmount` supports only whole-currency amounts at rate 100, so test amounts use 80.00/1080.00/1580.00 instead of the planned 80.50/1080.50/1580.50. Decimal formatting is still covered by MoneyFormat.

**3. Layout.** The page passes `mainWide => true` but the layout support arrives in 11-02, so it is currently ignored. CSS classes (.work-table, .dt-table, .dt-only-lg/sm, .rpt-*) are also from 11-02 and unstyled until then.

**4. Structure.** The `_header` partial is rendered from `_month_body` (inside `#report-body`) rather than from `month.php`, to avoid a stale hidden `period` field after htmx navigation.

## Known Stubs
None. The Uhradené column and the Mesiac/Rok switch are out of scope (11-04, 11-06).

## Self-Check: PASSED
