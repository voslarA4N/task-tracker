---
phase: 07-doru-enie-hrady-a-upomienky
plan: 11
subsystem: delivery
tags: [manual-send, void, htmx, sheet, audit]
requires: [07-10, 07-06, 07-04]
provides:
  - ManualSendService (markSent, voidManual), insert-only and audited
  - Mark-as-sent and void-mark sheets with no-JS pages, 4 routes
  - Void button on manual history records, mark button on invoice detail
affects: []
key-files:
  created:
    - app/Libraries/Delivery/ManualSendService.php
    - app/Views/invoices/_mark_sent_sheet.php
    - app/Views/invoices/_mark_sent_form.php
    - app/Views/invoices/_send_void_sheet.php
    - app/Views/invoices/_send_void_form.php
    - app/Views/invoices/mark_sent_page.php
    - tests/integration/Delivery/ManualSendServiceTest.php
    - tests/feature/Invoicing/MarkSentTest.php
  modified:
    - app/Libraries/Delivery/SendRejected.php
    - app/Controllers/InvoiceDelivery.php
    - app/Config/Routes/invoices.php
    - app/Views/invoices/show.php
    - app/Views/invoices/_history.php
    - app/Language/sk/Deliveries.php
    - app/Language/en/Deliveries.php
key-decisions:
  - "sent_at of a manual mark = today's Clock time if the date is today, otherwise {date} 12:00 Europe/Bratislava"
  - "Only kind manual can be voided (not_manual otherwise); status is derived from the view, no UPDATE/DELETE"
  - "Sheet/form split like payments so validation errors re-render only #sheet-body"
requirements-completed: [INV-06]
duration: 30min
completed: 2026-10-29
---

# Phase 7 Plan 11: Manual sent mark Summary

Owner can mark an issued invoice as sent without e-mail (date default today, optional note) and cancel a mistaken mark with a mandatory reason; the record stays in the history as cancelled and status is recalculated.

## Tasks

1. ManualSendService (markSent/voidManual), SendRejected reasons, 8 integration tests - 2f6701c
2. Controller actions, routes, sheets and no-JS pages, show/history buttons, lang sk/en, MarkSentTest (5 tests) - dc621b3

## Verification

Targeted tests green (ManualSendServiceTest, MarkSentTest, delivery, payments, lang, CSP views). `composer stan` and `composer cs` clean. Full PHPUnit and JS results are in the orchestrator report.

## Deviations from Plan

**1. [Rule 3 - Structure] Extra view files** - `_mark_sent_form.php`, `_send_void_form.php` and `mark_sent_page.php` added so errors re-render only `#sheet-body` and the screens work without JS (same split as 07-10).

**2. [Test design]** Note field is a textarea per UI-SPEC; additional lang keys (errors, history note) added in sk and en.

## Known Stubs

None.

## Threat Flags

None beyond plan threat model (T-07-47..51 covered: audit, not_manual, no UPDATE/DELETE grep, CSRF 403 test, esc output).

## Self-Check: PASSED

Files exist; commits 2f6701c and dc621b3 exist.
