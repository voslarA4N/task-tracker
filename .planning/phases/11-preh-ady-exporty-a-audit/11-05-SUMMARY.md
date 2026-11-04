---
phase: 11-preh-ady-exporty-a-audit
plan: 05
subsystem: audit
tags: [audit, viewer, read-only, htmx, aud-02]
requires: [11-01, 11-02]
provides:
  - AuditFilter, AuditQueries, AuditDiff
  - GET /settings/audit (list, filters, paging) and GET /settings/audit/{id} (before/after detail)
  - Viac menu entry "Denník zmien"
affects: [11-12]
tech-stack:
  added: []
  patterns: [dynamic DISTINCT filter lists with lang labels and raw-key fallback, htmx fragment + sheet/page detail]
key-files:
  created:
    - app/Libraries/Audit/AuditFilter.php
    - app/Libraries/Audit/AuditQueries.php
    - app/Libraries/Audit/AuditDiff.php
    - app/Controllers/Settings/Audit.php
    - app/Views/settings/audit/index.php
    - app/Views/settings/audit/_list.php
    - app/Views/settings/audit/_detail.php
    - app/Views/settings/audit/_detail_sheet.php
    - app/Views/settings/audit/show.php
    - app/Language/sk/AuditLog.php
    - app/Language/en/AuditLog.php
    - tests/unit/Audit/AuditDiffTest.php
    - tests/integration/Audit/AuditQueriesTest.php
    - tests/feature/Settings/AuditViewerTest.php
  modified:
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
key-decisions:
  - "Entity and action filter options come from SELECT DISTINCT on audit_log; labels via lang with raw-key fallback (orchestrator decision 3)."
  - "Date range is day-inclusive in the app timezone via AT TIME ZONE; all values bound, page capped at 10000."
requirements-completed: [AUD-02]
duration: ~30 min
completed: 2026-11-04
---

# Phase 11 Plan 05: Audit log viewer Summary

Read-only `/settings/audit` for the admin: newest-first audit_log with entity/action/user/from/to filters (htmx, URL-persisted), 50 per page with Novšie/Staršie, and a before/after detail with changed fields highlighted (sheet via htmx, full page without JS).

## Tasks
1. AuditDiff, AuditFilter, AuditQueries with tests (08b07be).
2. Controller, 2 GET routes, 5 views, sk/en lang, menu link, feature tests (c72e685).

## Verification
- Audit unit + integration + AuditViewerTest + I18n + ViewsCspTest: OK (24 tests, 93 assertions).
- tests/feature + tests/unit: OK (1394 tests, 19670 assertions).
- PHPStan (incl. views): no errors. php-cs-fixer applied.
- Human check pending on staging (filters, iPhone sheet, PC).

## Deviations from Plan

**1. [Rule 1 - Test harness] 404 and missing-route tests use expectException.** CI4 FeatureTest rethrows PageNotFoundException instead of returning a 404 response, so unknown ID and POST-without-route are asserted via `PageNotFoundException`.

**2. [Rule 1 - Test naming] Helper named `entry()`** because `put()` collides with the `AuthTestCase` method.

**3. Clear-filter link is a plain link** (full navigation) rather than htmx, to keep the filter form state consistent.

## Known Stubs
None.

## Threat Flags
None. T-11-14..17 mitigated (admin-only via AuthGate with 403 test, esc() everywhere with an XSS test, whitelisted/bound filters, GET-only routes and no write SQL in AuditQueries).

## Self-Check: PASSED
