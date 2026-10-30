---
phase: 08-meny-a-kurzy
plan: 09
subsystem: invoicing
tags: [fx, invoice-detail, invoice-list, immutability, staging-seed]
requires: [08-05, 08-07, 08-10]
provides:
  - "Invoice detail shows 'Spolu v {entity currency}', Kurz / Zdroj kurzu / Datum kurzu rows, manual badge and original fetched rate, all from invoice columns and snapshot"
  - "Invoice list shows a second total (.fx-sub) only for invoices with a rate"
  - "InvoiceFxImmutableTest proves cache and settings changes do not touch an issued invoice or its PDF"
  - "Staging seed issues PL entity EUR invoices with an NBP rate seeded into exchange_rate_cache (no network)"
affects: []
key-files:
  created:
    - tests/feature/Invoicing/InvoiceFxDisplayTest.php
    - tests/integration/Invoicing/InvoiceFxImmutableTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Views/invoices/show.php
    - app/Views/invoices/_list.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
    - app/Libraries/StagingSeed/Catalog.php
    - app/Libraries/StagingSeed/Profile.php
    - app/Libraries/StagingSeed/Modules/Invoices.php
    - tests/integration/StagingSeed/StagingSeederTest.php
    - tests/unit/StagingSeed/HistoryPlannerTest.php
key-decisions:
  - "List query joins invoices (fx columns are not in invoice_status_v) and LEFT JOINs entities for the entity currency; external invoices have no rate so nothing is shown"
  - "Staging: existing client pl_alpen (CH) becomes EUR (Catalog 'currency' override) and the small profile gains it; every seeded foreign-currency invoice gets a fixed 4.2512 NBP row in exchange_rate_cache before draft/subset token"
requirements-completed: []
duration: ~40min
completed: 2026-10-30
---

# Phase 8 Plan 09: Invoice FX display, immutability, staging seed Summary

Detail and list of invoices now show the entity-currency total and the rate (expression, source with table number, date and basis, manual badge with the original fetched rate) strictly from invoice columns and the snapshot; a test proves later cache/settings changes leave the invoice, snapshot and PDF bytes unchanged; the staging seed produces PL/EUR invoices without any live API call.

Commits: `54c8a0a` (display + immutability test), `2e0dd96` (staging seed).

## Deviations from Plan
- Tests written together with implementation (no separate RED commits).
- Seed: instead of adding a new client, the existing `pl_alpen` client was switched to EUR and added to the small profile; HistoryPlannerTest updated (client currency may differ from entity currency, small profile has 4 clients). Doc language of that client is en, so the seeded snapshot rate line is English ("Exchange rate NBP 1 EUR = 4.2512 PLN").
- The list query needed a JOIN to `invoices` because `invoice_status_v` lacks fx columns.

## Known Stubs
None.

## Self-Check: PASSED
Targeted tests, `make stan` OK; full `make test`: OK (1770 tests, 24567 assertions).
