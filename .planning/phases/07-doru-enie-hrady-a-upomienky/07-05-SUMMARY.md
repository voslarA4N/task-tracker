---
phase: 07-doru-enie-hrady-a-upomienky
plan: 05
subsystem: delivery-ui
tags: [invoice-send, htmx, bottom-sheet, status-badge, css]
requires: [07-04]
provides:
  - InvoiceDelivery controller (GET/POST invoices/{id}/send) with sheet and no-JS page
  - invoice detail with status badge (Vystavena/Odoslana), send button, Odoslanie history section
  - all phase 7 CSS classes in app.css
affects: [07-06, 07-09, 07-11]
key-files:
  created:
    - app/Controllers/InvoiceDelivery.php
    - app/Views/invoices/_delivery_sheet.php
    - app/Views/invoices/_delivery_form.php
    - app/Views/invoices/delivery_page.php
    - app/Language/sk/Deliveries.php
    - app/Language/en/Deliveries.php
    - tests/feature/Invoicing/DeliverySendTest.php
  modified:
    - app/Config/Routes/invoices.php
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Views/invoices/show.php
    - app/Views/layouts/app.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
    - public/assets/css/app.css
    - tests/feature/LayoutTest.php
key-decisions:
  - "Sheet GET returns the offcanvas, POST responses return only the form (_delivery_form) into #sheet-body"
  - "Success = HX-Location to detail + HX-Trigger allocationChanged (closes sheet, no JS change)"
  - "SMTP, attachment and record failures all return 502 and htmx swaps 502 into the sheet"
requirements-completed: [MAIL-01, INV-06]
duration: 60min
completed: 2026-10-29
---

# Phase 7 Plan 05: Send invoice from detail Summary

Owner sends invoice plus report from the invoice detail in one confirmation (bottom sheet with prefilled recipients, template subject/body and two attachments); detail then shows the Odoslana badge and a send history with date and recipients. SMTP failure re-renders the sheet with the same request_key and leaves the invoice unchanged.

## Tasks

1. Controller, routes, sheet, no-JS page, Deliveries language files - 899eb93
2. Detail status/history, InvoiceQueries status columns, Invoices language keys, phase 7 CSS, tests - 9a10e95

## Verification

Full PHPUnit: 1437 tests, 22733 assertions, OK. `composer stan` (both configs) and `composer cs` clean. DeliverySendTest (12 tests) covers prefill, no-JS page, CSRF 403, PRG, htmx headers, idempotent request_key, 422 is-invalid, external 404, anonymous redirect, detail states, no inline style/onclick/script. Manual Mailpit check from the plan was not done.

## Deviations from Plan

**1. [Rule 3 - Blocking] 502 instead of 500 for attachments_failed and record_failed; htmx swaps 502**
- htmx `responseHandling` did not swap 5xx, so the in-sheet alerts would never display. Added `502` (swap, error=false) to the config in `layouts/app.php`; all three mail-side failures return 502. `LayoutTest::testHtmxConfigMeta` updated accordingly.

**2. [Rule 3] Extra view `_delivery_form.php`**
- Shared by the GET sheet and the POST error re-render (target is `#sheet-body`), plus the no-JS page. Not in plan file list.

**3. Test hygiene**
- My first external-invoice test disabled a trigger on the shared test DB and left it disabled after a failure, which broke InvoiceImmutabilityTest until re-enabled; the test now uses `insertExternalInvoice` fixture instead.

CSS file starts with a comment fix only; `asset_url` versions by mtime so no manual version bump was needed.

## Known Stubs

None. Address fields are plain comma-separated text by plan (07-06 adds the Alpine editor).

## Self-Check: PASSED

Commits 899eb93 and 9a10e95 exist; created files present.
