---
phase: 05-faktura-n-jadro
plan: 05
subsystem: invoicing
tags: [draft, guards, currency, backdate, series-lock]
requires:
  - phase: 05-03
    provides: InvoiceIssuer, SnapshotBuilder, InvoiceCalculator, InvoiceParts, IssueFixtures
provides:
  - InvoiceGuards (partsCurrency, assertCurrency, assertEntityActive, lastIssue, backdate)
  - InvoiceDraftService and Draft (read-only preview identical to issue)
  - SeriesService::isFormatLocked backed by the invoices table
affects: [05-06, 05-07, 05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - currency guard runs before SnapshotBuilder::build so bank account lookup cannot mask currency_mismatch
    - backdate check runs after the series row lock
key-files:
  created:
    - app/Libraries/Invoicing/InvoiceGuards.php
    - app/Libraries/Invoicing/InvoiceDraftService.php
    - app/Libraries/Invoicing/Draft.php
    - tests/integration/Invoicing/CurrencyGuardTest.php
    - tests/integration/Invoicing/BackdateTest.php
    - tests/integration/Invoicing/InvoiceDraftServiceTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - app/Libraries/Master/SeriesService.php
    - tests/integration/Master/SeriesServiceTest.php
key-decisions:
  - "Draft ignores invalid GET dates and falls back to defaults instead of failing"
  - "Draft vat code is resolved from the legal profile and is null when it cannot be determined"
requirements-completed: [INV-01, INV-02, INV-04, INV-15, NFR-06]
duration: ~35min
completed: 2026-10-27
---

# Phase 5 Plan 05: Draft preview and server guards Summary

Server now rejects foreign currency, ended entities and unconfirmed backdated issue dates, `InvoiceDraftService` shows exactly what would be issued without writing anything, and a series format locks after its first invoice.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | InvoiceGuards and issue guards (currency, inactive entity, backdate) | d693a5c |
| 2 | InvoiceDraftService, Draft, isFormatLocked | c7821d1 |

## Verification

Full PHPUnit suite green (991 tests, 6935 assertions); `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 3 - Blocking] Acceptance grep on InvoiceDraftService** - `grep -cE 'INSERT|UPDATE |Transaction::run|date\('` returns 1 because the required call `$this->guards->backdate(` contains the substring `date(`. There is no write, no transaction and no PHP `date()` call; the match is a false positive.

**2. SeriesService test** - the old `testNotLockedInPhaseThree` was replaced by `testFormatLockFollowsIssuedInvoices`, which issues a real invoice through `InvoiceIssuer` (instead of a raw external-invoice insert).

**3. TDD** - guard tests were written together with the implementation and passed on first run (no separate RED commit).

## Known Stubs

None.

## Threat Flags

None. T-05-26 to T-05-30 mitigated: backdate_unconfirmed after series lock, currency_mismatch before and after build, entity_inactive in issuer, isFormatLocked under series lock, draft has no writes (row counts tested).

## Self-Check: PASSED

All created files exist; commits d693a5c and c7821d1 are in git log.
