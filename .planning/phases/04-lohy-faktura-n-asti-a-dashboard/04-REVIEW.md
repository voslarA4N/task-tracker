---
phase: 04-lohy-faktura-n-asti-a-dashboard
reviewed: 2026-10-26T12:00:00Z
depth: standard
files_reviewed: 70
files_reviewed_list:
  - app/Commands/ImportExcel.php
  - app/Config/Routes/allocations.php
  - app/Config/Routes/dashboard.php
  - app/Config/Routes/task_status.php
  - app/Config/Routes/tasks.php
  - app/Controllers/Allocations.php
  - app/Controllers/Home.php
  - app/Controllers/Settings/Clients.php
  - app/Controllers/TaskStatus.php
  - app/Controllers/Tasks.php
  - app/Database/Migrations/2026-10-25-000007_CreateTasks.php
  - app/Database/Migrations/2026-10-25-000008_CreateBillingAllocations.php
  - app/Database/Migrations/2026-10-25-000009_CreateTaskBillingView.php
  - app/Database/Support/GrantsPrivileges.php
  - app/Language/en/Allocations.php
  - app/Language/en/Clients.php
  - app/Language/en/Dashboard.php
  - app/Language/en/Tasks.php
  - app/Language/sk/Allocations.php
  - app/Language/sk/Clients.php
  - app/Language/sk/Dashboard.php
  - app/Language/sk/Tasks.php
  - app/Libraries/Master/ClientCurrencyLocked.php
  - app/Libraries/Master/ClientService.php
  - app/Libraries/Platform/Hours.php
  - app/Libraries/Platform/MoneyFormat.php
  - app/Libraries/Work/AllocationInput.php
  - app/Libraries/Work/AllocationLocked.php
  - app/Libraries/Work/AllocationService.php
  - app/Libraries/Work/BillingMonth.php
  - app/Libraries/Work/DashboardFilter.php
  - app/Libraries/Work/Import/ExcelReader.php
  - app/Libraries/Work/Import/ImportBlock.php
  - app/Libraries/Work/Import/ImportPlan.php
  - app/Libraries/Work/Import/ImportPlanner.php
  - app/Libraries/Work/Import/ImportReportFormatter.php
  - app/Libraries/Work/Import/ImportResult.php
  - app/Libraries/Work/Import/ImportRow.php
  - app/Libraries/Work/Import/ImportRunner.php
  - app/Libraries/Work/Import/ImportSheet.php
  - app/Libraries/Work/LastClient.php
  - app/Libraries/Work/TaskFilter.php
  - app/Libraries/Work/TaskHasLockedAllocations.php
  - app/Libraries/Work/TaskInput.php
  - app/Libraries/Work/TaskQueries.php
  - app/Libraries/Work/TaskRejected.php
  - app/Libraries/Work/TaskService.php
  - app/Views/home/_body.php
  - app/Views/home/_switch_sheet.php
  - app/Views/home/index.php
  - app/Views/layouts/_nav_items.php
  - app/Views/layouts/app.php
  - app/Views/tasks/_alloc_delete.php
  - app/Views/tasks/_alloc_form.php
  - app/Views/tasks/_allocations.php
  - app/Views/tasks/_done_form.php
  - app/Views/tasks/_list.php
  - app/Views/tasks/_portal_preview.php
  - app/Views/tasks/_rate_field.php
  - app/Views/tasks/_sheet.php
  - app/Views/tasks/_summary.php
  - app/Views/tasks/_task_row.php
  - app/Views/tasks/form.php
  - app/Views/tasks/index.php
  - app/Views/tasks/sheet_page.php
  - app/Views/tasks/show.php
  - composer.json
  - docs/ops/import-excel.md
  - public/assets/css/app.css
  - public/assets/js/app.js
findings:
  critical: 1
  warning: 7
  info: 8
  total: 16
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-10-26T12:00:00Z
**Depth:** standard
**Files Reviewed:** 70
**Status:** issues_found

## Summary

I reviewed tasks, billing allocations, the dashboard, the Excel import and the related views and JS at standard depth. The core of the allocation-locking design holds up:

- Every mutation of an allocation is a single `UPDATE/DELETE ... WHERE id = :id: AND invoice_id IS NULL` with an `affectedRows() !== 1` check.
- The `alloc_guard` trigger backs this up as the last line of defence.
- Lock order (task row `FOR UPDATE`, then the allocation) is used consistently in `AllocationService::add/lockRow`, `TaskService::update/delete/markDone`.
- The amount is recomputed server-side with bcmath and matches the DB `CHECK (amount = round(hours * rate, 2))`. Both round half away from zero.
- `markDone` runs status, part and audit in one transaction. Nested `Transaction::run` is handled.

**Authorization and CSRF.** Every new route goes through the global `authgate` + `csrf` (`HtmxCsrf`) filters. All mutating routes are POST, and the GET routes only render panels.

**XSS.** I found no XSS:
- The places that use `htmlspecialchars` instead of `esc` either build the URL from values already whitelisted by regex (`month` matches `^\d{4}-(0[1-9]|1[0-2])$`, client is cast to int) or check the scheme first.
- `tasks/show.php:20-21` renders the portal link only if `parse_url` gives an `http`/`https` scheme and a non-empty host. A `javascript:` URL cannot get through: `TaskInput::urlIsValid` rejects it, and `ExcelReader::url` drops it on import as well.

**Import idempotency.** Import is idempotent through `import_key`, which is made from month, URL, normalized title, hours and a duplicate ordinal, and is backed by a unique partial index. It also runs in a single transaction.

**Main concerns:**
- A validation gap lets one task with an absurd hours × rate make the task list, dashboard and task detail return HTTP 500, and the task cannot be repaired from the UI (CR-01).
- The dashboard groups overlap, and the tiles do not match the lists they link to (WR-01, WR-02).
- The month switch on the dashboard cannot go back to a preset month (WR-03).
- A task whose client is archived cannot be edited (WR-04).
- Editing a task stores a bare ticket number instead of expanding it with the portal template (WR-05).
- There is a currency race between allocation insert and a client currency change (WR-06).
- Import turns rows marked "Fakturované = ÁNO" into unlocked parts that can be invoiced again (WR-07).

### Answer to the dashboard question (partially invoiced, in-progress task in both groups)

**It is a defect relative to the spec's model of the dashboard, but the spec does not literally say the groups must not overlap. I classify it as WARNING (WR-01), not BLOCKER.**

- **What the spec defines:** `04-UI-SPEC.md` §1.3 lists three groups in a fixed order: **Zapracované, do faktúry** ("úlohy `done` s nezamknutou časťou vybraného mesiaca"), **Čiastočne vyfakturované** and **V práci** ("nezapracované"). The badge table gives each task exactly one state, and "Čiastočne" is a state of its own, separate from "V práci". The list filters were deliberately made exclusive in one direction: `TaskQueries::list` `'done'` is `status='done' AND locked_count = 0`, so partial tasks are excluded. That shows the intended reading is one bucket per task.
- **What the code does:** the dashboard groups are not exclusive:
  - an `in_progress` task with any locked part is in both `partial` and `in_progress`;
  - a `done` task with locked parts plus an unlocked part in the selected month is in both `done_due` and `partial`.
- **The test codifies the overlap:** `TaskQueriesDashboardTest::testGroups` asserts `partial.total = 2` and `in_progress.total = 13` for 14 tasks.
- **What the user sees:** the same card twice, and group count badges that add up to more than the number of tasks. Inside the "V práci" group the card shows the yellow "Čiastočne" badge, because `_task_row` checks the partial state first.
- **Partial excuse:** the original mockup (`docs/mockups/mobile-home.html`, variant B) had only two groups and put the partial Sentry task inside "V práci". The UI-SPEC then added a dedicated partial group without saying where such tasks should leave. So the overlap is a gap left when the spec was turned into code, not a deliberate decision.
- **Recommendation:** partition the groups with a fixed precedence (`done_due` > `partial` > `in_progress`), or confirm with the owner that the overlap is intended and document it in the spec.

## Critical Issues

### CR-01: hours × rate can exceed numeric(12,2); one task makes the task list, dashboard and task detail return HTTP 500, and it cannot be fixed from the UI

**File:** `app/Libraries/Work/TaskInput.php:391-397`, `app/Libraries/Work/AllocationInput.php:508-528`, `app/Libraries/Platform/MoneyFormat.php:126`, `app/Controllers/Tasks.php:319-324`, `app/Views/tasks/_alloc_form.php:34-42`

**Issue:**
- Validation accepts hours up to `99999.99` (5 digits) and rate up to `99999999.99` (8 digits), but never checks the product. `tasks` has no amount column, so `TaskService::create` succeeds even when `hours × rate >= 10^10`.
- Every read path then formats `quoted_amount = round(t.hours * t.rate, 2)` with `MoneyFormat::format`. That calls `Money::of`, which rejects more than 10 integer digits with `InvalidArgumentException`. Affected views:
  - `_summary.php:41` (detail)
  - `_task_row.php:32` (task list and every dashboard group)
  - `home/_body.php:54` (WIP tile)
- **Result:** `/tasks`, `/` and `/tasks/{id}` all return HTTP 500. The edit page also crashes: `Tasks::formData` calls `Money::timesHours`, which throws `OverflowException`. The delete modal is on the crashing detail page. So the user cannot repair or remove the task from the UI.
- **Allocations hit the same gap.** `AllocationService::add/update` throw an uncaught `OverflowException` in `Money::timesHours` and return HTTP 500 instead of a 422. `_alloc_form.php:34-42` throws the same exception while re-rendering a 422.
- **How likely:** it needs two typos together (for example rate `1000000` and hours `10000`), so it is unlikely, but the effect is severe.

**Fix:** reject the product during validation (task and allocation) and in the service before writing:
```php
// TaskInput::validate / AllocationInput::validate, after hours and rate pass their regexes
try {
    Money::of(str_replace(',', '.', $plain('rate')))->timesHours(Hours::of($hours));
} catch (\OverflowException) {
    $errors['rate'] = lang('Tasks.errorAmountTooLarge'); // new lang key, sk + en
}
```
Also add a DB guard: `CHECK (round(hours * rate, 2) < 10000000000)` on `tasks`.

## Warnings

### WR-01: Dashboard groups overlap; a partially invoiced task appears in two groups

**File:** `app/Libraries/Work/TaskQueries.php:163-171`, `app/Views/tasks/_task_row.php:19-24`

**Issue:** see the answer above. `partial` (`billing_state = 'partial'`) overlaps with `in_progress` (`status = 'in_progress'`) and with `done_due`. The count badges double-count, and cards in "V práci" carry a "Čiastočne" badge. This is inconsistent with the one-state-per-task badge model and with the list's `done` filter, which excludes partial tasks.

**Fix:**
```php
'done_due'    => $this->group("t.status = 'done' AND EXISTS (...unlocked in :month:...)", ...),
'partial'     => $this->group("v.billing_state = 'partial' AND NOT (t.status = 'done' AND EXISTS (...unlocked in :month:...))", ...),
'in_progress' => $this->group("t.status = 'in_progress' AND v.billing_state <> 'partial'", ...),
```
Update `testGroups` to match, and change the `/tasks?state=in_progress` filter in the same way so the "Zobraziť všetky" counts stay consistent.

### WR-02: Dashboard tiles and "Zobraziť všetky" links open lists that do not match the shown amounts and counts

**File:** `app/Views/home/_body.php:24,45`, `app/Libraries/Work/TaskQueries.php:51-54,147-153`

**Issue:**
- **"Na fakturáciu" tile:** it sums every unlocked part of the month, including parts of `in_progress` tasks (allowed by ALC-05) and parts of `done` tasks that already have locked parts. The tile links to `/tasks?month=X&state=done`. That filter is `status='done' AND locked_count = 0`, so it leaves out both kinds of task. Clicking the tile shows a list whose total differs from the tile.
- **"Zapracované, do faktúry" group:** it includes done tasks that have locked parts. Its "Zobraziť všetky" link uses the same `state=done` filter, which leaves those tasks out, so the group badge count is higher than the linked list.
- This works against the core value that sums must match on every screen.

**Fix:** make the tile query and the linked filter agree. Either:
- add a list state such as `due` (`EXISTS unlocked part in :month:`, optionally `t.status='done'`) and link to it; or
- restrict the tile to `t.status = 'done'` (matching "h zapracovaných") and change the list `done` filter to `t.status = 'done'` combined with the month's unlocked parts.

### WR-03: Dashboard month switch cannot return from a custom month to a preset one

**File:** `app/Controllers/Home.php:40-44`, `app/Views/home/_switch_sheet.php:37`

**Issue:**
1. When the selected month is not one of the three presets, the sheet pre-fills `month_other` with it.
2. If the user then picks the "Aktuálny" radio and submits, both `month=2026-10` and `month_other=2026-05` are sent.
3. `Home::data` always lets a non-empty `month_other` win, so the dashboard stays on 2026-05.

The user has to clear the month input by hand, which without JS is not obvious.

**Fix:** add an explicit "Iný" radio (`month=other`) and use `month_other` only when it is selected:
```php
if (($get['month'] ?? null) === 'other' && is_string($other) && $other !== '') {
    $get['month'] = $other;
}
```
Alternatively, do not pre-fill `month_other` and make the preset radios take precedence.

### WR-04: A task whose client is archived cannot be edited at all

**File:** `app/Controllers/Tasks.php:139-146` (with `clientsFor`, lines 269-283)

**Issue:**
- `clientsFor()` deliberately keeps the task's own archived client in the form, and `TaskService::update` only checks for archived status when the client changes.
- `Tasks::update` validates with `activeClient()`, though, which returns `null` for an archived client and adds `errors['client_id']`.
- So every save of such a task (title, note, hours or URL) fails with 422 "Tasks.errorClient". The same happens when the client field is locked because the task has parts.

**Fix:** in `update`, accept the task's current client even when it is archived:
```php
$client = $values['client_id'] === (string) $task['client_id']
    ? $service->find((int) $task['client_id'])
    : $this->activeClient($service, $values['client_id']);
```

### WR-05: Editing a task stores a bare ticket number instead of the expanded portal URL

**File:** `app/Libraries/Work/TaskService.php:98-106` (compare with `create` at line 54)

**Issue:**
- `TaskInput::urlIsValid` accepts a bare number (`^\d{1,20}$`) whenever the client has a portal template, and the form tells the user they may enter just the ticket number.
- `create()` expands it with `expandUrl()`, but `update()` stores `'url' => $this->nullable($values['url'])` as is.
- After an edit the task URL is `"12345"`. `show.php` hides the portal link because there is no scheme, and the data section shows the raw number. The live preview showed the user the expanded URL, so the stored data silently differs from what they were shown.

**Fix:** in `update()`, load the target client's `portal_url_template` (the client row is already read when the client changes; otherwise read it `FOR SHARE`) and apply `expandUrl()` exactly as `create()` does.

### WR-06: Race between allocation insert and client currency change can leave a part in the wrong currency

**File:** `app/Libraries/Work/AllocationService.php:46-49`, `app/Libraries/Master/ClientService.php:66-90`

**Issue:**
- `ClientService::update` locks the client `FOR UPDATE`, checks "no allocations exist", and then changes `currency`.
- `AllocationService::add` locks only the task (`FOR UPDATE OF t`) and reads `c.currency` without a lock. Under READ COMMITTED it reads the old committed currency without blocking.
- If the two transactions interleave, the client ends up in PLN while a new allocation is stored in EUR. That is the kind of inconsistency the `ClientCurrencyLocked` guard was added to prevent.
- `TaskService::create` already takes `FOR SHARE` on the client, but `add` does not.

**Fix:**
```sql
SELECT t.id, t.rate, c.currency FROM tasks t JOIN clients c ON c.id = t.client_id
WHERE t.id = :id: FOR UPDATE OF t FOR SHARE OF c
```

### WR-07: Import turns already-invoiced Excel rows into unlocked, invoiceable parts; the `billed` flag is read but never used or reported

**File:** `app/Libraries/Work/Import/ImportRunner.php:80`, `app/Libraries/Work/Import/ExcelReader.php:148`, `app/Libraries/Work/Import/ImportPlanner.php`, `docs/ops/import-excel.md:56`

**Issue:**
- `ImportRow::$billed` (column H "Fakturované = ÁNO") is parsed and then ignored everywhere.
- Rows that were already invoiced outside the app (for example all of 09/2026) become `done` tasks with unlocked parts. They show up as "Aktuálne" (`unlocked_count > 0`), in the due sums for their month, and will be selectable for invoicing in Phase 5. That is a double-billing risk.
- The runbook documents the behaviour, but the dry-run report does not list how many billed rows will become invoiceable, so the operator gets no warning.

**Fix:**
- At minimum, have `ImportPlanner` count billed rows per month and have `ImportReportFormatter` print them as a warning.
- Better: skip creating parts for `billed` rows (create the task as `done` with no part), or record them so that Phase 5 can lock them against a historical invoice record.

## Info

### IN-01: Deleting a part does not work without JS

**File:** `app/Views/tasks/_alloc_form.php:104-106`

**Issue:** "Zmazať časť" is a `type="button"` that only has `hx-get`, so without JS it does nothing. UI-SPEC says every form works as a classic POST + PRG without JS.

**Fix:** use an `<a href="/allocations/{id}/delete" hx-get=... hx-target="#sheet-body">`. The confirm page already renders via `sheet_page`.

### IN-02: Spec deviation in how the page refreshes after a part change; the dashboard refresh trigger is never used

**File:** `app/Controllers/Allocations.php:336-348`, `app/Views/home/_body.php:34`

**Issue:**
- UI-SPEC §4 says part changes replace `#task-summary` and `#task-allocations` with one `hx-swap-oob` swap. The implementation instead does an `HX-Location` navigation to the whole page.
- The dashboard's `hx-trigger="allocationChanged from:body"` can never fire, because no dashboard flow emits that event.

**Fix:** either update the spec (D-15 already favours `HX-Location`) or remove the unused trigger.

### IN-03: Task count shows "200 úloh" when the list is truncated

**File:** `app/Views/tasks/_list.php:12,15`

**Issue:** `count($rows)` is capped at 200, so the subtitle understates the real total.

**Fix:** return a `COUNT(*) OVER ()` total from `TaskQueries::list`.

### IN-04: Edit form pre-fills hours and rate in DB format ("40.00") instead of the locale format

**File:** `app/Controllers/Tasks.php:116-123`

**Issue:** this is inconsistent with `Allocations::edit`, which uses `Hours::format`. The value still parses because both separators are accepted.

**Fix:** format hours with `Hours::format` and the rate with the locale decimal separator.

### IN-05: Duplicated helpers

**File:** `Tasks.php:388`, `Allocations.php:448`, `TaskStatus.php:593`; `Allocations.php:397` and `TaskStatus.php:559`; `Tasks.php:319-324` and `_alloc_form.php:34-42`

**Issue:**
- `numberFormat()` is copied verbatim in three controllers.
- `formValues()` is duplicated in two controllers.
- The "sum preview" parsing is duplicated in two places.
- These copies are also where the CR-01 overflow handling would have to be repeated.

**Fix:** move them into one shared helper (for example `MoneyFormat::jsFormat()` and `AllocationInput::formValues()`).

### IN-06: Imported tasks get `created_at` and `done_at` set to the import time

**File:** `app/Libraries/Work/Import/ImportRunner.php:76-80`

**Issue:** September 2026 tasks show "Vytvorená/Zapracovaná 26. októbra 2026". This is harmless for billing, but misleading in the detail view.

**Fix:** document it in the runbook, or pass the end of the block's month as `done_at`.

### IN-07: WIP tile counts the full quoted amount of partially invoiced in-progress tasks

**File:** `app/Libraries/Work/TaskQueries.php:155-161`

**Issue:** this follows the spec text ("suma nacenených nezapracovaných úloh") and the mockup (82 h includes the partly invoiced Sentry task). Still, the already-invoiced portion is counted both as invoiced and as "V práci".

**Fix:** confirm the intent with the owner. If the tile should show only the remaining work, subtract `billed_amount` and `billed_hours`.

### IN-08: A task or part deleted concurrently causes an uncaught 500

**File:** `app/Controllers/Allocations.php:270`, `app/Controllers/Tasks.php:150`, `app/Controllers/TaskStatus.php:463,495`

**Issue:** the services throw `InvalidArgumentException('Task N not found')` inside the transaction, and these controllers do not catch it. `Allocations::update/delete` already catch it.

**Fix:** catch it and convert it to `PageNotFoundException`, as `Allocations::update/delete` do.

---

_Reviewed: 2026-10-26T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
