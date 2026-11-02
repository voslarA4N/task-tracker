---
phase: 10-limity-obratu
plan: 07
subsystem: limits-ui
tags: [limits, turnover, settings, htmx, crud]
requires: [10-03, 10-04]
provides:
  - Settings\Limits newForm/create/edit/update/deleteForm/delete
  - views settings/limits form, _form, _form_sheet, delete, _delete_sheet
  - add-for-year and edit actions in usage cards
affects: [10-08]
tech-stack:
  added: []
  patterns: [sheet vs no-JS page via is_htmx_fragment_request, HX-Location on success, LimitRejected -> 422/409/404]
key-files:
  created:
    - app/Views/settings/limits/form.php
    - app/Views/settings/limits/_form.php
    - app/Views/settings/limits/_form_sheet.php
    - app/Views/settings/limits/delete.php
    - app/Views/settings/limits/_delete_sheet.php
    - tests/feature/Settings/LimitsCrudTest.php
  modified:
    - app/Controllers/Settings/Limits.php
    - app/Config/Routes/settings.php
    - app/Views/settings/limits/index.php
    - app/Views/settings/limits/_card.php
    - app/Language/sk/Limits.php
    - app/Language/en/Limits.php
    - tests/feature/Settings/LimitsControllerTest.php
key-decisions:
  - "Controller only maps LimitRejected to HTTP: invalid/duplicate 422, stale 409 (form re-rendered with fresh DB values), not_found 404"
  - "hx-post attributes only rendered in sheet mode; the no-JS page uses a plain POST + redirect"
  - "Href/hx-get URLs with query use esc() (html) not esc(...,'attr'), so '&amp;' is literal and slashes stay readable"
requirements-completed: [LIM-01, LIM-05]
duration: 40min
completed: 2026-11-02
---

# Phase 10 Plan 07: Limit CRUD screens Summary

Owner can add, edit and delete turnover limits from Settings, Limits via a bottom sheet (or a plain page without JS), with field errors, concurrency handling, audit through LimitService and removal of the Draft badge on first save.

## Tasks

| Task | Commit |
|------|--------|
| 1 Failing feature tests (RED) | 20e3b6a |
| 2 Add/edit controller, routes, form, lang | ff7cd08 |
| 3 Delete, list actions, card actions | d2da4ff |

## Verification

- `phpunit tests/feature/Settings tests/unit/I18n`: OK (89 + 4 tests).
- PHPStan on the controller and new test: no errors.
- Full `phpunit` (run alone): 2098 tests OK.
- No `style=`, `onclick=`, `<script` in any limits view.

## Deviations from Plan

**1. [Process] Delete actions and routes landed in the Task 2 commit.** The controller and routes file were written once, so `deleteForm`/`delete` and the delete routes are in ff7cd08; the delete views and list/card changes are in d2da4ff.

**2. [Rule 1 - Test adjust]** `LimitsControllerTest::testFallbackYearAlert` regex relaxed to allow the new `<p class="mb-2">` wrapper inside the fallback alert.

**3. Test notes:** helper methods renamed (`send`, `limitCount`) because `AuthTestCase::post` is public; 404 test uses `expectException(PageNotFoundException)` like other feature tests; names in assertions avoid spaces because `esc(...,'attr')` encodes them.

Not covered by an automated test: the human-check on iPhone (sheet UX, 44 px targets) remains for staging.

## Known Stubs

None.

## Threat Flags

None beyond the plan threat model (T-10-26 to T-10-31 mitigated: CSRF test, LimitInput whitelist, revision/409, esc on output, 404 for unknown ids, audit via LimitService).

## Self-Check: PASSED
