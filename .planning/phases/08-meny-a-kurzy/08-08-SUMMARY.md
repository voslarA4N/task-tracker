---
phase: 08-meny-a-kurzy
plan: 08
subsystem: fx
tags: [fx, settings, audit, revision, cur-05]
requires: [08-04]
provides:
  - "FxSettingsService::updateAll (atomic, FOR UPDATE, revision, audit fx_settings)"
  - "FxSettingsRejected (invalid|stale|not_found)"
  - "GET/POST /settings/fx (Settings\\FxSettings) with PRG, 422, 409, HX-Location"
  - "Language FxSettings.php sk/en, links from /more and legal texts"
affects: [08-09, 08-10]
key-files:
  created:
    - app/Libraries/Fx/FxSettingsRejected.php
    - app/Controllers/Settings/FxSettings.php
    - app/Views/settings/fx/index.php
    - app/Language/sk/FxSettings.php
    - app/Language/en/FxSettings.php
    - tests/integration/Fx/FxSettingsServiceTest.php
    - tests/feature/Settings/FxSettingsTest.php
  modified:
    - app/Libraries/Fx/FxSettingsService.php
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
    - app/Views/settings/legal_texts/index.php
    - tests/_support/FxFixtures.php
key-decisions:
  - "All three countries saved in one transaction; only changed rows bump revision and are audited"
  - "On 409 the form re-renders with current DB values and fresh revisions so the retry works"
  - "restoreFxSettings fixture now also resets revision to 1"
requirements-completed: []
duration: ~20min
completed: 2026-10-30
---

# Phase 8 Plan 08: Fx settings page Summary

Owner can change rate source, date basis and day rule per country (SK, PL, CZ) at /settings/fx, with optimistic locking (409), whitelist validation (422) and audit_log entries in the same transaction.

## Deviations from Plan
None of substance. Tests were written together with implementation. A dynamic lang key in the view tripped LangKeysTest and was replaced with a static map.

## Known Stubs
None.

## Self-Check: PASSED
