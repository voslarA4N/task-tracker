---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 04
subsystem: master-data
tags: [entities, versions, history, htmx, audit, phpunit]
requires: [03-03]
provides:
  - EntityService::newVersion (insert-only, audit before/after)
  - EntityVersionResolver::history/nextAfter/find
  - VersionDiff::changedFields, VersionRejected
  - Settings\EntityVersions controller (newForm, notice, create)
  - Version history section on entity detail
affects: [03-05, 03-06, phase-05]
tech-stack:
  added: []
  patterns: [insert-only versions, pre-transaction checks, unique violation mapped outside transaction, htmx notice fragment]
key-files:
  created:
    - app/Libraries/Master/VersionDiff.php
    - app/Libraries/Master/VersionRejected.php
    - app/Controllers/Settings/EntityVersions.php
    - app/Views/settings/entities/version_new.php
    - app/Views/settings/entities/_version_notice.php
    - app/Views/settings/entities/_history.php
    - tests/unit/Master/VersionDiffTest.php
    - tests/integration/Master/EntityVersionTest.php
    - tests/feature/Master/EntityVersionsTest.php
  modified:
    - app/Libraries/Master/EntityService.php
    - app/Libraries/Master/EntityVersionResolver.php
    - app/Controllers/Settings/Entities.php
    - app/Config/Routes/entities.php
    - app/Views/settings/entities/show.php
    - app/Views/settings/entities/_regime_fields.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
key-decisions:
  - "_regime_fields got a versionMode flag that hides the currency select; currency is shown as text in version_new"
  - "History field labels are built in Entities::fieldLabels with literal lang() keys (LangKeysTest)"
requirements-completed: [ENT-03]
duration: 25min
completed: 2026-10-25
---

# Phase 3 Plan 04: Entity Versions Summary

Owner changes an entity address via "New data version" with a mandatory "valid from" date. A new insert-only version is created and audited with before/after values, older versions stay untouched, and the detail page shows the history (newest first) with Valid today / Future badges and highlighted changed fields. "New version from this one" prefills a chosen version for typo fixes.

## Tasks
1. RED tests: edfb346
2. newVersion, history, VersionDiff: 31f11bf
3. Controller, views, routes, translations: a970b06

## Deviations from Plan

**1. [Rule 3] `_regime_fields.php` modified** (not in the plan's file list) to add a `versionMode` flag that hides the currency select, as the plan requires "VAT regime only" on the version form.

**2. Extra translation keys** beyond the plan's list: `versionNewTitle`, `cancel`, `historyFrom`, `historyChanged`, `historyShow` (sk and en).

Otherwise the plan was executed as written.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-03-18 to T-03-23 covered: no edit/delete route, whitelist without country/currency, find() scoped by entity_id with 404 test, duplicate date to 422, audit in the same transaction, esc() output).

## Verification
Full suite 436 tests green, `composer stan` clean, `composer cs` clean.

## Self-Check: PASSED
Commits edfb346, 31f11bf, a970b06 exist; created files present.
