---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 04
subsystem: work-domain
tags: [tasks, audit, transactions, tdd]
requires: ["04-01", "04-02"]
provides:
  - "TaskInput (FIELDS whitelist, fromPost, validate, normalize)"
  - "TaskService create/update/delete/find with audit and atomic lock checks"
  - "LastClient::resolve default client for new task"
  - "ClientCurrencyLocked guard in ClientService::update"
  - "Tasks language files (sk/en)"
affects: [04-07, 04-08, 04-12]
key-files:
  created:
    - app/Libraries/Work/TaskInput.php
    - app/Libraries/Work/TaskService.php
    - app/Libraries/Work/TaskRejected.php
    - app/Libraries/Work/TaskHasLockedAllocations.php
    - app/Libraries/Work/LastClient.php
    - app/Libraries/Master/ClientCurrencyLocked.php
    - app/Language/sk/Tasks.php
    - app/Language/en/Tasks.php
    - tests/unit/Work/TaskInputTest.php
    - tests/integration/Work/TaskServiceTest.php
    - tests/integration/Work/TaskDeleteTest.php
    - tests/integration/Master/ClientCurrencyLockTest.php
  modified:
    - app/Libraries/Master/ClientService.php
    - app/Controllers/Settings/Clients.php
    - app/Language/sk/Clients.php
    - app/Language/en/Clients.php
key-decisions:
  - "Client currency lock applies to ANY billing allocation (locked and unlocked), stricter than the Claude's Discretion wording 'while the client has unlocked parts'. Reason: RESEARCH Pitfall 9 and Open Question 3 (RESOLVED): after Phase 5 the client would hold issued documents in the original currency and a currency change would break total consistency."
  - "LastClient derived from the latest task (no new table); archived last client falls back to first active client by name"
  - "Task update that changes nothing returns false without writing or auditing"
requirements-completed: [TSK-01, TSK-02]
duration: 30min
completed: 2026-10-26
---

# Phase 4 Plan 04: Task domain layer Summary

Task input validation, TaskService with transactional audit, atomic delete (unlocked allocations removed, locked ones block via `AND NOT EXISTS` plus affectedRows), client-change guard inside the same UPDATE, and a client currency lock.

## Commits
- f357313 test(04-04): failing tests (RED)
- 563c457 feat(04-04): TaskInput, TaskService, LastClient, Tasks language
- f9ad468 feat(04-04): currency lock in ClientService and 422 in Settings\Clients

## Decisions Made
- Currency lock covers any allocation, locked or unlocked (stricter than the Discretion text; see key-decisions).
- Allocation `billing_month` is `char(7)` `YYYY-MM`; tests use that format.

## Deviations from Plan
**1. [Rule 1 - Test bug]** TaskDeleteTest helper named `count` collided with final `TestCase::count`; renamed `rows`. Rollback assertion scoped to task and billing_allocation audit rows (client create audit rows legitimately exist).
**2. [Rule 3]** php-cs-fixer reformatted two new test files.

## Known Stubs
None. Tasks language keys beyond error and field labels are added by 04-07 and 04-12.

## Verification
Unit, integration and feature Master/Work tests green (103 + 31 tests); `make stan` and `make cs` clean.

## Self-Check: PASSED
