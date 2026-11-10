# Quick 261110-g7j Summary

Closes REVIEW 12.1 WR-01 (ErrorException from bundle build now shows Bundle.errorBuild) and IN-01 (non-recursive sortColumn fallback, strict sort-key tests).

## Commits
- 3cbb66a: fix(quick-261110-g7j): show errorBuild for ErrorException in accountant bundle download
- 3f2560e: fix(quick-261110-g7j): non-recursive sort fallback and strict sort-key test

## Changes
- `Services::accountantBundle()` service seam; `Accountant::download()` uses `service('accountantBundle')` and catches `RuntimeException | ErrorException` (no Throwable).
- Feature test (data provider: ErrorException and RuntimeException modes): 500, errorBuild text, non-zip content type, no new file in writable/exports. RED confirmed before the fix (status 200).
- `InvoiceQueries::sortColumn()` falls back directly to `SORT_SQL[DEFAULT_SORT]`. Unit tests: per-key resolution, no dead keys, direct default fallback.

## Deviations
None. (php-cs-fixer renamed the data provider to `provideDownloadBuildFailureShowsErrorBuild`.)

## Verification
- composer stan and composer cs clean.
- Full suite, single run: OK, 2697 tests, 41508 assertions, 1 skipped (pre-existing).
- Not pushed, not deployed.

## Self-Check: PASSED
