---
phase: 11-preh-ady-exporty-a-audit
plan: 07
subsystem: settings-ui
tags: [users, read-only, desktop-layout, ui-03]
requires: [11-02, 11-05]
provides:
  - UserDirectory::list (read-only users with role, 2FA, last login)
  - GET /settings/users (admin only) and Viac menu entry "Používatelia"
  - .w-sm-auto utility and w-lg-auto on settings action buttons
affects: [11-10]
tech-stack:
  added: []
  patterns: [single SELECT with subqueries, work-narrow/work-wide wrappers on settings pages]
key-files:
  created:
    - app/Libraries/Auth/UserDirectory.php
    - app/Controllers/Settings/Users.php
    - app/Views/settings/users/index.php
    - app/Language/sk/Users.php
    - app/Language/en/Users.php
    - tests/feature/Settings/UsersListTest.php
    - tests/feature/Settings/SettingsDesktopLayoutTest.php
  modified:
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
    - public/assets/css/app.css
    - 9 settings/invoicing views (w-lg-auto) and 18 settings views (width wrapper)
key-decisions:
  - "Users screen is GET-only with no write routes or forms; accounts remain created by spark (D-16)."
  - "Role labels use a literal-key map in the view (the lang key scanner rejects dynamic keys)."
requirements-completed: [UI-03]
duration: ~30 min
completed: 2026-11-04
---

# Phase 11 Plan 07: Users list and desktop settings layout Summary

Read-only `/settings/users` (email/name, role, 2FA, last login from auth_events; table on PC, cards on mobile) plus PC width fixes: `.w-sm-auto` added to CSS and `w-lg-auto` added to full-width action buttons in settings and the FX card.

## Tasks
1. Users list, route, view, lang, menu link, tests (fa49e1d).
2. Desktop layout fix and test (be9ab5e).

## Verification
- UsersListTest + I18n + ViewsCspTest: OK (9 tests, 49 assertions).
- tests/feature/Settings, Master, InvoicingScreenTest, ViewsCspTest: OK (220 tests, 903 assertions); SettingsDesktopLayoutTest: OK (10 tests).
- Full suite: OK (2233 tests, 26478 assertions). PHPStan incl. views: no errors. php-cs-fixer applied.
- Human check pending on staging (PC and iPhone).

## Deviations from Plan

**1. [Rule 1 - Route] `/invoicing` has no route.** The Fakturácia mesiaca screen is `/invoices/new`; the layout test uses it.

**2. [Rule 2 - Layout, plan step c] Width wrappers added.** 18 settings views had no `work-narrow`/`work-wide` container; wrapped content (narrow for forms, wide for lists/detail) in the content section. Added `w-lg-auto` only where `btn ... w-100` lacked it; `_end_modal.php`, `limits/index.php` needed no change. Non-button `w-100` (audit `inv-row-meta`) left as is.

**3. Test harness.** The POST-without-route check uses expectException (CI4 FeatureTest).

## Known Stubs
None.

## Threat Flags
None. T-11-22..24 mitigated: admin-only (403 test), SELECT exposes no secrets (only `totp_enabled_at IS NOT NULL`), no write routes, `esc()` on output.

## Self-Check: PASSED
