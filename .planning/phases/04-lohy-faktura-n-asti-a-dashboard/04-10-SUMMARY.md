---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 10
subsystem: work-ui
tags: [allocations, offcanvas, htmx, alpine-csp, summary]
requires: ["04-05", "04-07"]
provides:
  - "Allocations controller (new, create, edit, update, confirmDelete, delete) and six routes"
  - "tasks/_summary, _allocations, _sheet, _alloc_form, _alloc_delete, sheet_page views, included in tasks/show"
  - "Alpine allocForm and bottom-sheet handling in app.js (open on swap into #sheet, close on allocationChanged, backdrop cleanup)"
  - "htmx 409 swap rule in layout responseHandling"
affects: [04-11, 04-13]
key-files:
  created:
    - app/Controllers/Allocations.php
    - app/Config/Routes/allocations.php
    - app/Views/tasks/_summary.php
    - app/Views/tasks/_allocations.php
    - app/Views/tasks/_sheet.php
    - app/Views/tasks/_alloc_form.php
    - app/Views/tasks/_alloc_delete.php
    - app/Views/tasks/sheet_page.php
    - tests/feature/Work/AllocationPanelTest.php
    - tests/feature/Work/TaskDetailSummaryTest.php
    - tests/js/alloc-form.test.js
  modified:
    - app/Views/tasks/show.php
    - app/Views/layouts/app.php
    - app/Language/sk/Allocations.php
    - app/Language/en/Allocations.php
    - public/assets/js/app.js
    - tests/feature/LayoutTest.php
key-decisions:
  - "Form and delete confirmation target #sheet-body, so the controller returns only the inner view when HX-Target is sheet-body and the full offcanvas wrapper when opening the panel"
  - "Success is 200 + HX-Location /tasks/{id} + HX-Trigger allocationChanged for htmx, 302 otherwise; flash set in both cases"
  - "On 409 the panel shows only the alert and a close button (no editable fields for a locked part)"
requirements-completed: [ALC-02, ALC-05, TSK-05, TSK-03]
duration: 50min
completed: 2026-10-26
---

# Phase 4 Plan 10: Allocation panel and task summary Summary

Owner can split a task into billing parts from the task detail: hours summary (quoted, invoiced, planned, remaining with a no-inline-style progress bar and neutral hours delta), a parts list with locked parts control-free, and a bottom panel to add, edit, move and delete parts, with 409 for locked parts and 422 for validation errors.

## Tasks
1. RED tests (panel feature, summary feature, LayoutTest 409, JS allocForm) - 0fd8fbe
2. Controller, routes, 409 layout rule, lang keys - 3b831b6
3. Views and show.php integration - 0b13426
4. allocForm and panel handling in app.js - 9245938

## Deviations from Plan

**1. [Rule 1 - Bug] Nested offcanvas on swap**: the interface said GET panel returns `_sheet`, but forms and the delete button target `#sheet-body` with innerHTML, which would nest a whole offcanvas inside the body. The controller now returns the inner view when `HX-Target: sheet-body`.
**2. Month label case**: `BillingMonth::label` yields "Október 2026"; radio labels and the delete confirmation lowercase it (per plan text), the range error message keeps the label case (test adjusted).
**3. Added lang keys** `editLink`, `deleteTitle`, `close` beyond the planned list.

## Known Stubs
None.

## Verification
`phpunit tests/feature tests/unit` 594 green, `node --test tests/js/` 38 green, `make stan` OK, `make cs` run. Manual iPhone check of the panel (Task 4 human-check) not performed.

## Self-Check: PASSED
