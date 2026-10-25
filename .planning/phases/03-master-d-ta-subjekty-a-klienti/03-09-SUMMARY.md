---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 09
subsystem: master-data
tags: [numbering-series, series-format, entities, audit, htmx, phpunit]
requires: [03-08]
provides:
  - SeriesFormat (parse, render, periodKey, resetsMonthly, digits, maxSequence) pure parser for Phase 5 numbering
  - InvalidSeriesFormat and SeriesRejected exceptions with machine-readable reason
  - SeriesService (listFor, find, update, isFormatLocked), audited, IDOR-safe by (entityId, kind)
  - Settings\Series controller (edit, preview, update), series section on entity detail, form with live preview
affects: [phase-05]
tech-stack:
  added: []
  patterns: [format tokenizer with longest-token-wins, lock hook overridable by subclass for Phase 5]
key-files:
  created:
    - app/Libraries/Master/SeriesFormat.php
    - app/Libraries/Master/InvalidSeriesFormat.php
    - app/Libraries/Master/SeriesRejected.php
    - app/Libraries/Master/SeriesService.php
    - app/Controllers/Settings/Series.php
    - app/Views/settings/entities/series_form.php
    - app/Views/settings/entities/_series_preview.php
    - app/Views/settings/entities/_series.php
    - tests/unit/Master/SeriesFormatTest.php
    - tests/integration/Master/SeriesServiceTest.php
    - tests/feature/Master/SeriesTest.php
  modified:
    - app/Controllers/Settings/Entities.php
    - app/Config/Routes/entities.php
    - app/Views/settings/entities/show.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
    - tests/feature/Auth/AuthGateTest.php
key-decisions:
  - "Sequence max computed with str_repeat('9', digits) because bcmath is restricted to Money by PHPStan and ** may yield float"
  - "Unchanged format and initial number is a no-op (no UPDATE, no audit row)"
  - "Locked series renders the edit link as disabled (aria-disabled) with the hint text"
requirements-completed: [ENT-01]
duration: 45min
completed: 2026-10-25
---

# Phase 3 Plan 09: Entity Numbering Series Summary

Each entity now has a configurable invoice and credit note number format (tokens RRRR, RR, MM, a single # block, literal text) and starting number. The format is parsed and validated by the pure `SeriesFormat` class, edited through a form with a live htmx preview, shown on the entity detail, and every change is audited. No number is allocated in this phase (D-11).

## Tasks
1. SeriesFormat TDD: RED 0347dd8, GREEN 1ad4d78 (30 tests)
2. SeriesService TDD: RED bf78fbd, GREEN f8c3f95 (9 tests, audit, same-as-other, lock via subclass)
3. UI: RED 0ee4949, GREEN b49ba44 (9 feature tests)

## Deviations from Plan

**1. [Rule 3 - Blocking] AuthGateTest route placeholder.** The new `(:segment)` routes are stored by CI4 as `([^/]+)`, which the anonymous-route sweep in `AuthGateTest` did not substitute, producing a BadRequest. Added a replacement for `([^/]+)` (value `invoice`) in the test regex. Commit b49ba44.

**2. [Rule 1 - Static analysis] maxSequence and parse refactor.** `10 ** n` and bcmath are rejected by the project PHPStan rules, so `maxSequence()` uses `str_repeat('9', digits)`. The by-reference flush closure was replaced by merging adjacent literal characters.

**3. `getPost(` grep acceptance.** The plan wants `grep -c 'getPost(' Series.php` = 0 while also asking for a `postString` helper. The helper (same pattern as `Settings\Security`) contains one `getPost($name)` call, so the grep returns 1. Behavior is as intended (string-only input).

**4. Locked state UI.** The edit control is an `<a>` styled disabled (`aria-disabled`, `tabindex=-1`) instead of a `<button disabled>`, since the edit action is a navigation. The locked state is not reachable in Phase 3 (`isFormatLocked` returns false); the form also renders the format field readonly when locked.

## Known Stubs
`SeriesService::isFormatLocked()` always returns false by design (D-10); Phase 5 replaces it with a check for issued documents in the series (documented in PHPDoc).

## Threat Flags
None. T-03-48 (character whitelist, 30 char limit), T-03-49 (same_as_other), T-03-50 (lock check, tested with subclass), T-03-51 (kind whitelist, 404), T-03-52 (audit in the same transaction) are covered by tests.

## Verification
Full PHPUnit suite 600 tests green, JS tests 29 green, `composer stan` clean, `composer cs` clean.

## Self-Check: PASSED
All created files exist; commits 0347dd8, 1ad4d78, bf78fbd, f8c3f95, 0ee4949 and b49ba44 exist.
