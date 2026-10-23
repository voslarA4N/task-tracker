---
phase: 01-z-klad-a-infra-trukt-ra
plan: 03
subsystem: platform
tags: [money, bcmath, clock, tdd]
requires: ["01-01"]
provides:
  - "App\\Libraries\\Platform\\Money (bcmath, half-up, bez float)"
  - "service('clock') a FrozenClock pre testy"
affects: [01-07, 01-08, 01-09]
tech-stack:
  added: []
  patterns: ["bcround HalfAwayFromZero, explicitny scale vo vsetkych bc* volaniach", "Clock jediny zdroj casu v pasme Europe/Bratislava"]
key-files:
  created: [app/Libraries/Platform/Money.php, app/Libraries/Platform/Clock.php, app/Libraries/Platform/FrozenClock.php, tests/unit/Platform/MoneyTest.php, tests/unit/Platform/ClockTest.php]
  modified: [app/Config/Services.php]
key-decisions:
  - "bcmath potvrdene (half-up aj pre zaporne hodnoty, testy zelene), brick/math netreba"
requirements-completed: [NFR-04]
duration: ~10 min
completed: 2026-10-23
---

# Phase 1 Plan 03: Money a Clock Summary

Immutable `Money` nad bcmath (`bcround` s `RoundingMode::HalfAwayFromZero`, normalizacia `-0.00`, strikte regexy, `OverflowException` mimo numeric(12,2)) a `service('clock')` so zmrazitelnym `FrozenClock`, vsetko TDD (RED potom GREEN).

## Tasks
1. Money: RED `9b9e344`, GREEN `91952e2` (32 testov zelenych)
2. Clock: RED (commit `test(01-03): add failing Clock tests`), GREEN `feat(01-03): add Clock service` (spolu 41 testov v tests/unit/Platform zelenych)

## Deviations from Plan
None - plan executed exactly as written.

## Notes for later plans
- **bcmath potvrdene** (half-up pre kladne aj zaporne tie hodnoty, bez -0.00); 01-10 to prenesie do PROJECT.md.
- PHPUnit v kontajneri je 11.5.57.
- `new DateTimeImmutable` je v `app/` iba v `Clock.php` (FrozenClock pouziva staticke `createFromFormat`); PHPStan pravidlo z 01-07 ma povolit oba subory.
- V testoch: `Services::injectMock('clock', new FrozenClock('...'))` a `Services::reset()` v tearDown.

## Known Stubs
None.

## Self-Check: PASSED
