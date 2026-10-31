---
phase: 09-dobropisy
plan: 09
subsystem: invoicing-ui
tags: [credit-note, views, invoice-detail, invoice-list]
requires: [09-06, 09-07, 09-08]
provides:
  - "Vystaviť dobropis" entry on own non-cancelled invoice detail
  - cancelled invoice detail (badge, banner, read-only payments, no pay/remind)
  - credit note detail (badges, banner, correction type/reason/scope, negative lines, no report/payments)
  - invoice list badges, corrects/credit note hints, filters Stornovaná and Dobropis
  - app_notice link rendering in layout (released parts link from 09-06)
affects: [09-11]
key-files:
  modified:
    - app/Views/invoices/show.php
    - app/Views/invoices/_payments.php
    - app/Views/invoices/_list.php
    - app/Views/invoices/index.php
    - app/Views/layouts/app.php
    - app/Controllers/Invoices.php
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
  created:
    - tests/feature/Invoicing/CreditNoteDetailTest.php
    - tests/feature/Invoicing/CreditNoteListTest.php
key-decisions:
  - "Credit note hours sum comes from snapshot totals.hours (Hours::plus does not support negatives)"
  - "Credit notes carry status cancelled in the status view, so isCreditNote is evaluated before cancelled everywhere"
requirements-completed: [INV-07, INV-10]
duration: 35min
completed: 2026-11-01
---

# Phase 9 Plan 09: Credit note visibility in detail and list Summary

Invoice detail offers "Vystaviť dobropis" for own non-cancelled invoices, cancelled invoices show a banner with no payment actions, credit notes render a full detail with correction data, and the list shows badges and filters.

## Tasks

1. Detail (show.php, _payments.php, controller dates, lang): RED `718a66e`, GREEN `9d6f05f`.
2. List and filter: GREEN `925f5ab` (RED tests shared in `718a66e`).
3. Extra: layout renders the `link` key of the `app_notice` flash (`feat(09-09)` commit after `925f5ab`).

## Verification

- PHPUnit filter CreditNote|InvoiceDetail|InvoicesList|InvoiceFxDisplay|PaymentsTest|ReminderTest|ViewsCsp|ViewsHardcodedText|LangKeys|InvoiceQueries: 184 tests OK.
- `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 3 - Blocking] `sent_at` added to InvoiceQueries::list select**
- List needs it to show "Odoslaný" vs "Vystavený" on credit notes. File: app/Libraries/Invoicing/InvoiceQueries.php.

**2. [Rule 2 - Missing] Layout renders flash `link`**
- 09-06 sets a link in the flash that nothing rendered; added a same-origin-only (`/`-prefixed) link in layouts/app.php.

**3. Test adjustments**
- Invoices are immutable in DB, so overdue state in tests is produced by advancing the FrozenClock (with session `last_activity` refreshed) instead of updating `due_date`.

## Known Stubs

None.

## Self-Check: PASSED
