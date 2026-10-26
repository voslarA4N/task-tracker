---
phase: 04-lohy-faktura-n-asti-a-dashboard
fixed_at: 2026-10-26T14:30:00Z
review_path: .planning/phases/04-lohy-faktura-n-asti-a-dashboard/04-REVIEW.md
iteration: 1
findings_in_scope: 8
fixed: 8
skipped: 0
status: partial
---

# Phase 4: Code Review Fix Report

**Fixed at:** 2026-10-26T14:30:00Z
**Source review:** .planning/phases/04-lohy-faktura-n-asti-a-dashboard/04-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 8 (CR-01, WR-01 to WR-07; Info findings are out of scope)
- Fixed: 8 (WR-07 only partially: it now reports the problem, and the owner decides what the import should write)
- Skipped: 0

**Verification:** after every fix I ran `composer stan` (both configs plus the selftest), `composer cs` and the relevant PHPUnit suites. At the end I ran the full `composer test` against a freshly rebuilt test DB with all migrations including the new one: 897 tests passed. `node --test tests/js/` passed 38 of 38. All runs used the dev php image (`task-tracker-php:dev`) with the isolated review-fix worktree mounted.

## Fixed Issues

### CR-01: hours × rate can exceed numeric(12,2)

**Files modified:** `app/Libraries/Work/TaskInput.php`, `app/Libraries/Work/AllocationInput.php`, `app/Controllers/Tasks.php`, `app/Views/tasks/_alloc_form.php`, `app/Language/{sk,en}/Tasks.php`, `app/Language/{sk,en}/Allocations.php`, `app/Database/Migrations/2026-10-26-000001_AddTasksQuotedAmountCheck.php` (new), tests (`TaskInputTest`, `AllocationInputTest`, `TaskFormTest`, `AllocationPanelTest`, `TaskServiceTest`)
**Commit:** 9d9d4f5
**Applied fix:**
- New `TaskInput::amountFits()` uses `Money::timesHours` (no new bcmath) to check that hours × rate fits.
- Task and part validation now return a 422 field error on `rate` instead of failing: `Tasks.errorAmountTooLarge` / `Allocations.errorAmount` (sk and en).
- The sum previews in `Tasks::formData` and `_alloc_form.php` skip a product that does not fit, so re-rendering a 422 no longer crashes.
- New migration adds `CHECK tasks_quoted_amount_fits (round(hours * rate, 2) <= 9999999999.99)`. It is added `NOT VALID` and then validated only if no existing row violates it, so the migration cannot fail on old data. It is validated in the test DB.
- The migration is applied to the dev DB (`make migrate` after the fast-forward) and to the test DB.

### WR-01: Dashboard groups overlap

**Files modified:** `app/Libraries/Work/TaskQueries.php`, `tests/integration/Work/TaskQueriesDashboardTest.php`
**Commit:** f72a2a3
**Applied fix:**
- Groups now split the tasks with a fixed priority:
  1. `done_due`: done, with an unlocked part in the selected month.
  2. `partial`: partially invoiced and not in group 1.
  3. `in_progress`: in progress and not partially invoiced.
- The conditions live in one public place, `TaskQueries::groupCondition()`, together with `DASHBOARD_GROUPS`.
- `testGroups` now expects 1 / 1 / 12, which adds up to the 14 tasks. It also checks that in another month the done task moves to partial.
- New `testGroupsAreDisjoint`.
- Status: fixed: requires human verification (logic change; the priority was set by the orchestrator, not by the spec).

### WR-02: Tiles and "Zobraziť všetky" links open lists that do not match

**Files modified:** `app/Libraries/Work/TaskFilter.php`, `app/Libraries/Work/TaskQueries.php`, `app/Views/home/_body.php`, `app/Views/tasks/_list.php`, `app/Language/{sk,en}/Tasks.php`, tests (`TaskFilterTest`, `TaskQueriesListTest`, `DashboardTest`)
**Commit:** 55c0058
**Applied fix:**
- `TaskFilter` has a new `group` parameter with a strict whitelist: `due`, `done_due`, `partial`, `in_progress`.
  - A group that depends on the month is dropped when no month is given.
  - When a group is set, `state` is reset to the default.
- `TaskQueries::list` uses exactly `groupCondition()` for the group. The month is used inside the group condition, not as the separate "has a part in month" filter. So the list matches the tile or group by construction.
- Links:
  - "Na fakturáciu" tile: `/tasks?group=due&month=X`, meaning any task with an unlocked part in month X (spec D-14 / ALC-05).
  - Group links: `group=done_due|partial|in_progress`.
  - The "V práci" tile still links to `state=in_progress`, which matches its sum.
- The list shows a "Výber z prehľadu: …" line with a "Vymazať filtre" link.
- The integration test checks that each group's list has the same IDs and count as the dashboard.
- Status: fixed: requires human verification (UX of the drill-down note).

### WR-03: Month switch cannot return from a custom month to a preset

**Files modified:** `app/Controllers/Home.php`, `app/Views/home/_switch_sheet.php`, `tests/feature/Work/DashboardTest.php`
**Commit:** 415aee0
**Applied fix:**
- New explicit "Iný…" radio (`month=other`). It is checked when the current month is not one of the presets.
- `month_other` is used only when `month=other`, or when `month` is missing entirely (so old links keep working).
- With JS off, the user has to pick the "Iný" radio before typing a custom month.

### WR-04: A task whose client is archived cannot be edited

**Files modified:** `app/Controllers/Tasks.php`, `tests/feature/Work/TaskFormTest.php`
**Commit:** f83c535
**Applied fix:**
- `Tasks::update` accepts the task's own client even when it is archived, through `ClientService::find`.
- Choosing a different client still goes through `activeClient()`, so picking a different archived client still returns 422 `Tasks.errorClient`.

### WR-05: Editing a task stores a bare ticket number

**Files modified:** `app/Libraries/Work/TaskService.php`, `tests/integration/Work/TaskServiceTest.php`
**Commit:** 6838c67
**Applied fix:**
- `TaskService::update` now always reads the target client `FOR SHARE`, even when the client does not change. Lock order is still task, then client.
- It expands a bare ticket number with that client's `portal_url_template` (`expandUrl`), the same way `create()` does. This happens before the no-op comparison.
- The archived rule is unchanged: the task's own client may be archived, a newly chosen client may not.

### WR-06: Race between part insert and client currency change

**Files modified:** `app/Libraries/Work/AllocationService.php`, `tests/integration/Work/AllocationServiceTest.php`
**Commit:** 0414af1
**Applied fix:**
- `AllocationService::add` now locks with `FOR UPDATE OF t FOR SHARE OF c`.
- `markDone` and the import go through `add`, so they are covered too.
- New test: while `add` is running inside a transaction, a second session cannot get `FOR UPDATE NOWAIT` on the client. I confirmed that this test fails without the fix.

### WR-07: Import turns already-invoiced Excel rows into invoiceable parts

**Status:** partially fixed, awaiting owner decision
**Files modified:** `app/Libraries/Work/Import/ImportPlan.php`, `app/Libraries/Work/Import/ImportPlanner.php`, `app/Libraries/Work/Import/ImportReportFormatter.php`, `app/Commands/ImportExcel.php`, `docs/ops/import-excel.md`, tests (`ImportPlannerTest`, `ImportReportFormatterTest`)
**Commit:** bb50dd7
**Applied fix:**
- **Report only. The import still writes the same data (IMP-01).**
- `ImportPlanner` counts the rows marked "Fakturované = ÁNO" that are also done (only these become unlocked parts). It records them per month in `ImportPlan::billedParts` with the number of rows, hours and amount.
- The dry-run and commit reports print:
  - one line per month: `! <mesiac>: N riadkov s Fakturované = ÁNO (h / suma) bude nezamknutými časťami na fakturáciu.`
  - a summary line `POZOR: … sa importuje ako NEZAMKNUTÉ časti a budú ponúknuté na fakturáciu znova …`
- The commit run repeats the warning in yellow after the result.
- The runbook describes the warning and says the owner decides.
- **Still open, owner decision:** whether billed rows should create no part, or be locked against a historical invoice in Phase 5.

## Notes for the orchestrator

- **Migration:** the new migration is `2026-10-26-000001_AddTasksQuotedAmountCheck`. I did not edit any existing migration. It is applied to `task_tracker_test` and, through `make migrate`, to the dev DB `task_tracker`.
- **Worktree testing:** the worktree was tested with `docker run` on `task-tracker-php:dev`, mounting the worktree and the existing `task-tracker_vendor` volume. The temporary volumes have been removed.

---

_Fixed: 2026-10-26T14:30:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_

## Owner decision WR-07 (2026-10-26)

Voľba **C**: import ostáva bez zmeny (nezamknuté časti + varovanie v reporte); vo Phase 5 sa tieto časti zamknú na historickú externú faktúru bez čísla z vlastného radu. Sledované v STATE.md a ROADMAP Phase 5.
