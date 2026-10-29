---
phase: 07-doru-enie-hrady-a-upomienky
plan: 13
subsystem: delivery-reminders
tags: [reminder, mail, threading, dashboard, htmx]
requires: [07-11, 07-12]
provides:
  - InvoiceMailer reminder send (invoice-only attachment, In-Reply-To/References threading, no_balance guard)
  - GET/POST /invoices/{id}/remind with return_to whitelist
  - Reminder sheet mode, buttons on invoice detail and dashboard overdue rows, last reminder shown
key-files:
  created:
    - tests/integration/Delivery/ReminderMailerTest.php
    - tests/feature/Invoicing/ReminderTest.php
  modified:
    - app/Libraries/Delivery/InvoiceMailer.php
    - app/Libraries/Delivery/SendRejected.php
    - app/Controllers/InvoiceDelivery.php
    - app/Config/Routes/invoices.php
    - app/Views/invoices/_delivery_form.php
    - app/Views/invoices/_delivery_sheet.php
    - app/Views/invoices/show.php
    - app/Views/home/_overdue.php
    - app/Language/sk/Deliveries.php
    - app/Language/en/Deliveries.php
key-decisions:
  - "Thread parent = latest non-voided invoice/resend message_id, preferring counts_as_sent; stored in invoice_sends.in_reply_to"
  - "no_balance check runs after idempotency replay and validation, before SMTP; GET form for a zero-balance invoice also returns 409 with an alert"
  - "Money::of()->compare() used instead of bccomp (PHPStan forbiddenBcmath rule)"
requirements-completed: [MAIL-03]
duration: 40min
completed: 2026-10-29
---

# Phase 7 Plan 13: Reminder Summary

One-tap manual reminder (invoice PDF only, threaded via In-Reply-To/References, only while balance > 0) from the dashboard overdue rows and the invoice detail, recorded as `invoice_sends` kind `reminder`.

## Tasks

1. InvoiceMailer reminder: no_balance guard, threading, in_reply_to persisted, counts_as_sent false. Commit 6bc44e8.
2. Controller, routes, sheet mode, detail and dashboard buttons, last reminder date, lang keys sk/en, feature tests. Commit 5cb26f7.

## Deviations from Plan

- [Rule 3] bccomp/bcsub replaced by `Money` because PHPStan forbids direct bcmath outside Money/Hours.
- The draft/attachment code for kind reminder already existed from earlier plans, so Task 1 only added the guard and threading. Tests were written alongside the implementation rather than as a separate RED commit.
- The fixture invoice and report PDFs have identical bytes, so the "no report" check asserts exactly one PDF part rather than absence of report bytes.
- The "last reminder" on the detail reuses the existing `historySummary` line from `_history.php` (already wired to `last_reminder_at`).

## Verification

- Full PHPUnit: OK (1562 tests, 23308 assertions) as last test step after cs fixes
- JS tests: 58 pass, 0 fail
- composer stan: no errors; composer cs: 0 files to fix
- Manual Mailpit threading check from the plan's verification section was not performed.

## Known Stubs

None.

## Self-Check: PASSED
