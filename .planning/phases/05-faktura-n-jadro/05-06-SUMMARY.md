---
phase: 05-faktura-n-jadro
plan: 06
subsystem: invoicing
tags: [concurrency, numbering, immutability, tests]
requires:
  - phase: 05-03
    provides: InvoiceIssuer, IssueFixtures, DocumentStore
provides:
  - issue_worker.php (separate process issuing invoices as app_rw)
  - IssueConcurrencyTest, CounterLockTest, InvoiceImmutableAfterChangeTest
affects: [05-07, 05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - proc_open workers released by a shared start advisory lock (777)
    - pg_connect with PGSQL_CONNECT_FORCE_NEW for independent test connections
key-files:
  created:
    - tests/_support/issue_worker.php
    - tests/integration/Invoicing/IssueConcurrencyTest.php
    - tests/integration/Invoicing/CounterLockTest.php
    - tests/integration/Invoicing/InvoiceImmutableAfterChangeTest.php
  modified: []
key-decisions:
  - "Worker takes a fifth argument entityId (needed for the preview token)"
requirements-completed: [INV-03, INV-04, INV-13, INV-14, NFR-06]
duration: ~40min
completed: 2026-10-27
---

# Phase 5 Plan 06: Concurrency and immutability proofs Summary

Two PHP processes issue 50 invoices concurrently and get exactly 202610001..202610050, the counter row lock is shown deterministically (rollback returns the same number, commit the next), and changing entity, client, rate, currency, IBAN and legal text leaves snapshot, columns, lines and PDF bytes unchanged.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Concurrency (50 issues, 2 processes) and counter lock tests | 4acf585 |
| 2 | Change-everything immutability test (+ stan fixes for task 1 files) | 3108175 |

## Verification

Target tests green three times in a row; full PHPUnit suite green (995 tests, 6978 assertions); `composer stan` OK; `composer cs` clean. No production code changed; the issuer needed no fixes.

## Deviations from Plan

**1. [Rule 3 - Blocking] Worker fifth argument** - the worker also receives `entityId` (the interface listed four arguments) because the preview token requires it.

**2. [Rule 1 - Bug in test] pg_connect reuse** - identical connection strings return the same link, so both CounterLockTest connections initially shared a session; fixed with `PGSQL_CONNECT_FORCE_NEW`. B's upsert is sent after a separate BEGIN.

**3. [Rule 3 - Blocking] PHPStan rules** - project rules forbid `microtime`/`hrtime`, `/` and `$argv`; the start-wait loop uses an attempt counter (200 x 50 ms = 10 s), `intdiv` and `$_SERVER['argv']`.

**4. Entity version in immutability test** - the new entity version is inserted via `EntityService::newVersion` and then back-dated by owner `UPDATE valid_from` so it is effective at the issue date (strongest case).

## Known Stubs

None.

## Threat Flags

None. T-05-31, T-05-32 and T-05-33 mitigated by the tests (10 s start-lock wait bound, proc_close exit code check).

## Self-Check: PASSED

All four files exist; both task commits are in git log.
