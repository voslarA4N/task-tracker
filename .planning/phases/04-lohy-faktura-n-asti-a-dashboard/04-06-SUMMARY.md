---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 06
subsystem: work-queries
tags: [postgres, queries, filter, dashboard]
requires: [04-01, 04-02]
provides:
  - TaskFilter (fromQuery, toQuery, activeCount)
  - TaskQueries (list, detail, dashboard)
affects: [04-07, 04-12, 04-13]
tech-stack:
  added: []
  patterns: [single aggregate SQL over task_billing_v, ILIKE with ESCAPE, per-currency GROUP BY]
key-files:
  created:
    - app/Libraries/Work/TaskFilter.php
    - app/Libraries/Work/TaskQueries.php
    - tests/unit/Work/TaskFilterTest.php
    - tests/integration/Work/TaskQueriesListTest.php
    - tests/integration/Work/TaskQueriesDetailTest.php
    - tests/integration/Work/TaskQueriesDashboardTest.php
key-decisions:
  - "Dashboard uses exactly 5 queries (due, wip, 3 groups with COUNT(*) OVER ())"
  - "planned_pct is clipped so billed_pct + planned_pct <= 100"
requirements-completed: [TSK-03, TSK-04, TSK-05, TSK-06, DSH-01]
duration: 20min
completed: 2026-10-26
---

# Phase 4 Plan 06: Task queries Summary

Read layer for tasks: whitelisted URL filter, N+1-free list over `task_billing_v`, detail with allocation aggregates (remaining never below 0, delta), and dashboard tiles grouped per currency (never summed) plus three task groups.

## Tasks
1. RED tests - 6cbe2b4
2. TaskFilter and TaskQueries GREEN - 4e4c164

## Deviations from Plan
- Test expectations corrected during GREEN: `done` state includes done tasks with only unlocked allocations (locked_count = 0, per spec); a done task with locked and unlocked parts counts in `partial` group; PL fixture client uses default identifier_type (nip violates CHECK).
- `make cs-fix` reformatted unrelated 04-05 files (AllocationInput/Service, AllocationInputTest); reverted as out of scope. Plain `make cs` may flag them (deferred).

## Verification
Full suite 790 tests green, phpstan OK, `ESCAPE` and `GROUP BY a|c.currency` gates satisfied, no float.

## Self-Check: PASSED
