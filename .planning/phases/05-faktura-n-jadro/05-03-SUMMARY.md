---
phase: 05-faktura-n-jadro
plan: 03
subsystem: invoicing
tags: [invoice-issue, numbering, snapshot, transactions, idempotency, bytea]
requires:
  - phase: 05-01
    provides: invoices tables, lock_allocations(), immutability triggers
  - phase: 05-02
    provides: InvoiceCalculator, IssueRejected, PdfRenderer, MpdfInvoiceRenderer
provides:
  - InvoiceIssuer::issue as the single writer of own invoices (one transaction, 14 ordered steps)
  - SnapshotBuilder (schema 1 snapshot shared by preview and issue), InvoiceParts, IssueCommand, IssuedInvoice
  - NumberAllocator (gapless upsert counter per series and period), DocumentStore (bytea with sha256 check)
  - IssueFixtures (issueSetup, issueCommand, issuer)
affects: [05-04, 05-05, 05-06, 05-07, 05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - issue = one Transaction::run, render and PDF store inside the transaction, SET CONSTRAINTS ALL IMMEDIATE at the end
    - guard hooks left as comments for 05-05 (pre-build, post-build, post-series-lock)
key-files:
  created:
    - app/Libraries/Invoicing/InvoiceParts.php
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/NumberAllocator.php
    - app/Libraries/Invoicing/AllocatedNumber.php
    - app/Libraries/Invoicing/DocumentStore.php
    - app/Libraries/Invoicing/StoredDocument.php
    - app/Libraries/Invoicing/DocumentIntegrityError.php
    - app/Libraries/Invoicing/IssueCommand.php
    - app/Libraries/Invoicing/IssuedInvoice.php
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - tests/_support/IssueFixtures.php
    - tests/integration/Invoicing/SnapshotBuilderTest.php
    - tests/integration/Invoicing/NumberAllocatorTest.php
    - tests/integration/Invoicing/InvoiceIssuerTest.php
    - tests/integration/Invoicing/IssueRollbackTest.php
    - tests/integration/Invoicing/IssueIdempotencyTest.php
  modified: []
key-decisions:
  - "NumberAllocator uses SeriesFormat::maxSequence() for overflow; the literal acceptance grep for SEQUENCE cannot be 0 because of the required reason code sequence_overflow"
  - "issueCommand fixture computes the preview token only over the requested parts, and a zero token when none are unlocked, so rejection tests can still build a command"
  - "issuer() fixture added in Task 3 (not Task 1) so PHPStan passes at every commit"
requirements-completed: [INV-02, INV-03, INV-04, INV-08, INV-13, INV-14, ALC-03, NFR-06]
duration: ~50min
completed: 2026-10-27
---

# Phase 5 Plan 03: Atomic invoice issue Summary

`InvoiceIssuer::issue()` turns unlocked parts of a client and month into a numbered, immutable invoice with a stored PDF in one transaction, with gapless numbering (202610001, 202610002, 202611001), request_key idempotency and preview_token staleness protection.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | InvoiceParts, SnapshotBuilder, IssueCommand, IssueFixtures | 66d75da |
| 2 | NumberAllocator and DocumentStore | 7445c59 |
| 3 | InvoiceIssuer::issue with issue, rollback and idempotency tests | 9c89ebe |

## Verification

Full PHPUnit suite green (967 tests, 6846 assertions) after `make test-db`; `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 3 - Blocking] Acceptance grep on NumberAllocator** - `grep -ciE 'nextval|SEQUENCE|MAX\('` cannot return 0 because the plan itself requires the reason code `sequence_overflow` and the `maxSequence()` call. No database SEQUENCE, nextval or MAX() is used; docblock wording and variable names were adjusted to keep matches to the unavoidable ones.

**2. [Rule 3 - Blocking] IssueCommand date validation** - project PHPStan rule forbids `DateTimeImmutable::createFromFormat` outside the clock; dates are validated with a regex plus `checkdate`.

**3. [Rule 1 - Bug, test only] Legal text test left seed table empty after a failed first run** - the first version of the CZ legal-text test deleted seed rows and failed to restore (identity `id`). Restore now drops `id`; the seed was re-created with `make test-db`. Test restores rows in `finally`.

**4. Fixtures** - `issueSetup` supports `country` SK, PL and CZ (CZ sets CZK, `cz_non_vat`, language cs), needed for the legal text test. `issuer()` fixture was added in Task 3.

## Known Stubs

None. Guard hooks for 05-05 are intentional comments (`05-05: guards (pre-build|post-build|post-series-lock)`).

## Threat Flags

None. T-05-13 to T-05-19 mitigated as planned: upsert counter under series lock with rollback tests, ids only as ints via `idsLiteral`, `hash_equals` preview token, `request_key` checked before and after locks, parts locked with client and month conditions, render and PDF store in the same transaction, audit without snapshot or bytes.

## Self-Check: PASSED

All 16 files exist; commits 66d75da, 7445c59, 9c89ebe are in git log.
