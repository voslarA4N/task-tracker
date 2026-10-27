---
phase: 05-faktura-n-jadro
plan: 07
subsystem: invoicing
tags: [invoice-list, invoice-detail, pdf-endpoint, navigation, css]
requires:
  - phase: 05-03
    provides: DocumentStore (read, meta), IssueFixtures, issued invoices with stored PDF
provides:
  - InvoiceQueries (list with limit and truncation, detail from snapshot without PDF bytes)
  - Invoices controller (index, show, pdf) and routes /invoices, /invoices/{id}, /invoices/{id}/pdf
  - Faktury tab in navigation, all .inv-* CSS classes for 05-08 and 05-09
  - Locked allocation shows link "Zamknute - FA {number}" or "externa faktura"
affects: [05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - PDF endpoint serves stored bytes only (no renderer dependency), ETag is the sha256
key-files:
  created:
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Controllers/Invoices.php
    - app/Config/Routes/invoices.php
    - app/Views/invoices/index.php
    - app/Views/invoices/_list.php
    - app/Views/invoices/show.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
    - tests/integration/Invoicing/InvoiceQueriesTest.php
    - tests/feature/Invoicing/InvoicesListTest.php
    - tests/feature/Invoicing/InvoiceDetailTest.php
    - tests/feature/Invoicing/InvoicePdfEndpointTest.php
  modified:
    - app/Libraries/Work/TaskQueries.php
    - app/Views/tasks/_allocations.php
    - app/Views/layouts/_nav_items.php
    - app/Language/sk/Allocations.php
    - app/Language/en/Allocations.php
    - public/assets/css/app.css
    - tests/integration/Work/TaskQueriesDetailTest.php
    - tests/feature/Work/DashboardTest.php
key-decisions:
  - "External invoice link uses htmlspecialchars (as Tasks show view) instead of esc(attr), because esc attr encodes slashes in the URL"
requirements-completed: [INV-03, INV-08, INV-13, ALC-03, NFR-06]
duration: ~45min
completed: 2026-10-27
---

# Phase 5 Plan 07: Invoice list, detail and stored PDF Summary

The owner can open the Faktury tab, see issued and external invoices, open a read-only detail built only from the snapshot, and view or download the exact stored PDF bytes; locked parts in the task detail link to their invoice.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | InvoiceQueries and invoice number on locked parts | 6a50abc |
| 2 | Invoices controller, list, detail, navigation, .inv-* CSS | e08f246 |
| 3 | Stored PDF endpoint (inline and download) | c564557 |

## Verification

Full PHPUnit suite green (1015 tests, 7066 assertions); `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 1 - Bug] DashboardTest asserted the old disabled nav item** - `testSwitchSheetFormAndNavigation` expected `aria-disabled="true"`; updated to expect the active `/invoices` link. Commit c564557.

**2. External ref link escaping** - the plan asked for `esc($x, 'attr')`; that encodes `/` as entities, so `htmlspecialchars(..., ENT_QUOTES)` is used (equally safe, same as the task detail portal link).

## Known Stubs

None. The "Fakturovat mesiac" button links to `/invoices/new`, which 05-08 provides (intentional, per plan).

## Threat Flags

None. T-05-34 to T-05-39 mitigated: `Cache-Control: private, no-store`, sanitized filename (tested with `FA/2026/001`), http(s)-only external link (tested with `javascript:`), 404 for unknown or external ids, sha256 verified in `DocumentStore::read` with 500 and critical log, `nosniff`. The corrupted-PDF 500 branch is implemented but has no dedicated test (immutability triggers block tampering the stored row).

## Self-Check: PASSED

All created files exist; commits 6a50abc, e08f246, c564557 are in git log.
