---
phase: 07-doru-enie-hrady-a-upomienky
plan: 12
subsystem: receivables-ui
tags: [overdue, dashboard, invoice-list, status-filter, clock]
requires: [07-09, 07-10]
provides:
  - InvoiceStatusQueries::overdue / countOverdue (Clock day, oldest due first)
  - InvoiceQueries::list status filter plus status, balance, overdue, days_overdue
  - Dashboard "Po splatnosti" section (max 10, show-all link, payment sheet action)
  - Invoice list status badges, remaining balance and Stav filter
affects: [07-13]
key-files:
  created:
    - app/Views/home/_overdue.php
    - tests/integration/Receivables/OverdueQueriesTest.php
    - tests/feature/Work/OverdueDashboardTest.php
    - tests/feature/Invoicing/InvoicesListStatusTest.php
  modified:
    - app/Libraries/Receivables/InvoiceStatusQueries.php
    - app/Libraries/Invoicing/InvoiceQueries.php
    - app/Controllers/Home.php
    - app/Controllers/Invoices.php
    - app/Views/home/_body.php
    - app/Views/home/index.php
    - app/Views/invoices/index.php
    - app/Views/invoices/_list.php
    - app/Language/sk/Dashboard.php
    - app/Language/en/Dashboard.php
    - app/Language/sk/Invoices.php
    - app/Language/en/Invoices.php
    - tests/_support/InvoiceFixtures.php
    - tests/integration/Invoicing/InvoiceQueriesTest.php
key-decisions:
  - "Overdue = due_date < CAST(:today: AS date) from service('clock')->today() and balance > 0; due day itself is not overdue"
  - "Status filter whitelisted in the controller; unknown value means no filter"
  - "Dashboard section hidden entirely when empty; full list always at /invoices?status=overdue"
requirements-completed: [PAY-02, INV-06]
duration: 40min
completed: 2026-10-29
---

# Phase 7 Plan 12: Overdue dashboard and invoice list statuses Summary

Dashboard now lists overdue invoices (oldest first, max 10, client filter respected) with a payment sheet action, and the invoice list shows status badges, remaining balance and a Stav filter, all with the "today" boundary taken from the Bratislava Clock.

## Tasks

1. Overdue queries and status filter with Clock day boundary - d6693ac
2. Dashboard section, invoice list statuses and filter, feature tests - a036f4d

## Verification

Full PHPUnit: 1551 tests, 23264 assertions, OK (run last, after cs fixes). JS tests: 58 pass. `composer stan` no errors, `composer cs` clean. No CURRENT_DATE/now() in the two query classes; no inline style/onclick in new views.

## Deviations from Plan

**1. [Rule 3 - Blocking] Fixture due_date override** - `InvoiceFixtures::insertIssuedInvoiceRaw` had a hardcoded due date; added a `due_date` override (default unchanged) so tests can place invoices around the boundary.

**2. [Minor] `InvoiceQueries::list` casts `id` to int** so callers and tests compare ids reliably. The filter form also has a `noscript` submit button and an extra `Invoices.filterApply` key.

## Known Stubs

None. The "Upomienka" button and "last reminder" display are deferred to 07-13 as planned.

## Threat Flags

None beyond the plan threat model (T-07-52 whitelist plus binds, T-07-53 Clock day test around midnight, T-07-54 `esc()`, T-07-55 only `return_to=/` sent).

## Self-Check: PASSED

Created files exist; commits d6693ac and a036f4d exist.
