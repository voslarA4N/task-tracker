---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 13
subsystem: work-ui
tags: [dashboard, htmx, tiles]
requires: ["04-06", "04-10", "04-12"]
provides:
  - "GET / dashboard (Home::index) and GET /fragments/dashboard (Home::body)"
  - "DashboardFilter (month, client whitelist from URL)"
  - "home/index, home/_body, home/_switch_sheet views, Dashboard.* language keys (sk, en)"
affects: []
key-files:
  created:
    - app/Libraries/Work/DashboardFilter.php
    - app/Config/Routes/dashboard.php
    - app/Views/home/_body.php
    - app/Views/home/_switch_sheet.php
    - app/Language/sk/Dashboard.php
    - app/Language/en/Dashboard.php
    - tests/unit/Work/DashboardFilterTest.php
    - tests/feature/Work/DashboardTest.php
  modified:
    - app/Controllers/Home.php
    - app/Views/home/index.php
key-decisions:
  - "Non-JS 'Iný…' month input is named month_other and overrides the month radio in Home::data"
  - "Unknown or archived client in URL falls back to All clients"
requirements-completed: [DSH-01]
duration: 25min
completed: 2026-10-26
---

# Phase 4 Plan 13: Dashboard Summary

Overview (/) shows the current billing month (Clock) and last used client (or All), "To invoice this month" and "In progress" tiles per currency (never summed), task groups with progress bars, a month/client switch sheet and a FAB, refreshed by an htmx fragment on allocationChanged.

## Tasks
1. RED tests - 9ea9e05
2. DashboardFilter, Home, route, views, language - 7ef40e8

## Deviations from Plan
- FAB and link hrefs use `htmlspecialchars` instead of `esc(..., 'attr')` (esc hex-encodes `&` and diacritics, breaking the expected hrefs; same approach as 04-07/04-12).
- Added `month_other` handling in `Home` so the "Iný…" month input works as a plain GET form.
- Partial tasks (in progress) appear in both "Čiastočne vyfakturované" and "V práci" groups, as produced by `TaskQueries::dashboard` (04-06); left unchanged.

## Known Stubs
None.

## Deferred
- Manual iPhone check (PWA: tiles, groups, FAB above the bottom bar, no horizontal scroll, switch sheet updates URL) deferred to end-of-phase human verification.

## Verification
`phpunit tests/unit tests/feature` 634 green (includes ViewsCspTest, I18n), `make stan` OK, `make cs` clean.

## Self-Check: PASSED
