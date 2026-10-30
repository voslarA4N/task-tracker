---
phase: 08-meny-a-kurzy
plan: 10
subsystem: invoicing
tags: [fx, issuer, audit, check-constraint]
requires: [08-04, 08-05]
provides:
  - "InvoiceIssuer reads the rate only from cache (FxService::cached), no network in the transaction"
  - "invoices.fx_* columns and total_entity_ccy filled from snapshot fx; audit issue carries fx_source, fx_rate, fx_manual, total_entity_ccy"
  - "InvoiceGuards::assertFx (fx_missing); CHECK invoices_fx_pair_check and invoices_fx_total_check"
affects: [08-06, 08-07]
key-files:
  created:
    - app/Database/Migrations/2026-10-30-000005_AddInvoiceFxChecks.php
    - tests/integration/Invoicing/InvoiceIssuerFxTest.php
  modified:
    - app/Libraries/Invoicing/InvoiceGuards.php
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - tests/_support/IssueFixtures.php
    - tests/integration/Invoicing/CurrencyGuardTest.php
key-decisions:
  - "Issuer no longer calls assertCurrency; currency_mismatch is gone from the issuer, replaced by fx_missing (assertCurrency stays for the draft/preview service until 08-06)"
  - "CHECK constraints verified by applying their catalog definitions to a temp table, because invoices is insert-only behind guard triggers"
requirements-completed: []
duration: ~25min
completed: 2026-10-30
---

# Phase 8 Plan 10: Issuing with FX rate Summary

The issuer resolves currency context and a cached quote inside the transaction (no `resolve()`, zero HTTP calls), rejects a foreign currency without a rate as `fx_missing`, passes the quote to SnapshotBuilder before the preview token comparison (so a rate change gives `preview_stale`), and writes fx columns, `total_entity_ccy` and the audit fields. DB CHECKs keep fx columns consistent.

Commits: `d265222` (migration + assertFx), `25bf912` (issuer, fixtures, tests). 7 new tests (6 issuer/fx, 1 guard) plus reworked CurrencyGuardTest case.

## Deviations from Plan
- Tests were written together with the implementation, no separate RED commits.
- Audit keys are named `fx_source`, `fx_rate` (rate_display), `fx_manual`, `total_entity_ccy` as planned; `total_entity_ccy` is also recorded for same-currency invoices (equals total).
- php-cs-fixer realigned array arrows in InvoiceIssuer (cosmetic diff).

## Known Stubs
None.

## Self-Check: PASSED
Targeted tests, `make stan` OK; full `make test`: OK (1712 tests, 24299 assertions).
