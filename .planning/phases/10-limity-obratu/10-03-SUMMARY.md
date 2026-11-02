---
phase: 10-limity-obratu
plan: 03
subsystem: settings-ui
tags: [limits, turnover, settings, views, css, i18n]
requires: [10-02]
provides:
  - GET /settings/limits (Settings\Limits::index) with usage cards and limits list
  - settings/limits/index and _card views with auditable formula
  - LimitSelector::all()
  - Limits lang file (sk, en)
  - .lim-* CSS classes including dashboard .lim-alert* rows
affects: [10-04, 10-05, 10-06]
tech-stack:
  added: []
  patterns: [view only formats, numbers come from TurnoverCalculator, lang params passed as strings to avoid ICU number grouping]
key-files:
  created:
    - app/Controllers/Settings/Limits.php
    - app/Views/settings/limits/index.php
    - app/Views/settings/limits/_card.php
    - app/Language/sk/Limits.php
    - app/Language/en/Limits.php
    - tests/feature/Settings/LimitsControllerTest.php
  modified:
    - app/Libraries/Reporting/LimitSelector.php
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
    - public/assets/css/app.css
key-decisions:
  - "Strictest badge only when more than one status has no error and only on the first card (D-02)"
  - "Entity currency is passed to _card as extra var entityCurrency for the currency-mismatch alert"
  - "Lang args (years, counts, days) passed as strings so MessageFormatter does not group digits"
requirements-completed: [LIM-02, LIM-05, LIM-01, CUR-04]
duration: 25min
completed: 2026-11-02
---

# Phase 10 Plan 03: Limits settings screen Summary

Nastavenia, Limity obratu (`/settings/limits`) shows per active entity a usage card per limit with progress bar, percent, remaining or exceeded amount and an auditable formula (period, documents with invoice and credit note counts, opening turnover, total, limit incl. pro-rata `240 000,00 zł × 170 / 365 = 111 780,82 zł`), plus the full limits list. The view only formats; all numbers come from `TurnoverCalculator`.

## Tasks

| Task | Commit |
|------|--------|
| 1 Failing feature test (RED) | 3ea4c01 |
| 2 Controller, route, menu link, views, lang (GREEN) | 89fc522 |
| 3 `.lim-*` CSS | 33fd5b7 |

## Verification

- `phpunit tests/feature/Settings/LimitsControllerTest.php tests/unit/I18n`: 16 tests OK (12 feature tests; I18n incl. LangKeys, hardcoded text, CSP).
- `composer test`: 2014 tests OK.
- PHPStan on touched code (run after composer test, not concurrently): no errors.
- Acceptance greps: no `style=`/`onclick=`/`<script` in the views, no bcmath or `(float)` in view/controller, no accent colour in the new CSS block.
- Human check pending: on staging, open Viac, Limity obratu on iPhone (single column, no horizontal scroll, readable formula, visible Návrh badge).

## Deviations from Plan

None in behaviour. Minor: added `testNoSeedNoNotice` (12th test) to cover the "notice absent without seed rows" clause; `lang` keys `cardMeta` added beyond the listed set for the card meta line.

## Known Stubs

None. Edit/delete actions and "Pridať limit" are intentionally deferred to 10-04 per plan.

## Threat Flags

None beyond the plan's threat model (route under the same auth filter as `settings/fx`, verified by `testRequiresLogin`; all output through `esc()`).

## Self-Check: PASSED
All created files and commits 3ea4c01, 89fc522, 33fd5b7 verified present.
