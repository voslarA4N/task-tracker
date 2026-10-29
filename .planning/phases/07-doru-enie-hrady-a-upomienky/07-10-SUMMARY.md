---
phase: 07-doru-enie-hrady-a-upomienky
plan: 10
subsystem: receivables-ui
tags: [payments, htmx, sheet, invoice-detail, overdue]
requires: [07-09, 07-06]
provides:
  - Payments controller (form, record, impact, voidForm, void) and 5 routes
  - Payment and void sheets with no-JS page, server-side impact preview
  - Payments card on invoice detail, partially_paid/paid/overdue badges, status-based actions
affects: [07-11]
key-files:
  created:
    - app/Controllers/Payments.php
    - app/Views/invoices/_payment_sheet.php
    - app/Views/invoices/_payment_form.php
    - app/Views/invoices/_payment_impact.php
    - app/Views/invoices/_payment_void_sheet.php
    - app/Views/invoices/_payment_void_form.php
    - app/Views/invoices/payment_page.php
    - app/Views/invoices/_payments.php
    - app/Language/sk/Payments.php
    - app/Language/en/Payments.php
    - tests/feature/Invoicing/PaymentsTest.php
  modified:
    - app/Config/Routes/invoices.php
    - app/Controllers/Invoices.php
    - app/Views/invoices/show.php
key-decisions:
  - "Impact preview is a server htmx POST on amount change (no JS bcmath)"
  - "Success returns HX-Location to a whitelisted return_to (/ or /invoices/{id}) plus HX-Trigger allocationChanged"
  - "Sheet and form split into separate views so validation errors re-render only #sheet-body"
requirements-completed: [PAY-01, PAY-03, INV-06]
duration: 45min
completed: 2026-10-29
---

# Phase 7 Plan 10: Payment screens Summary

Owner records one or more payments in a bottom sheet (date default today, blank amount = whole balance, server impact preview), voids mistakes with a mandatory reason, and sees balance, Partially paid / Paid badges, "Po splatnosti · n dní" (from Clock) and a Payments card on the invoice detail.

## Tasks

1. Payments controller, routes, payment/void sheets, no-JS page, lang files - 3d2b80a
2. Payments card, status badges, overdue badge, actions by status, PaymentsTest (15 tests) - 383d4a3

## Verification

Full PHPUnit: 1523 tests, 23117 assertions, OK. JS tests: 58 pass. `composer stan` and `composer cs` clean (run in php container). Sheets and detail contain no `style=`/`onclick`; no CURRENT_DATE/now() in Invoices controller.

## Deviations from Plan

**1. [Rule 3 - Structure] Extra view files** - `_payment_form.php` and `_payment_void_form.php` added (not in plan file list) so validation errors re-render only `#sheet-body`, mirroring the delivery sheet/form split. The void page also works without JS via `payment_page.php`.

**2. [Test design] External invoice form test** - the 404 is asserted via `PageNotFoundException` (framework throws in feature tests), as in earlier delivery tests. Overdue test refreshes `last_activity` after moving the frozen clock, otherwise the idle timeout redirects.

Task 1 tests were committed with Task 2 because the test file covers both screens.

## Known Stubs

None.

## Threat Flags

None beyond plan threat model (T-07-40b, 43, 45, 46, 46b covered by tests: repeated request_key, foreign return_to, no-CSRF 403, no inline attrs, routes behind existing auth).

## Self-Check: PASSED

Files exist; commits 3d2b80a and 383d4a3 exist.
