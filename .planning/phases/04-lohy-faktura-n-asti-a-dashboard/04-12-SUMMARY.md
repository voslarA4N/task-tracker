---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 12
subsystem: work-ui
tags: [tasks, list, filters, htmx]
requires: ["04-06", "04-07"]
provides:
  - "GET /tasks (Tasks::index) with TaskFilter whitelist and htmx fragment"
  - "tasks/index, tasks/_list, tasks/_task_row (shared .work-task row for dashboard 04-13)"
  - "Tasks.* list, filter, row and empty-state language keys (sk, en)"
affects: [04-13]
key-files:
  created:
    - app/Views/tasks/index.php
    - app/Views/tasks/_list.php
    - app/Views/tasks/_task_row.php
    - tests/feature/Work/TaskListTest.php
  modified:
    - app/Controllers/Tasks.php
    - app/Config/Routes/tasks.php
    - app/Language/sk/Tasks.php
    - app/Language/en/Tasks.php
key-decisions:
  - "Filter form targets #task-list with outerHTML swap; the fragment includes the meta count and hint so they refresh together"
  - "Client filter lists active and archived clients (archived marked)"
requirements-completed: [TSK-03, TSK-04, TSK-06]
duration: 20min
completed: 2026-10-26
---

# Phase 4 Plan 12: Task list and filters Summary

Owner sees "Aktuálne úlohy" on /tasks with derived state badges, per-row quoted hours and amount, a parts progress bar, and filters (client, state, billing month, text) that update via htmx fragment with the state pushed to the URL and work as a plain GET form without JS.

## Tasks
1. RED feature tests - 9eed0bf
2. Tasks::index, route, views, language keys - a21de62

## Deviations from Plan
- `aria-label` of the FAB uses `htmlspecialchars` instead of `esc(..., 'attr')` (the latter hex-encodes diacritics; same approach as 04-07).
- `getGet()` is not nullable per phpstan, so no `?? []` fallback.

## Known Stubs
None.

## Deferred
- Manual device checks (iPhone: filter collapse, FAB, one-handed use) deferred to end-of-phase human verification.

## Verification
`phpunit tests/feature tests/unit` 605 green, ViewsCspTest and I18n green, `make stan` OK, `make cs` clean.

## Self-Check: PASSED
