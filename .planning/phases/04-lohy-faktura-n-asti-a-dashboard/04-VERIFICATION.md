---
phase: 04-lohy-faktura-n-asti-a-dashboard
verified: 2026-10-26T14:00:00Z
status: human_needed
score: 5/5 roadmap success criteria verified (automated); 0 blocking gaps; 8 warnings carried as follow-ups
overrides_applied: 0
gaps: []
warnings:
  - id: CR-01
    severity: high-warning (code review blocker, not a roadmap-criterion failure)
    summary: "hours x rate is not validated against numeric(12,2); an absurd task makes /tasks, / and /tasks/{id} return 500 and cannot be repaired from the UI"
  - id: WR-07
    severity: high-warning (must be resolved before Phase 5 invoicing)
    summary: "Import ignores 'Fakturovane = ANO'; already invoiced rows become unlocked, invoiceable parts (double-invoicing risk). Matches the literal text of IMP-01/SC5, but contradicts owner intent and the Core Value"
  - id: WR-02
    severity: warning
    summary: "Dashboard tile 'Na fakturaciu' and group 'Zapracovane, do faktury' link to /tasks?state=done, whose filter excludes tasks with locked parts / in_progress tasks with parts, so totals/counts differ from the linked list"
  - id: WR-01
    severity: warning
    summary: "Dashboard groups overlap (partial task appears in 'Ciastocne' and 'V praci' / 'done_due')"
  - id: WR-03-06
    severity: warning
    summary: "Month switch cannot return to a preset; archived-client task cannot be edited; edit stores bare ticket number instead of expanded URL; currency race between allocation insert and client currency change"
deferred:
  - truth: "ALC-03 (part on an issued invoice is locked; server rejects edits)"
    addressed_in: "Phase 5"
    evidence: "ROADMAP Phase 5 Requirements include ALC-03; REQUIREMENTS.md traceability: ALC-03 Phase 5 Pending. DB trigger alloc_guard + AllocationLocked guard already exist in Phase 4."
human_verification:
  - test: "iPhone PWA: create a task one-handed, open it, press 'Oznacit ako zapracovanu', choose 'Aktualny', confirm"
    expected: "Whole flow in about 30 s with one hand; sheet slides up from bottom, live amount recalculation, sheet closes and detail shows the new unlocked part"
    why_human: "Touch ergonomics, 44 px targets, timing (deferred in 04-10 and 04-11 summaries)"
  - test: "iPhone: /tasks list filter collapse, FAB position above bottom bar, no horizontal scroll on /, /tasks, /tasks/{id}"
    expected: "Filter collapses/expands, FAB not covered by bottom bar, no horizontal scrolling (deferred in 04-12, 04-13)"
    why_human: "Visual/responsive behaviour on a real device"
  - test: "iPhone: dashboard month/client switch sheet"
    expected: "Selecting month/client updates URL and content; tiles 'Na fakturaciu' and 'V praci', groups, progress bar and bottom bar Prehlad/Ulohy/Faktury/Viac render correctly"
    why_human: "Visual verification of DSH-01 variant B on a device"
  - test: "Open an imported task at /tasks/{id} (e.g. one of the 16 imported tasks)"
    expected: "Detail renders without error; portal link works; hours summary and part list correct"
    why_human: "Real-data rendering check; orchestrator already ran the import on the dev DB"
  - test: "Owner decision on WR-07: should rows with 'Fakturovane = ANO' (e.g. all 09/2026 rows) be imported as unlocked invoiceable parts?"
    expected: "Owner confirms either 'import as unlocked' (as spec text says) or requests billed rows be imported without invoiceable parts before Phase 5 starts"
    why_human: "Business decision: current dev DB holds 11 parts / 89 h / 2 403,00 EUR for 2026-09 that may already have been invoiced externally"
---

# Phase 4: Ulohy, fakturacne casti a dashboard - Verification Report

**Phase Goal:** Vlastnik eviduje ulohy klientov s nacenenim, rozdeluje ich na fakturacne casti po mesiacoch (aj ciastocne) a na mobile vidi dashboard s tym, co treba tento mesiac fakturovat; realne data z Excelu su naimportovane.
**Verified:** 2026-10-26
**Status:** human_needed
**Re-verification:** No, initial verification
**Mode:** mvp (the goal is not in "As a ..., I want ..." user-story form; verified against the roadmap success criteria instead)

Evidence base: file inspection of app/, tests/ and the migrations, plus the code review (04-REVIEW.md) which I cross-checked against the code at the cited locations. I did not re-run the test suite; the orchestrator reports 882 PHP + 38 JS tests green, cs/stan clean. That is treated as orchestrator-supplied, not independently verified.

## Observable Truths (roadmap success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Create a task (default last client, URL, title, hours, rate prefilled from client), live amount, edit/delete if no locked parts | VERIFIED | TaskInput, TaskService (atomic delete, client lock), LastClient, Tasks controller, tasks/form.php, task-form JS test (tests/js/task-form.test.js), TaskHasLockedAllocations guard. Caveat: CR-01 overflow edge case and WR-04/WR-05 on edit. |
| 2 | "Mark as done" sheet (Current/Next/Other month, default remaining hours, live amount) creates a part; part addable to not-done task; unlocked part editable/movable/deletable | VERIFIED | TaskStatus controller + tasks/_done_form.php, AllocationService (atomic `invoice_id IS NULL` conditions, `affectedRows() !== 1`), Allocations controller, _alloc_form.php, alloc-form JS test, alloc_guard trigger migration 000008. Caveat: IN-01 (no-JS delete), CR-01 on allocation overflow (500 instead of 422). |
| 3 | Derived billing state, delta ("+6 h"), invoiced/planned/remaining hours; "Aktualne ulohy" list and filters (client, state, month, text) | VERIFIED | Migration 000009 view task_billing_v, Hours::delta/remaining/formatSigned, TaskQueries::list, TaskFilter, tasks/_summary.php (delta rendered via Allocations.deltaOver), _list.php. Caveat: WR-02 filter `done` vs dashboard link mismatch; IN-03 count capped at 200. |
| 4 | iPhone dashboard: current billing month and client, tiles "Na fakturaciu" + "V praci", groups by state, progress indicator, bottom bar Prehlad/Ulohy/Faktury/Viac | VERIFIED (code) / device check pending | Home controller, home/_body.php, _switch_sheet.php, DashboardFilter, TaskQueries dashboard groups, layouts/_nav_items.php. Visuals need a device (human verification). Caveats: WR-01, WR-02, WR-03. |
| 5 | Import 09+10/2026 with dry run + Excel-sum diff; "Zapracovane = ANO" creates a done task with unlocked part; re-run creates no duplicates | VERIFIED | ImportExcel command, ExcelReader (hyperlinks), ImportPlanner (dry run), ImportRunner, unique partial index tasks_import_key_uq, `import_key` sha256. Orchestrator ran the real import on dev DB: 16 tasks, 12 unlocked parts, re-run created 0. Caveat: WR-07 (see below). |

**Score:** 5/5 criteria satisfied as written.

## Requirements Coverage

| Requirement | Source plan(s) | Status | Evidence |
|-------------|----------------|--------|----------|
| TSK-01 | 04-04, 04-07 | SATISFIED | TaskInput/TaskService/form |
| TSK-02 | 04-04, 04-07 | SATISFIED | atomic delete rejected when locked parts exist (TaskHasLockedAllocations) |
| TSK-03 | 04-01, 04-06, 04-10, 04-12 | SATISFIED | tasks status+done_at CHECK; derived state in task_billing_v |
| TSK-04 | 04-06, 04-12 | SATISFIED | TaskQueries::list |
| TSK-05 | 04-02 (Hours::delta), 04-06 (query field `delta`), 04-10 (UI in tasks/_summary.php) | SATISFIED | Delivered across three plans; user-visible delivery is 04-10 (detail summary "+N h nad nacenene"). Not shown on list rows, which the requirement does not demand. |
| TSK-06 | 04-06, 04-12 | SATISFIED | TaskFilter, index filters |
| ALC-01 | 04-01, 04-02, 04-05 | SATISFIED | billing_allocations + AllocationService, currency copied from client |
| ALC-02 | 04-01, 04-05, 04-10 | SATISFIED | Atomic update/move/delete with `invoice_id IS NULL` + trigger |
| ALC-04 | 04-08, 04-11 | SATISFIED | TaskStatus::markDone single transaction; _done_form.php |
| ALC-05 | 04-05, 04-10 | SATISFIED | parts on any task state; detail shows invoiced/planned/remaining |
| DSH-01 | 04-06, 04-13 | SATISFIED (code) | Home dashboard; device check pending |
| IMP-01 | 04-03, 04-09 | SATISFIED as written | see WR-07 |

All 12 phase requirement IDs appear in PLAN frontmatter and in REQUIREMENTS.md as Complete. No orphaned Phase 4 requirements. ALC-03 is mapped to Phase 5 (Pending) and is not part of this phase; the DB-level guard it relies on already exists.

## Assessment of the code review findings (gap vs follow-up)

- **CR-01 (overflow -> 500):** Cross-checked: validation caps hours and rate independently and no product check exists; confirmed as a real defect. It needs two simultaneous absurd inputs and does not break any success criterion for normal use, so it is not a phase-goal gap. It is the highest-priority fix: a poisoned task bricks /tasks, / and the task page. Recommend fixing before relying on the app in production (small change: product check in TaskInput/AllocationInput plus a DB CHECK).
- **WR-07 (billed rows imported as unlocked parts):** IMP-01 and SC5 literally specify that "Zapracovane = ANO" becomes a done task with an unlocked part, and the runbook (docs/ops/import-excel.md:56) states the billed rows are imported as unlocked parts. So the criterion is met as written; this is not a gap against the contract. However, it conflicts with the Core Value (no double invoicing). The 09/2026 parts on the dev DB (11 parts, 89 h, 2 403,00 EUR) will appear as invoiceable in Phase 5. I treat it as a required owner decision before Phase 5 and as a human-verification item, not a Phase 4 gap. At minimum the dry-run report should warn how many billed rows become invoiceable.
- **WR-02 (tile/link mismatch):** SC4 does not demand that tile totals equal the linked list, so not a criterion failure. It is a visible inconsistency against the Core Value ("totals match everywhere") and should be fixed in a follow-up, ideally before Phase 5 builds the "Fakturacia mesiaca" screen on the same queries.
- **WR-01 (overlapping groups):** SC4 says "ulohy zoskupene podla stavu"; overlap is a spec ambiguity (UI-SPEC added a Partial group without exclusivity), confirmed as quality follow-up. Owner should pick precedence.
- **WR-03, WR-04, WR-05, WR-06:** functional defects on secondary flows (month switch, archived client edit, URL expansion on edit, currency race with a client currency change). None blocks a roadmap criterion; WR-04/WR-05 are cheap, WR-06 is a one-line lock fix.

## Anti-Patterns

Review-reported only (not re-scanned exhaustively). No debt markers (TBD/FIXME/XXX) check was run beyond the review; no stub implementations were found in the files examined (ImportRunner, ExcelReader, Hours, _summary view are substantive and wired).

## Human Verification Required

See the `human_verification` frontmatter: five items (iPhone one-handed flow, filter/FAB/no-horizontal-scroll, dashboard switch sheet, opening an imported task, owner decision on WR-07). These were explicitly deferred by executors in 04-10, 04-11, 04-12 and 04-13 summaries.

## Gaps Summary

No success criterion or requirement is unmet in code, so there are no blocking gaps. Status is human_needed because device-only checks and one owner decision (WR-07) remain. Recommended follow-up plan (`/gsd-plan-phase --gaps` or a quick task) before Phase 5: CR-01, WR-07, WR-02, WR-01 (they affect the numbers Phase 5 will invoice), then WR-03 to WR-06.

---

_Verified: 2026-10-26_
_Verifier: Claude (gsd-verifier)_
