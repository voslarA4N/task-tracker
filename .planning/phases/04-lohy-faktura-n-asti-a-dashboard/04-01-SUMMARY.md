---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 01
subsystem: database
tags: [postgres, migrations, trigger, immutability, view]
requires: []
provides:
  - tasks table
  - billing_allocations table with alloc_guard trigger
  - task_billing_v derived billing state view
  - GrantsPrivileges::grantDelete()
  - MasterFixtures createTask/createAllocation/lockAllocation
affects: [04-02, phase-05]
tech-stack:
  added: []
  patterns: [DB-level immutability via trigger + column grants + FK RESTRICT, derived state in view]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000007_CreateTasks.php
    - app/Database/Migrations/2026-10-25-000008_CreateBillingAllocations.php
    - app/Database/Migrations/2026-10-25-000009_CreateTaskBillingView.php
    - tests/integration/Work/AllocationSchemaTest.php
    - tests/integration/Work/AllocationGuardTest.php
    - tests/integration/Work/TaskBillingViewTest.php
  modified:
    - app/Database/Support/GrantsPrivileges.php
    - tests/_support/MasterFixtures.php
key-decisions:
  - "billing_month char(7) with regex CHECK; invoice_id without FK until Phase 5"
  - "Lock order documented: tasks row FOR UPDATE first, then allocation"
requirements-completed: [ALC-01, ALC-02, TSK-03]
duration: 25min
completed: 2026-10-25
---

# Phase 4 Plan 01: Tasks and billing allocations schema Summary

Schema for tasks and billing allocations with database-enforced immutability (CHECK amount = round(hours*rate,2), alloc_guard trigger for all roles, column grants without invoice_id/task_id/currency, FK RESTRICT) and a derived `task_billing_v` view.

## Tasks
1. RED tests and fixtures - 21983b7
2. Migrations, view, grantDelete - see git log `feat(04-01)`

## Deviations from Plan
- [Rule 1 - Test] FK test regex widened: PostgreSQL message for RESTRICT reads "violates RESTRICT setting of foreign key", not "violates foreign key".
- php-cs-fixer reformatted the new test files; added a phpdoc return type to a test helper for PHPStan.

## Verification
tests/integration: 188 tests green; phpstan OK; cs clean.

## Self-Check: PASSED
