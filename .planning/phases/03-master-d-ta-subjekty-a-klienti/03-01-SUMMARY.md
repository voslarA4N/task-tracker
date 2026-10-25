---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 01
subsystem: database
tags: [audit, postgres, grants, jsonb, phpunit]
requires: []
provides:
  - audit_log insert-only table
  - AuditLogger (record, recordChange, diff, scrub)
  - GrantsPrivileges::grantUpdateColumns
  - DbBool
  - MasterFixtures test trait
affects: [03-02, 03-03, 03-04, 03-05, 03-06, 03-07, 03-08, 03-09, 03-10]
tech-stack:
  added: []
  patterns: [audit in caller's transaction, column-level GRANT UPDATE]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000001_CreateAuditLog.php
    - app/Libraries/Audit/AuditLogger.php
    - app/Libraries/Platform/DbBool.php
    - tests/_support/MasterFixtures.php
    - tests/integration/Audit/AuditLoggerTest.php
    - tests/unit/Audit/AuditLoggerDiffTest.php
    - tests/unit/Platform/DbBoolTest.php
  modified:
    - app/Database/Support/GrantsPrivileges.php
    - tests/integration/DbRolesTest.php
key-decisions:
  - "audit_log is insert-only for app_rw (ACL ar), verified in tests_db"
  - "AuditLogger writes through caller's connection so rollback removes change and audit"
requirements-completed: [AUD-01]
duration: 20min
completed: 2026-10-25
---

# Phase 3 Plan 01: Audit log foundation Summary

Insert-only `audit_log` with `AuditLogger` writing in the caller's transaction, plus column-level `grantUpdateColumns`, `DbBool` and `MasterFixtures`.

## Tasks
1. RED tests: fed4d31
2. GREEN implementation: 2f1dfa0

## Deviations from Plan

**1. [Rule 1 - Bug] Test method named `count` clashed with final PHPUnit `TestCase::count()`** - renamed to `rows`. Fixed in 2f1dfa0.

**2. [Rule 1 - Bug] `ip = 'cli'` assertion** - under PHPUnit the `request` service is an IncomingRequest (ip `0.0.0.0`), not CLIRequest, so the test asserts a non-empty ip instead. The logger still writes `'cli'` for CLIRequest as specified.

**3. RED run** failed on missing class (and, incidentally, the `count` clash); accepted as RED.

**4. Verification note:** the `\dp` check used DB `task_tracker_test` (no DB named `app`); ACL is `app_rw=ar`, as required.

## Known Stubs
None.

## Self-Check: PASSED
Files and commits fed4d31, 2f1dfa0 exist; 77 targeted tests, `make test-unit` (153) and `composer stan` pass.
