---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 08
subsystem: work
tags: [tasks, allocations, transaction, audit]
requires: [04-04, 04-05]
provides:
  - TaskService::remainingHours, markDone, reopen
affects: [04-09, 04-11]
tech-stack:
  added: []
  patterns: [FOR UPDATE + conditional UPDATE with affectedRows, nested Transaction::run]
key-files:
  created: [tests/integration/Work/MarkDoneServiceTest.php]
  modified: [app/Libraries/Work/TaskService.php]
key-decisions:
  - "markDone with 0 h marks done without a new allocation (returns null)"
  - "reopen keeps all allocations unchanged"
requirements-completed: [ALC-04]
duration: 15min
completed: 2026-10-26
---

# Phase 4 Plan 08: Mark done and reopen Summary

Atomic "mark as done" (status, done_at from Clock, allocation in chosen month at task rate, audit) and "reopen" in `TaskService`, with remaining-hours default for the panel.

## Tasks
1. RED: 9 integration tests (commit see git log, `test(04-08)`).
2. GREEN: `remainingHours`, `markDone`, `reopen` (`feat(04-08)`). Work integration suite 69 tests green, PHPStan and cs clean.

## Deviations from Plan
**[Rule 3 - Blocking]** PHPStan rule forbids `bcadd` outside Money/Hours; used `Hours::plus` for normalization. Test view query uses `task_id` column (plan said `id`). No other deviations.

## Known Stubs
None.

## Self-Check: PASSED
