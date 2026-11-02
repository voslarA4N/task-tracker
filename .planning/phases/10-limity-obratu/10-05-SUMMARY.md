---
phase: 10-limity-obratu
plan: 05
subsystem: dashboard
tags: [limits, turnover, dashboard, alerts]
requires: [10-02]
provides:
  - LimitAlerts::fromEntities (sorted dashboard alert rows)
  - home/_limits partial (block Limity obratu, rendered only with a reason)
affects: [10-07]
key-files:
  created:
    - app/Libraries/Reporting/LimitAlerts.php
    - app/Views/home/_limits.php
    - tests/unit/Reporting/LimitAlertsTest.php
    - tests/feature/DashboardLimitsTest.php
  modified:
    - app/Controllers/Home.php
    - app/Views/home/_body.php
    - app/Language/sk/Dashboard.php
    - app/Language/en/Dashboard.php
key-decisions:
  - "Row order: crit, config (currency error), warn, info"
  - "No limit for country shown as an info row on the dashboard"
  - "Method and badge texts live in Dashboard.php, not Limits.php, so the plan is independent of 10-03"
requirements-completed: [LIM-03]
duration: 20min
completed: 2026-11-02
---

# Phase 10 Plan 05: Dashboard limit alerts Summary

Dashboard block "Limity obratu" shown only when a limit reaches a threshold, has a currency error, or the current-year limit row is missing. It sits above "Po splatnosti" in the existing `body` fragment, ignores the client filter, links every row to `/settings/limits` and cannot be dismissed.

## Tasks

| Task | Commits |
|------|---------|
| 1 LimitAlerts | 6cbdce5 (test), 75892f0 (feat) |
| 2 Dashboard block | 16b4704 (test), 2353123 (feat) |

## Verification

- `phpunit LimitAlertsTest DashboardLimitsTest tests/feature/Work tests/unit/I18n`: 96 tests OK.
- PHPStan on `app/Libraries/Reporting`, `Home.php` and the new tests: no errors.
- Acceptance greps: `btn-close|data-bs-dismiss|style=|onclick=|clientId` in `_limits.php` = 0; `_limits` is included before `_overdue`.
- Human check on staging (iPhone) remains from the plan's verification section.

## Deviations from Plan

**1. [Rule 1 - Test bug]** The test `testMissingYearInfo` checks the dismissal elements inside the limits block only. The layout itself has `btn-close` and `data-bs-dismiss` (install hint, offcanvas), so a page-wide assertion always failed. The lowercase "limit pre rok" matches the lang string `{0}: limit pre rok ...`.

**2.** The unit test helper is named `st()` because `status()` is a final method of PHPUnit's TestCase.

## Known Stubs

None.

## Threat Flags

None. T-10-17 to T-10-20 are mitigated: `esc()` on all text, no client parameter in the limits query, no dismiss control (tested).

## Self-Check: PASSED
