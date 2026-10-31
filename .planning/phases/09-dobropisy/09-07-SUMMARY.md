---
phase: 09-dobropisy
plan: 07
subsystem: invoicing
tags: [credit-note, receivables, overdue, payments, pdf-download]
requires: [09-01, 09-03, 09-04]
provides:
  - cancelled status excluded from overdue (list, count, per-invoice flag, invoices list)
  - PaymentRejected('cancelled') for record/void, impact() zero result for cancelled
  - InvoiceQueries::list filters cancelled and credit_note, detail with credit note fields
  - Invoices::serve credit_note filename (Dobropis_{number}.pdf), report of credit note is 404
affects: [09-08, 09-09]
tech-stack:
  added: []
  patterns: [defence in depth - explicit status <> 'cancelled' on top of balance = 0 in view]
key-files:
  created:
    - tests/integration/Receivables/CreditNoteReceivablesTest.php
    - tests/integration/Invoicing/CreditNoteQueriesTest.php
    - tests/feature/Invoicing/CreditNotePdfEndpointTest.php
  modified:
    - app/Libraries/Receivables/InvoiceStatusQueries.php
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Libraries/Receivables/PaymentService.php
    - app/Libraries/Receivables/PaymentRejected.php
    - app/Controllers/Payments.php
    - app/Controllers/Invoices.php
    - app/Language/sk/Payments.php
    - app/Language/en/Payments.php
key-decisions:
  - "Cancelled check in PaymentService::record runs after the origin/type check so a credit note yields not_payable and a cancelled invoice yields cancelled"
  - "list(status=cancelled|paid|...) also restricts to type = invoice, because credit notes carry status cancelled in the view too"
requirements-completed: [INV-07]
duration: 25min
completed: 2026-11-01
---

# Phase 9 Plan 07: Cancelled status in receivables Summary

Cancelled invoices and credit notes never appear as overdue, accept no new or voided payments, and credit note PDFs download as Dobropis_{number}.pdf while their report returns 404.

## Tasks

1. Overdue, list, detail, payments for cancelled and credit notes: `test(09-07)` (RED) then `00b8be7`.
2. List filters and credit note PDF endpoint: RED test commit then `61405de`.

## Verification

- Combined PHPUnit filter (CreditNote, Overdue, PaymentService, PaymentVoid, InvoiceQueries, InvoicePdfEndpoint, InvoicesList, PaymentsTest, InvoiceDetail): 193 tests OK.
- `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 1 - Bug] Status filter restricted to type = invoice**
- Issue: filter `cancelled` via `i.status = :status:` would also return credit notes (view marks them cancelled).
- Fix: where clause adds `i.type = 'invoice'` for the status whitelist.
- Files: app/Libraries/Invoicing/InvoiceQueries.php

**2. [Rule 1 - Bug] Check order in PaymentService::record**
- Plan said cancelled before all checks; but the behavior spec requires not_payable for credit notes, so cancelled check follows the origin/type check.
- `PaymentRejected::REASONS` does not exist in code; reason list is documented in the class docblock, which was updated.

Payments controller maps `cancelled` to HTTP 409 for both the record form and void.

## Known Stubs

None.

## Self-Check: PASSED
