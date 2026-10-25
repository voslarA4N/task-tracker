---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 02
subsystem: platform-helpers
tags: [bcmath, hours, money-format, billing-month, phpstan, tdd]
requires: []
provides:
  - "Hours: bcmath string helper for hours (of, plus, minus, compare, remaining, delta, percentOf, format, formatSigned)"
  - "MoneyFormat::format shared amount formatter"
  - "BillingMonth: RRRR-MM parse, arithmetic, range, labels via Clock"
affects: [04-03, tasks, allocations, dashboard]
tech-stack:
  added: []
  patterns: ["bcmath only in Money and Hours (PHPStan allowlist)", "month arithmetic via intdiv, labels via IntlCalendar"]
key-files:
  created:
    - app/Libraries/Platform/Hours.php
    - app/Libraries/Platform/MoneyFormat.php
    - app/Libraries/Work/BillingMonth.php
    - tests/unit/Platform/HoursTest.php
    - tests/unit/Platform/MoneyFormatTest.php
    - tests/unit/Work/BillingMonthTest.php
  modified:
    - app/Controllers/Settings/Clients.php
    - tests/_support/PhpStan/ForbiddenCallsRule.php
key-decisions:
  - "Hours::percentOf rounds half-up to step via bcround, returns 100 for >= 100 percent"
  - "MoneyFormat puts the minus sign before the whole formatted amount"
requirements-completed: [TSK-05, ALC-01]
duration: 20min
completed: 2026-10-26
---

# Phase 4 Plan 02: Hours, MoneyFormat, BillingMonth Summary

Float-free helper classes for hours (bcmath strings with signed delta and zero-floored remaining), shared money formatting extracted from the clients list, and Clock-driven billing month arithmetic.

## Commits
- 994fe0e test(04-02): failing tests (RED)
- f8c1b7e feat(04-02): implementation (GREEN)

## Deviations from Plan

**1. [Rule 1 - Bug in test] Polish zloty test uses locale `pl`**
- The plan's vector `format('27.00','PLN','sk')` contains "zł"; ICU `sk` renders `PLN`. Test changed to locale `pl` (formatter logic unchanged from `formatRate`).
- **Fix:** tests/unit/Platform/MoneyFormatTest.php

**2. [Rule 2] Negative amounts with |value| < 1** keep their sign (explicit minus handling, since the integer part would otherwise format as 0).

Selftest fixture needed no change (counts unchanged; fixture is outside the allowlist). php-cs-fixer applied to new files.

## Verification
- unit suite + ClientsTest: 297 tests green; `composer stan` passes incl. selftest; `composer cs` clean.

## Known Stubs
None.

## Self-Check: PASSED
