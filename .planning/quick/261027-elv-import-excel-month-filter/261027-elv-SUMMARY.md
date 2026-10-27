# Quick 261027-elv: import:excel --month filter Summary

`spark import:excel` gets `--month RRRR-MM`, implemented as `ImportSheet::forMonth` applied before planning; planner, runner and formatter untouched, import_key identical with and without the filter.

## Commits
- 202be44 ImportSheet::forMonth + unit tests
- (task 2) --month option + integration tests incl. real file
- (task 3) docs/ops/import-excel.md, stan and cs fixes

## Deviations
- [Rule 1 - plan assumption] The plan expected a `POZOR:` line for 09/2026 in the real file. The real file has no "Fakturované = ÁNO" rows, so no POZOR line is printed (also unfiltered: 0). Real-file test does not assert POZOR; docs state the line appears only when such rows exist and that `--expect-*` values then come from the Zapracované totals (11 / 89 / 2403).
- Post-task fix: PHPStan alreadyNarrowedType in ImportExcel and cs-fixer formatting, folded into the docs commit.

## Verification
Full PHPUnit 1071 tests OK, stan OK, cs OK.
