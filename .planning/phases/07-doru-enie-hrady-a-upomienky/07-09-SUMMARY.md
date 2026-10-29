---
phase: 07-doru-enie-hrady-a-upomienky
plan: 09
subsystem: receivables
tags: [payments, insert-only, postgres-view, advisory-lock, bcmath, clock]
requires: [07-04]
provides:
  - payments and payment_voids insert-only tables (immutable_guard and truncate_guard, app_rw SELECT/INSERT only)
  - invoice_status_v v2 (22 columns; paid_amount, balance, last_paid_on appended; statuses partially_paid and paid)
  - PaymentService (record, void, impact, parseAmount), PaymentRejected, RecordedPayment
  - InvoiceStatusQueries (forInvoice with Clock day, payments list)
affects: [07-10, 07-11]
tech-stack:
  added: []
  patterns: [pg_advisory_xact_lock per invoice, request_key replay, void as separate insert-only record, overdue computed from Clock day via CAST(:today: AS date)]
key-files:
  created:
    - app/Database/Migrations/2026-10-29-000005_CreatePayments.php
    - app/Database/Migrations/2026-10-29-000006_ExtendInvoiceStatusViewPayments.php
    - app/Libraries/Receivables/PaymentService.php
    - app/Libraries/Receivables/PaymentRejected.php
    - app/Libraries/Receivables/RecordedPayment.php
    - app/Libraries/Receivables/InvoiceStatusQueries.php
    - tests/integration/Receivables/PaymentRolesTest.php
    - tests/integration/Receivables/InvoiceStatusViewTest.php
    - tests/integration/Receivables/PaymentServiceTest.php
    - tests/integration/Receivables/PaymentVoidTest.php
  modified:
    - tests/_support/MasterFixtures.php
    - tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
    - app/Libraries/Delivery/TemplateContext.php
    - app/Libraries/Delivery/InvoiceMailer.php
key-decisions:
  - "Status priority external > paid > partially_paid > sent > issued; total = 0 is never auto-paid and accepts no payment (already_paid)"
  - "Concurrency guard is pg_advisory_xact_lock(hashtext('payments'), invoice_id) taken before reading the balance, in the same transaction"
  - "Void is a separate insert-only payment_voids row with mandatory reason (DB CHECK and service), audited as payment_void"
  - "Money arithmetic goes through Platform\\Money (phpstan rule forbids raw bcmath outside Money/Hours)"
requirements-completed: [PAY-01, PAY-03, INV-06]
duration: 70min
completed: 2026-10-29
---

# Phase 7 Plan 09: Payments, balance and payment states Summary

Insert-only payments and voids with a balance-aware invoice_status_v (partially_paid, paid), and a PaymentService that writes payments exactly (default whole balance, overpayment rejected, advisory-locked, request_key idempotent) with Clock-based overdue queries.

## Tasks

1. payments / payment_voids tables, invoice_status_v v2, roles, view and column tests - a83e16a
2. PaymentService, PaymentRejected, RecordedPayment, InvoiceStatusQueries, TemplateContext balance, service and void tests - 3eba716

## Verification

Full PHPUnit suite: 1473 tests, 22902 assertions, OK. `composer stan` no errors. `composer cs` clean (after php-cs-fixer fix). Acceptance greps: no float/round in Receivables, no CURRENT_DATE/now() in InvoiceStatusQueries and the view migration, `CAST(:today: AS date)` present, no UPDATE/DELETE on payments.

## Deviations from Plan

**1. [Rule 3 - Blocking] Money instead of raw bcmath in PaymentService**
- The project phpstan rule `app.forbiddenBcmath` allows bccomp/bcsub only in Platform\Money and Hours. PaymentService uses Money (compare, plus, minus), which is bcmath scale 2 underneath.
- Commit: 3eba716

**2. [Rule 1 - Test design] Concurrency test uses owner() as the second session**
- `Database::connect('tests', false)` returns a connection with identical credentials; PHP `pg_connect` reuses the same underlying session, so advisory locks were re-entrant and the test could not observe blocking. The test now takes the second session from `owner()` (different credentials) and asserts `pg_try_advisory_xact_lock(hashtext('payments'), id)` fails while the first transaction holds the lock and succeeds after commit, then that a following record sees the committed balance.

**3. [Info] Migrations kept as two files**
- Plan 07-04 warned about the 30-file migrations directory limit in the old /mnt/c environment. The repo now lives on ext4 (31 files + the two new ones) and `spark migrate` ran 000005 and 000006 normally, so the planned two-file layout was used.

**4. [Rule 2 - Test fixtures] Fixture invoice totals**
- InvoiceFixtures requires lines to sum to the invoice total, so tests build a 1080.00 invoice through `createAllocation(... ['rate' => '135.00', 'amount' => '1080.00'])` instead of overriding the total; the zero-total case uses a 0.00 rate allocation.

Additionally, `InvoiceMailer::loadInvoice` now selects `balance` from invoice_status_v and TemplateContext uses it for `{{balance}}` (falls back to total when absent), as required by the plan.

## Known Stubs

None. `impact()` is server-side for the 07-10 UI; no screens are added in this plan.

## Threat Flags

None beyond the plan threat model (T-07-39 to T-07-44b mitigated and tested).

## Self-Check: PASSED

Files, and commits a83e16a and 3eba716 exist.
