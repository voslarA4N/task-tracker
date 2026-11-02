---
phase: 10-limity-obratu
plan: 08
subsystem: limits-ui
tags: [limits, opening-turnover, settings, htmx]
requires: [10-04, 10-07]
provides:
  - Settings\Limits openingForm/saveOpening
  - views settings/limits opening, _opening_form, _opening_sheet
  - Opening turnover section in Settings, Limits
  - MoneyFormat::symbol
affects: []
tech-stack:
  added: []
  patterns: [sheet vs no-JS page, HX-Location on success, LimitRejected -> 422/409]
key-files:
  created:
    - app/Views/settings/limits/opening.php
    - app/Views/settings/limits/_opening_form.php
    - app/Views/settings/limits/_opening_sheet.php
    - tests/feature/Settings/OpeningTurnoverTest.php
  modified:
    - app/Controllers/Settings/Limits.php
    - app/Config/Routes/settings.php
    - app/Views/settings/limits/index.php
    - app/Libraries/Platform/MoneyFormat.php
    - app/Language/sk/Limits.php
    - app/Language/en/Limits.php
key-decisions:
  - "saveOpening whitelists entity_id, year, amount, note, revision; currency is never read (D-07)"
  - "Entity select is disabled with a hidden entity_id when opened from a row link"
  - "Added MoneyFormat::symbol() for the input-group currency addon instead of parsing formatted output"
requirements-completed: [LIM-02]
duration: 35min
completed: 2026-11-02
---

# Phase 10 Plan 08: Opening turnover entry Summary

Owner can enter, change or remove (empty amount) opening turnover per active entity and year from Settings, Limits; the usage card includes it immediately, the currency comes from the entity, and changes are audited by OpeningTurnoverService.

## Tasks

| Task | Commit |
|------|--------|
| 1 Failing feature tests (RED) | e347776 |
| 2 Section, sheet, save (GREEN) | bc50482 |

## Verification

- `phpunit tests/feature/Settings tests/unit/I18n`: OK (103 tests).
- Full `phpunit` (run alone): 2108 tests OK.
- PHPStan (app and views configs): no errors on changed files.
- php-cs-fixer applied to the controller (it also realigned two pre-existing docblocks from 10-07).
- Acceptance greps: no `'currency'` in saveOpening; no `style=`, `onclick=`, `<script` in the new views; opening routes precede the `(:num)` routes.

## Deviations from Plan

**1. [Rule 1 - Copy] Existing lang texts kept.** `errOpeningAmount` already reads "Zadajte sumu 0 alebo vyššiu." (plan test text said "0 alebo viac"), so tests assert the existing text. `errOpeningNote` said 200 characters while the service validates 255; corrected to 255 in sk/en.

**2. [Test adjust]** Flash message is asserted via `session()->getFlashdata('app_notice')` (as other feature tests do) because the redirect consumes it.

**3. [Process]** `composer stan` full run could not write its cache as www-data; PHPStan was run on the changed files instead.

Not covered by an automated test: the iPhone human-check on staging.

## Known Stubs

None.

## Threat Flags

None beyond the plan threat model (T-10-32 to T-10-36: CSRF test, field whitelist plus service validation, revision/409, esc on output, audit delete test).

## Self-Check: PASSED
