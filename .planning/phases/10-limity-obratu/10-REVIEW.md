---
phase: 10-limity-obratu
reviewed: 2026-11-02T12:00:00Z
depth: standard
files_reviewed: 61
files_reviewed_list:
  - app/Config/Routes/settings.php
  - app/Config/Services.php
  - app/Controllers/Home.php
  - app/Controllers/Invoicing.php
  - app/Controllers/Settings/Limits.php
  - app/Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php
  - app/Language/en/Dashboard.php
  - app/Language/en/Invoicing.php
  - app/Language/en/Limits.php
  - app/Language/sk/Dashboard.php
  - app/Language/sk/Invoicing.php
  - app/Language/sk/Limits.php
  - app/Libraries/Platform/MoneyFormat.php
  - app/Libraries/Reporting/EntityLimits.php
  - app/Libraries/Reporting/LimitAlerts.php
  - app/Libraries/Reporting/LimitInput.php
  - app/Libraries/Reporting/LimitMath.php
  - app/Libraries/Reporting/LimitPeriod.php
  - app/Libraries/Reporting/LimitPrecheck.php
  - app/Libraries/Reporting/LimitRejected.php
  - app/Libraries/Reporting/LimitSelector.php
  - app/Libraries/Reporting/LimitService.php
  - app/Libraries/Reporting/LimitStatus.php
  - app/Libraries/Reporting/OpeningTurnoverService.php
  - app/Libraries/Reporting/PeriodResolver.php
  - app/Libraries/Reporting/TurnoverCalculator.php
  - app/Views/home/_body.php
  - app/Views/home/_limits.php
  - app/Views/invoicing/_limit_warning.php
  - app/Views/invoicing/_preview.php
  - app/Views/more/index.php
  - app/Views/settings/limits/_card.php
  - app/Views/settings/limits/_delete_sheet.php
  - app/Views/settings/limits/_form.php
  - app/Views/settings/limits/_form_sheet.php
  - app/Views/settings/limits/_opening_form.php
  - app/Views/settings/limits/_opening_sheet.php
  - app/Views/settings/limits/delete.php
  - app/Views/settings/limits/form.php
  - app/Views/settings/limits/index.php
  - app/Views/settings/limits/opening.php
  - public/assets/css/app.css
  - tests/_support/InvoiceFixtures.php
  - tests/_support/MasterFixtures.php
  - tests/_support/PhpStan/ForbiddenCallsRule.php
  - tests/_support/TurnoverFixtures.php
  - tests/feature/DashboardLimitsTest.php
  - tests/feature/Invoicing/InvoicingLimitWarningTest.php
  - tests/feature/Settings/LimitsControllerTest.php
  - tests/feature/Settings/LimitsCrudTest.php
  - tests/feature/Settings/OpeningTurnoverTest.php
  - tests/integration/Reporting/LimitSchemaTest.php
  - tests/integration/Reporting/LimitSelectorTest.php
  - tests/integration/Reporting/LimitServiceTest.php
  - tests/integration/Reporting/OpeningTurnoverServiceTest.php
  - tests/integration/Reporting/TurnoverCalculatorTest.php
  - tests/unit/Reporting/LimitAlertsTest.php
  - tests/unit/Reporting/LimitInputTest.php
  - tests/unit/Reporting/LimitPrecheckTest.php
  - tests/unit/Reporting/PeriodResolverTest.php
  - tests/unit/Reporting/ThresholdTest.php
findings:
  critical: 1
  warning: 6
  info: 5
  total: 12
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-11-02T12:00:00Z
**Depth:** standard
**Files Reviewed:** 61
**Status:** issues_found

## Summary

I reviewed the Phase 10 turnover-limit feature: the schema and seed migration, TurnoverCalculator, PeriodResolver, LimitMath, LimitSelector, LimitService, OpeningTurnoverService, LimitPrecheck, the Settings > Limits controller and views, the dashboard alert block, and the invoice-preview precheck. Test files got a lighter pass.

The core arithmetic is sound. It uses bcmath strings and no float, and the period windows, pro-rata formula, denormalized-column-only turnover query and credit-note handling match the CONTEXT decisions (D-03, D-07, D-08, D-09). The precheck is correctly wrapped in try/catch and never writes. The views escape output and contain no inline script or style.

The defects are in the concurrency/optimistic-lock handling of the settings forms, a wrong user-facing message in the precheck, and missing fault isolation and zero-limit guards on the dashboard path:

- **Optimistic locking on limit edit:** the lock can be bypassed after a validation error (CR-01).
- **Optimistic locking on opening turnover:** the lock is not tied to a specific row (WR-01).
- **Precheck message:** says "limit already exceeded" when only the 80 % warning threshold has been reached (WR-02).

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: Optimistic lock bypassed after a 422 on limit edit (lost update)

**File:** `app/Controllers/Settings/Limits.php:261-267, 318-320` and `app/Views/settings/limits/_form.php:43-45`
**Issue:** `update()` loads `$limit` fresh from the DB before calling the service. Validation runs before the revision check. When the input is invalid, `LimitService::validated()` runs before `lock()`, so validation errors are reported before the stale check. The same happens with `duplicate`, where the DB exception comes after the lock but the page is re-rendered the same way. In both cases `rejected()` re-renders the form with `formData($limit, $values, ...)`. The hidden field in `_form.php` is rendered from `$limit['revision']`, which is the **current DB revision**, not the revision the user submitted (`$values['revision']`).

Scenario: you open the edit form on the PC at rev 1. Someone (for example the iPhone session) saves rev 2. On the PC you submit with a typo in the amount. The 422 form now carries rev 2. You fix the typo and save, and the other session's change is silently overwritten without a 409. This defeats the `revision` optimistic lock, which is a stated hard constraint.
**Fix:** Echo the submitted revision back on non-stale rejections, and let the lock decide staleness:
```php
// _form.php
<?php if ($limit !== null) : ?>
    <input type="hidden" name="revision" value="<?= esc($values['revision'] ?? (string) $limit['revision'], 'attr') ?>">
<?php endif ?>
```
`valuesOf()` already puts `revision` into `$values` for GET and stale renders, so the GET and 409 paths keep working. Alternatively, run `lock()` (the revision check) before field validation inside `LimitService::update()`. Then a stale submission always returns 409 regardless of field errors. Add a feature test: stale revision plus an invalid amount must not render the new revision.

## Warnings

### WR-01: Opening-turnover revision is not bound to a row: editing year or entity can overwrite another row

**File:** `app/Views/settings/limits/_opening_form.php:35, 42, 52` and `app/Libraries/Reporting/OpeningTurnoverService.php:148-172`
**Issue:** The form loads the revision for one (entity, year), but `year` stays an editable input. `entity_id` is also editable when the form is opened without `?entity=`. On save, the service looks up whichever (entity, year) was posted and only compares `$revision !== (string) $old['revision']`. Revisions start at 1 for every row, so this is a likely sequence:

1. Open the opening turnover for 2026 (rev 1).
2. Change the year field to 2025, which also has a row at rev 1.
3. Save.

The 2025 value is overwritten without you ever having seen it, and no stale error appears. The lock only works if the revision belongs to the row being changed.
**Fix:** Bind the lock to the row identity. Either:
- post a hidden `opening_id` (empty for new) and check `$old['id'] === (int) $openingId` alongside the revision, rejecting with `stale` otherwise; or
- make `year` and `entity_id` read-only once an existing row is loaded (hidden inputs), and only allow free choice when `revision === ''`. On the server, reject with `stale` when a row exists but `$revision === ''`.

### WR-02: "Limit already exceeded" shown at 80 %: `alreadyOver` uses the lowest threshold

**File:** `app/Libraries/Reporting/LimitPrecheck.php:76`, `app/Language/sk/Invoicing.php:108`, `app/Language/en/Invoicing.php:108`
**Issue:** `alreadyOver` is computed as `LimitMath::reached($status->total, $status->limit, min($status->thresholds))`, which is true as soon as the **warning** threshold (80 %) is reached. The message it selects (`limitRowAlready`) says "Limit už je prekročený" / "Limit already exceeded". At 85 % before the invoice the user is told the limit is already exceeded, which is false and alarming for a VAT-registration decision. `tests/feature/Invoicing/InvoicingLimitWarningTest.php::testAlreadyOver` (45 000 of 50 000 = 90 %) and `tests/unit/Reporting/LimitPrecheckTest.php::testAlreadyOver` lock in the wrong behaviour.
**Fix:** Either compare against 100 % of the limit:
```php
'alreadyOver' => bccomp($status->total, $status->limit, 2) >= 0,
```
or keep the threshold semantics and change the text to something like "Prah {x} % už je dosiahnutý" / "Threshold already reached". Update both tests to cover 90 % (no "exceeded") and 101 % ("exceeded").

### WR-03: Pro-rata limit can round to 0.00, causing DivisionByZeroError on the dashboard, settings and precheck

**File:** `app/Libraries/Reporting/PeriodResolver.php:60-71`, `app/Libraries/Reporting/TurnoverCalculator.php:150, 159`, `app/Libraries/Reporting/LimitMath.php:16-24`
**Issue:** The validation and DB CHECK only guarantee `amount > 0`. `proRataLimit()` rounds `amount × days / 365` to 2 decimals. For small amounts (for example `0.01` with ≤ 182 days) the result is `0.00`. `LimitMath::percent()` and `ratio()` then call `bcdiv(..., '0.00')`, which throws `DivisionByZeroError`. The error propagates uncaught through `Home::data()` (dashboard) and `Limits::index()`. The precheck is caught and shows "failed". A typo when entering a limit can therefore take down the main screen.
**Fix:** Guard in TurnoverCalculator, either by returning a `LimitStatus` with `error: 'zero_limit'` (rendered as a config error) when `bccomp($limit, '0', 2) <= 0`, or by clamping the pro-rata result to a minimum of `0.01`. Also make `LimitMath::percent/ratio` throw a domain `InvalidArgumentException` instead of relying on the engine error.

### WR-04: Dashboard has no fault isolation for the limit calculation

**File:** `app/Controllers/Home.php:524`
**Issue:** The invoice precheck is deliberately wrapped in `try/catch (Throwable)` (Invoicing.php:432-439), but the dashboard calls `TurnoverCalculator::forActiveEntities()` unguarded. The calculation includes per-entity date parsing (`PeriodResolver::parse` throws on a malformed `active_from`), division (WR-03) and DB queries. Any exception there makes the whole dashboard, and its htmx refresh fragment, return 500. A secondary informational block should not be able to break the primary work screen.
**Fix:**
```php
try {
    $limits = LimitAlerts::fromEntities((new TurnoverCalculator())->forActiveEntities(service('clock')->today()));
} catch (Throwable $e) {
    log_message('error', 'Dashboard limits failed: ' . $e->getMessage());
    $limits = [['kind' => 'config', /* ...generic "could not compute" row... */]];
}
```
Alternatively, catch per entity inside `forActiveEntities()` and emit an error status, so one bad entity does not hide the others.

### WR-05: Opening-turnover form shows a stale currency when the entity select changes

**File:** `app/Views/settings/limits/_opening_form.php:42-46, 60, 63`
**Issue:** When the form is opened without `?entity=` (unlocked), the user can pick another entity in the `<select>`. The currency symbol in the `input-group-text` and the hint text ("v mene {1}") are rendered once for the initially selected entity and never update. The server stores the amount in the newly selected entity's currency (D-07). A user who switches from an EUR entity to a PLN entity sees "€" and may enter a EUR amount that is then stored as PLN, which corrupts the turnover total.
**Fix:** Either always open the form locked to one entity (the index page links always pass `entity=`, so drop the free selector), or re-render the form via `hx-get` on `change` of the select (`hx-get="/settings/limits/opening" hx-include="closest form" hx-trigger="change"`), so the symbol and hint follow the selection.

### WR-06: Concurrent first insert of an opening turnover returns 500; a deleted row is silently recreated

**File:** `app/Libraries/Reporting/OpeningTurnoverService.php:148-166`
**Issue:** `SELECT ... FOR UPDATE` locks nothing when the row does not exist. Two concurrent first saves for the same (entity, year) both reach the `INSERT`, and the second fails on `entity_opening_turnover_uq`. That `DatabaseException` is not mapped (unlike `LimitService::duplicateOrRethrow`), so the user gets a 500. Separately, when the row was deleted concurrently and the client posts a non-empty `revision`, the create branch ignores the revision and silently recreates the row instead of reporting `stale`.
**Fix:** Use `INSERT ... ON CONFLICT (entity_id, year) DO NOTHING RETURNING id`, and throw `LimitRejected('stale')` when nothing is returned. In the `$r === null` branch, throw `stale` when `$revision !== ''` and the amount is non-empty.

## Info

### IN-01: `w-lg-auto` class does not exist

**File:** `app/Views/settings/limits/index.php:66`
**Issue:** Bootstrap 5.3.8 has no responsive width utilities, and `app.css` does not define `.w-lg-auto`. On desktop the "Add" button stays at 100 % width, contrary to the intent.
**Fix:** Add `@media (min-width: 992px) { .w-lg-auto { width: auto !important; } }` to `app.css`, or use `d-grid d-lg-block` on the wrapper.

### IN-02: `EntityLimits::alerts()` is dead code

**File:** `app/Libraries/Reporting/EntityLimits.php:44-47`
**Issue:** Neither application code nor tests call it. Dashboard selection is done in `LimitAlerts::fromEntities()`, so there are two diverging definitions of "alert".
**Fix:** Remove it, or have `LimitAlerts` use it.

### IN-03: `duplicateOrRethrow()` should declare a native `never` return type; `currval` vs `RETURNING`

**File:** `app/Libraries/Reporting/LimitService.php:72, 196`, `app/Libraries/Reporting/OpeningTurnoverService.php:162`
**Issue:** `create(): int` relies on a `: void` method (with only a `@return never` docblock) always throwing. If that ever changes, the function falls off the end and raises a TypeError. `currval(pg_get_serial_sequence(...))` is an extra round trip.
**Fix:** Declare `private function duplicateOrRethrow(DatabaseException $e): never`, and use `INSERT ... RETURNING id`.

### IN-04: The entity select becomes locked after any failed opening save

**File:** `app/Controllers/Settings/Limits.php:130`
**Issue:** `$locked = isset($entities[(int) $values['entity_id']])` is true for any valid active entity. After a 422 or 409 the select is disabled even if the user opened the form unlocked. This is inconsistent UX (it interacts with WR-01 and WR-05).
**Fix:** Carry the original locked state in a hidden field, or derive it the same way as `openingForm()`.

### IN-05: Duplicate SELECT and row mapping for limit rows in three places

**File:** `app/Libraries/Reporting/LimitSelector.php:63-86, 103-126`, `app/Libraries/Reporting/LimitService.php:44-53, 172-191, 248-262`
**Issue:** The same column list, `array_to_string(thresholds, ',')`, and the same trim/cast mapping are repeated. A future column change must be applied in five places.
**Fix:** Extract a single `LimitSelector::mapRow()` and a shared column-list constant.

---

_Reviewed: 2026-11-02T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
