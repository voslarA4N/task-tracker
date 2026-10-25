---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 02
subsystem: master-data
tags: [entities, versions, invoice-series, htmx, grants, phpunit]
requires: [03-01]
provides:
  - entities, entity_versions (insert-only), invoice_series tables
  - EntityService::create (one transaction with audit)
  - EntityVersionResolver::atDate/current
  - EntityInput whitelist and validation
  - Settings\Entities controller (new, regimeFields, create, show)
  - Clock::parseDate strict date parser
affects: [03-03, 03-04, 03-05, 03-06]
tech-stack:
  added: []
  patterns: [column-level GRANT without country/currency, insert-only versions, htmx fragment for country dependent fields]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000002_CreateEntities.php
    - app/Libraries/Master/Country.php
    - app/Libraries/Master/Currency.php
    - app/Libraries/Master/DocLanguage.php
    - app/Libraries/Master/VatRegime.php
    - app/Libraries/Master/EntityInput.php
    - app/Libraries/Master/EntityService.php
    - app/Libraries/Master/EntityVersionResolver.php
    - app/Libraries/Master/VersionNotFound.php
    - app/Controllers/Settings/Entities.php
    - app/Config/Routes/entities.php
    - app/Views/settings/entities/new.php
    - app/Views/settings/entities/_identity_fields.php
    - app/Views/settings/entities/_regime_fields.php
    - app/Views/settings/entities/show.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
    - tests/unit/Master/EntityInputTest.php
    - tests/integration/Master/EntityServiceTest.php
    - tests/feature/Master/EntitiesCreateTest.php
  modified:
    - app/Libraries/Platform/Clock.php
    - app/Views/layouts/app.php
    - public/assets/css/app.css
    - tests/_support/MasterFixtures.php
key-decisions:
  - "country and currency are excluded from app_rw column grants, so immutability is enforced by the DB"
  - "Strict date parsing lives in Clock::parseDate because the PHPStan rule forbids DateTimeImmutable outside Clock"
  - "EntityService resets transStatus after a failed transaction so the shared connection stays usable"
requirements-completed: [ENT-01, ENT-02, ENT-05, ENT-07]
duration: 45min
completed: 2026-10-25
---

# Phase 3 Plan 02: Entities Summary

Owner can create an SK (EUR, non-VAT) or PL (PLN, art. 113 ust. 1) entity at `/settings/entities/new`. One transaction writes the entity, its first version (`valid_from = active_from`), the invoice series `RRRRMM###` and credit note series `DB-RRRRMM###`, plus an audit row for each. The detail page shows country and currency as text only, and `app_rw` cannot change them in the DB.

## Tasks
1. RED tests: df498f1
2. Migration, enums, EntityInput, EntityService, EntityVersionResolver: f6259cd
3. Controller, routes, views, CSS, flash type: 7047994

## Deviations from Plan

**1. [Rule 1 - Bug] `count` and `post` helper names clashed with PHPUnit/FeatureTestTrait** - renamed to `rows` and `submit` in the tests (fixed in f6259cd, 7047994).

**2. [Rule 1 - Bug] Failed transaction left `transStatus = false` on the shared connection.** The next `create()` silently rolled back. Added `resetTransStatus()` in the catch block of `EntityService::create` (f6259cd). The same latent pattern exists in `AccountRecovery` (not touched, out of scope).

**3. [Rule 3 - Blocking] `AuditLogger` is `final`, so the rollback test could not subclass it.** The test now makes the third audit insert fail through a temporary DB trigger on `audit_log`, which exercises a real rollback and needs no production change.

**4. [Rule 3 - Blocking] PHPStan `forbiddenTime` rule rejects `DateTimeImmutable::createFromFormat` outside Clock.** Added `Clock::parseDate()` (the allowed file) and used it in `EntityInput` and the controller (7047994, outside the plan's file list: `app/Libraries/Platform/Clock.php`).

**5. Fixture note:** `createEntity` accepts null overrides, and `EntityService::create` treats `''` as null for optional fields.

php-cs-fixer reports 2 pre-existing files from plan 01 (`AuditLoggerTest`, `DbBoolTest`); left untouched as out of scope.

## Known Stubs
None. Edit, new-version, bank account and series screens belong to later plans (03-03 to 03-06).

## Threat Flags
None beyond the plan's threat model (T-03-06 to T-03-12 are covered by tests).

## Verification
Full suite 403 tests green, `composer stan` clean, ViewsCspTest and I18n tests green.

## Self-Check: PASSED
Files and commits df498f1, f6259cd, 7047994 exist.
