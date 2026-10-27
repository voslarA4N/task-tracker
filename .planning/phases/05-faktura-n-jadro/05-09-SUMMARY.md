---
phase: 05-faktura-n-jadro
plan: 09
subsystem: invoicing
tags: [issue, htmx, idempotency, confirm-sheet, totals-parity]
requires:
  - phase: 05-03
    provides: InvoiceIssuer, IssueCommand, IssueRejected
  - phase: 05-08
    provides: preview screen, #inv-issue-form, Invoicing::errorMessage
provides:
  - POST /invoices/issue (Invoicing::issue) with HX-Location redirect and full IssueRejected mapping
  - IssueInput (whitelist, validate, toCommand)
  - invoiceIssuer shared service (injectable mock)
  - Confirm bottom sheet (_confirm_sheet.php) and alerts in the preview
  - End-to-end totals parity test (screen = invoice = lines = snapshot = PDF)
affects: [05-10]
tech-stack:
  added: []
  patterns:
    - rejections re-render #inv-preview from a fresh draft with alerts and a forced status (409, 422, 500)
    - invoiceIssuer service lets feature tests inject FakePdfRenderer or the real mPDF renderer
key-files:
  created:
    - app/Libraries/Invoicing/IssueInput.php
    - app/Views/invoicing/_confirm_sheet.php
    - tests/unit/Invoicing/IssueInputTest.php
    - tests/feature/Invoicing/InvoicingIssueTest.php
    - tests/feature/Invoicing/InvoicingScreenTest.php
  modified:
    - app/Controllers/Invoicing.php
    - app/Config/Routes/invoicing.php
    - app/Config/Services.php
    - app/Views/invoicing/_preview.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
key-decisions:
  - "already_issued redirects to the first invoice with the issued flash (idempotent success)"
  - "Unexpected exceptions are logged and return 500 with a generic message, no DB detail"
  - "Without JS a noscript submit button posts the form and the server enforces confirm_backdate and all guards"
requirements-completed: [INV-01, INV-02, INV-03, INV-04, INV-13, INV-14, INV-15, NFR-06]
duration: ~45min
completed: 2026-10-27
---

# Phase 5 Plan 09: Issue invoice from preview Summary

The owner issues an invoice from the preview through a bottom confirm sheet: POST `/invoices/issue` calls `InvoiceIssuer::issue`, redirects with `HX-Location` to the detail with the flash "Faktúra 202610001 bola vystavená.", and every rejection returns the right status (409 or 422) with a specific message and no write. A repeated request key redirects to the first invoice instead of creating a second.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | IssueInput, Invoicing::issue, rejection mapping, service, routes, language, confirm sheet wiring | 72e379e |
| 2 | End-to-end totals parity test (confirm sheet shipped in Task 1 commit) | d4bcbba |

## Verification

Full PHPUnit suite green (1062 tests, 7231 assertions) after `make test-db`; `node --test tests/js/` 42 passing; `composer stan` and `composer cs` clean. The PDF text check ran (pdftotext is available in the container) and finds `221,98 €`.

## Deviations from Plan

**1. Task split** - the confirm sheet, the `_preview.php` changes and the language keys were committed with Task 1 because the 409/422 alerts and the sticky button belong to the same view and the Task 1 feature tests need them. Task 2's commit contains only `InvoicingScreenTest`.

**2. Test rate setup** - the parity test sets the 33.33 rate through task overrides (the allocation rate comes from the task), not the client rate.

## Known Stubs

None.

## Threat Flags

None. T-05-45 to T-05-50 mitigated: `IssueInput` whitelist has no total, number or amount (test posts them and the invoice keeps 1080.00), `hx-disabled-elt` plus `request_key` idempotency tested, CSRF 403 and anonymous redirect tested, currency mismatch by direct POST returns 422 with no write, 500 path logs only and shows a generic message, parts of other clients are rejected by the issuer (`parts_changed`).

## Self-Check: PASSED

All created files exist; commits 72e379e and d4bcbba are in git log.
