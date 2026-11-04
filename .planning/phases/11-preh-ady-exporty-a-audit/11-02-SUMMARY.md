---
phase: 11-preh-ady-exporty-a-audit
plan: 02
subsystem: invoicing-ui
tags: [invoices, list, table, filters, sorting, css, ui-03]
requires: []
provides:
  - InvoiceFilter (whitelisted filter and sort)
  - InvoiceQueries::list with filter, sort whitelist, limit null, entity_id/turnover_date/delivery_date/last_paid_on
  - Desktop invoice table in the same htmx fragment as mobile cards
  - Layout flag mainWide (.app-main--wide) and phase 11 CSS classes
affects: [11-04, 11-06, 11-08, 11-09, 11-10, 11-11]
tech-stack:
  added: []
  patterns: [dt-only-sm/dt-only-lg dual rendering in one fragment, server-side sort via SORT_SQL map]
key-files:
  created:
    - app/Libraries/Invoicing/InvoiceFilter.php
    - app/Views/invoices/_status_badges.php
    - tests/unit/Invoicing/InvoiceFilterTest.php
    - tests/feature/Invoicing/InvoicesListTableTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Controllers/Invoices.php
    - app/Views/invoices/index.php
    - app/Views/invoices/_list.php
    - app/Views/layouts/app.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
    - public/assets/css/app.css
key-decisions:
  - "ORDER BY only via private const SORT_SQL map plus ASC/DESC, all filter values bound; default sort keeps the previous ORDER BY."
  - "Hidden sort/dir inputs live inside #invoice-list with form=inv-filter so a filter change keeps the sort."
requirements-completed: [UI-03]
duration: ~35 min
completed: 2026-11-04
---

# Phase 11 Plan 02: Invoice table on desktop Summary

`/invoices` now renders a sortable, filterable table on PC (>= 992px) and the existing cards on mobile from one htmx fragment and one query, plus the whole phase 11 CSS set so later plans do not touch `app.css`.

## Tasks
1. RED tests (7154c93): InvoiceFilterTest, InvoicesListTableTest.
2. Filter and query (ec0ffb0): `InvoiceFilter`, `InvoiceQueries::list(?int $limit, ?status, ?today, ?InvoiceFilter)` with `limit = null` for exports and extra columns `entity_id`, `turnover_date`, `delivery_date`, `last_paid_on`.
3. UI (6ead8a3): controller, index/_list/_status_badges views, sk/en lang, `app-main--wide` layout support, CSS (`.work-table`, `.dt-*`, `.rpt-*`, `.aud-*`, `.w-lg-auto`). This also gives 11-01's report screens (`mainWide => true`) their layout and table classes.

## Verification
- phpunit tests/feature/Invoicing, InvoiceFilterTest, tests/unit/I18n, ViewsCspTest, LayoutTest: OK (222 tests, 927 assertions).
- InvoicesListTableTest + InvoiceFilterTest: OK (19 tests, 59 assertions).
- PHPStan: no errors. php-cs-fixer applied.
- Human check still pending on staging (PC table, iPhone cards).

## Deviations from Plan

**1. [Rule 1 - Test fixture] Amounts via allocations.** `insertIssuedInvoiceRaw` rejects a `total` override that differs from the line sum (`invoice_complete_check`), so the table test varies totals through allocation rate/amount instead.

**2. [Rule 3 - Lint] Date validation via clock service.** PHPStan `app.forbiddenTime` forbids `DateTimeImmutable::createFromFormat`; `InvoiceFilter` uses `service('clock')->parseDate()` plus a round-trip check (as the plan intended).

## Known Stubs
None.

## Threat Flags
None. T-11-06 (SQLi) mitigated by the SORT_SQL map and bound values; T-11-07 by `http_build_query` and `esc(..., 'attr')`.

## Self-Check: PASSED
