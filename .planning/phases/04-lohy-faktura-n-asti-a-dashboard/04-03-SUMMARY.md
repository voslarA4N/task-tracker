---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 03
subsystem: work-import
tags: [import, xlsx, phpspreadsheet, dry-run, tdd]
requires: ["04-02"]
provides:
  - "ExcelReader::read(path): ImportSheet (hyperlinks, normalisation)"
  - "ImportPlanner::plan(sheet, clientRate, clientCurrency): ImportPlan (dry run)"
  - "ImportFixture generator of anonymised XLSX (dev/tests)"
affects: ["04-09"]
key-files:
  created:
    - app/Libraries/Work/Import/ExcelReader.php
    - app/Libraries/Work/Import/ImportPlanner.php
    - app/Libraries/Work/Import/ImportPlan.php
    - app/Libraries/Work/Import/ImportRow.php
    - app/Libraries/Work/Import/ImportBlock.php
    - app/Libraries/Work/Import/ImportSheet.php
    - tests/_support/ImportFixture.php
    - tests/unit/Work/Import/ExcelReaderTest.php
    - tests/unit/Work/Import/ImportPlannerTest.php
  modified:
    - composer.json
    - composer.lock
decisions:
  - "phpoffice/phpspreadsheet ^5.10 (5.10.0) only in require-dev (D-16); only ExcelReader and the test fixture use PhpOffice"
  - "Float cells converted with var_export, values with more than 2 decimals become null instead of being rounded (PHPStan forbids bcround outside Money/Hours)"
metrics:
  tasks: 3
  completed: 2026-10-26
---

# Phase 4 Plan 03: Import reader and dry-run planner Summary

Zdrojový XLSX sa číta vrátane skutočných bunkových hyperlinkov a čistý `ImportPlanner` z neho urobí suchý beh: sumy prepočíta sadzbou klienta, porovná súčty a rozsahy `SUM` s Excelom a pridelí idempotentné kľúče sha256.

## Tasks

| Task | Name | Commits |
|------|------|---------|
| 1 | Package legitimacy checkpoint (phpoffice/phpspreadsheet) | no changes, owner approved (Packagist metadata verified by orchestrator) |
| 2 | Dev dependency, fixture, ExcelReader | 0e77bbe (test), 210b314 (feat) |
| 3 | ImportPlanner | 30568c4 (feat; test commit precedes it), 8c866c0 (style) |

Test commit for the planner: "test(04-03): add failing ImportPlanner tests" (between 210b314 and 30568c4).

## Verification

- `phpunit tests/unit/Work/Import`: 13 tests, 51 assertions OK
- `composer stan` and `make cs`: clean
- PhpOffice only in `ExcelReader.php` (production code); no float casts in `app/Libraries/Work/Import`; no xlsx tracked in git

## Deviations from Plan

**1. [Rule 1 - Static analysis] bcround and float literals forbidden by project PHPStan rules**
- **Found during:** Task 2 verification
- **Fix:** ExcelReader accepts values with at most 2 decimals via Money::of/Hours::of, otherwise null (flagged later by planner as missing); fixture passes decimals as numeric strings
- **Commit:** 210b314

**2. Fixture design:** rows with text hours ("8,5", NBSP thousands) have no amount formulas, because a text cell cannot be multiplied; the October block has a deliberately incomplete F range and one hand-typed wrong amount.

## Known Stubs

None. The DB writer (ImportRunner, `spark import:excel`) is delivered by 04-09. `clientCurrency` parameter of `plan()` is accepted per the interface but not yet used.

## Self-Check: PASSED

Files and commits 0e77bbe, 210b314, 30568c4, 8c866c0 verified in git log.
