---
phase: 08-meny-a-kurzy
reviewed: 2026-10-30T12:00:00Z
depth: standard
files_reviewed: 78
files_reviewed_list:
  - app/Config/Routes/settings.php
  - app/Config/Services.php
  - app/Controllers/Invoicing.php
  - app/Controllers/Settings/BankAccounts.php
  - app/Controllers/Settings/Clients.php
  - app/Controllers/Settings/Entities.php
  - app/Controllers/Settings/FxSettings.php
  - app/Database/Migrations/2026-10-30-000001_CreateCurrencies.php
  - app/Database/Migrations/2026-10-30-000002_ReplaceCurrencyChecks.php
  - app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php
  - app/Database/Migrations/2026-10-30-000004_CreateExchangeRateCache.php
  - app/Database/Migrations/2026-10-30-000005_AddInvoiceFxChecks.php
  - app/Language/cs/InvoicePdf.php
  - app/Language/en/Currency.php
  - app/Language/en/FxSettings.php
  - app/Language/en/InvoicePdf.php
  - app/Language/en/Invoices.php
  - app/Language/en/Invoicing.php
  - app/Language/pl/InvoicePdf.php
  - app/Language/sk/Currency.php
  - app/Language/sk/FxSettings.php
  - app/Language/sk/InvoicePdf.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Invoicing.php
  - app/Libraries/Fx/CnbRateProvider.php
  - app/Libraries/Fx/CurlRateHttpClient.php
  - app/Libraries/Fx/EcbRateProvider.php
  - app/Libraries/Fx/FxBoundDate.php
  - app/Libraries/Fx/FxContext.php
  - app/Libraries/Fx/FxConverter.php
  - app/Libraries/Fx/FxFormat.php
  - app/Libraries/Fx/FxInput.php
  - app/Libraries/Fx/FxQuote.php
  - app/Libraries/Fx/FxService.php
  - app/Libraries/Fx/FxSettingsRejected.php
  - app/Libraries/Fx/FxSettingsService.php
  - app/Libraries/Fx/HttpResult.php
  - app/Libraries/Fx/NbpRateProvider.php
  - app/Libraries/Fx/RateHttpClient.php
  - app/Libraries/Fx/RateNotFound.php
  - app/Libraries/Fx/RateProvider.php
  - app/Libraries/Fx/RateProviders.php
  - app/Libraries/Fx/RateQuote.php
  - app/Libraries/Fx/RateUnavailable.php
  - app/Libraries/Fx/RateWindow.php
  - app/Libraries/Fx/UnsupportedPair.php
  - app/Libraries/Invoicing/Draft.php
  - app/Libraries/Invoicing/InvoiceDraftService.php
  - app/Libraries/Invoicing/InvoiceGuards.php
  - app/Libraries/Invoicing/InvoiceIssuer.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Invoicing/IssueCommand.php
  - app/Libraries/Invoicing/IssueInput.php
  - app/Libraries/Invoicing/IssueRejected.php
  - app/Libraries/Invoicing/PreviewInput.php
  - app/Libraries/Invoicing/Samples/SampleSnapshots.php
  - app/Libraries/Invoicing/SnapshotBuilder.php
  - app/Libraries/Master/BankAccountInput.php
  - app/Libraries/Master/ClientInput.php
  - app/Libraries/Master/Country.php
  - app/Libraries/Master/CurrencyRepository.php
  - app/Libraries/Master/EntityInput.php
  - app/Libraries/StagingSeed/Catalog.php
  - app/Libraries/StagingSeed/Modules/Invoices.php
  - app/Libraries/StagingSeed/Profile.php
  - app/Views/invoices/_list.php
  - app/Views/invoices/show.php
  - app/Views/invoicing/_confirm_sheet.php
  - app/Views/invoicing/_fx_card.php
  - app/Views/invoicing/_preview.php
  - app/Views/more/index.php
  - app/Views/pdf/invoice_inv2.php
  - app/Views/settings/clients/form.php
  - app/Views/settings/entities/_regime_fields.php
  - app/Views/settings/fx/index.php
  - app/Views/settings/legal_texts/index.php
  - public/assets/css/app.css
  - resources/pdf/invoice-inv2.css
findings:
  critical: 2
  warning: 7
  info: 5
  total: 14
status: issues_found
---

# Phase 8: Code Review Report

**Reviewed:** 2026-10-30T12:00:00Z
**Depth:** standard
**Files Reviewed:** 78 (non-test source; the test files were skimmed for false positives)
**Status:** issues_found

## Summary

I reviewed the Phase 8 currency and FX work: the currencies table and FKs, FX math, the NBP/ECB/CNB providers, the HTTP client, the rate cache and FxService, the settings page, snapshot schema 3, the issuer, preview, manual entry, the detail and list views, and the staging seed.

Several areas are correct:
- **Conversion math:** the direction, the CNB `amount` handling, and truncating at 12 places before a single half-up to 2 places are all right.
- **Preview token vs issue:** an invoice cannot be issued with a rate different from the one previewed. The token hashes the whole `fx` block, and the issuer rebuilds that block from the same cache row and manual input.
- **Escaping, CSRF and CSP:** all FX text output is escaped, the new POST route is covered by the global CSRF filter, and no inline style or script was added.
- **Provider URLs:** URL parameters are whitelisted (`[A-Z]{3}` currency, parsed dates), so I found no SSRF vector.

There are two blockers:
1. **No live rate is ever fetched in production.** `CurlRateHttpClient` builds the CI4 HTTP client with a wrong argument. Every fetch throws a `TypeError`, the client swallows it as `http_error`, and the user falls back to manual entry on every invoice. I confirmed this inside the running php container.
2. **The rate cache can freeze a wrong rate.** A row fetched before the bound date's rate was published becomes "final" once that date passes. After that it cannot be refreshed, not even with Retry. This hits the CZ default (`on_or_before`) and any future bound date.

Tests did not catch either problem. Every test injects `FakeRateHttpClient`, and `FxServiceCacheTest::testFinalRowNeverOverwritten` asserts the freeze behaviour as intended.

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: CurlRateHttpClient always fails, so production never gets a live rate

**File:** `app/Libraries/Fx/CurlRateHttpClient.php:17-24`
**Issue:** `service('curlrequest', [...], false)` resolves to `Services::curlrequest(array $options, ?ResponseInterface $response = null, ?App $config = null, bool $getShared = true)`. The `false` was meant as `$getShared`, but it lands in the `$response` slot, which raises a `TypeError`. I reproduced this in `task-tracker-php-1`:

```
TypeError: CodeIgniter\Config\Services::curlrequest(): Argument #2 ($response) must be of type ?CodeIgniter\HTTP\ResponseInterface, false given
```

`catch (Throwable)` turns this into `RateUnavailable('http_error')`. As a result:
- Every preview for a foreign-currency invoice shows "rate unavailable".
- A negative-cache entry is written for 60 s.
- Only manual rates can ever be issued.

The log line in FxService reports only `reason=http_error`, so the programming error is invisible. Staging hides the problem because the seed inserts rates straight into `exchange_rate_cache`. No test exercises `CurlRateHttpClient`.

**Fix:**
```php
$client = single_service('curlrequest', [
    'timeout'         => 3,
    'connect_timeout' => 2,
    'http_errors'     => false,
    'allow_redirects' => false,
    'headers'         => ['Accept' => 'application/json, text/csv, text/plain'],
]);
// or: \Config\Services::curlrequest([...], null, null, false);
...
} catch (\CodeIgniter\HTTP\Exceptions\HTTPException $e) {   // only transport errors
    throw new RateUnavailable('http_error');
}
```
Let `TypeError` and other `Error`s propagate. Also add an integration test that builds the real client (for example `service('rateHttpClient')`) and asserts it does not throw before any network I/O. One way is to request a non-routable URL with a short timeout and expect `RateUnavailable`, not a `TypeError` hidden behind it. Log the exception class in `FxService::resolve`.

### CR-02: The rate cache freezes rates fetched before publication, and Retry cannot fix them

**File:** `app/Libraries/Fx/FxService.php:64, 132-135, 162-170`
**Issue:** `isFinal()` is just `bound < today`. It ignores when the row was fetched and whether the rate for the bound date was already published at that time. The upsert also refuses to update once `bound_date < today` (`WHERE exchange_rate_cache.bound_date >= :today:`).

Example:
1. A CZ entity uses the default `on_or_before` rule with delivery date D.
2. The user previews on D at 09:00. CNB has not published D yet (it publishes at 14:30), so the provider returns the rate from D-1, and it is cached under `bound_date = D`.
3. From D+1 onward the row counts as final. `resolve()` returns it before the retry logic is reached, and the upsert's WHERE blocks any refresh.
4. Every later invoice with delivery date D, including one issued days later, silently uses the D-1 rate. That rate is written into an immutable tax document.

Future bound dates make this worse. I checked live: CNB `daily?date=2026-11-05` and ECB `endPeriod=2026-11-05` both return the latest available (older) rate. That rate is cached under the future bound date and becomes final as soon as that date passes, even if nobody re-fetches on the day. `FxServiceCacheTest::testFinalRowNeverOverwritten` asserts the current behaviour, so it encodes the bug.

**Fix:** Treat a row as final only if it was fetched after the bound date's publication window. Allow the upsert to replace non-final rows:
```php
private function isFinal(FxContext $ctx, array $row): bool
{
    // fetched on a later calendar day than bound => the bound day's rate was already published
    return $ctx->bound < service('clock')->today()
        && substr((string) $row['fetched_at'], 0, 10) > $ctx->bound;
}
```
```sql
... ON CONFLICT (source, currency, bound_date) DO UPDATE SET ...
WHERE exchange_rate_cache.bound_date >= :today:
   OR exchange_rate_cache.fetched_at::date <= exchange_rate_cache.bound_date
```
For `bound > today`, either refuse to resolve (see WR-01) or never mark the row final. Update the test so it asserts the corrected rule.

## Warnings

### WR-01: Providers handle a future bound date inconsistently

**File:** `app/Libraries/Fx/NbpRateProvider.php:42-44`, `app/Libraries/Fx/EcbRateProvider.php:38-46`, `app/Libraries/Fx/CnbRateProvider.php:44-52`
**Issue:** A bound date in the future can happen when the delivery or issue date is in the future, or with `on_or_before` and today's date. In that case:
- **NBP** returns 400 (confirmed live for a range ending 2026-11-04). The code maps this to `RateNotFound`, which is never negatively cached, so every htmx preview hits the API again and the user must type a manual rate.
- **ECB and CNB** return the latest older rate and present it as the rate for that future date.

The same invoice parameters therefore behave differently per country. The ECB/CNB path also feeds CR-02.

**Fix:** In `FxService::resolve()` (or `RateWindow::range()`), decide once what happens when `bound > today`. The most defensible option is `RateNotFound('not_yet_published')` for all sources, plus a dedicated UI message. The alternative is to clamp to today and mark the quote as provisional, never final.

### WR-02: A manual rate date can be later than the anchor or bound date

**File:** `app/Libraries/Fx/FxInput.php:291-307`, `app/Libraries/Invoicing/InvoiceIssuer.php:176-182`
**Issue:** `fx_date` is checked only against today and today minus one year. For delivery date 2026-09-30, a manual rate dated 2026-10-29 is accepted and stored in `invoices.fx_date` and the snapshot, although no valid rate for that delivery could come from after it. The cache table has a `valid_for <= bound_date` CHECK, but neither the manual path nor `invoices` has one. `SampleSnapshots::fxInvoice()` even builds a manual sample dated 2026-10-28 for anchor 2026-10-26, and an NBP sample with `valid_for` 2026-10-27 for anchor 2026-10-26 under `previous_business_day`.

**Fix:** Pass `$ctx->bound` (or the anchor date) into validation and reject `date > bound` with a new `date_after_bound` error. Mirror this in the issuer before `FxQuote::manual()`. Correct the sample dates.

### WR-03: A manual rate has no plausibility check against the fetched rate

**File:** `app/Libraries/Fx/FxInput.php:320-348`, `app/Libraries/Invoicing/InvoiceDraftService.php:175-178`
**Issue:** Any rate in `(0, 9999999999.99999999]` is accepted. A typo such as `42512` instead of `4.2512`, or a reversed ECB direction (`0.2352` instead of `4.2512`), produces a wildly wrong `total_entity_ccy`. That value counts toward the turnover limits and is frozen on the invoice. A fetched rate is often available as `$fetched`, but it is shown only as a placeholder.

**Fix:** When `$fetched !== null` and the manual rate deviates by more than about 10%, set a `deviation` warning that requires explicit confirmation, similar to `confirm_backdate`. Show it in the confirmation sheet.

### WR-04: Providers trust row order and the date range without checking, and an out-of-range date causes a 500

**File:** `app/Libraries/Fx/EcbRateProvider.php:65-72`, `app/Libraries/Fx/NbpRateProvider.php:70-77`
**Issue:**
- ECB takes the last CSV line and NBP takes `end($rates)`, assuming the rows are sorted ascending.
- Neither checks that the returned date is `<= bound`, which CNB does in `quote()`, nor, for ECB, that the `CURRENCY` column matches.
- If an API ever returns a later date, `upsert()` violates `CHECK (valid_for <= bound_date)`. That raises a `DatabaseException`, which `InvoiceDraftService` does not catch, so the preview fails with a 500 instead of the "unavailable" state.

**Fix:** Select the row with the maximum date that is `<= bound`, and check that the currency matches for ECB. Throw `RateNotFound` or `RateUnavailable('parse_error')` otherwise. Apply the same `validFor > bound` guard as CNB.

### WR-05: The invoice list and detail page take the entity currency from the live `entities` row instead of the snapshot

**File:** `app/Libraries/Invoicing/InvoiceQueries.php:53-56, 83-85`, `app/Views/invoices/show.php:33`, `app/Views/invoices/_list.php:56`
**Issue:** The currency code shown next to `total_entity_ccy` comes from `LEFT JOIN entities e`. The value is already frozen in `snapshot.fx.entity_currency`. `entities.currency` is currently immutable at the DB level, but this breaks the project rule that issued documents render only from the snapshot. With the LEFT JOIN, a missing entity row would also format the amount with an empty currency.

**Fix:** Use `i.snapshot->'fx'->>'entity_currency' AS entity_currency` (or `$snap['fx']['entity_currency']` in `show.php`) and drop the join.

### WR-06: A client with an inactive currency cannot be saved

**File:** `app/Controllers/Settings/Clients.php:84, 139-145`, `app/Libraries/Master/ClientInput.php:214-218`
**Issue:** `currencyOptions()` adds the record's current currency even when it is inactive. However, `ClientInput::validate()` accepts only active currencies. If a currency is later deactivated, a client using it (often with a locked currency, see `ClientCurrencyLocked`) can no longer be edited, because every save returns 422 on `currency`.

**Fix:** On edit, accept the unchanged current currency even if inactive. For example, pass a callable that checks `$c === $existing['currency'] || $repo->isActive($c)`.

### WR-07: A currency mismatch in fxBlock ends as an opaque 500

**File:** `app/Libraries/Invoicing/SnapshotBuilder.php:196-198`, `app/Controllers/Invoicing.php:130-134`
**Issue:** `fxBlock()` throws `LogicException` when the quote currencies differ from the invoice. In the issuer this is caught only by the generic `Throwable` handler (500, "errorFailed"). In the preview it is not caught at all. The case can happen if `partsCurrency()` and `$calc['currency']` ever diverge.

**Fix:** Throw `IssueRejected('fx_missing')` (or a dedicated reason), or assert equality earlier in `InvoiceIssuer` and `InvoiceDraftService` and map it to a user-facing message.

## Info

### IN-01: The detail page hardcodes the Slovak number format for the rate

**File:** `app/Views/invoices/show.php:186, 190`
**Issue:** `FxFormat::expression($fx, 'sk')` ignores the UI locale, so English users see `4,2512`. The preview and confirmation sheet use `getLocale()`.
**Fix:** Use `service('request')->getLocale()`.

### IN-02: `currencies.decimals` is unused

**File:** `app/Libraries/Master/CurrencyRepository.php:66-71`, `app/Libraries/Fx/FxConverter.php:31`
**Issue:** `decimals()` has no callers, and `FxConverter` always rounds to 2 places. The column suggests a configurability that does not exist.
**Fix:** Remove the column and method, or document that only 2 decimal places are supported (the DB check already allows 0-4).

### IN-03: The staging seed hardcodes an NBP table number for any source

**File:** `app/Libraries/StagingSeed/Modules/Invoices.php:157`
**Issue:** `'209/A/NBP/2026'` is written even if the context source is ECB or CNB, for example after the FX settings change on staging.
**Fix:** Use `$fx->source === 'NBP' ? '209/A/NBP/2026' : null`.

### IN-04: Settings allow source and country combinations that can never resolve

**File:** `app/Libraries/Fx/FxSettingsService.php:172-195`
**Issue:** For example, SK can be set to NBP. Every SK/EUR entity then silently becomes `unsupported`, and only a generic warning text is shown.
**Fix:** Reject or confirm combinations where `RateProviders::HOME[source]` differs from all active entity currencies in that country.

### IN-05: Tests encode the defects instead of catching them

**File:** `tests/integration/Fx/FxServiceCacheTest.php:105` (`testFinalRowNeverOverwritten`), no test for `CurlRateHttpClient`
**Issue:** The cache test asserts the CR-02 behaviour. The production HTTP client has no coverage at all, which is how CR-01 shipped.
**Fix:** Update the test together with CR-02. Add a construction test for `CurlRateHttpClient`.

---

_Reviewed: 2026-10-30T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
