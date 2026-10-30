---
phase: 08-meny-a-kurzy
fixed_at: 2026-10-30T12:46:00Z
review_path: .planning/phases/08-meny-a-kurzy/08-REVIEW.md
iteration: 1
findings_in_scope: 9
fixed: 9
skipped: 0
status: fixed
---

# Phase 8: Code Review Fix Report

**Fixed at:** 2026-10-30T12:46:00Z
**Source review:** .planning/phases/08-meny-a-kurzy/08-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 9 (CR-01, CR-02, WR-01 to WR-07)
- Fixed: 9
- Skipped: 0
- Info items (optional): IN-01 and IN-03 fixed. IN-05 is covered by the CR-01 and CR-02 tests. IN-02 and IN-04 were left open (see below).

**Verification:** `make stan` passes. `make test` passes with 1798 tests and 24684 assertions (the previous run had 1770 tests).

## Fixed Issues

### CR-01: CurlRateHttpClient always fails, so production never gets a live rate

**Files modified:** `app/Libraries/Fx/CurlRateHttpClient.php`, `app/Libraries/Fx/RateUnavailable.php`, `app/Libraries/Fx/FxService.php`, `tests/unit/Fx/CurlRateHttpClientTest.php`
**Commits:** 3efb7ea, 78fbd80 (PHPStan follow-up)
**Applied fix:**
- The client is now built with `single_service('curlrequest', OPTIONS)`.
- Only `HTTPException` is mapped to `RateUnavailable('http_error')`. A `TypeError` or other `Error` now propagates instead of being swallowed.
- `RateUnavailable` keeps the original exception as `previous`, and `FxService` logs its class as `cause`.
- An optional `CURLRequest` constructor argument lets tests inject a mock.

New tests:
- The real `CURLRequest` is built and sent to `127.0.0.1:1`, a closed local port, so no network is used. The test expects `http_error` with an `HTTPException` as the cause. Against the old code it fails with the TypeError.
- A `MockCURLRequest` test checks status and body mapping and that the timeouts and TLS verification are set.

### CR-02: The rate cache freezes rates fetched before publication, and Retry cannot fix them

**Files modified:** `app/Libraries/Fx/FxService.php`, `tests/integration/Fx/FxServiceCacheTest.php`
**Commit:** 82bcb94
**Status:** fixed, needs human verification (logic change)
**Applied fix:**
- A cache row is final only when `bound_date < today` and the row was fetched on a calendar day after `bound_date`. The fetch day is computed as `(fetched_at AT TIME ZONE app tz)::date`, which is Europe/Bratislava from `service('clock')`.
- A single SQL predicate (`FINAL_SQL`) drives both `selectRow()` and the upsert's `WHERE NOT final`.
- Rows that are not final follow the normal 15-minute refresh and Retry, and the upsert may overwrite them. Final rows are still never overwritten.
- `testFinalRowNeverOverwritten` now uses a row fetched after the bound day and checks that Retry makes no HTTP call.
- New tests cover:
  - a row fetched before publication being refreshed after the bound day (and final after that refresh),
  - the outage fallback to that row,
  - the Bratislava-midnight boundary in both directions.

Effects on other parts:
- Issued invoices are unaffected, because they read only their snapshot.
- If the rate refreshes between preview and issue, the preview token changes and the issue is rejected as `preview_stale`. This is intended.
- The staging seed inserts rows with `fetched_at = now` for past bound dates, so those rows stay final.

### WR-01: Providers handle a future bound date inconsistently

**Files modified:** `app/Libraries/Fx/FxService.php`, `app/Libraries/Fx/RateNotFound.php`, `app/Libraries/Invoicing/InvoiceDraftService.php`, `app/Libraries/Invoicing/Draft.php`, `app/Views/invoicing/_fx_card.php`, `app/Views/invoicing/_preview.php`, `app/Language/{en,sk}/Currency.php`, and tests (`FxServiceCacheTest`, `InvoiceDraftFxTest`, `InvoicingFxPreviewTest`, `PreviewTokenScopeTest`)
**Commits:** 4de3538, 35c90b3 (test follow-up)
**Applied fix:** I chose to reject a future bound for every source. `resolve()` throws `RateNotFound(reason: not_yet_published)` when `bound > today`, before touching the cache or the network. `cached()` returns null in that case. As a result:
- NBP no longer re-queries the API on every preview.
- ECB and CNB no longer present an older rate as the rate for a future day.

The preview shows its own message (`Currency.futureBoundBody`) without the Retry button, and the user enters the rate manually. A bound equal to today is still fetched; under CR-02 that row is not final and keeps refreshing.

### WR-02: A manual rate date can be later than the anchor or bound date

**Files modified:** `app/Libraries/Fx/FxInput.php`, `app/Libraries/Invoicing/InvoiceDraftService.php`, `app/Libraries/Invoicing/InvoiceIssuer.php`, `app/Libraries/Invoicing/Samples/SampleSnapshots.php`, `app/Views/invoicing/_fx_card.php`, `app/Language/{en,sk}/Currency.php`, `tests/unit/Fx/FxInputTest.php`, `tests/integration/Invoicing/InvoiceManualFxTest.php`
**Commits:** 7d22a8b, d333b28 (cs alignment)
**Applied fix:**
- New `FxInput::withBound($bound)` adds a `date_after_bound` error. Both the draft and the issuer apply it; in the issuer it results in `fx_invalid`.
- A manual rate without a date now defaults to `min(bound, today)`, so its date is never in the future. This is consistent with WR-01.
- The PDF fx sample was corrected: delivery and issue are now 2026-10-28, so the bound is 2026-10-27. The NBP rate is from 2026-10-27 and the manual rate is dated 2026-10-27.

### WR-03: A manual rate has no plausibility check against the fetched rate

**Files modified:** `app/Libraries/Fx/FxConverter.php`, `app/Libraries/Invoicing/InvoiceDraftService.php`, `app/Libraries/Invoicing/Draft.php`, `app/Views/invoicing/_fx_card.php`, `app/Views/invoicing/_confirm_sheet.php`, `app/Language/{en,sk}/Currency.php`, and tests (`FxConverterTest`, `InvoiceManualFxTest`, `InvoicingFxPreviewTest`)
**Commit:** 3b68924
**Status:** fixed, needs human verification (a design choice that departs from the review)
**Applied fix:**
- New `FxConverter::deviationExceeds()` compares the per-unit rates (`rate / amount`) with bcmath against a 10 % limit (`DEVIATION_PERCENT`).
- The draft sets `fx.deviation`, and a warning appears in both the FX card and the confirmation sheet.

**Departure from the review:** the review suggested a blocking confirmation, similar to `confirm_backdate`. D-11 (owner, 2026-10-30) says a manual override needs no reason, so I made it a visible warning instead of a mandatory extra step. If the owner wants it to block, it can be extended later with a checkbox and an issuer guard.

### WR-04: Providers trust row order and the date range without checking, and an out-of-range date causes a 500

**Files modified:** `app/Libraries/Fx/NbpRateProvider.php`, `app/Libraries/Fx/EcbRateProvider.php`, `tests/unit/Fx/NbpRateProviderTest.php`, `tests/unit/Fx/EcbRateProviderTest.php`, `tests/integration/Fx/FxServiceCacheTest.php`
**Commit:** 5ee8ee3
**Applied fix:**
- NBP and ECB now scan every row, ignore rows dated after the bound, and pick the row with the latest date. ECB also ignores rows for another currency.
- A malformed date or a missing `CURRENCY` column is now `parse_error`.
- An integration test feeds a full-range response for an earlier bound and confirms there is no CHECK violation.

### WR-05: The invoice list and detail page take the entity currency from the live `entities` row instead of the snapshot

**Files modified:** `app/Libraries/Invoicing/InvoiceQueries.php`, `app/Views/invoices/show.php`, `app/Views/invoices/_list.php`, `tests/feature/Invoicing/InvoiceFxDisplayTest.php`
**Commit:** ebc0c80
**Applied fix:**
- The list now reads `f.snapshot->'fx'->>'entity_currency'`, and the detail page reads `$snap['fx']['entity_currency']`.
- Both `LEFT JOIN entities` were removed.
- The list hides the second total when the snapshot has no entity currency.

### WR-06: A client with an inactive currency cannot be saved

**Files modified:** `app/Controllers/Settings/Clients.php`, `tests/feature/Master/CurrencyListTest.php`
**Commit:** eb0b739
**Applied fix:**
- `update()` now passes a validator that accepts the client's current currency unchanged, even if it is inactive. Any other currency must still be active.
- The test fails against the old code. It also checks that switching to a different inactive currency is still rejected with 422.

### WR-07: A currency mismatch in fxBlock ends as an opaque 500

**Files modified:** `app/Libraries/Invoicing/SnapshotBuilder.php`, `tests/integration/Invoicing/SnapshotBuilderFxTest.php`
**Commit:** 841b9dd
**Applied fix:** `fxBlock()` logs the mismatched currencies and throws `IssueRejected('fx_missing', ['cause' => 'currency_mismatch'])` instead of `LogicException`. The preview (through the draft error list) and the issue endpoint (through `rejected()`) both show it as a readable message.

## Info Items

- **IN-01** fixed (de04f30): the detail page formats the rate with `service('request')->getLocale()`.
- **IN-03** fixed (b262426): the staging seed writes the NBP table number only when the source is NBP.
- **IN-05** covered by the CR-01 test (real client construction) and the CR-02 cache test rewrite.
- **IN-02** not fixed (optional, not trivial). Removing `currencies.decimals` needs a new migration, and documenting it is a product decision.
- **IN-04** not fixed (optional, not trivial). Rejecting or confirming source and country combinations changes the FX settings UX and validation, so it needs an owner decision.

## Notes

- PHP-CS-Fixer was applied only to code these fixes touched. Several touched files already had style differences, for example the `@throws` order in `FxService`, `FxConverterTest`, the `Clients` controller arrays, and `CurrencyListTest`. I left those alone, as instructed.

---

_Fixed: 2026-10-30T12:46:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
