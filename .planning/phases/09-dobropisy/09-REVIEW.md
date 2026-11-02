---
phase: 09-dobropisy
reviewed: 2026-11-01T00:25:17Z
depth: standard
files_reviewed: 94
files_reviewed_list:
  - app/Config/Routes/invoices.php
  - app/Config/Services.php
  - app/Controllers/CreditNotes.php
  - app/Controllers/InvoiceDelivery.php
  - app/Controllers/Invoices.php
  - app/Controllers/Payments.php
  - app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php
  - app/Language/cs/InvoicePdf.php
  - app/Language/en/CreditNotes.php
  - app/Language/en/Deliveries.php
  - app/Language/en/EmailTemplates.php
  - app/Language/en/InvoicePdf.php
  - app/Language/en/Invoices.php
  - app/Language/en/Payments.php
  - app/Language/pl/InvoicePdf.php
  - app/Language/sk/CreditNotes.php
  - app/Language/sk/Deliveries.php
  - app/Language/sk/EmailTemplates.php
  - app/Language/sk/InvoicePdf.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Payments.php
  - app/Libraries/Delivery/EmailTemplateService.php
  - app/Libraries/Delivery/InvoiceMailer.php
  - app/Libraries/Delivery/ManualSendService.php
  - app/Libraries/Delivery/TemplateContext.php
  - app/Libraries/Delivery/TemplateRenderer.php
  - app/Libraries/Invoicing/CorrectionType.php
  - app/Libraries/Invoicing/CreditNoteCommand.php
  - app/Libraries/Invoicing/CreditNoteDraftService.php
  - app/Libraries/Invoicing/CreditNoteInput.php
  - app/Libraries/Invoicing/CreditNoteIssuer.php
  - app/Libraries/Invoicing/CreditNoteRejected.php
  - app/Libraries/Invoicing/CreditNoteSnapshotBuilder.php
  - app/Libraries/Invoicing/DocumentFilename.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Invoicing/IssuedCreditNote.php
  - app/Libraries/Invoicing/MpdfCreditNoteCn1Renderer.php
  - app/Libraries/Invoicing/RendererRegistry.php
  - app/Libraries/Receivables/InvoiceStatusQueries.php
  - app/Libraries/Receivables/PaymentRejected.php
  - app/Libraries/Receivables/PaymentService.php
  - app/Libraries/StagingSeed/HistoryPlan.php
  - app/Libraries/StagingSeed/HistoryPlanner.php
  - app/Libraries/StagingSeed/Modules/Invoices.php
  - app/Views/credit_notes/_confirm_body.php
  - app/Views/credit_notes/_confirm_sheet.php
  - app/Views/credit_notes/_preview.php
  - app/Views/credit_notes/confirm_page.php
  - app/Views/credit_notes/new_page.php
  - app/Views/invoices/_delivery_sheet.php
  - app/Views/invoices/_list.php
  - app/Views/invoices/_payments.php
  - app/Views/invoices/delivery_page.php
  - app/Views/invoices/index.php
  - app/Views/invoices/show.php
  - app/Views/layouts/app.php
  - app/Views/pdf/credit_note_cn1.php
  - app/Views/settings/email_templates/edit.php
  - app/Views/settings/email_templates/index.php
  - public/assets/css/app.css
  - resources/pdf/credit-note-cn1.css
  - tests/_support/CreditNoteFixtures.php
  - tests/_support/credit_note_worker.php
  - tests/feature/Invoicing/CreditNoteDeliverySheetTest.php
  - tests/feature/Invoicing/CreditNoteDetailTest.php
  - tests/feature/Invoicing/CreditNoteListTest.php
  - tests/feature/Invoicing/CreditNotePdfEndpointTest.php
  - tests/feature/Invoicing/CreditNotesControllerTest.php
  - tests/feature/Settings/EmailTemplatesControllerTest.php
  - tests/integration/Delivery/CreditNoteMailerTest.php
  - tests/integration/Delivery/CreditNoteTemplateTest.php
  - tests/integration/Delivery/EmailTemplatesSeedTest.php
  - tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
  - tests/integration/Invoicing/CreditNoteConcurrencyTest.php
  - tests/integration/Invoicing/CreditNoteDraftServiceTest.php
  - tests/integration/Invoicing/CreditNoteFxTest.php
  - tests/integration/Invoicing/CreditNoteGuardsTest.php
  - tests/integration/Invoicing/CreditNoteImmutabilityTest.php
  - tests/integration/Invoicing/CreditNoteIssuerTest.php
  - tests/integration/Invoicing/CreditNoteQueriesTest.php
  - tests/integration/Invoicing/CreditNoteReissueTest.php
  - tests/integration/Invoicing/CreditNoteRollbackTest.php
  - tests/integration/Invoicing/CreditNoteSchemaTest.php
  - tests/integration/Invoicing/CreditNoteSnapshotBuilderTest.php
  - tests/integration/Invoicing/MpdfCreditNoteCn1RendererTest.php
  - tests/integration/Receivables/CreditNoteReceivablesTest.php
  - tests/integration/Receivables/CreditNoteStatusViewTest.php
  - tests/integration/StagingSeed/StagingSeederTest.php
  - tests/integration/Work/CreditNoteAllocationReleaseTest.php
  - tests/unit/Delivery/TemplateRendererTest.php
  - tests/unit/Invoicing/CreditNoteCommandTest.php
  - tests/unit/Invoicing/DocumentFilenameTest.php
  - tests/unit/Invoicing/RendererRegistryTest.php
  - tests/unit/StagingSeed/HistoryPlannerTest.php
findings:
  critical: 0
  warning: 5
  info: 6
  total: 11
status: issues_found
---

# Phase 9: Code Review Report

**Reviewed:** 2026-11-01T00:25:17Z
**Depth:** standard
**Files Reviewed:** 94
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

Reviewed the Phase 9 credit-note slice (diff `1dccbd5..HEAD`): migration (DB guards, `release_allocations()`, `alloc_guard` exception, `invoice_status_v` `cancelled`), `CreditNoteIssuer` / `CreditNoteSnapshotBuilder` / `CreditNoteDraftService`, cn-1 renderer + PDF view, `CreditNotes` controller and views, receivables / payment / delivery changes, staging seed. Tests were read for reliability only.

The core guarantees hold up under tracing: money stays in string/bcmath (`Money`, `timesHours` uses HalfAwayFromZero, matching PostgreSQL `round(numeric)` and the `amount = round(hours*rate,2)` CHECK); the credit note copies amounts, FX and `total_entity_ccy` from the stored invoice row/snapshot and never from live master data; one-credit-note-per-invoice is enforced by a partial unique index plus the BEFORE INSERT target trigger plus the pre-existing `(type='credit_note') = (corrected_invoice_id IS NOT NULL)` CHECK; numbering reuses `NumberAllocator` under `FOR UPDATE` on series rows; `app_rw` never receives `UPDATE (invoice_id)`; `CREATE OR REPLACE VIEW` only appends columns and keeps `balance` as `numeric(12,2)`. All language keys used by new views/controllers exist in `sk` and `en`, and all `cn*` PDF labels exist in all four document languages (verified by script).

No blockers found. The warnings concern business-state edges around the new `cancelled` state (a cancelled invoice can still be sent to the client; mistaken payments become permanently un-voidable; the "no new payments after storno" rule is app-only and races), a misleading 404 for internal sum/parts mismatches, and a live-preview UX defect that loses keystrokes.

## Warnings

### WR-01: A cancelled invoice can still be sent / re-sent / marked as sent to the client

**File:** `app/Views/invoices/show.php:66-67`, `app/Libraries/Delivery/InvoiceMailer.php:385-388`, `app/Controllers/InvoiceDelivery.php:145`
**Issue:** `$canSend = ! $external && ! $isSent;` and `$canResend = ! $external && $isSent;` ignore `$cancelled`. `InvoiceMailer::loadInvoice()` only rejects `reminder` for cancelled invoices, so `invoice` and `resend` kinds go through. If the user issues a credit note for an invoice that was never sent (the common "caught the mistake before sending" case) the detail page still shows the primary "Send invoice" button, and sending it mails the client a payable invoice (IBAN, variable symbol, QR) that is already storno'd. The client can pay a cancelled document, and D-15 says the cancelled invoice must not invite new payments. The same applies to `mark-sent` (`InvoiceDelivery.php:145`, `ManualSendService`).
**Fix:** Block first-time delivery of a cancelled invoice and (optionally) allow resend only with explicit acknowledgment:
```php
// show.php
$canSend   = ! $external && ! $isSent && ! $cancelled;
$canResend = ! $external && $isSent && ! $cancelled; // or keep, with a warning text
// InvoiceMailer::loadInvoice()
if ($row['type'] === 'invoice' && $row['status'] === 'cancelled' && $kind !== 'resend') {
    throw new SendRejected('not_sendable');
}
```
and add the same status check in `ManualSendService` / `InvoiceDelivery::markSentForm`.

**Status: FIXED** in d00c006. Cancelled invoices (type invoice) are rejected with `not_sendable` for send, resend and mark-sent (InvoiceMailer, ManualSendService, InvoiceDelivery forms 404); show.php hides the buttons; credit notes remain deliverable.

### WR-02: Payments recorded before storno can never be voided afterwards

**File:** `app/Libraries/Receivables/PaymentService.php:142-146`, `app/Views/invoices/show.php:200` (`readOnly`)
**Issue:** `void()` now throws `PaymentRejected('cancelled')` for any payment on a cancelled invoice and the payments panel hides the void link. D-17 only says that after storno *new* payments cannot be added and that existing payments stay recorded (insert-only, voiding is itself insert-only). If a payment was recorded on the wrong invoice (typo, wrong client) and the invoice is later cancelled, that erroneous payment is now permanently counted in `paid_amount`, `last_paid_on` and any future cash/turnover reporting, with no correction path at all.
**Fix:** Allow `void()` on cancelled invoices (it only adds a `payment_voids` row and does not change the cancelled status or balance, since `balance` is forced to 0 for cancelled invoices). Remove the `cancelled` branch from `void()` and pass `readOnly` only to hide the "record payment" CTA, not the void link.

### WR-03: Internal snapshot inconsistency (parts_mismatch / sum_mismatch) is reported as "Invoice not found" (404); the sum-check UI is dead code

**File:** `app/Controllers/CreditNotes.php:169-203`, `app/Controllers/CreditNotes.php:86-88`, `app/Views/credit_notes/_preview.php:41,147-149`
**Issue:** `CreditNoteSnapshotBuilder::build()` throws `CreditNoteRejected('parts_mismatch' | 'sum_mismatch')` when stored lines and the snapshot disagree. `load()` catches every `CreditNoteRejected` and hands it to `blocked()`, which for anything other than `already_credited` throws `PageNotFoundException` with `Invoices.notFound`. So the exact integrity problem the UI was designed to surface (`CreditNotes.errorSum` "...Kontaktujte správcu") is shown as a 404 "invoice not found" on GET, preview, confirm and issue. Because `build()` throws before returning, `$draft['sum_check']` can never be non-zero, so the `$loaded['sum_check'] !== '0.00'` branch in `confirm()` and the `! $sumOk` alert in `_preview.php` can never run.
**Fix:** Handle the mismatch reasons in `blocked()` with a 409 page and the `errorSum` / `errorStale` alert, and log it at `critical`:
```php
if (in_array($e->reason, ['sum_mismatch', 'parts_mismatch'], true)) {
    log_message('critical', 'Credit note draft mismatch invoice={id} reason={r}', ['id' => $id, 'r' => $e->reason]);
    $data = ['id' => $id, 'draft' => null, 'values' => $values, 'errors' => [],
             'alerts' => [['type' => 'danger', 'message' => lang('CreditNotes.errorSum')]]];
    return $this->response->setStatusCode(409)->setBody(htmx_view('credit_notes/_preview', $data, 'credit_notes/new_page'));
}
```
Then either remove the unreachable `sum_check` branches or keep them as documented defense in depth.

### WR-04: "No payments after storno" is app-only and races with credit-note issuance (different advisory lock namespaces)

**File:** `app/Libraries/Invoicing/CreditNoteIssuer.php:100`, `app/Libraries/Receivables/PaymentService.php:233-236,275`
**Issue:** `PaymentService` serializes on `pg_advisory_xact_lock(hashtext('payments'), id)` and then reads `invoice_status_v.status`. `CreditNoteIssuer` locks `hashtext('credit_note')`, a different key. Under READ COMMITTED, a payment transaction that reads the status before the credit-note transaction commits sees `issued/sent`, inserts a payment, and both commit. The result is a payment on an invoice that was cancelled before the payment committed, which D-15 forbids. No DB trigger on `payments` backs up the rule, although the project relies on DB-level guards for every other immutability invariant. The same race applies to `InvoiceMailer` reminders, which check `status` without any lock.
**Fix:** In `issueInTransaction()`, also take the payments lock for the original invoice (keep a fixed order: `payments` before `credit_note`, and have `PaymentService` never take `credit_note`):
```php
$this->db->query("SELECT pg_advisory_xact_lock(hashtext('payments'), :id:)", ['id' => $cmd->originalId]);
$this->db->query("SELECT pg_advisory_xact_lock(hashtext('credit_note'), :id:)", ['id' => $cmd->originalId]);
```
Preferably also add a `BEFORE INSERT ON payments` trigger that raises when a `credit_note` row with `corrected_invoice_id = NEW.invoice_id` exists.

### WR-05: Live preview on reason `keyup` replaces the textarea that the user is typing in, so keystrokes are lost

**File:** `app/Views/credit_notes/_preview.php:43,77`, `app/Controllers/CreditNotes.php:39-62`
**Issue:** The reason `<textarea>` has `hx-trigger="change, keyup changed delay:500ms"` with `hx-target="#cn-preview" hx-swap="innerHTML"`, and the `#cn-preview` response re-renders the whole form, including the textarea, with the value captured when the request started. htmx keeps focus on the element with the same `id`, but any characters typed between request start and swap are overwritten by the server's older value. On iPhone, where pauses longer than 500 ms are normal while typing, this silently drops text from a field that is printed on an immutable tax document. The `preview_token` is also recomputed from the truncated text, so the confirm step shows the truncated reason.
**Fix:** Do not swap the input fields while the user is typing. Either trigger the preview for the reason only on `change`/`blur`, or split the response so that only the read-only parts (number card, lines, totals, the Continue button state) are swapped, e.g. `hx-target="#cn-summary"` with `hx-select="#cn-summary"`, and keep the form controls outside the swapped region.

## Info

### IN-01: Duplicate submit with the same `request_key` can return "already credited" (409) instead of the replay redirect

**File:** `app/Libraries/Invoicing/CreditNoteIssuer.php:96-117`
**Issue:** `assertNotIssued()` runs before `pg_advisory_xact_lock`. A second concurrent POST with the same key waits on the lock, then hits the `already_credited` check, not `already_issued`, so the user gets a warning page instead of being redirected to the credit note they just created.
**Fix:** Repeat `$this->assertNotIssued($cmd->requestKey)` right after acquiring the advisory lock.

### IN-02: Trigger rejection detected by substring of the exception message

**File:** `app/Libraries/Invoicing/CreditNoteIssuer.php:84`
**Issue:** `str_contains($e->getMessage(), 'is not creditable')` couples PHP to the free text in `credit_note_target_guard()`. Rewording the RAISE (or localizing `lc_messages`) silently turns this into a 500.
**Fix:** Raise with a dedicated SQLSTATE or `CONSTRAINT` name (`USING ERRCODE = 'integrity_constraint_violation', CONSTRAINT = 'credit_note_target'`) and match on that.

### IN-03: `numberPreview()` duplicates `NumberAllocator::next()` rules

**File:** `app/Libraries/Invoicing/CreditNoteDraftService.php:130-152`
**Issue:** The `initial_number` / per-period / overflow logic is copied from `NumberAllocator`. Any future change to numbering rules has to be made twice, or the preview shows a different number from the one that gets issued.
**Fix:** Extract a read-only `NumberAllocator::peek(array $series, string $date): ?string` and use it from both draft services.

### IN-04: `correction_type` is not constrained to the enum at DB level, but views call `CorrectionType::from()`

**File:** `app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php:27-35`, `app/Views/invoices/show.php` (`CorrectionType::from((string) $invoice['correction_type'])`), `app/Views/pdf/credit_note_cn1.php:134`
**Issue:** The CHECK only requires `correction_type IS NOT NULL` (plus the old regex). Any value outside the enum (manual repair, future rename) makes `from()` throw `ValueError`, which turns the detail page into a 500.
**Fix:** Add `AND correction_type IN ('data_error','amount_error','fx_error','order_cancelled','other')` to `invoices_credit_note_fields_check`, or use `tryFrom()` with a fallback label in the views.

### IN-05: Flash-notice link guard accepts protocol-relative URLs

**File:** `app/Views/layouts/app.php:64`
**Issue:** `str_starts_with($href, '/')` also accepts `//evil.example/...`. Today the href is server-generated (`releaseLink()`), so this cannot be exploited now, but the guard looks stricter than it is.
**Fix:** `str_starts_with($href, '/') && ! str_starts_with($href, '//')`.

### IN-06: `days_overdue` expression lacks the `cancelled` exclusion added to `overdue`

**File:** `app/Libraries/Receivables/InvoiceStatusQueries.php:37`
**Issue:** The `overdue` CASE got `AND status <> 'cancelled'` but the sibling `days_overdue` CASE did not. This is harmless today only because the view forces `balance = 0` for cancelled invoices. The inconsistency is a trap for future edits.
**Fix:** Add `AND status <> 'cancelled'` to the `days_overdue` CASE for symmetry.

---

_Reviewed: 2026-11-01T00:25:17Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
