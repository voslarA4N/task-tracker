---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 07
subsystem: work-ui
tags: [tasks, form, alpine-csp, bigint, htmx, css]
requires: ["04-04", "04-06"]
provides:
  - "Tasks controller (new, create, show, edit, update, delete, clientDefaults, portalPreview) and routes"
  - "tasks/form, _rate_field, _portal_preview, show views"
  - "Alpine taskForm with BigInt half-up live sum (window.AppMoney.calcAmount/formatAmount)"
  - "All .work-* CSS classes for phase 4"
  - "Tasks nav item active on /tasks*"
affects: [04-08, 04-10, 04-11, 04-12, 04-13]
key-files:
  created:
    - app/Controllers/Tasks.php
    - app/Config/Routes/tasks.php
    - app/Views/tasks/form.php
    - app/Views/tasks/_rate_field.php
    - app/Views/tasks/_portal_preview.php
    - app/Views/tasks/show.php
    - tests/feature/Work/TaskFormTest.php
    - tests/feature/Work/TaskShowDeleteTest.php
    - tests/js/task-form.test.js
  modified:
    - app/Views/layouts/_nav_items.php
    - app/Language/sk/Tasks.php
    - app/Language/en/Tasks.php
    - public/assets/js/app.js
    - public/assets/css/app.css
key-decisions:
  - "Locked client in edit form keeps a hidden client_id input plus .master-fixed display, so the POST still reaches the server guard (TaskService rejects change with client_locked)"
  - "Task detail dates formatted via service('clock')->now()->modify(raw) because phpstan forbids strtotime/new DateTimeImmutable outside Clock"
requirements-completed: [TSK-01, TSK-02]
duration: 45min
completed: 2026-10-26
---

# Phase 4 Plan 07: Task form, detail and delete Summary

Owner can add, edit, view and delete a task: form with last-used client default, rate prefilled from the client (htmx fragment on client change), live quoted amount computed in BigInt cents with half-up rounding (parity with PHP `Money`), PRG on save, detail page, and delete that the server refuses with 409 when a locked part exists.

## Tasks
1. RED tests (feature form, show/delete, JS parity vectors) - c167ea5
2. Controller, routes, views, nav, language keys - 86f5cde
3. Alpine taskForm with BigInt and `.work-*` CSS - 0e0fedc

## Deviations from Plan

**1. [Rule 3 - Blocking] phpstan forbiddenTime**: `strtotime()` / `new DateTimeImmutable` in the controller were rejected by the project rule; dates now go through `service('clock')`.
**2. [Rule 2] Empty-state error**: when POST fails with no active client the form shows the empty state; added an `alert-danger` with the client error there so the 422 carries the message.
**3. Test adjustments**: 404 test uses `expectException(PageNotFoundException)` (project pattern); anonymous access test omitted from TaskFormTest (covered globally by `AuthGateTest::testEveryRegisteredRouteIsDeniedToAnonymous`). Link `href` is emitted with `htmlspecialchars` instead of `esc(..., 'attr')` because the latter hex-encodes `:` and `/`.

## Known Stubs
- `GET /tasks` list does not exist yet (04-12); delete redirect and the nav link point to it until then.
- `<!-- 04-10: summary + allocations -->` placeholder in `tasks/show.php` for the next plan.

## Verification
`phpunit tests/feature tests/unit` 574 tests green, `node --test tests/js/` 34 green, `make stan` OK, `make cs` clean.

## Self-Check: PASSED
