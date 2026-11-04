---
phase: 11-preh-ady-exporty-a-audit
plan: 03
subsystem: work-ui
tags: [tasks, list, table, sorting, ui-03]
requires: []
provides:
  - TaskFilter sort/dir whitelist, withSort()
  - TaskQueries::list ORDER BY via SORT_SQL map
  - Desktop task table in the same htmx fragment as mobile cards
affects: [11-11]
key-files:
  created:
    - tests/unit/Work/TaskFilterSortTest.php
    - tests/feature/Work/TasksListTableTest.php
  modified:
    - app/Libraries/Work/TaskFilter.php
    - app/Libraries/Work/TaskQueries.php
    - app/Controllers/Tasks.php
    - app/Views/tasks/index.php
    - app/Views/tasks/_list.php
    - app/Language/sk/Tasks.php
    - app/Language/en/Tasks.php
key-decisions:
  - "Table is read-only (no bulk select exists); row links to the task detail."
  - "Default sort keeps the previous ORDER BY; sort/dir never interpolated, only SORT_SQL map plus ASC/DESC."
requirements-completed: [UI-03]
completed: 2026-11-04
---

# Phase 11 Plan 03: Task table on desktop Summary

`/tasks` renders a sortable table on PC (>= 992px) and the existing cards on mobile from one fragment (`#task-list`) and one query; filters stay in one row on PC.

## Tasks
1. RED tests: `test(11-03): failing task sort tests` (b391634).
2. `feat(11-03): server-side task sorting with whitelist` (TaskFilter, TaskQueries).
3. `feat(11-03): task table on desktop in the same fragment` (controller `mainWide`, views, sk/en lang).

## Verification
- phpunit tests/feature/Work, tests/unit/Work, tests/unit/I18n, ViewsCspTest: OK (160 tests, 601 assertions).
- PHPStan: no errors. php-cs-fixer applied. No inline style/onclick/script in task views.
- Human check pending on staging (PC table, iPhone cards).

## Deviations from Plan
None in behavior. Test assertion matches the escaped `client&#x3D;` form of sort links; the filter grid uses `row g-3 g-lg-0 dt-filters` with `col-lg-12` columns so mobile is unchanged. app.css not modified.

## Known Stubs
None.

## Threat Flags
None. T-11-09 mitigated by SORT_SQL + unit test with malicious sort; T-11-10 by esc() and http_build_query.

## Self-Check: PASSED
