---
phase: 07-doru-enie-hrady-a-upomienky
reviewed: 2026-10-29T18:24:55Z
depth: standard
files_reviewed: 136
files_reviewed_list:
  - .env.example
  - .github/workflows/ci.yml
  - app/Config/Email.php
  - app/Config/Routes/invoices.php
  - app/Config/Routes/settings.php
  - app/Controllers/Home.php
  - app/Controllers/InvoiceDelivery.php
  - app/Controllers/Invoices.php
  - app/Controllers/Payments.php
  - app/Controllers/Settings/EmailTemplates.php
  - app/Controllers/Settings/Entities.php
  - app/Database/Migrations/2026-10-29-000001_CreateEmailTemplates.php
  - app/Database/Migrations/2026-10-29-000002_CreateInvoiceSends.php
  - app/Database/Migrations/2026-10-29-000004_AddEntitiesMailSender.php
  - app/Database/Migrations/2026-10-29-000005_CreatePayments.php
  - app/Database/Migrations/2026-10-29-000006_ExtendInvoiceStatusViewPayments.php
  - app/Language/en/Dashboard.php
  - app/Language/en/Deliveries.php
  - app/Language/en/EmailTemplates.php
  - app/Language/en/Entities.php
  - app/Language/en/Invoices.php
  - app/Language/en/Payments.php
  - app/Language/sk/Dashboard.php
  - app/Language/sk/Deliveries.php
  - app/Language/sk/EmailTemplates.php
  - app/Language/sk/Entities.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Payments.php
  - app/Libraries/Delivery/EmailTemplateRejected.php
  - app/Libraries/Delivery/EmailTemplateService.php
  - app/Libraries/Delivery/InvoiceMailer.php
  - app/Libraries/Delivery/ManualSendService.php
  - app/Libraries/Delivery/SendCommand.php
  - app/Libraries/Delivery/SendRejected.php
  - app/Libraries/Delivery/SendResult.php
  - app/Libraries/Delivery/SenderIdentity.php
  - app/Libraries/Delivery/SenderRejected.php
  - app/Libraries/Delivery/TemplateContext.php
  - app/Libraries/Delivery/TemplateRenderer.php
  - app/Libraries/Invoicing/DocumentFilename.php
  - app/Libraries/Invoicing/DocumentStore.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Mail/AppEmail.php
  - app/Libraries/Mail/AppMailer.php
  - app/Libraries/Mail/OutgoingMessage.php
  - app/Libraries/Mail/SendOutcome.php
  - app/Libraries/Master/EntityInput.php
  - app/Libraries/Master/EntityService.php
  - app/Libraries/Platform/Uuid.php
  - app/Libraries/Receivables/InvoiceStatusQueries.php
  - app/Libraries/Receivables/PaymentRejected.php
  - app/Libraries/Receivables/PaymentService.php
  - app/Libraries/Receivables/RecordedPayment.php
  - app/Libraries/StagingSeed/HistoryPlan.php
  - app/Libraries/StagingSeed/HistoryPlanner.php
  - app/Libraries/StagingSeed/Modules/Deliveries.php
  - app/Libraries/StagingSeed/Modules/Invoices.php
  - app/Libraries/StagingSeed/StagingSeeder.php
  - app/Views/home/_body.php
  - app/Views/home/_overdue.php
  - app/Views/home/index.php
  - app/Views/invoices/_delivery_form.php
  - app/Views/invoices/_delivery_sheet.php
  - app/Views/invoices/_history.php
  - app/Views/invoices/_list.php
  - app/Views/invoices/_mark_sent_form.php
  - app/Views/invoices/_mark_sent_sheet.php
  - app/Views/invoices/_payment_form.php
  - app/Views/invoices/_payment_impact.php
  - app/Views/invoices/_payment_sheet.php
  - app/Views/invoices/_payment_void_form.php
  - app/Views/invoices/_payment_void_sheet.php
  - app/Views/invoices/_payments.php
  - app/Views/invoices/_send_void_form.php
  - app/Views/invoices/_send_void_sheet.php
  - app/Views/invoices/delivery_page.php
  - app/Views/invoices/index.php
  - app/Views/invoices/mark_sent_page.php
  - app/Views/invoices/payment_page.php
  - app/Views/invoices/show.php
  - app/Views/layouts/app.php
  - app/Views/more/index.php
  - app/Views/settings/email_templates/_preview.php
  - app/Views/settings/email_templates/edit.php
  - app/Views/settings/email_templates/index.php
  - app/Views/settings/entities/_sender_fields.php
  - app/Views/settings/entities/edit.php
  - compose.server.mail.yaml
  - docs/ops/mail.md
  - docs/ops/server.md
  - public/assets/css/app.css
  - public/assets/js/app.js
  - scripts/deploy.sh
  - tests/_support/CapturingEmail.php
  - tests/_support/DeliveryFixtures.php
  - tests/_support/InvoiceFixtures.php
  - tests/_support/MasterFixtures.php
  - tests/feature/Invoicing/DeliveryResendTest.php
  - tests/feature/Invoicing/DeliverySendTest.php
  - tests/feature/Invoicing/InvoicePdfEndpointTest.php
  - tests/feature/Invoicing/InvoicesListStatusTest.php
  - tests/feature/Invoicing/MarkSentTest.php
  - tests/feature/Invoicing/PaymentsTest.php
  - tests/feature/Invoicing/ReminderTest.php
  - tests/feature/LayoutTest.php
  - tests/feature/Master/EntitySenderFormTest.php
  - tests/feature/Settings/EmailTemplatesControllerTest.php
  - tests/feature/Work/OverdueDashboardTest.php
  - tests/integration/Delivery/DeliveryRolesTest.php
  - tests/integration/Delivery/EmailTemplateServiceTest.php
  - tests/integration/Delivery/EmailTemplatesSeedTest.php
  - tests/integration/Delivery/EntitySenderTest.php
  - tests/integration/Delivery/InvoiceMailerFailureTest.php
  - tests/integration/Delivery/InvoiceMailerIdempotencyTest.php
  - tests/integration/Delivery/InvoiceMailerTest.php
  - tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
  - tests/integration/Delivery/ManualSendServiceTest.php
  - tests/integration/Delivery/ReminderMailerTest.php
  - tests/integration/Delivery/ResendTest.php
  - tests/integration/Invoicing/InvoiceQueriesTest.php
  - tests/integration/Mail/AppEmailTest.php
  - tests/integration/Mail/AppMailerMessageTest.php
  - tests/integration/Mail/MailpitDeliveryTest.php
  - tests/integration/Receivables/InvoiceStatusViewTest.php
  - tests/integration/Receivables/OverdueQueriesTest.php
  - tests/integration/Receivables/PaymentRolesTest.php
  - tests/integration/Receivables/PaymentServiceTest.php
  - tests/integration/Receivables/PaymentVoidTest.php
  - tests/integration/StagingSeed/StagingSeederTest.php
  - tests/js/dl-recipients.test.js
  - tests/js/et-vars.test.js
  - tests/unit/Delivery/SenderIdentityTest.php
  - tests/unit/Delivery/TemplateRendererTest.php
  - tests/unit/Invoicing/DocumentFilenameTest.php
  - tests/unit/Scripts/DeployGuardTest.php
  - tests/unit/StagingSeed/HistoryPlannerTest.php
findings:
  critical: 1
  warning: 6
  info: 5
  total: 12
status: issues_found
---

# Phase 7: Code Review Report

**Reviewed:** 2026-10-29T18:24:55Z
**Depth:** standard
**Files Reviewed:** 136
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I reviewed e-mail delivery (InvoiceMailer, AppMailer/AppEmail, SenderIdentity, templates), manual send marks and their voids, payments and their voids, invoice_status_v v2, the overdue dashboard, reminders, the entity sender card, deploy/compose changes and the staging seed. The core invariants hold:
- Money is handled as strings through Money/bcmath.
- invoice_sends, invoice_send_voids, payments and payment_voids are insert-only, with a grant check plus immutable_guard and truncate_guard.
- Payments are serialized by an advisory lock, and the request_key replay is checked again inside the lock.
- Attachments are the stored bytes.
- The views escape their output and contain no inline script or style.
- CSRF is enforced, including on htmx calls through the `hx-headers` setting on `<body>`.
- `return_to` only accepts a fixed set of values.

Subject and recipient headers reach the wire without CR/LF. Part of that protection comes from CI4 `setHeader`/`prepQEncoding`, which strip CR/LF; the application's own validation does not.

The main defects:
- The entity edit form saves the computed default sender values into the database, which defeats the "NULL = default" design (D-27). It can also block editing an entity's base data.
- invoice_status_v puts delivery state and payment state into one `status` column. Because of that, an invoice that was paid but never sent can never be marked as sent, and the "Send" button disappears.
- Repeating a request with the same request_key after a payment or mark-sent returns 404 instead of the earlier result.
- The mail send has an idempotency gap between the request_key lookup and the SMTP call.

## Critical Issues

### CR-01: The entity edit form saves the default sender values, so the "NULL = default" setting is lost (D-27)

**File:** `app/Controllers/Settings/Entities.php:177`, `app/Controllers/Settings/Entities.php:295`, `app/Controllers/Settings/Entities.php:312-322`, `app/Views/settings/entities/_sender_fields.php:18-46`, `app/Libraries/Master/EntityService.php` (updateBase, `$withSender` branch)
**Issue:** `senderValues()` fills the form with the output of `SenderIdentity::resolve()`. That output is already resolved: it uses `config('Email')->fromEmail`, the current version's `trade_name`, and the entity e-mail for Reply-To and BCC. These values are rendered as the `value` of the input fields. The form always posts all four sender fields, so `senderFromPost()` never returns `[]`. The next save of anything on the edit page (for example a `name_internal` change) therefore writes the following into `entities`:
- `mail_from_email = <config fromEmail>`
- `mail_from_name = <trade_name>`
- `mail_reply_to = <entity email>`
- `mail_bcc = ["<entity email>"]`

The columns are documented as NULL meaning "use the default" (migration comments: "NULL = config fromEmail", "NULL = trade name", "NULL = entity e-mail"). After that first save, later changes stop propagating to outgoing e-mails:
- a new entity version with a new trade name or e-mail
- a change of `email.fromEmail`

The BCC archive keeps going to the old address without any warning. The user also cannot get back to "default BCC": clearing the field stores `[]`, which means "no BCC".

There is a second effect. `validateSender()` checks the prefilled `fromEmail` against `allowedFromDomains`, and the domain must match exactly. Where `email.fromEmail` is not in an allowed domain (see WR-05), every save of the entity form fails with 422. The user can no longer change the internal name or the active period without first editing a sender field they never touched.

EntitySenderFormTest does not cover this path: it always posts explicit values and never posts back the prefilled defaults.
**Fix:** Keep the stored raw values separate from the resolved defaults. Show the defaults only as placeholders or hints.
```php
private function senderValues(int $id): array
{
    $row = db_connect()->query('SELECT mail_from_name, mail_from_email, mail_reply_to, mail_bcc FROM entities WHERE id = :id:', ['id' => $id])->getRowArray() ?? [];
    $bcc = $row['mail_bcc'] === null ? null : json_decode((string) $row['mail_bcc'], true);
    return [
        'mail_from_name'  => (string) ($row['mail_from_name'] ?? ''),
        'mail_from_email' => (string) ($row['mail_from_email'] ?? ''),
        'mail_reply_to'   => (string) ($row['mail_reply_to'] ?? ''),
        'mail_bcc'        => is_array($bcc) ? implode("\n", $bcc) : '',
        // plus a separate 'mail_bcc_default' => true/false flag (checkbox "use default BCC")
    ];
}
```
Render `SenderIdentity::defaults()` as `placeholder=""` or as `form-text`. To tell "default BCC" (NULL) apart from "no BCC" (`[]`), add an explicit checkbox. Add a test: open the edit page, post it back unchanged, and assert that all four columns are still NULL.

## Warnings

### WR-01: An invoice that was paid before it was sent cannot be marked as sent, and "Send" disappears

**File:** `app/Libraries/Delivery/ManualSendService.php:178-180`, `app/Controllers/InvoiceDelivery.php:138`, `app/Views/invoices/show.php:49-50`, `app/Database/Migrations/2026-10-29-000006_ExtendInvoiceStatusViewPayments.php:318-322`
**Issue:** In invoice_status_v v2, `paid` and `partially_paid` take priority over `sent`. The `status` column is therefore no longer a delivery state. Even so:
- `ManualSendService::markInTransaction` rejects with `already_sent` whenever `status !== 'issued'`.
- `InvoiceDelivery::markable()` returns 404 in the same case.
- `show.php` shows `$canSend` and the mark-sent action only when `$status === 'issued'`.

Common case: the client pays a proforma or pays straight from the PDF before the e-mail goes out, or the user records the payment first. After that:
- the invoice can never get a manual sent mark, and the server reports "already sent", which is wrong;
- the primary Send action is hidden, so the only remaining way to deliver it is "Resend", which drops CC (`draft()` sets `cc=[]` for `resend`).

Delivery history and the "Sent" date in the status summary then stay empty for good.
**Fix:** Base delivery decisions on `sent_at`, not on `status`:
```php
$row = $this->db->query('SELECT origin, type, sent_at FROM invoice_status_v WHERE id = :id:', ...)->getRowArray();
if ($row['sent_at'] !== null) { throw new SendRejected('already_sent'); }
```
Make the same change in `markable()` (`v.sent_at IS NULL`) and in `show.php`: `$canSend = ! $external && $invoice['sent_at'] === null;` and offer resend only when `sent_at !== null`.

### WR-02: Repeating a payment or mark-sent request returns 404 instead of the earlier result

**File:** `app/Controllers/Payments.php:436` with `:521-524`, `app/Controllers/InvoiceDelivery.php:75` with `:138`
**Issue:** `PaymentService::record()` and `ManualSendService::markSent()` replay correctly by `request_key`. But both controllers first run a state gate:
- `context()` requires `status IN ('issued','sent','partially_paid')`;
- `markable()` requires `status = 'issued'`.

After the first request succeeds (a full payment makes the invoice `paid`, a mark-sent makes it `sent`), a repeated POST with the same `request_key` gets 404 "Invoice not found" and never reaches the replay. On an iPhone with a weak signal this is a realistic case: the response is lost and the user taps again. In the no-JS form, `data-submit-once` does not apply. The data stays correct, but the user is told the invoice does not exist. This breaks the idempotency contract at the HTTP layer, and no test covers it (the PaymentsTest and MarkSentTest replays run before the state changes).
**Fix:** In `record()` and `markSent()`, check for a replay before the state gate, for example:
```php
$replay = (new PaymentService())->replay($values['request_key']); // expose replayOf as public
if ($replay !== null) { return $this->done(lang('Payments.recorded', [...]), $values['return_to']); }
$ctx = $this->context($id);
```
Or loosen the gate in POST handlers to "invoice exists and is issued/type invoice" and let the service decide.

### WR-03: Two sends with the same request_key can each deliver the e-mail; retrying after `record_failed` sends it again

**File:** `app/Libraries/Delivery/InvoiceMailer.php:113-117`, `:163`, `:193-222`; `app/Controllers/InvoiceDelivery.php:357-367`
**Issue:** The `request_key` lookup runs before SMTP, with no lock. Two concurrent POSTs with the same key can both pass `findByRequestKey`, and both send to the client over SMTP. This happens with a no-JS double submit, or when a proxy or browser retries during the SMTP timeout of up to 15 s. Only the INSERT then fails on the unique key and returns the first row, so the client receives the invoice twice.

Separately, on `record_failed` the panel is shown again with the same `request_key` and a "Retry" button (`retry = status === 502`). The e-mail was already delivered but no row exists, so pressing Retry sends it again.
**Fix:** Take a session-level advisory lock on the key for the whole send:
```php
$this->db->query("SELECT pg_advisory_lock(hashtext('invoice_sends_rk'), hashtext(:k:))", ['k' => $cmd->requestKey]);
try { $previous = $this->findByRequestKey(...); ... SMTP ... insert ... }
finally { $this->db->query("SELECT pg_advisory_unlock(hashtext('invoice_sends_rk'), hashtext(:k:))", ['k' => $cmd->requestKey]); }
```
For `record_failed`, render the form with a new `request_key` and no Retry button, and tell the user the e-mail was most likely delivered.

### WR-04: The send subject is not checked for control characters, so the saved subject can differ from the one sent

**File:** `app/Libraries/Delivery/InvoiceMailer.php:296-302`, `:180`, `:202`; `app/Controllers/InvoiceDelivery.php:267`
**Issue:** `validate()` checks only that the subject is not empty and not too long. Templates reject `[\x00-\x1F\x7F]` (`EmailTemplateService::update`), but the send form does not, and the controller only applies `trim()`. CI4 `prepQEncoding`/`setHeader` silently remove CR/LF, so no header injection is possible. However:
- `invoice_sends.subject` and the audit record store the original value with `\n`/`\t`;
- the e-mail goes out with a different subject.

That contradicts the requirement that the record match what was actually sent. Header safety also depends on a framework detail instead of the application's own rule.
**Fix:** In `validate()`, add `if (preg_match('/[\x00-\x1F\x7F]/', $cmd->subject) === 1) { throw new SendRejected('invalid_subject'); }`, map it to a field error in `rejected()`, and add a test with `"A\r\nBcc: x@evil"`.

### WR-05: The documented staging `fromEmail` does not match `allowedFromDomains` (exact match), so staging sends fail with `sender_invalid`

**File:** `app/Libraries/Delivery/SenderIdentity.php:533-537`, `docs/ops/server.md:155`, `docs/ops/server.md:498`, `app/Libraries/StagingSeed/Modules/Deliveries.php:39-42`
**Issue:** The `app.env` template sets `email.fromEmail = noreply@$DOMAIN`, which on staging is `noreply@work-tracker-stage.vostack.dev`. Section 20 then says staging keeps `email.allowedFromDomains = vostack.dev`. `assertAllowed()` compares domains for exact equality, so `work-tracker-stage.vostack.dev` is not equal to `vostack.dev`. Every send on staging, including the UAT step "Resend to your own address" (`server.md:511`), therefore ends with the alert `sender_invalid`. Together with CR-01, entity edits are blocked as well.

The staging seed works around this: `Deliveries::run` temporarily appends the `fromEmail` domain to `allowedFromDomains`. That hides the problem in the seed but not in the app.
**Fix:** Either document staging with `email.fromEmail = noreply@vostack.dev` (or add the staging domain to `allowedFromDomains`), or check the default `fromEmail` at startup or in `SenderIdentity::defaults()` and log a clear configuration error. Remove the seed workaround so the seed fails loudly on a bad configuration.

### WR-06: The payment date and the manual sent date have no lower bound (they can be before the issue date)

**File:** `app/Libraries/Receivables/PaymentService.php:241-247`, `app/Libraries/Delivery/ManualSendService.php:55-63`, `app/Views/invoices/_payment_form.php:28`, `app/Views/invoices/_mark_sent_form.php:79`
**Issue:** Both only reject future dates. `paid_on = 2025-10-29` on an invoice issued in 2026 is accepted, which is a plausible typo on mobile. It feeds `last_paid_on` and any later cash-basis or turnover report. A manual "sent" date before the invoice existed is accepted too. Records are insert-only, so the only fix is a void followed by a new entry.
**Fix:** Inside the transaction, load `issue_date` and reject `paid_on < issue_date` (`invalid_date` or a new `before_issue`). Do the same for `markSent`. Add `min="<issue_date>"` to both date inputs.

## Info

### IN-01: A request_key replay does not check that it belongs to the same invoice or kind

**File:** `app/Libraries/Delivery/InvoiceMailer.php:405-427`, `app/Libraries/Receivables/PaymentService.php:427-441`, `app/Libraries/Delivery/ManualSendService.php:203-212`
**Issue:** If a key that already exists is posted to a different invoice (or a different kind), the service returns the earlier result for the other invoice as a success. Keys are server-generated UUIDs, so this mostly matters for robustness.
**Fix:** Add `AND invoice_id = :i:` (and `kind`) to the replay query. On a mismatch, reject with `errorRequest`.

### IN-02: Splitting recipients differs between the client and the server

**File:** `public/assets/js/app.js` (`dlRecipients.parse`, split `/[,;\s]+/`), `app/Libraries/Master/CcEmails.php:19` (split `/[,;\r\n]+/`)
**Issue:** With JS, `a@x.sk b@y.sk` becomes two addresses. Without JS, the same text is a single invalid address, and the server returns `invalid_address`.
**Fix:** Also split on spaces in `CcEmails::parse` (`/[,;\s]+/`), or document the comma-only rule in `Deliveries.addressHint`.

### IN-03: timestamptz values are formatted with `now()->modify($v)`, which ignores the UTC offset

**File:** `app/Views/invoices/_history.php:73`, `app/Views/invoices/_payments.php:27`, `app/Controllers/InvoiceDelivery.php:186`, `app/Views/home/_overdue.php:152`
**Issue:** `DateTimeImmutable::modify('2026-10-29 23:30:00+00')` keeps the object's own timezone and drops `+00`. I verified this. The output is correct only because the database session timezone is `Europe/Bratislava`. During the DST fall-back hour it is ambiguous. `_overdue.php` takes `substr(…, 0, 10)` of a timestamptz, which has the same dependency.
**Fix:** Parse with `new DateTimeImmutable($v)` (it respects the offset), then `->setTimezone(service('clock')->timezone())`.

### IN-04: The payment button is rendered twice with redundant nested conditions

**File:** `app/Views/invoices/show.php:97-112`
**Issue:** The two blocks `if ($payPrimary) { if ($canPay) … $payPrimary ? 'btn-primary' : … }` and `if (! $payPrimary) { … }` exist only to change the button order. Inside each block the ternary is a constant. The code is hard to maintain and the indentation is inconsistent.
**Fix:** Compute the action list in order once (an array of `[url, label, class]`) and render it in a single loop.

### IN-05: Placeholders with spaces (`{{ number }}`) are neither rejected nor substituted

**File:** `app/Libraries/Delivery/TemplateRenderer.php:637-642`
**Issue:** `unknownPlaceholders()` only matches `\{\{([a-z_]+)\}\}`. A typo such as `{{ number }}` or `{{Number}}` passes validation and is sent to the client as literal text.
**Fix:** Detect `\{\{\s*([^}]*)\s*\}\}`, and report anything that is not exactly in the whitelist (or is not normalized to it) as an unknown placeholder.

---

_Reviewed: 2026-10-29T18:24:55Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
