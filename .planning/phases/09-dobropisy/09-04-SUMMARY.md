---
phase: 09-dobropisy
plan: 04
subsystem: invoicing
tags: [credit-note, issuer, advisory-lock, transaction]
requires: [09-01, 09-02, 09-03]
provides:
  - CreditNoteIssuer::issue(CreditNoteCommand): IssuedCreditNote
  - IssuedCreditNote value object
  - Services::creditNoteIssuer()
  - CreditNoteFixtures: creditNoteIssuer, loadOriginal, creditNoteCommand, issueCreditNote, assertSumsToZero
affects: [09-05, 09-06, 09-07, 09-08]
tech-stack:
  added: []
  patterns: [pg_advisory_xact_lock instead of row lock for insert-only tables, copy fx from original]
key-files:
  created:
    - app/Libraries/Invoicing/CreditNoteIssuer.php
    - app/Libraries/Invoicing/IssuedCreditNote.php
    - tests/integration/Invoicing/CreditNoteIssuerTest.php
    - tests/integration/Invoicing/CreditNoteGuardsTest.php
    - tests/integration/Invoicing/CreditNoteFxTest.php
  modified:
    - app/Config/Services.php
    - tests/_support/CreditNoteFixtures.php
key-decisions:
  - "Unique violations on invoices_entity_id_number_key and invoices_series_id_number_key both map to number_collision"
  - "IssueRejected from NumberAllocator is rewrapped as CreditNoteRejected with the same reason"
requirements-completed: [INV-07, INV-10]
duration: ~30 min
completed: 2026-11-01
---

# Phase 9 Plan 04: CreditNoteIssuer Summary

`CreditNoteIssuer` issues a full storno of an issued invoice atomically: advisory lock on the original, number from the entity's credit_note series by credit note date, negative lines, cn-1 PDF, release of all parts and audit. FX columns and the entity-currency total are copied from the original (no FX service, no network), so the pair always sums to 0.00.

## Commits
- c862b29 test: failing issuer, guards and FX tests with fixtures
- c079ceb feat: CreditNoteIssuer, IssuedCreditNote, Services::creditNoteIssuer

## Verification
- `composer test --filter 'CreditNote|InvoiceIssuer|AllocationGuard|IssueConcurrency'`: 86 tests OK
- `composer stan`: no errors; `composer cs`: clean

## Deviations from Plan
- Added `SERIES_NUMBER_CONSTRAINT` (`invoices_series_id_number_key`) mapped to `number_collision` as well; the plan named only the entity constraint.
- `phpunit` here is 11.5 (not the pinned 10.5 in docs); no impact.

## Known Stubs
None.

## Self-Check: PASSED
