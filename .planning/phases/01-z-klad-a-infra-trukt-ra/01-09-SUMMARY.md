---
phase: 01-z-klad-a-infra-trukt-ra
plan: 09
subsystem: persistence
tags: [postgres, money, htmx, insert-only, parity]
requires: [01-05, 01-08]
provides:
  - "demo_calculations insert-only table (CHECK amount = round(hours*rate,2)), owned by app_owner"
  - "DemoCalculationModel record/latest/totalAmount/sqlTotal"
  - "POST /demo/calculations, demo/_list, #demo-panel fragment"
  - "Money vs PG round(numeric,2) parity test on 2000 combinations"
affects: [01-10]
tech-stack:
  added: []
  patterns: ["insert-only table without grantMutable", "single swap target #demo-panel containing form and list"]
key-files:
  created:
    - app/Database/Migrations/2026-10-23-000001_CreateDemoCalculations.php
    - app/Models/DemoCalculationModel.php
    - app/Views/demo/_list.php
    - tests/integration/MoneyDbParityTest.php
    - tests/integration/DemoCalculationsTest.php
    - tests/feature/DemoPersistTest.php
  modified:
    - app/Controllers/Demo.php
    - app/Config/Routes.php
    - app/Views/demo/_calculator.php
    - app/Language/sk/Demo.php
    - app/Language/en/Demo.php
key-decisions:
  - "Demo controller shares validation (validates/postedValues) between calculate() and store(); every response passes rows and total"
requirements-completed: [NFR-04, UI-01]
duration: ~20 min
completed: 2026-10-23
---

# Phase 1 Plan 09: Money-DB parity and demo persistence Summary

Insert-only `demo_calculations` (numeric(7,2)/(12,2), CHECK on amount) written as app_rw through `DemoCalculationModel`, htmx list with total on `/demo`, and a 2000-combination test proving `Money` equals PostgreSQL `round(numeric, 2)`.

## Tasks
1. RED tests: cd30cc8
2. Migration and model: commit `feat(01-09): add insert-only demo_calculations table and model`
3. Controller, route, views, lang: commit `feat(01-09): persist demo calculations as app_rw`

## Verification
Full PHPUnit 92 tests OK, `composer check` green, `make smoke` SMOKE OK, `/demo` contains `id="demo-panel"`; app_rw privileges on table: UPDATE f, DELETE f, INSERT t.

## Deviations from Plan
- [Rule 1 - Bug] Test helper `count()` clashed with final `TestCase::count()`; renamed `rowCount()`.
- Feature test GET /demo after htmx POSTs sends `HX-Boosted: true` to obtain the full page (FeatureTestTrait keeps the HX-Request header between calls).
- Model `latest()` docblock typed `array<string, mixed>` to satisfy PHPStan.

## Known Stubs
None.

## Notes for later plans
- Untracked `tmp/` dir (schema.php) appeared in repo root from tooling; not committed.
- Human check (save 1,25x27 and 2x10,50, total 54.75 without reload, 375 px) deferred to 01-10.

## Self-Check: PASSED
