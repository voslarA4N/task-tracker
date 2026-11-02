---
phase: 09-dobropisy
plan: 11
subsystem: delivery
tags: [credit-note, email, invoice-mailer]
requires: [09-01, 09-03, 09-04, 09-08]
provides:
  - credit note e-mail send/resend/mark-sent via existing InvoiceMailer
  - reminders rejected for credit notes and cancelled invoices
affects: [app/Libraries/Delivery, app/Views/invoices]
key-files:
  modified:
    - app/Libraries/Delivery/InvoiceMailer.php
    - app/Libraries/Delivery/ManualSendService.php
    - app/Controllers/InvoiceDelivery.php
    - app/Views/invoices/_delivery_sheet.php
    - app/Views/invoices/delivery_page.php
    - app/Language/sk/Deliveries.php
    - app/Language/en/Deliveries.php
  created:
    - tests/integration/Delivery/CreditNoteMailerTest.php
    - tests/feature/Invoicing/CreditNoteDeliverySheetTest.php
key-decisions:
  - "Recipients come from the live clients row by credit note client_id (same client as the original thanks to credit_note_target_guard); no e-mail snapshot (deviation from D-19 wording)"
requirements-completed: [INV-07, INV-10]
duration: 20min
completed: 2026-11-01
---

# Phase 9 Plan 11: Credit note e-mail delivery Summary

Credit notes are sent, resent and manually marked as sent through the existing InvoiceMailer with the `credit_note` template and a PDF-only `Dobropis_{number}.pdf` attachment; reminders are rejected server-side for credit notes and cancelled invoices.

## Accomplishments
- `InvoiceMailer::loadInvoice` accepts `credit_note`, rejects `reminder` for credit notes and `cancelled` invoices (`not_sendable`); template and attachments (single `invoice` document, filename via DocumentFilename) chosen by document type.
- Cancelled invoices can be resent with invoice and report.
- `ManualSendService` and `InvoiceDelivery::markable` allow credit notes.
- Sheet and page titles "Odoslať dobropis" / "Odoslať dobropis znova" (sk, en).

## Task Commits
1. Task 1: 12d8391 (mailer, mark-sent, integration tests)
2. Task 2: 4bea6a4 (sheet titles, lang, feature tests)

## Deviations from Plan
- **D-19 recipients:** recipients are loaded from the live `clients` row of the credit note's `client_id` (identical to the original invoice's client via the DB target guard), not a snapshot. As specified in the plan.
- **TDD order:** Task 1 tests were written together with the implementation (they passed on first run) and committed in one `feat` commit; no separate RED commit. Same for Task 2.
- **Test location:** sheet tests are in a new feature test `CreditNoteDeliverySheetTest` (feature tests need AuthTestCase), not inside the integration test.
- **Controller:** `form()` unchanged; views use `$invoice['type']` (already passed) instead of a new `$docType`.
- **cs-fixer:** realigned `=>` in both Deliveries.php lang files, so the diff touches the whole array.

## Verification
- Filter run of CreditNote/InvoiceMailer/ReminderMailer/ManualSend/Resend/Delivery/MarkSent/Reminder/LangKeys/Views tests: green (185 + 188 test runs). `composer stan` and `composer cs` clean.

## Known Stubs
None.

## Self-Check: PASSED
