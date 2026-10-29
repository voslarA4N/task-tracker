---
phase: 07-doru-enie-hrady-a-upomienky
plan: 06
subsystem: delivery-ui
tags: [resend, alpine-csp, history, htmx]
requires: [07-05]
provides:
  - GET/POST invoices/{id}/resend (InvoiceDelivery::resendForm/resend, kind resend)
  - Alpine CSP component dlRecipients (To/CC editor, outside-client warning)
  - _history.php partial with per-send details
affects: [07-09, 07-11]
key-files:
  created:
    - app/Views/invoices/_history.php
    - tests/integration/Delivery/ResendTest.php
    - tests/feature/Invoicing/DeliveryResendTest.php
    - tests/js/dl-recipients.test.js
  modified:
    - app/Controllers/InvoiceDelivery.php
    - app/Libraries/Delivery/InvoiceMailer.php
    - app/Config/Routes/invoices.php
    - app/Views/invoices/_delivery_form.php
    - app/Views/invoices/_delivery_sheet.php
    - app/Views/invoices/delivery_page.php
    - app/Views/invoices/show.php
    - app/Language/sk/Deliveries.php
    - app/Language/en/Deliveries.php
    - public/assets/js/app.js
    - public/assets/css/app.css
key-decisions:
  - "Resend draft: To = client invoice_email, CC empty, but client_emails still holds all client addresses for the outside-client warning"
  - "Warning is informational only; server decides counts_as_sent (T-07-26 accepted)"
  - "No active_to check anywhere in the resend path (D-08, ENT-04)"
requirements-completed: [MAIL-02, MAIL-01]
duration: 45min
completed: 2026-10-29
---

# Phase 7 Plan 06: Resend and send history Summary

Owner can resend a stored invoice with report to any address from the detail (button for sent, partially paid, paid), with byte-identical attachments verified by sha256 against invoice_documents; addresses are edited row by row (Alpine CSP, plain comma field without JS) and the detail shows the full send history with expandable technical details.

## Tasks

1. Resend routes, controller generalised to form/submit by kind, draft(resend), ResendTest and DeliveryResendTest - ac13d4c
2. dlRecipients editor, outside-client warning, _history partial, show.php button, language keys, CSS, JS test - ac13d4c (single commit, both tasks touched the same form and views)

## Verification

Full PHPUnit: 1483 tests, 22951 assertions, OK. `node --test tests/js/`: 55 pass. `composer stan` and `composer cs` clean. Manual Mailpit/iPhone check not done.

## Deviations from Plan

**1. [Rule 3] Single commit for both tasks.** Form, views and tests overlap; the work was verified together.

**2. [Rule 3] CSP-friendly bindings.** Used the repo's `x-bind:hidden` pattern (hidden attribute) instead of x-show/:class; added `notEnhanced`/`noWarn` getters. Warning is rendered once under To and reacts to CC via a `dl-change` event on the form.

**3. [Rule 1] History SMTP reply.** `InvoiceMailer::history()` now also selects `smtp_response`, shown in the detail (the plan listed it but the query did not return it).

**4. Test helper naming.** Test methods named `invoiceStatus`/`postForm` to avoid clashing with final/parent PHPUnit and AuthTestCase methods.

## Known Stubs

None.

## Self-Check: PASSED

Commit ac13d4c and created files exist.
