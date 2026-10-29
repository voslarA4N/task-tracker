---
phase: 07-doru-enie-hrady-a-upomienky
fixed_at: 2026-10-29T19:23:03Z
review_path: .planning/phases/07-doru-enie-hrady-a-upomienky/07-REVIEW.md
iteration: 1
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
verification:
  phpunit_full: "OK (1582 tests, 23916 assertions), make test, 4:27"
  node_test: "58 pass, 0 fail (node --test tests/js/)"
  phpstan: "OK (both configs)"
  php_cs_fixer: "0 of 551 files to fix (composer cs dry run)"
---

# Phase 7: Code Review Fix Report

**Fixed at:** 2026-10-29T19:23:03Z
**Source review:** .planning/phases/07-doru-enie-hrady-a-upomienky/07-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 7 (CR-01, WR-01 to WR-06; IN-* left alone as instructed)
- Fixed: 7
- Skipped: 0

I checked each finding against the code before fixing it, and all 7 were accurate.

**Final verification (run after the php-cs-fixer pass):**
- `make test`: OK, 1582 tests and 23916 assertions.
- `node --test tests/js/`: 58 passed, 0 failed.
- `composer stan`: OK.
- `composer cs`: 0 files to fix.
- No triggers were disabled and no transactions were left open. The one cache entry written by a test is deleted in that test's `finally`.

## Fixed Issues

### CR-01: The entity edit form saves the default sender values, so "NULL = default" is lost

**Files modified:** `app/Controllers/Settings/Entities.php`, `app/Libraries/Master/EntityInput.php`, `app/Libraries/Master/EntityService.php`, `app/Views/settings/entities/_sender_fields.php`, `app/Views/settings/entities/edit.php`, `app/Language/{sk,en}/Entities.php`, `tests/feature/Master/EntitySenderFormTest.php`, `tests/unit/Delivery/SenderIdentityTest.php`
**Commit:** 65352e5
**Applied fix:**
- **Form values:** the form now shows the raw stored columns (NULL shows as an empty field). The resolved `SenderIdentity::defaults()` appear only as `placeholder`, plus a hint that an empty field means "default".
- **BCC:** a new checkbox, "Neposielať skrytú kópiu" (`mail_bcc_none`), separates "default BCC" (empty field, stored as NULL) from "no BCC" (stored as `[]`). Ticking the box while also entering addresses gives a 422 (`errorSenderBccConflict`).
- **Saving:** `EntityInput::senderForStorage()` converts the POST to columns. `EntityService::updateBase()` now accepts `mail_bcc = null` and writes SQL NULL, not the JSON `null`.
- **Hint text:** the BCC hint now says that an empty field means the default copy to the entity e-mail.
- **Tests:**
  - Load the edit page, post the parsed form back unchanged with only `name_internal` changed: all four sender columns stay NULL.
  - The same round trip still works when `email.fromEmail` is outside `allowedFromDomains`. This was the 422 blocker.
  - The no-BCC checkbox stores `[]`, then unticking it returns to NULL.
  - Checkbox plus addresses gives 422.
  - Unit tests for `senderForStorage` and the conflict check.

### WR-01: A paid invoice that was never sent cannot be marked as sent, and "Send" disappears

**Files modified:** `app/Libraries/Delivery/ManualSendService.php`, `app/Controllers/InvoiceDelivery.php`, `app/Views/invoices/show.php`, `tests/feature/Invoicing/MarkSentTest.php`, `tests/feature/Invoicing/PaymentsTest.php`, `tests/integration/Delivery/ManualSendServiceTest.php`
**Commit:** 35adf8b
**Applied fix:**
- Delivery decisions now use `invoice_status_v.sent_at` instead of `status`:
  - `markInTransaction` rejects `already_sent` only when `sent_at` is set.
  - `markable()` requires `sent_at IS NULL`.
  - In the detail view, `$canSend = !external && sent_at === null` and `$canResend = !external && sent_at !== null`.
- **Changed existing test:** `PaymentsTest::testActionsFollowStatus` asserted the old behaviour (a paid but unsent invoice offers `/resend`). It now asserts `/send` and no `/resend`.
- **New tests:** a paid-before-sent invoice shows Send and Mark sent, can be marked, and then shows Resend. The service accepts a partially paid, unsent invoice once, then rejects it with `already_sent`.

**Status:** fixed: requires human verification. This changes which actions appear when the invoice is paid but not sent.

### WR-02: Repeating a payment or mark-sent request returns 404 instead of the earlier result

**Files modified:** `app/Controllers/Payments.php`, `app/Controllers/InvoiceDelivery.php`, `app/Libraries/Receivables/PaymentService.php`, `app/Libraries/Delivery/ManualSendService.php`, `tests/feature/Invoicing/PaymentsTest.php`, `tests/feature/Invoicing/MarkSentTest.php`
**Commit:** 109a4b6
**Applied fix:**
- New public `PaymentService::replayFor(invoiceId, key)` and `ManualSendService::replayFor(invoiceId, key)`.
- They look up the request_key scoped to the same invoice, and the manual one also to `kind = 'manual'`. This also covers IN-01 for the controller path.
- Both POST handlers call this before the state gate and return the original success (flash plus HX-Location or redirect).
- **Tests:**
  - Repeating a full payment with the same key returns 200 with "Faktúra je uhradená." over htmx and a redirect without htmx, and writes only one row.
  - Repeating a mark-sent returns 200 and writes only one row. A new key on an already sent invoice is still 404.
  - The same key posted to a different invoice id is not replayed.

### WR-03: Two sends with the same request_key can both deliver the e-mail, and Retry after record_failed sends it again

**Files modified:** `app/Libraries/Delivery/InvoiceMailer.php`, `app/Controllers/InvoiceDelivery.php`, `tests/integration/Delivery/InvoiceMailerIdempotencyTest.php`
**Commit:** 79abd0d
**Applied fix:**
- **Lock:** `send()` takes a session lock, `pg_advisory_lock(hashtext('invoice_sends_rk'), hashtext(key))`, for the whole send. It checks the key again under the lock (`sendLocked`) and releases the lock in `finally`. If the release fails because the connection dropped, it is logged and does not replace the original exception, since the lock dies with the connection.
- **record_failed:** the key is stored in the CI4 cache (`invoice_send_unrecorded_<key>`, 7 days). A later send with the same key returns `record_failed` again without any SMTP call.
- **Panel:** after `record_failed` the panel no longer offers "Skúsiť znova" (`retry=false`).
- **Tests:**
  - With another connection holding the lock and `lock_timeout = 200ms`, a send times out on the lock before any e-mail is built. After the lock is released it sends normally and leaves no advisory lock behind.
  - After a failing `insertSend`, a retry with the same key gives `record_failed`, the e-mail factory was called only once, and no lock is left.
- **Not covered by a test:** the controller's `retry=false` change. The controller creates `new InvoiceMailer()` itself, so a feature test cannot inject a recording failure.

**Status:** fixed: requires human verification. This is concurrency logic. Note that the record_failed guard depends on the file cache; if the cache falls back to `dummy`, it does nothing.

### WR-04: The send subject is not checked for control characters

**Files modified:** `app/Libraries/Delivery/InvoiceMailer.php`, `app/Controllers/InvoiceDelivery.php`, `app/Language/{sk,en}/Deliveries.php`, `tests/integration/Delivery/InvoiceMailerTest.php`, `tests/feature/Invoicing/DeliverySendTest.php`
**Commit:** 152c647
**Applied fix:**
- `validate()` rejects `[\x00-\x1F\x7F]` in the subject with `invalid_subject`.
- The controller shows this as a field error on the subject (`Deliveries.errorSubjectChars`).
- **Tests:** `"A\r\nBcc: x@evil.example"`, a tab and DEL are rejected by the service. A feature POST returns 422 with `is-invalid` and nothing is sent.

### WR-05: The documented staging fromEmail does not match allowedFromDomains

**Files modified:** `docs/ops/server.md`, `app/Libraries/Delivery/InvoiceMailer.php`, `app/Libraries/StagingSeed/Modules/Deliveries.php`, `tests/integration/Delivery/InvoiceMailerTest.php`
**Commit:** e1e4b04
**Applied fix:** I chose to correct the documentation and keep the exact domain match, not to allow subdomains.
- **Why exact match:** an allowlist that also accepts subdomains would allow From addresses on hosts where SPF, DKIM and DMARC alignment are not set up. `SenderIdentityTest` already asserts that `x@sub.vostack.dev` is rejected on purpose.
- **Docs:** the `app.env` template in section 3 now uses `email.fromEmail = noreply@vostack.dev` and an explicit `email.allowedFromDomains = vostack.dev`, with a comment explaining why. Section 20 step 3 now tells the owner to check the staging `app.env` and `migrate.env` and explains the symptom.
- **Logging:** `InvoiceMailer` now logs `mail.sender_not_allowed` with the From address (control characters masked) and the allowlist whenever it rejects the sender.
- **Staging seed:** I did not remove the seed workaround. The seed runs during the staging deploy, so removing it would break staging deploys until `app.env` is corrected. Instead, the seed logs a `staging_seed.sender_not_allowed` warning when it has to widen the allowlist.
- No server, deploy or real `app.env` was touched.
- **Tests:** a log assertion for `mail.sender_not_allowed`, and a test that `noreply@work-tracker-stage.vostack.dev` is rejected under `vostack.dev`.
- **Owner action:** check `email.fromEmail` in the staging `/opt/work-tracker-staging/app.env`. If it is still `noreply@work-tracker-stage.vostack.dev`, sends on staging fail with `sender_invalid` until it is changed.

### WR-06: The payment date and the manual sent date have no lower bound

**Files modified:** `app/Libraries/Receivables/PaymentService.php`, `app/Libraries/Delivery/ManualSendService.php`, `app/Controllers/Payments.php`, `app/Controllers/InvoiceDelivery.php`, `app/Views/invoices/_payment_form.php`, `app/Views/invoices/_mark_sent_form.php`, `app/Language/{sk,en}/{Payments,Deliveries}.php`, tests (`PaymentServiceTest`, `ManualSendServiceTest`, `PaymentsTest`, `MarkSentTest`)
**Commit:** 1b1619b
**Applied fix:**
- Both services load `invoices.issue_date` inside the locked transaction and reject an earlier date with `before_issue`. The form shows it as a field error (`Payments.errorBeforeIssue`, `Deliveries.errorSentBeforeIssue`).
- Both date inputs now have `min="<issue_date>"`.
- **Changed existing test:** `ManualSendServiceTest::testPastDateUsesNoonBratislava` used 2026-10-20 on an invoice issued 2026-10-27. It now moves the clock to 2026-10-30 and marks 2026-10-28.
- php-cs-fixer realigned the `=>` column in `Deliveries.php` (sk and en). That part of the diff is whitespace only.

**Status:** fixed: requires human verification. The rule is that the issue date itself is allowed and anything earlier is rejected.

## Notes

- Commits 65352e5 to 1b1619b are on `develop` and have not been pushed. Each one ends with the Co-Authored-By and Claude-Session lines.
- On request, I worked directly on the main tree with no worktree or sentinel.
- To run the tests I brought the Docker stack up with `make up`. `db`, `php` and `mailpit` are running; `nginx` did not start because host port 80 was already taken. The tests do not need nginx.

---

_Fixed: 2026-10-29T19:23:03Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
