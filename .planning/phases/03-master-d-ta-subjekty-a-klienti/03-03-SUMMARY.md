---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 03
subsystem: master-data
tags: [entities, directory, list, lateral-join, phpunit]
requires: [03-02]
provides:
  - EntityDirectory::listAll/forSelect/activeAt
  - Settings\Entities::index and list view at /settings/entities
  - More -> Entities navigation link
affects: [03-04, 03-05, 03-06, phase-05]
tech-stack:
  added: []
  patterns: [single LATERAL query for list (no N+1), today date always from clock, ended entities never hidden]
key-files:
  created:
    - app/Libraries/Master/EntityDirectory.php
    - app/Views/settings/entities/index.php
    - tests/integration/Master/EntityDirectoryTest.php
    - tests/feature/Master/EntitiesListTest.php
  modified:
    - app/Controllers/Settings/Entities.php
    - app/Config/Routes/entities.php
    - app/Views/more/index.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
key-decisions:
  - "activeAt orders by active_from DESC, id DESC, so with equal active_from the newer id wins (D, E, A in the test data)"
  - "listAll sorts in PHP after one SQL query (status groups need the clock date)"
requirements-completed: [ENT-01, ENT-04]
duration: 20min
completed: 2026-10-25
---

# Phase 3 Plan 03: Entity List and Directory Summary

Owner opens More -> Entities and sees every entity with country, currency, VAT regime, period and an Active / Prepared / Ended badge. `EntityDirectory` provides `forSelect` (all entities, ended flagged, never hidden) and `activeAt` (entities active on a date, newest `active_from` first, overlap allowed) for later phases.

## Tasks
1. RED tests: 3892c38
2. EntityDirectory, list controller, view, More link, translations: 7b1e1e2
3. Extra cleanup `style(03-03): fix cs in plan 03-01 tests`: 6406ac1 (AuditLoggerTest, DbBoolTest formatted with php-cs-fixer)

## Deviations from Plan

**1. [Rule 1 - Spec inconsistency] activeAt order for equal active_from.** The plan's behavior text lists "D, A, E", but A and E share `active_from` 2026-01-01 and the stated ordering `active_from DESC, id DESC` yields D, E, A (E has the higher id). Implemented the stated SQL ordering and asserted D, E, A in the test. D is still first, which is what matters for the invoice prefill.

**2. Translation key additions** beyond the plan's list: `navTitle`, `listTitle`, `statusPrepared`, `emptyHeading`, `emptyBody`, `addButton`, `periodFrom` (sk and en).

## Known Stubs
None.

## Threat Flags
None. T-03-14 (esc on output), T-03-15 (single LATERAL query), T-03-16 (global AuthGate) are covered.

## Verification
Full suite 411 tests green, `composer stan` clean, `composer cs` clean (0 of 200 files).

## Self-Check: PASSED
Files and commits 6406ac1, 3892c38, 7b1e1e2 exist.
