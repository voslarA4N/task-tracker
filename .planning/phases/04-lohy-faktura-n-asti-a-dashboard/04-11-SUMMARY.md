---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 11
subsystem: work-ui
tags: [tasks, mark-done, offcanvas, htmx, summary]
requires: ["04-08", "04-10"]
provides:
  - "TaskStatus controller (donePanel, markDone, reopen) and three routes"
  - "tasks/_done_form bottom-sheet form, detail actions and reopen modal"
affects: [04-13]
key-files:
  created:
    - app/Controllers/TaskStatus.php
    - app/Config/Routes/task_status.php
    - app/Views/tasks/_done_form.php
    - tests/feature/Work/MarkDonePanelTest.php
  modified:
    - app/Controllers/Tasks.php
    - app/Views/tasks/show.php
    - app/Views/tasks/_sheet.php
    - app/Views/tasks/sheet_page.php
    - app/Language/sk/Allocations.php
    - app/Language/en/Allocations.php
key-decisions:
  - "Sheet title comes from _sheet/sheet_page via mode 'done' (no duplicate h2 in the form)"
  - "Forged rate is overwritten with the task rate before validation; the part takes the task rate in TaskService"
  - "Reopen 409 renders the task detail with an alert, via Tasks::showData (made public)"
requirements-completed: [ALC-04]
duration: 25min
completed: 2026-10-26
---

# Phase 4 Plan 11: Mark done panel Summary

Owner marks a task as done from the detail via a bottom panel (Aktuálny / Ďalší / Iný month, hours defaulting to the remaining balance, live sum), creating status done plus the part in one transaction; done tasks can be returned to In progress via a modal with parts kept.

## Tasks
1. RED feature tests (13) - test(04-11)
2. Controller, routes, views, lang keys, detail actions - fc9b2ca

## Deviations from Plan
**1. [Rule 1 - Bug] Test helper `status()` clashed with a final PHPUnit method**, so the RED commit failed by fatal error instead of assertions; renamed to `taskStatus()` in the GREEN commit.
**2.** Flash assertions use `session()->getFlashdata('app_notice')` (test client does not carry session across requests); 404 asserted via `PageNotFoundException` like the 04-10 tests.
**3.** The form has no own `h2` (the sheet header from `_sheet.php` already carries `work-sheet-title`); `_sheet`/`sheet_page` got a `done` title case.

## Known Stubs
None.

## Verification
`phpunit tests/feature/Work tests/unit/Security/ViewsCspTest.php tests/unit/I18n` 62 green, `node --test tests/js/` 38 green, `make stan` and `make cs` clean, no inline style/onclick/script in `app/Views/tasks/`.

## Deferred manual checks
iPhone PWA: new task, Označiť ako zapracovanú, Aktuálny, Potvrdiť within 30 s one-handed (G5); panel closes and page scrolls after swap. Not performed, left to end-of-phase human verification.

## Self-Check: PASSED
