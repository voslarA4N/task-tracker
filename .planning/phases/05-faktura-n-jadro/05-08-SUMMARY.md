---
phase: 05-faktura-n-jadro
plan: 08
subsystem: invoicing
tags: [preview, htmx, alpine, move-part, backdate-warning]
requires:
  - phase: 05-05
    provides: InvoiceDraftService, Draft, InvoiceGuards
  - phase: 05-07
    provides: .inv-* CSS, Invoices controller and routes, /invoices/new link
provides:
  - /invoices/new and /fragments/invoice-preview (read-only month preview, no number)
  - PreviewInput (whitelist and validation of GET selection)
  - Invoicing::errorMessage (IssueRejected reason to text, extended by 05-09)
  - Move of an unlocked part to another month from the preview (bottom sheet)
  - Alpine invIssue and invMove components
affects: [05-09]
tech-stack:
  added: []
  patterns:
    - entity select is sent out-of-band with every preview fragment so ended entities disappear when the issue date changes
    - preview root carries hx-get with the current selection and refreshes on allocationChanged
key-files:
  created:
    - app/Libraries/Invoicing/PreviewInput.php
    - app/Controllers/Invoicing.php
    - app/Config/Routes/invoicing.php
    - app/Views/invoicing/index.php
    - app/Views/invoicing/_picker.php
    - app/Views/invoicing/_entity_field.php
    - app/Views/invoicing/_preview.php
    - app/Views/invoicing/_move_sheet.php
    - app/Views/invoicing/_move_form.php
    - app/Views/invoicing/move_page.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
    - tests/unit/Invoicing/PreviewInputTest.php
    - tests/feature/Invoicing/InvoicingPreviewTest.php
    - tests/js/inv-issue.test.js
  modified:
    - public/assets/js/app.js
key-decisions:
  - "Preview month range is current -24 to current +1 (PreviewInput), independent of the allocation range used for moves"
  - "Changing the issue date does not send the entity, so entity, due date and backdate warning are recomputed server side"
  - "Without JS the Vystavit button is enabled and the server must enforce confirm_backdate (05-09)"
  - "Flash after move is read into the refreshed fragment only for htmx fragment requests"
requirements-completed: [INV-01, INV-02, INV-09, INV-15]
duration: ~50min
completed: 2026-10-27
---

# Phase 5 Plan 08: Month invoicing preview and part move Summary

The owner picks client, month and entity on /invoices/new, sees the exact would-be invoice (lines, VAT code, total, dates, entity, bank account, currency, no number), gets danger alerts and a backdate warning that disable the issue button, and can move a part to another month from a bottom sheet with the preview refreshing.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | PreviewInput, Invoicing controller (new, preview), routes, language files, views | fe4d0f2 |
| 2 | Alpine invIssue and invMove, JS test, markup test | 7623722 |
| 3 | Move part from preview (moveSheet, move), sheet views, tests | c6515c8 |

## Verification

Full PHPUnit suite green (1039 tests, 7160 assertions); `node --test tests/js/` 42 passing; `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. Extra view files** - `_entity_field.php`, `_move_form.php` and `move_page.php` were added (not in the plan file list). The entity field must be re-sent with each fragment (test requires ended entities absent from the fragment), and the move form needs an inner-only variant for 409/422 swaps into `#sheet-body` plus a no-JS full page.

**2. Test id collision** - the move test initially asserted `value="N"` which matched `client_id`; changed to `name="part_ids[]" value="N"`.

## Known Stubs

None. The form posts to `/invoices/issue` which 05-09 implements (intentional, per plan).

## Threat Flags

None. T-05-40 to T-05-44 mitigated: move through `AllocationService::update` (409 on locked, tested), CSRF 403 tested, `PreviewInput::validate` with 422, preview writes nothing and shows no number (tested by row counts and body), all output escaped and CSP view tests green. A move for a part not belonging to the submitted client returns 404.

## Self-Check: PASSED

All created files exist; commits fe4d0f2, 7623722 and c6515c8 are in git log.
