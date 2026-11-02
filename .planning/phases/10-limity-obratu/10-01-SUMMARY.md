---
phase: 10-limity-obratu
plan: 01
subsystem: reporting
tags: [limits, bcmath, tdd, period-resolver, thresholds]
requires: []
provides:
  - App\Libraries\Reporting\PeriodResolver (calendar_year, rolling_12m, pro_rata_first_year, proRataLimit)
  - App\Libraries\Reporting\LimitPeriod (readonly VO)
  - App\Libraries\Reporting\LimitMath (percent, ratio, reached, reachedThresholds, level, remaining, exceededBy, formatPercent)
  - App\Libraries\Reporting\LimitStatus (readonly VO)
affects: [10-02, 10-03, 10-05, 10-06]
tech-stack:
  added: []
  patterns: [bcmath string arithmetic, exact threshold decision via bccomp, Clock::parseDate for strict dates]
key-files:
  created:
    - app/Libraries/Reporting/LimitPeriod.php
    - app/Libraries/Reporting/PeriodResolver.php
    - app/Libraries/Reporting/LimitMath.php
    - app/Libraries/Reporting/LimitStatus.php
    - tests/unit/Reporting/PeriodResolverTest.php
    - tests/unit/Reporting/ThresholdTest.php
  modified:
    - tests/_support/PhpStan/ForbiddenCallsRule.php
key-decisions:
  - "A3: pro-rata days capped at 365 (min(days,365)), base 365 even in leap year; marked [overit] with accountant (D-03)"
  - "A4: threshold decided by exact bccomp(total*100, threshold*limit); percent for display is half-up to 1 decimal (79.96 % shows 80.0 but does not reach 80)"
  - "D-07: rolling_12m openingYears = all calendar years the window touches"
  - "Window always computed from the reference date, not from the limit row year"
  - "LimitMath and PeriodResolver added to the bcmath allow-list of ForbiddenCallsRule (same precedent as Fx)"
requirements-completed: [LIM-05, LIM-03]
duration: 15min
completed: 2026-11-02
---

# Phase 10 Plan 01: Limit period resolver and threshold math Summary

Pure, DB-free calculation core for turnover limits: three window methods (calendar year, rolling 12 months, pro-rata first year with 240 000 x 170/365 = 111 780,82) and exact bcmath threshold/percent logic with a readonly LimitStatus value object.

## Tasks

| Task | Commits |
|------|---------|
| 1 PeriodResolver + LimitPeriod | 01bdf99 (RED), bd29801 (GREEN) |
| 2 LimitMath + LimitStatus | bad0243 (RED), 0be0763 (GREEN) |

Verification: `phpunit tests/unit/Reporting` 27 tests OK; `phpstan analyse app/Libraries/Reporting tests/unit/Reporting` no errors. TDD gate order (test then feat) present for both tasks.

## Deviations from Plan

**1. [Rule 3 - Blocking] PHPStan ForbiddenCallsRule rejected bcmath and DateTimeImmutable::createFromFormat**
- **Found during:** Task 2 verification
- **Issue:** the project rule allows bcmath only in Money/Hours/Fx and static DateTime constructors only in Clock; the plan required both in the new classes (and its acceptance phpstan check).
- **Fix:** added `LimitMath.php` and `PeriodResolver.php` to `BC_ALLOWED_FILES` (as was done for Fx); `PeriodResolver::parse` uses `(new Clock('UTC'))->parseDate()` instead of `createFromFormat` (identical strict behaviour).
- **Files:** tests/_support/PhpStan/ForbiddenCallsRule.php, app/Libraries/Reporting/PeriodResolver.php
- **Commit:** 0be0763

**2. [Rule 1 - Bug in test] test helper `status()` collided with final `TestCase::status()`** - renamed to `makeStatus()`.

Note: the plan's acceptance grep for `round\(` on PeriodResolver matches the required `bcround(` call (count 1, not 0); no PHP `round()` is used.

## Known Stubs

None.

## Self-Check: PASSED
All six created files and four commits verified present.
