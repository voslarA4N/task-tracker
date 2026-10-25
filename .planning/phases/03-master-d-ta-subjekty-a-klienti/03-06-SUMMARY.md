---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 06
subsystem: master-data
tags: [entities, edit, end, immutability, grants, audit, phpunit]
requires: [03-04, 03-05]
provides:
  - EntityService::updateBase and end (audited, one transaction)
  - EntityInput::baseUpdateFromPost, validateBaseUpdate, hasImmutableKeys
  - Entities::edit/update/end, edit screen, end modal on detail
  - 422 on forged country/currency in update, end and new version
  - MasterRolesTest (summary of master table grants)
affects: [phase-04, phase-05]
tech-stack:
  added: []
  patterns: [forged-key rejection before validation, column-grant-only UPDATE]
key-files:
  created:
    - app/Views/settings/entities/edit.php
    - app/Views/settings/entities/_end_modal.php
    - tests/integration/Master/MasterRolesTest.php
    - tests/feature/Master/EntityEditTest.php
    - tests/feature/Master/EntityImmutableTest.php
  modified:
    - app/Libraries/Master/EntityService.php
    - app/Libraries/Master/EntityInput.php
    - app/Controllers/Settings/Entities.php
    - app/Controllers/Settings/EntityVersions.php
    - app/Config/Routes/entities.php
    - app/Views/settings/entities/show.php
    - app/Views/settings/entities/version_new.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
    - tests/feature/Master/EntityVersionsTest.php
key-decisions:
  - "active_from may not be earlier than the first version's valid_from (RESEARCH Pattern 2), not later as UI-SPEC text says"
  - "Forged country/currency is rejected with 422 on update, end and new version (previously new version silently ignored them)"
requirements-completed: [ENT-04, ENT-07]
duration: 30min
completed: 2026-10-25
---

# Phase 3 Plan 06: Entity Edit, End and Immutability Summary

Owner can rename an entity and change Active from / Active to without creating a version (audited with only changed fields), end an entity via a date modal (it stays listed and selectable; clearing Active to cancels the end), and any POST carrying country or currency is rejected with 422 and no DB change. MasterRolesTest proves app_rw grants on all master tables.

## Tasks
1. RED tests: 6888802
2. Service, input, controller, views, routes, translations: 2ffbded

## Deviations from Plan

**1. [Rule 1 - Bug] Existing test superseded.** `EntityVersionsTest::testCountryCurrencyInPostIgnored` expected a forged currency to be ignored (302); the plan now requires 422, so it was renamed `...Rejected` and expects 422. The detail-page assertion `assertStringNotContainsString('/edit')` was narrowed to `versions/\d+/edit` since the detail now links to the entity edit screen.

**2. Version form error banner.** `version_new.php` shows `$errors['_form']` first so the immutability message appears there.

**3. Translation key.** Added `errorImmutable` alongside the existing `errorCountryCurrencyFixed`; `activeFromLaterHint` already existed from 03-02.

## Known Stubs
None.

## Threat Flags
None. T-03-32 to T-03-36 covered (hasImmutableKeys, whitelist, column grants, CSRF field in modal, audit in same transaction, ended entity remains in forSelect).

## Verification
Full suite 493 tests green, `composer stan` clean, `composer cs` clean.

## Self-Check: PASSED
Created files exist; commits 6888802 and the feat(03-06) commit exist.
