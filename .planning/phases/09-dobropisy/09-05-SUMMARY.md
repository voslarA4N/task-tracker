---
phase: 09-dobropisy
plan: 05
subsystem: invoicing
tags: [credit-note, tests, concurrency, immutability]
requires: [09-04]
provides:
  - tests/_support/credit_note_worker.php
  - CreditNoteConcurrencyTest, CreditNoteRollbackTest, CreditNoteReissueTest, CreditNoteImmutabilityTest
affects: [09-06]
key-files:
  created:
    - tests/_support/credit_note_worker.php
    - tests/integration/Invoicing/CreditNoteConcurrencyTest.php
    - tests/integration/Invoicing/CreditNoteRollbackTest.php
    - tests/integration/Invoicing/CreditNoteReissueTest.php
    - tests/integration/Invoicing/CreditNoteImmutabilityTest.php
requirements-completed: [INV-07]
duration: ~20 min
completed: 2026-11-01
---

# Phase 9 Plan 05: Credit note edge-case proofs Summary

Four integration test classes prove that a credit note is safe under concurrency, rollback, re-invoicing in another month, and later master-data changes. No production code needed changing: every test passed against the 09-04 `CreditNoteIssuer`.

## Commits
- 428c827 test: concurrency (2 processes, different/same request_key: exactly one credit note, `already_credited`, gapless series) and rollback (renderer failure, wrong template version: nothing persisted, counter and parts untouched, number stays free)
- 9807f4f test: reissue cycle (F1 -> credit note -> moveMonth to 2026-11 -> F2; also same-month reissue; task_billing_v/TaskQueries billed hours not doubled; F1 lines keep source_allocation_id) and immutability (original state/PDF bytes/sha256 unchanged; UPDATE/DELETE blocked; credit note unchanged after client/entity/rate/FX cache changes)

## Verification
- `composer test --filter 'CreditNote|InvoiceImmutab|TaskBillingViewTest|TaskQueriesTest|AllocationServiceTest'`: 103 tests OK
- `composer stan` OK, `composer cs` clean

## Deviations from Plan
None. No fixes were needed in CreditNoteIssuer, TaskQueries, AllocationService or task_billing_v (no migration added).

## Known Stubs
None.

## Self-Check: PASSED
