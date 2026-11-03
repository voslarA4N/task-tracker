---
phase: 10-limity-obratu
fixed_at: 2026-11-03T11:34:31Z
review_path: .planning/phases/10-limity-obratu/10-REVIEW.md
iteration: 1
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
---

# Phase 10: Code Review Fix Report

**Fixed at:** 2026-11-03T11:34:31Z
**Source review:** .planning/phases/10-limity-obratu/10-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 7 (1 critical, 6 warnings; Info findings out of scope)
- Fixed: 7
- Skipped: 0

Verification: full PHPUnit suite run in the `task-tracker-php:dev` image against the isolated worktree: 2118 tests, 0 failures, 1 skipped (pre-existing). PHPStan (`composer stan`) is clean, and a `php-cs-fixer --dry-run` over the whole project reports 0 fixable files.

## Fixed Issues

### CR-01: Optimistic lock bypassed after a 422 on limit edit (lost update)

**Files modified:** `app/Views/settings/limits/_form.php`, `tests/feature/Settings/LimitsCrudTest.php`
**Commit:** a36d91d
**Applied fix:** The hidden `revision` field now echoes `$values['revision']`, which is the submitted revision on 422 and the DB revision on GET or 409 via `valuesOf()`. Before, it always rendered the current DB `$limit['revision']`. New test `testEditInvalidKeepsSubmittedRevision`: a stale revision plus an invalid amount returns 422 and keeps rev 1, and the resubmit returns 409. I confirmed the test fails without the fix.

### WR-01: Opening-turnover revision is not bound to a row

**Files modified:** `app/Libraries/Reporting/OpeningTurnoverService.php`, `app/Controllers/Settings/Limits.php`, `app/Views/settings/limits/_opening_form.php`, `tests/feature/Settings/OpeningTurnoverTest.php`, `tests/integration/Reporting/OpeningTurnoverServiceTest.php`
**Commit:** d90fc47
**Applied fix:** The form posts a hidden `opening_id` (the id of the loaded row, empty for a new row). The service rejects with `stale` unless both `revision` and `opening_id` match the locked row. The controller's 409 path refreshes `opening_id` as well, and clears revision and id when the row no longer exists, so the form does not loop on stale. Existing tests now post `opening_id`. New tests cover changing the year to another row with the same revision; the result is 409 and the other row is unchanged.

### WR-02: "Limit already exceeded" shown at 80 %

**Files modified:** `app/Libraries/Reporting/LimitPrecheck.php`, `tests/unit/Reporting/LimitPrecheckTest.php`, `tests/feature/Invoicing/InvoicingLimitWarningTest.php`
**Commit:** 0658d1a
**Applied fix:** `alreadyOver` is now `LimitMath::reached(total, limit, 100)`, so the flag is set only when the turnover before the invoice is at or above 100 % of the limit. Tests now check that 90 % is not reported as exceeded and that 101 % is.
**Status:** fixed: requires human verification. UI-SPEC F words this as "obrat pred ≥ prah", and the review chose the 100 % reading. Please confirm this is the intended wording.

### WR-03: Pro-rata limit can round to 0.00 (DivisionByZeroError)

**Files modified:** `app/Libraries/Reporting/PeriodResolver.php`, `app/Libraries/Reporting/LimitMath.php`, `tests/unit/Reporting/PeriodResolverTest.php`, `tests/unit/Reporting/ThresholdTest.php`
**Commit:** 0c8a80f
**Applied fix:** `proRataLimit()` clamps a positive amount to a minimum of `0.01`. `LimitMath::percent()` and `ratio()` throw a domain `InvalidArgumentException` for a limit of zero or below, instead of the engine's `DivisionByZeroError`. Unit tests cover both.

### WR-04: Dashboard has no fault isolation for the limit calculation

**Files modified:** `app/Controllers/Home.php`, `app/Libraries/Reporting/LimitAlerts.php`, `app/Views/home/_limits.php`, `app/Language/sk/Dashboard.php`, `app/Language/en/Dashboard.php`, `tests/feature/DashboardLimitsTest.php`
**Commit:** 4607359
**Applied fix:** `Home::limitAlerts()` wraps the calculation in `try/catch (Throwable)`, logs the error, and returns one row of the new kind `failed` (from `LimitAlerts::failed()`). That row renders with a red badge "Výpočet zlyhal" / "Calculation failed" and the text `Dashboard.limitsFailed`. The feature test temporarily revokes SELECT on `entity_opening_turnover` from `app_rw` and re-grants it in a `finally` block. It checks that the dashboard returns 200 and shows the failure row. The guard covers the whole calculation, not each entity separately.

### WR-05: Opening-turnover form shows a stale currency when the entity select changes

**Files modified:** `app/Controllers/Settings/Limits.php`, `app/Views/settings/limits/_opening_form.php`, `tests/feature/Settings/OpeningTurnoverTest.php`
**Commit:** 6c27b88
**Applied fix:** I removed the free entity selector. The form now always renders a hidden `entity_id` and a disabled select, from `?entity=` or the first active entity. The currency symbol and hint therefore always belong to the entity being saved. The `lockedEntity` flag is gone, which also resolves IN-04 as a side effect. The new test checks a PLN entity's symbol and that the form opened without `?entity=` has no selectable `entity_id`.

### WR-06: Concurrent first insert returns 500; deleted row is silently recreated

**Files modified:** `app/Libraries/Reporting/OpeningTurnoverService.php`, `tests/integration/Reporting/OpeningTurnoverServiceTest.php`
**Commit:** fd0e684
**Applied fix:** The insert uses `INSERT ... ON CONFLICT (entity_id, year) DO NOTHING RETURNING id` and throws `stale` when no row comes back. This also replaces the extra `currval` round trip on this path. When the row is missing but the client posted a `revision` or `opening_id` with a non-empty amount, the service throws `stale` instead of recreating the row. An empty amount stays `noop`. The test covers the deleted-row case. The concurrent-insert race has no automated test; only the SQL path was checked.

---

_Fixed: 2026-11-03T11:34:31Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
