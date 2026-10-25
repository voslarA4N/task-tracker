---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 05
subsystem: work-domain
tags: [allocations, audit, transactions, tdd]
requires: ["04-01", "04-02"]
provides:
  - "AllocationInput (FIELDS whitelist, fromPost with month_choice, validate, normalize)"
  - "AllocationService add/update/delete/find/listForTask with atomic unlocked guard and audit"
  - "AllocationLocked domain exception (409)"
  - "Allocations language files (sk/en, base keys)"
affects: [04-08, 04-10, 04-11]
key-files:
  created:
    - app/Libraries/Work/AllocationInput.php
    - app/Libraries/Work/AllocationService.php
    - app/Libraries/Work/AllocationLocked.php
    - app/Language/sk/Allocations.php
    - app/Language/en/Allocations.php
    - tests/unit/Work/AllocationInputTest.php
    - tests/integration/Work/AllocationServiceTest.php
    - tests/integration/Work/AllocationE5Test.php
  modified: []
decisions:
  - "update() compares only hours, billing_month, rate, amount against the locked row; no-op returns false without UPDATE or audit"
  - "DatabaseException containing 'is locked' (trigger) is translated outside Transaction::run, after rollback"
  - "Existing allocations are never recalculated when the task rate changes"
requirements-completed: [ALC-01, ALC-02, ALC-05]
metrics:
  tasks: 2
  files: 8
completed: 2026-10-26
---

# Phase 4 Plan 05: Allocation service Summary

Billing allocation domain service: add/edit/move/delete of unlocked parts with `invoice_id IS NULL` enforced in the same SQL statement plus an `affectedRows()` check, trigger as last line of defence, and audit in the same transaction.

## What was built

- `AllocationInput`: whitelist `hours, billing_month, rate`; month composed from `month_choice` (current/next/other) unless `billing_month` is posted directly; validation with range limits in the message; `allowZeroHours` for the "Zapracované" panel.
- `AllocationService`: lock order task then allocation (T-04-23); amount always via `Money::timesHours`; currency copied from the client; rate defaults to the task rate; works for any task status (D-12); over-allocation is not blocked.
- `AllocationLocked` with `allocationId`.
- Tests: 9 input, 12 service, 1 E5 (200 h in 4 months, 5th part gives `allocated_hours` 206.00). Full `tests/unit/Work` + `tests/integration/Work` + LangKeysTest: 94 tests green; PHPStan clean.

## Commits

- 9ad45aa test(04-05): add failing allocation service tests (RED)
- f69c53e feat(04-05): AllocationService with atomic unlocked guard (GREEN)

## Deviations from Plan

None - plan executed as written.

## Known Stubs

None. Language files hold only base keys; 04-10 adds the rest as planned.

## Self-Check: PASSED

All 8 files exist; both commits present in git log.
