---
phase: 10-limity-obratu
plan: 02
subsystem: reporting
tags: [limits, turnover, postgres, migration, seed, fallback]
requires: [10-01]
provides:
  - turnover_limits and entity_opening_turnover tables (seed, grants, invoices_turnover_idx)
  - App\Libraries\Reporting\LimitSelector (forCountry with year fallback, parseThresholds)
  - App\Libraries\Reporting\EntityLimits (readonly VO, strictest, alerts)
  - App\Libraries\Reporting\TurnoverCalculator (turnover, opening, forEntity, forEntityId, forActiveEntities)
  - tests/_support/TurnoverFixtures trait
affects: [10-03, 10-04, 10-05, 10-06, 11]
tech-stack:
  added: []
  patterns: [thresholds read via array_to_string and written via string_to_array, single SUM over denormalized invoice columns]
key-files:
  created:
    - app/Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php
    - app/Libraries/Reporting/LimitSelector.php
    - app/Libraries/Reporting/EntityLimits.php
    - app/Libraries/Reporting/TurnoverCalculator.php
    - tests/_support/TurnoverFixtures.php
    - tests/integration/Reporting/LimitSchemaTest.php
    - tests/integration/Reporting/LimitSelectorTest.php
    - tests/integration/Reporting/TurnoverCalculatorTest.php
  modified:
    - app/Libraries/Reporting/LimitMath.php
    - tests/_support/InvoiceFixtures.php
    - tests/_support/MasterFixtures.php
key-decisions:
  - "A2: thresholds read as array_to_string(thresholds, ',') AS thresholds_csv and written via CAST(string_to_array(:t:, ',') AS integer[]); no driver array-format dependency"
  - "A1: DB CHECK covers cardinality 1-3 and no NULL; element range 1-200 is left to LimitInput (10-04)"
  - "A5: fallback = nearest lower year with rows for the country, else nearest higher; usedYear/fallbackFromYear carry it"
  - "is_seed column and invoices_turnover_idx are part of the schema; seed inserted only when turnover_limits is empty"
  - "Granted UPDATE on listed columns plus DELETE on both new tables to app_rw (settings, not documents); no REVOKE INSERT"
requirements-completed: [LIM-01, LIM-02, LIM-03, LIM-05, CUR-04]
duration: 40min
completed: 2026-11-02
---

# Phase 10 Plan 02: Turnover data model and calculator Summary

Turnover limits now live in the DB (4 unverified seed proposals) and `TurnoverCalculator` turns the stored `total_entity_ccy`, `turnover_date` and opening turnover into a `LimitStatus` per limit, with year fallback, currency-mismatch error and pro-rata limit, never reading snapshots or exchange rates.

## Tasks

| Task | Commit |
|------|--------|
| 1 Failing tests and fixtures (RED) | 5b2a25e |
| 2 Migration, seed, grants, index + LimitSchemaTest | c02f0a7 |
| 3 LimitSelector, EntityLimits, TurnoverCalculator (GREEN) | 6b5aca9 |

## Verification

- `phpunit tests/integration/Reporting tests/unit/Reporting`: 48 tests OK.
- `composer test`: 2002 tests OK (no fixture regression); `tests/integration/Invoicing` and `Work` green after the `InvoiceFixtures` change; TimezoneConsistencyTest green.
- `phpstan analyse app/Libraries/Reporting tests/integration/Reporting tests/_support`: no errors in touched code. A whole-project PHPStan run in the container failed only with "Could not write data to cache file" (build/phpstan cache permissions while composer test ran in parallel); not caused by this plan.
- Acceptance greps: no `snapshot|exchange_rate_cache|FROM clients|cancelled|CURRENT_DATE|date(|(float)` in TurnoverCalculator; limit amounts (49790, 62500, 240000) only in the migration.

## Deviations from Plan

**1. [Rule 3 - Blocking] bcmath forbidden outside the allow-list.** The sort in `TurnoverCalculator` and the test helpers needed bcmath comparison/division. Instead of widening `ForbiddenCallsRule`, added `LimitMath::compareRatio()` (LimitMath is already allow-listed) and replaced bcdiv in `TurnoverFixtures` with integer-only `hoursForAmount()`. Commit 6b5aca9.

**2. [Rule 3 - Blocking] Fixture shape.** `billing_month` is `char(7)` (`YYYY-MM`), so fixtures use that format. `invoices_fx_total_check` requires fx_rate when `total_entity_ccy != total`, so `insertIssuedInvoiceRaw` also gained optional `fx_rate`, `fx_source`, `fx_date` overrides (defaults null, existing behaviour unchanged). Test classes that use `CreditNoteFixtures` also use `IssueFixtures` (for `uuid4()`).

**3. [Rule 1 - Test hygiene]** `LimitSelectorTest` re-instantiates the selector for the second call to avoid a PHPStan pure-call narrowing false positive.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (all SQL parametrized; year list built from `(int)`; every turnover query filters `entity_id`).

## Self-Check: PASSED
All created files and commits 5b2a25e, c02f0a7, 6b5aca9 verified present.
