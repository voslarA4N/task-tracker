---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 07
subsystem: master-data
tags: [clients, archive, portal-url, htmx, xss, audit]
requires: [03-05]
provides:
  - PortalUrlTemplate validate/render (http/https, host, {id} exactly once, rawurlencode)
  - ClientService archive/unarchive with audit
  - Active/Archived filter, archive card with modal, live text-only portal preview
affects: [phase-04]
tech-stack:
  added: []
  patterns: [htmx fragment preview rendered as text only, archive via archived_at with audit before/after]
key-files:
  created:
    - app/Libraries/Master/PortalUrlTemplate.php
    - app/Views/settings/clients/_portal_preview.php
    - tests/unit/Master/PortalUrlTemplateTest.php
    - tests/integration/Master/ClientArchiveTest.php
    - tests/feature/Master/ClientsArchivePortalTest.php
  modified:
    - app/Libraries/Master/ClientInput.php
    - app/Libraries/Master/ClientService.php
    - app/Controllers/Settings/Clients.php
    - app/Config/Routes/clients.php
    - app/Views/settings/clients/index.php
    - app/Views/settings/clients/form.php
    - app/Language/sk/Clients.php
    - app/Language/en/Clients.php
key-decisions:
  - "Portal template accepts only http/https with host and {id} exactly once; preview is never an anchor"
  - "archive/unarchive are idempotent: no-op and no audit when state already matches"
requirements-completed: [CLI-01, CLI-04]
duration: 25min
completed: 2026-10-25
---

# Phase 3 Plan 07: Client Archive and Portal Link Summary

Owner archives and restores a client via a modal (data and history kept, audited with archived_at before and after), filters the list Active / Archived, and sets a portal name and link template where `javascript:`, `data:`, protocol-relative, ftp, missing or duplicate `{id}` and whitespace are rejected; a live htmx preview shows the link for task 123 as plain text.

## Tasks
1. RED tests: 9318d10
2. PortalUrlTemplate, archive/unarchive, controller, routes, views, translations: 21b3500

## Deviations from Plan
None. Test helper only: clock-based timestamp assertion uses `service('clock')` to satisfy the `app.forbiddenTime` PHPStan rule.

## Known Stubs
None.

## Threat Flags
None. T-03-37 to T-03-40 mitigated (validator and unit vectors, POST + CSRF, audit, no DELETE route).

## Verification
Full suite 521 tests green, `composer stan` clean, `composer cs` clean, `node --test tests/js` 29 pass.

## Self-Check: PASSED
