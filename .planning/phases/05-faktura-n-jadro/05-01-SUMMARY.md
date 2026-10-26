---
phase: 05-faktura-n-jadro
plan: 01
subsystem: database
tags: [postgres, invoices, immutability, triggers, security-definer, migrations]
requires:
  - phase: 04
    provides: billing_allocations, alloc_guard, MasterFixtures
provides:
  - invoices, invoice_lines, invoice_documents, invoice_counters tables
  - immutable_guard, counter_guard, deferred invoice_complete_check
  - lock_allocations() SECURITY DEFINER, FK billing_allocations.invoice_id
  - invoice_status_v view
  - legal_profiles.vat_code (provisional) and LegalProfileResolver::profile vat_code
  - InvoiceFixtures (insertExternalInvoice, insertIssuedInvoiceRaw), MasterFixtures::lockAllocation returning invoice id
affects: [05-02, 05-03, 05-04, 05-05, 05-06, 05-07, 05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - insert-only tables with immutable_guard trigger (blocks even owner)
    - deferred CONSTRAINT TRIGGER for completeness at commit
    - SECURITY DEFINER function as the only path to lock an allocation
key-files:
  created:
    - app/Database/Migrations/2026-10-27-000001_AddLegalProfilesVatCode.php
    - app/Database/Migrations/2026-10-27-000002_CreateInvoices.php
    - app/Database/Migrations/2026-10-27-000003_LockAllocationsToInvoices.php
    - app/Database/Migrations/2026-10-27-000004_CreateInvoiceStatusView.php
    - tests/_support/InvoiceFixtures.php
    - tests/integration/Invoicing/InvoiceSchemaTest.php
    - tests/integration/Invoicing/InvoiceImmutabilityTest.php
    - tests/integration/Invoicing/InvoiceDocumentTest.php
    - tests/integration/Invoicing/AllocationLockTest.php
  modified:
    - app/Libraries/Master/LegalProfileResolver.php
    - tests/_support/MasterFixtures.php
    - tests/integration/Work/AllocationGuardTest.php
    - tests/integration/Master/LegalProfileTest.php
key-decisions:
  - "Fixtures run in App\\Libraries\\Platform\\Transaction::run (transException) because CI4 suppresses query exceptions inside plain transBegin"
  - "SUM in fixtures done in SQL, since bcadd is forbidden outside Money/Hours by the project PHPStan rule"
requirements-completed: [INV-03, INV-08, INV-09, INV-13, ALC-03, NFR-06]
duration: ~40min
completed: 2026-10-27
---

# Phase 5 Plan 01: Invoice DB foundation Summary

Invoice tables with DB-enforced immutability (insert-only grants, immutable_guard for owner too, sha256 CHECK on stored PDF, deferred completeness check), allocation locking only through a SECURITY DEFINER `lock_allocations()`, and a provisional VAT code on legal profiles.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 CreateInvoices + schema/immutability/document tests | 9075e1c | 4 tables, 3 guard functions, 2 constraint triggers, 15 tests |
| 2 FK, alloc_guard, lock_allocations, invoice_status_v, fixtures | 98186a5 | Phase 4 tests stay green with real external invoice fixture |
| 3 vat_code + resolver | fa25718 | provisional seed zw/np, resolver returns vat_code |

## Verification

Full PHPUnit suite 922 tests / 6641 assertions green after a fresh `make test-db`; `composer stan` OK; `make migrate` on dev DB OK.

## Deviations from Plan

**1. [Rule 3 - Blocking] Fixtures transaction handling** - plain `transBegin()` swallows query exceptions in CI4, so fixtures and rollback-only tests use `Transaction::run` / `transException(true)`. Commits 9075e1c, fa25718.

**2. [Rule 3 - Blocking] Project PHPStan rule** forbids bcadd outside Money/Hours; fixture total now summed in SQL. Commit fa25718.

Otherwise the plan was executed as written. The php-cs-fixer pass reformatted alignment in a few new/edited test files.

## Known Stubs

None. `vat_code` values are intentionally provisional (risk R1), to be confirmed by the accountant and corrected by a new migration.

## Self-Check: PASSED
