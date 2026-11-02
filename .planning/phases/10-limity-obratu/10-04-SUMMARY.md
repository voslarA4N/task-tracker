---
phase: 10-limity-obratu
plan: 04
subsystem: limits-domain
tags: [limits, turnover, validation, audit, optimistic-locking]
requires: [10-02]
provides:
  - LimitRejected (reason + fields)
  - LimitInput (fromPost, normalize, validate, thresholds, toRow)
  - LimitService (find, create, update, delete, currencyWarning)
  - OpeningTurnoverService (find, listForEntities, validate, save)
affects: [10-07, 10-08]
tech-stack:
  added: []
  patterns: [Transaction::run + SELECT FOR UPDATE + revision, AuditLogger record/recordChange, validation returns field => lang key without calling lang()]
key-files:
  created:
    - app/Libraries/Reporting/LimitRejected.php
    - app/Libraries/Reporting/LimitInput.php
    - app/Libraries/Reporting/LimitService.php
    - app/Libraries/Reporting/OpeningTurnoverService.php
    - tests/unit/Reporting/LimitInputTest.php
    - tests/integration/Reporting/LimitServiceTest.php
    - tests/integration/Reporting/OpeningTurnoverServiceTest.php
  modified: []
key-decisions:
  - "Threshold range 1-200 and count 1-3 are guarded in PHP (A1); DB only has cardinality"
  - "Currency mismatch between limit and country entity is not blocked on save; LimitService::currencyWarning() returns the entity currency for a form warning"
  - "Empty opening turnover amount deletes the record (noop when none exists)"
  - "Duplicate (country, year, name) detected by catching DatabaseException on constraint name turnover_limits_name_uq, for both create and update"
  - "Validation errors carry lang keys (Limits.err*) that 10-07 must add; lang() is not called in domain code"
requirements-completed: [LIM-01, LIM-02, LIM-05]
duration: 25min
completed: 2026-11-02
---

# Phase 10 Plan 04: Limit and opening turnover domain services Summary

Validated, audited, optimistically locked write operations for turnover limits and opening turnover. Screens (10-07, 10-08) only call them.

## Tasks

| Task | Commits |
|------|---------|
| 1 LimitRejected, LimitInput | eb66b45 (test), 93b062c (feat) |
| 2 LimitService | 2bec1d4 (test), c94fa17 (feat) |
| 3 OpeningTurnoverService | ce01f64 (test), 87c1e87 (feat) |

## Verification

- `phpunit tests/integration/Reporting tests/unit/Reporting`: 86 tests OK.
- `phpstan analyse app/Libraries/Reporting tests/integration/Reporting tests/unit/Reporting --memory-limit=1G`: no errors. Whole-project PHPStan reports only "Could not write data to cache file" errors (environmental, same as noted in 10-02), not related to this plan.
- Full `phpunit` (run alone): 2052 tests, only 3 failures, all `MpdfSpikeTest` ("Temporary files directory writable/mpdf/spike/mpdf is not writable"), a pre-existing container permission issue, unrelated.
- Acceptance greps: no `lang(`/float/`is_numeric` in LimitInput, `PeriodResolver::METHODS` used, `FOR UPDATE` x2, `'turnover_limit'` x3, `is_seed = false` present, `currency` count 0 in OpeningTurnoverService.

## Deviations from Plan

**1. [Process] Test and implementation written in the same pass for Tasks 2 and 3.** Separate test and feat commits exist, but the RED run for the integration tests was not executed against a missing class before implementing. Task 1 followed strict RED/GREEN order.

**2. [Rule 3 - Minor]** `LimitService::create` also runs in `Transaction::run` (not required by the plan) so insert and audit are atomic and a failed unique insert does not poison the connection.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-10-12 to T-10-15 mitigated: whitelist validation, revision + FOR UPDATE, parameterised queries, audit pre/post).

## Self-Check: PASSED
