---
status: complete
quick: 261110-kfy
requirements: [09-REVIEW-WR-02, 09-REVIEW-WR-04]
commits: [57f506a, 9b3f1a6, 8bacda9]
completed: 2026-11-10
---

# Quick 261110-kfy: Phase 9 review WR-02 and WR-04

Payments recorded before storno can now be voided on a cancelled invoice, and a payment on a cancelled invoice is blocked by a DB trigger plus a shared advisory lock.

## Changes

- WR-02 (57f506a): `PaymentService::void()` no longer rejects cancelled invoices (D-17); removed unreachable `cancelled` arm in `Payments::void()`; void link shown whenever payment is not voided; `Invoices.paymentsLocked` reworded in sk/cs/pl/en ("new payments can no longer be added").
- WR-04 (9b3f1a6):
  - Migration `2026-11-10-000001_AddPaymentsCancelledGuard`: function `payment_cancelled_guard()` and BEFORE INSERT trigger `payments_not_cancelled` (with `down()`).
  - `PaymentService::CANCELLED_GUARD` and mapping of the trigger error to `PaymentRejected('cancelled')`.
  - `CreditNoteIssuer` takes `hashtext('payments')` xact lock before `credit_note`.
  - `InvoiceMailer::send()` holds `hashtext('payments')` session lock (after `invoice_sends_rk`) across status check and SMTP, released in nested finally.
- Todo moved to `.planning/todos/completed/` with `completed` and `resolved_by` (8bacda9).

## Tests

- `CreditNoteReceivablesTest::testExistingPaymentCanBeVoidedAfterStorno` (replaces old test), `CreditNoteDetailTest::testCancelledInvoiceDetail` updated.
- New `tests/integration/Receivables/PaymentCancelledGuardTest.php` (trigger for rw and owner, allowed without credit note, issuer waits for payments lock).
- Reminder lock test lives in `tests/integration/Delivery/ReminderMailerTest.php::testReminderWaitsForPaymentsLockAndReleasesLocks` (reused its fixtures).

## Deviations

- Migration must be run as owner (`composer test:migrate` and `php spark migrate -g owner --all`); plain `spark migrate` fails with "permission denied for schema public". Both dev and test DBs were migrated.
- Fixed a PHPStan impossibleType in the new void test by capturing the pre-void state in a variable.

## Verification

See final report for exact PHPUnit / PHPStan / php-cs-fixer results.
