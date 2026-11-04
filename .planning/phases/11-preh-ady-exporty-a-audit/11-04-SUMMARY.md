---
phase: 11-preh-ady-exporty-a-audit
plan: 04
subsystem: reporting
tags: [reports, rpt-01, payments, money]
requires: [11-01]
provides:
  - ReportMath::paidShare
  - ReportResult.paid (clients, months) and paidTotal
affects: [11-06, 11-08, 11-09]
key-files:
  created:
    - app/Libraries/Reporting/ReportMath.php
    - tests/unit/Reporting/ReportMathTest.php
    - tests/integration/Reporting/ReportPaymentsTest.php
  modified:
    - app/Libraries/Reporting/ReportCalculator.php
    - app/Libraries/Reporting/ReportResult.php
    - app/Views/reports/_month_body.php
    - app/Language/sk/Reports.php
    - app/Language/en/Reports.php
    - tests/_support/ReportFixtures.php
    - tests/_support/PhpStan/ForbiddenCallsRule.php
    - tests/feature/Reports/ReportsPageTest.php
    - app/Views/tasks/_list.php
key-decisions:
  - "Payment amount in entity currency = difference of cumulative paidShare values (round once on the cumulation), never a current rate."
  - "Voided payments are excluded; clients with only a payment in the period appear with invoiced 0.00."
requirements-completed: [RPT-01]
completed: 2026-11-04
---

# Phase 11 Plan 04: Paid column in monthly report Summary

Monthly report now shows Uhradené per client and in total, assigned by payment date, excluding voids, converted to the entity currency from the invoice's stored total/total_entity_ccy ratio.

## Tasks
1. `ReportMath::paidShare` (bcmath scale 12, half away from zero, clamp to total, zero-total guard) plus allowlist entry in ForbiddenCallsRule - a0d2d7b.
2. Second SQL in `ReportCalculator` (window SUM per invoice, NOT EXISTS payment_voids), `ReportResult` paid fields, view column/cards/summary, sk/en lang, tests - 374d3ab.

## Verification
- phpunit Reporting integration/unit, Reports feature, I18n: 156 tests green. PHPStan (incl. views) clean. php-cs-fixer applied.
- Full suite: OK (2194 tests, 26367 assertions).

## Deviations from Plan

**1. [Rule 3 - Blocker] PHPStan views error from 11-03.** `app/Views/tasks/_list.php` used `new DateTimeImmutable` (rule app.forbiddenTime), making `composer stan` fail. Replaced with `service('clock')->parseDate(substr(done_at, 0, 10))`. Commit 049e5a4. Task tests (121) green.

**2. Test fixtures.** PLN fixture invoice needs fx_rate/fx_source ('NBP')/fx_date to satisfy DB checks.

## Known Stubs
None.

## Self-Check: PASSED
