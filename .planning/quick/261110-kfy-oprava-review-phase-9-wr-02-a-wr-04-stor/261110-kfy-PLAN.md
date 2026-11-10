---
phase: quick-261110-kfy
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Libraries/Receivables/PaymentService.php
  - app/Controllers/Payments.php
  - app/Views/invoices/_payments.php
  - app/Language/sk/Invoices.php
  - app/Language/cs/Invoices.php
  - app/Language/pl/Invoices.php
  - app/Language/en/Invoices.php
  - app/Database/Migrations/2026-11-10-000001_AddPaymentsCancelledGuard.php
  - app/Libraries/Invoicing/CreditNoteIssuer.php
  - app/Libraries/Delivery/InvoiceMailer.php
  - tests/integration/Receivables/CreditNoteReceivablesTest.php
  - tests/feature/Invoicing/CreditNoteDetailTest.php
  - tests/integration/Receivables/PaymentCancelledGuardTest.php
  - .planning/todos/pending/2026-11-02-phase9-review-wr02-wr04.md
  - .planning/todos/completed/2026-11-02-phase9-review-wr02-wr04.md
autonomous: true
requirements: [09-REVIEW-WR-02, 09-REVIEW-WR-04]

must_haves:
  truths:
    - "A payment recorded before storno can be voided after the invoice is cancelled; paid_amount drops, status stays cancelled (WR-02, D-17)"
    - "Cancelled invoice detail shows the void link for non-voided payments but never the record-payment CTA"
    - "No payment can be inserted for an invoice that has a credit note, not even by a direct INSERT (DB trigger, D-15)"
    - "CreditNoteIssuer serializes with PaymentService on the same advisory key hashtext('payments') + invoice id, taken before credit_note"
    - "InvoiceMailer checks invoice status (reminder / cancelled) while holding the payments lock for that invoice, so storno cannot slip in between check and SMTP"
  artifacts:
    - path: "app/Database/Migrations/2026-11-10-000001_AddPaymentsCancelledGuard.php"
      provides: "BEFORE INSERT ON payments trigger payments_not_cancelled + function payment_cancelled_guard(), with down()"
      contains: "payment_cancelled_guard"
    - path: "tests/integration/Receivables/PaymentCancelledGuardTest.php"
      provides: "Trigger test + lock tests for CreditNoteIssuer and InvoiceMailer"
  key_links:
    - from: "app/Libraries/Invoicing/CreditNoteIssuer.php"
      to: "pg_advisory_xact_lock(hashtext('payments'), id)"
      via: "issueInTransaction() step 2, before the credit_note lock"
      pattern: "hashtext\\('payments'\\)"
    - from: "app/Libraries/Delivery/InvoiceMailer.php"
      to: "pg_advisory_lock(hashtext('payments'), invoiceId)"
      via: "send() wraps sendLocked() after the invoice_sends_rk lock"
      pattern: "hashtext\\('payments'\\)"
    - from: "app/Libraries/Receivables/PaymentService.php"
      to: "PaymentRejected('cancelled')"
      via: "catch DatabaseException carrying the trigger message token"
      pattern: "CANCELLED_GUARD"
---

<objective>
Fix Phase 9 review findings WR-02 and WR-04 (`.planning/phases/09-dobropisy/09-REVIEW.md`), then close the pending todo.

- WR-02: per D-17 only NEW payments are forbidden after storno. A payment recorded before storno must still be voidable (void is insert-only into `payment_voids`). `readOnly` hides only the "record payment" CTA.
- WR-04: per D-15 a cancelled invoice never accepts a payment or reminder. Today `PaymentService` locks `hashtext('payments')` and `CreditNoteIssuer` locks `hashtext('credit_note')`, so they race; the mailer checks status without any lock; no DB trigger backs the rule.

Credit notes are rows in `invoices` with `type = 'credit_note'` and `corrected_invoice_id` = original invoice id (verified in `2026-10-31-000001_AddCreditNotes.php`, partial unique index `invoices_one_credit_note_uq`). There is no separate credit-note table.

Money stays string + bcmath (`Money`), never float.
</objective>

<execution_context>
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/workflows/execute-plan.md
@/mnt/c/personal-projects/task-tracker/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md
@.planning/todos/pending/2026-11-02-phase9-review-wr02-wr04.md
@.planning/phases/09-dobropisy/09-REVIEW.md (sections WR-02, WR-04 only)
@app/Libraries/Receivables/PaymentService.php
@app/Libraries/Invoicing/CreditNoteIssuer.php (lines 60-140)
@app/Libraries/Delivery/InvoiceMailer.php (send() lines 105-135, loadInvoice() lines 364-400)
@app/Views/invoices/_payments.php
@app/Database/Migrations/2026-11-04-000002_CreateInvoiceRegenWindows.php (migration style)
@tests/integration/Receivables/CreditNoteReceivablesTest.php
@tests/integration/Delivery/InvoiceMailerIdempotencyTest.php (lines 125-150: lock_timeout test pattern)

Test run conventions (from quick 261110-g7j): everything runs in Docker from /home/voslar/projects/task-tracker:
- single file: `docker compose exec -T php vendor/bin/phpunit <path>`
- static checks: `docker compose exec -T php composer stan` and `docker compose exec -T php composer cs`
- migrations: `docker compose exec -T php php spark migrate` (test DB is migrated by the test bootstrap; check PgTestCase if not)
- The test DB is shared: before any phpunit run confirm nothing else runs (`docker compose exec -T php sh -c 'ps aux | grep [p]hpunit'` prints nothing). Never run two phpunit processes at once.
- Custom PHPStan rules: no float, no time().
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: WR-02 allow voiding payments on a cancelled invoice</name>
  <files>app/Libraries/Receivables/PaymentService.php, app/Controllers/Payments.php, app/Views/invoices/_payments.php, app/Language/sk/Invoices.php, app/Language/cs/Invoices.php, app/Language/pl/Invoices.php, app/Language/en/Invoices.php, tests/integration/Receivables/CreditNoteReceivablesTest.php, tests/feature/Invoicing/CreditNoteDetailTest.php</files>
  <behavior>
    - CreditNoteReceivablesTest: replace `testExistingPaymentStaysButCannotBeVoided` with `testExistingPaymentCanBeVoidedAfterStorno`: record 100.00, issue credit note, `$svc->void($paymentId, 'Omyl', null)` returns the invoice id; `InvoiceStatusQueries::payments($id)[0]['voided']` is true; `forInvoice($id, '2026-10-31')` gives `paid_amount` '0.00', `status` 'cancelled', `balance` '0.00'. A second void of the same payment still yields `already_voided`.
    - CreditNoteReceivablesTest `testPaymentsRejectedOnCancelledAndCreditNote` stays green (record on cancelled still `cancelled`).
    - CreditNoteDetailTest `testCancelledInvoiceDetail`: change `assertStringNotContainsString('/void"', ...)` to assert the void link for the inserted payment IS present (`/payments/{paymentId}/void"`; get the id via `RETURNING id` on the existing direct INSERT), keep the assertion that `href="/invoices/{orig}/payments"` (record CTA) is absent, and update the locked-text assertion to the new sk text.
  </behavior>
  <action>
    Per WR-02 and D-17 (only new payments are forbidden after storno; existing payments stay and void is itself insert-only):
    1. `PaymentService::void()`: delete the block that reads `invoice_status_v.status` and throws `PaymentRejected('cancelled')` (current lines 142-146). Keep `lock($invoiceId)`, the `already_voided` check, the INSERT into `payment_voids`, and the audit record unchanged. Update the docblock of `void()` with one line: allowed also on a cancelled invoice (WR-02, D-17). Do not touch `record()` or `impact()`.
    2. `app/Controllers/Payments.php::void()`: remove the now unreachable `'cancelled' => ...` arm from the `match` (the `default` arm stays). Leave `Payments.errorCancelled` language key in place because `rejected()` still uses it for record attempts.
    3. `app/Views/invoices/_payments.php`: the void link condition becomes only `! $p['voided']` (drop `&& empty($readOnly)`). Keep the `readOnly` paragraph at the bottom. `show.php` already passes `canPay` (false when cancelled) and `readOnly => $cancelled`; leave `show.php` as is unless the record CTA turns out to depend on `readOnly` (it does not today: it uses `canPay`).
    4. Language key `Invoices.paymentsLocked` in sk/cs/pl/en: reword so it no longer says payments cannot be changed, only that new payments cannot be added. sk: "Faktúra je stornovaná. Nové úhrady sa už nedajú pridať." cs: "Faktura je stornována. Nové úhrady již nelze přidat." pl: "Faktura jest anulowana. Nie można już dodawać nowych płatności." en: "This invoice is cancelled. New payments can no longer be added."
    5. Update the two tests as described in behavior. Write the test changes first, run them (expect failure), then implement.
  </action>
  <verify>
    <automated>cd /home/voslar/projects/task-tracker && docker compose exec -T php vendor/bin/phpunit tests/integration/Receivables/CreditNoteReceivablesTest.php && docker compose exec -T php vendor/bin/phpunit tests/feature/Invoicing/CreditNoteDetailTest.php && docker compose exec -T php vendor/bin/phpunit tests/integration/Receivables/PaymentVoidTest.php</automated>
  </verify>
  <done>Void of a pre-storno payment succeeds on a cancelled invoice and lowers paid_amount to 0.00 while status stays cancelled; detail page of a cancelled invoice shows the void link and no record-payment link; all three test files pass.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: WR-04 payments trigger, shared payments lock in CreditNoteIssuer and InvoiceMailer</name>
  <files>app/Database/Migrations/2026-11-10-000001_AddPaymentsCancelledGuard.php, app/Libraries/Receivables/PaymentService.php, app/Libraries/Invoicing/CreditNoteIssuer.php, app/Libraries/Delivery/InvoiceMailer.php, tests/integration/Receivables/PaymentCancelledGuardTest.php</files>
  <behavior>
    New `tests/integration/Receivables/PaymentCancelledGuardTest.php` (extends `Tests\Support\PgTestCase`, `#[Group('db')]` or whatever group CreditNoteReceivablesTest uses, same traits CreditNoteFixtures/InvoiceFixtures/IssueFixtures/MasterFixtures, same FrozenClock setup as CreditNoteReceivablesTest):
    - testDirectInsertRejectedAfterCreditNote: issue invoice, issue credit note, then `rw()` INSERT INTO payments (invoice_id, paid_on, amount, created_at) throws `DatabaseException` whose message contains `PaymentService::CANCELLED_GUARD`; repeat with `owner()` (owner is not exempt). Count of payments for the invoice stays 0.
    - testDirectInsertAllowedWithoutCreditNote: same INSERT on an issued invoice without credit note succeeds.
    - testCreditNoteIssuerWaitsForPaymentsLock: `owner()` takes `SELECT pg_advisory_lock(hashtext('payments'), :id:)` for the original invoice; `rw()` `SET lock_timeout = '200ms'`; issuing the credit note through the rw connection throws `DatabaseException` containing 'lock timeout' (catch it from `CreditNoteIssuer::issue()`); no `invoices` row with `type = 'credit_note' AND corrected_invoice_id = id` exists. In `finally`: `RESET lock_timeout` on rw and `pg_advisory_unlock` on owner. Follow the pattern in InvoiceMailerIdempotencyTest lines 128-150; reuse the credit note issuing helper from CreditNoteFixtures but make sure it uses the rw connection (inspect the helper; if it constructs `CreditNoteIssuer` with another connection, build the command and issuer locally with `$this->rw()`).
    - testReminderWaitsForPaymentsLock: sent invoice with balance (reuse fixtures/mailer setup from tests/integration/Delivery/ReminderMailerTest.php, copy the minimal fake mailer wiring); owner holds the payments lock for that invoice; rw lock_timeout 200ms; `send()` of a reminder throws `DatabaseException` 'lock timeout'; fake SMTP was never called and no `invoice_sends` row exists; afterwards (locks released) the `invoice_sends_rk` lock is not left held: `SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()` on rw returns 0. If wiring the mailer here is heavy, put this test into ReminderMailerTest.php instead (same assertions) and list that file in the SUMMARY.
  </behavior>
  <action>
    Per WR-04 and D-15 (cancelled invoice never accepts a payment or reminder):
    1. Migration `app/Database/Migrations/2026-11-10-000001_AddPaymentsCancelledGuard.php`, class `AddPaymentsCancelledGuard`, style as `CreateInvoiceRegenWindows` (final class, declare strict_types, short Slovak docblock without diacritics referencing WR-04 / D-15). up(): create plpgsql function `payment_cancelled_guard()` RETURNS trigger: IF EXISTS (SELECT 1 FROM invoices WHERE corrected_invoice_id = NEW.invoice_id AND type = 'credit_note') THEN RAISE EXCEPTION 'payment target % is cancelled by credit note', NEW.invoice_id USING ERRCODE = 'integrity_constraint_violation', CONSTRAINT = 'payments_not_cancelled'; END IF; RETURN NEW. The lookup uses the existing partial unique index `invoices_one_credit_note_uq`. Then `CREATE TRIGGER payments_not_cancelled BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION payment_cancelled_guard()`, plus `COMMENT ON FUNCTION` in English like the other migrations. Function is SECURITY INVOKER (default); app_rw already has SELECT on invoices, verify by the rw test. down(): `DROP TRIGGER IF EXISTS payments_not_cancelled ON payments` then `DROP FUNCTION IF EXISTS payment_cancelled_guard()`. Name must not end in `_no_truncate` (MasterFixtures disables those during cleanup). Run `docker compose exec -T php php spark migrate`, then `php spark migrate:rollback` is NOT required, but do verify down() SQL by reading it once more.
    2. `PaymentService`: add `public const CANCELLED_GUARD = 'is cancelled by credit note';`. In `record()`'s `catch (DatabaseException $e)` add, before `throw $e`, a loop over `$e` and `getPrevious()` (same shape as `InvoiceIssuer::isUniqueViolation`) that throws `new PaymentRejected('cancelled')` when a message contains `self::CANCELLED_GUARD`. Put it in a small private static helper `isCancelledGuard(DatabaseException $e): bool`. This is defense in depth; the normal path is still the status check under the lock.
    3. `CreditNoteIssuer::issueInTransaction()` step 2: before the existing `credit_note` lock, add `SELECT pg_advisory_xact_lock(hashtext('payments'), :id:)` with `$cmd->originalId`. Comment (Slovak, no diacritics like the surrounding code): fixed order payments -> credit_note, rovnaky kluc ako PaymentService::lock(), PaymentService nikdy neberie credit_note (WR-04). Do not change anything else in the issuer.
    4. `InvoiceMailer::send()`: after taking the `invoice_sends_rk` session lock and inside its try, take a session lock `SELECT pg_advisory_lock(hashtext('payments'), :id:)` with `$cmd->invoiceId`, then call `sendLocked()` inside a nested try/finally that releases it with `pg_advisory_unlock(hashtext('payments'), :id:)` (same Throwable-swallowing log pattern as the existing rk unlock, message `mail.unlock_failed`). If acquiring the payments lock throws (lock timeout), only the outer finally runs and releases rk. Lock order mailer: invoice_sends_rk -> payments; issuer: payments -> credit_note; PaymentService: payments only; no cycle. Add a comment: stav faktury (upomienka, storno) sa kontroluje a e-mail odchadza pod zamkom uhrad, takze dobropis nemoze vzniknut medzi kontrolou a SMTP (WR-04, D-15); platba na tej istej fakture pocka na koniec odoslania. The status checks themselves in `loadInvoice()` stay unchanged; they now run under the lock because `draft()` is called from `sendLocked()`.
    5. Write the new test file first (expect failures for trigger and issuer/mailer lock tests), then implement 1-4 until green.
  </action>
  <verify>
    <automated>cd /home/voslar/projects/task-tracker && docker compose exec -T php php spark migrate && docker compose exec -T php vendor/bin/phpunit tests/integration/Receivables/PaymentCancelledGuardTest.php && docker compose exec -T php vendor/bin/phpunit tests/integration/Invoicing/CreditNoteConcurrencyTest.php && docker compose exec -T php vendor/bin/phpunit tests/integration/Delivery/ReminderMailerTest.php && docker compose exec -T php vendor/bin/phpunit tests/integration/Delivery/InvoiceMailerIdempotencyTest.php</automated>
  </verify>
  <done>Direct INSERT into payments for an invoice with a credit note fails on the trigger for rw and owner; CreditNoteIssuer blocks on hashtext('payments') before credit_note; InvoiceMailer holds the payments lock across status check and SMTP and releases both locks on every path; existing concurrency, reminder and idempotency tests pass.</done>
</task>

<task type="auto">
  <name>Task 3: Full checks and close the todo</name>
  <files>.planning/todos/pending/2026-11-02-phase9-review-wr02-wr04.md, .planning/todos/completed/2026-11-02-phase9-review-wr02-wr04.md</files>
  <action>
    1. Run `docker compose exec -T php composer stan` and `docker compose exec -T php composer cs`. Both must be clean; fix findings in touched files only.
    2. Confirm no phpunit is running (`docker compose exec -T php sh -c 'ps aux | grep [p]hpunit'` prints nothing), then run the FULL suite exactly once: `docker compose exec -T php vendor/bin/phpunit`. Likely collateral failures: tests that insert payments directly on an invoice after issuing its credit note (move the insert before the credit note, which is what really happens), and any test asserting the old `paymentsLocked` / void-link behavior. Fix the cause, rerun only affected files, then one more full run.
    3. Close the todo: `.planning/todos/` currently has only `pending/`. Create `.planning/todos/completed/` and `git mv` the file there. In its frontmatter add `completed: 2026-11-10` and `resolved_by: quick-261110-kfy` (keep the rest unchanged). If `pending/` becomes empty it may disappear from git; do not add placeholder files.
  </action>
  <verify>
    <automated>cd /home/voslar/projects/task-tracker && docker compose exec -T php composer stan && docker compose exec -T php composer cs && docker compose exec -T php vendor/bin/phpunit && test -f .planning/todos/completed/2026-11-02-phase9-review-wr02-wr04.md && test ! -f .planning/todos/pending/2026-11-02-phase9-review-wr02-wr04.md</automated>
  </verify>
  <done>PHPStan, php-cs-fixer and the full PHPUnit suite are green; the todo lives in .planning/todos/completed/ with completed/resolved_by fields.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| app -> PostgreSQL | concurrent requests (payment, credit note, reminder) on the same invoice |
| owner/user -> void endpoint | authenticated POST /payments/{id}/void now reachable for cancelled invoices |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-kfy-01 | Tampering | payments INSERT on cancelled invoice (race or manual SQL) | mitigate | BEFORE INSERT trigger `payments_not_cancelled` + shared advisory key `hashtext('payments')` in PaymentService, CreditNoteIssuer, InvoiceMailer |
| T-kfy-02 | Denial of Service | lock ordering deadlock | mitigate | fixed order: mailer rk -> payments; issuer payments -> credit_note; PaymentService payments only; mailer releases both locks in finally |
| T-kfy-03 | Repudiation | void on cancelled invoice | accept | void stays insert-only with mandatory reason and audit record `payment_void`; invoice status and documents unchanged |
| T-kfy-04 | Tampering | issued documents | accept | no change to invoices, lines or PDFs; only payment_voids rows are added |
</threat_model>

<verification>
- `void()` works on a cancelled invoice and paid_amount falls to 0.00 (bcmath strings, no float).
- Cancelled invoice detail: void link present, record CTA absent.
- `SELECT tgname FROM pg_trigger WHERE tgname = 'payments_not_cancelled'` returns one row after migrate.
- `grep -n "hashtext('payments')" app/Libraries/Invoicing/CreditNoteIssuer.php app/Libraries/Delivery/InvoiceMailer.php` shows the lock in both, issuer line before the credit_note lock.
- stan, cs, full phpunit green.
</verification>

<success_criteria>
WR-02 and WR-04 from 09-REVIEW.md are fixed with tests, D-15 and D-17 hold at app and DB level, CI checks pass, todo moved to completed.
</success_criteria>

<output>
Create `.planning/quick/261110-kfy-oprava-review-phase-9-wr-02-a-wr-04-stor/261110-kfy-SUMMARY.md` when done
</output>
