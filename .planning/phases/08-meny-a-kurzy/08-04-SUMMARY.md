---
phase: 08-meny-a-kurzy
plan: 04
subsystem: fx
tags: [fx, cache, nbp, ecb, cnb, postgres]
requires: [08-01, 08-02, 08-03]
provides:
  - "fx_country_settings (seed D-05) + FxSettingsService read API"
  - "exchange_rate_cache keyed source+currency+bound_date, final rows immutable"
  - "FxService::context/resolve/cached, FxContext, UnsupportedPair"
  - "service('rateHttpClient'), tests/_support/FxFixtures"
affects: [08-05, 08-06, 08-08, 08-10]
key-files:
  created:
    - app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php
    - app/Database/Migrations/2026-10-30-000004_CreateExchangeRateCache.php
    - app/Libraries/Fx/FxSettingsService.php
    - app/Libraries/Fx/FxContext.php
    - app/Libraries/Fx/UnsupportedPair.php
    - app/Libraries/Fx/FxService.php
    - tests/_support/FxFixtures.php
    - tests/integration/Fx/FxSchemaTest.php
    - tests/integration/Fx/FxServiceCacheTest.php
  modified:
    - app/Config/Services.php
    - tests/_support/MasterFixtures.php
key-decisions:
  - "Cache keyed by bound_date (not valid_for); valid_for stored as data"
  - "Single quoteFromRow mapper for resolve and cached so previews and issuing share byte-identical core()"
  - "Negative cache 60 s via CI4 cache(), retry flag deletes the key; stale non-final row is returned on outage"
  - "Upsert guarded by WHERE bound_date >= today so final rows are never overwritten; app_rw has no DELETE"
requirements-completed: []
duration: ~25min
completed: 2026-10-30
---

# Phase 8 Plan 04: FxService and rate cache Summary

Per-country FX settings (PL NBP, SK ECB, CZ CNB) and a DB cache feed FxService, which resolves a rate through the right provider, caches it by bound date and can read it back without network.

Commits: `6dece66` (migrations, settings service, fixtures, schema test), `385288d` (FxService, rateHttpClient service, cache tests). Tests were written together with implementation (no separate RED commits). 18 new integration tests.

## Deviations from Plan
None of substance. In one test (issue_date basis) an inline NBP body is used instead of the shared fixture, because the fixture's last element has valid_for 2026-10-29, which violates the cache CHECK valid_for <= bound_date for bound 2026-10-26. `make stan` needed `build/` ownership fixed to www-data (environment, not code).

## Known Stubs
None.

## Self-Check: PASSED
Full `make test`: OK (1680 tests, 24151 assertions); `make stan` OK.
