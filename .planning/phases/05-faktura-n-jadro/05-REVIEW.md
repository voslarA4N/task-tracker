---
phase: 05-faktura-n-jadro
reviewed: 2026-10-27T01:48:09Z
depth: standard
files_reviewed: 109
files_reviewed_list:
  - .gitignore
  - app/Commands/InvoicesLockHistorical.php
  - app/Config/Routes/invoices.php
  - app/Config/Routes/invoicing.php
  - app/Config/Services.php
  - app/Controllers/Invoices.php
  - app/Controllers/Invoicing.php
  - app/Database/Migrations/2026-10-27-000001_AddLegalProfilesVatCode.php
  - app/Database/Migrations/2026-10-27-000002_CreateInvoices.php
  - app/Database/Migrations/2026-10-27-000003_LockAllocationsToInvoices.php
  - app/Database/Migrations/2026-10-27-000004_CreateInvoiceStatusView.php
  - app/Language/cs/InvoicePdf.php
  - app/Language/en/Allocations.php
  - app/Language/en/InvoicePdf.php
  - app/Language/en/Invoices.php
  - app/Language/en/Invoicing.php
  - app/Language/pl/InvoicePdf.php
  - app/Language/sk/Allocations.php
  - app/Language/sk/InvoicePdf.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Invoicing.php
  - app/Libraries/Invoicing/AllocatedNumber.php
  - app/Libraries/Invoicing/DocumentIntegrityError.php
  - app/Libraries/Invoicing/DocumentStore.php
  - app/Libraries/Invoicing/Draft.php
  - app/Libraries/Invoicing/ExternalInvoiceLocker.php
  - app/Libraries/Invoicing/InvoiceCalculator.php
  - app/Libraries/Invoicing/InvoiceDraftService.php
  - app/Libraries/Invoicing/InvoiceGuards.php
  - app/Libraries/Invoicing/InvoiceIssuer.php
  - app/Libraries/Invoicing/InvoiceParts.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Invoicing/IssueCommand.php
  - app/Libraries/Invoicing/IssueInput.php
  - app/Libraries/Invoicing/IssueRejected.php
  - app/Libraries/Invoicing/IssuedInvoice.php
  - app/Libraries/Invoicing/MpdfInvoiceRenderer.php
  - app/Libraries/Invoicing/NumberAllocator.php
  - app/Libraries/Invoicing/PdfFormat.php
  - app/Libraries/Invoicing/PdfRenderer.php
  - app/Libraries/Invoicing/PreviewInput.php
  - app/Libraries/Invoicing/RenderedPdf.php
  - app/Libraries/Invoicing/SnapshotBuilder.php
  - app/Libraries/Invoicing/StoredDocument.php
  - app/Libraries/Master/LegalProfileResolver.php
  - app/Libraries/Master/SeriesService.php
  - app/Libraries/Work/TaskQueries.php
  - app/Views/invoices/_list.php
  - app/Views/invoices/index.php
  - app/Views/invoices/show.php
  - app/Views/invoicing/_confirm_sheet.php
  - app/Views/invoicing/_entity_field.php
  - app/Views/invoicing/_move_form.php
  - app/Views/invoicing/_move_sheet.php
  - app/Views/invoicing/_picker.php
  - app/Views/invoicing/_preview.php
  - app/Views/invoicing/index.php
  - app/Views/invoicing/move_page.php
  - app/Views/layouts/_nav_items.php
  - app/Views/pdf/invoice_inv1.php
  - app/Views/tasks/_allocations.php
  - docs/ops/backup.md
  - docs/ops/import-excel.md
  - public/assets/css/app.css
  - public/assets/js/app.js
  - resources/pdf/invoice-inv1.css
  - scripts/backup.sh
  - tests/_support/FakePdfRenderer.php
  - tests/_support/FaultInjectingRenderer.php
  - tests/_support/InvoiceFixtures.php
  - tests/_support/IssueFixtures.php
  - tests/_support/MasterFixtures.php
  - tests/_support/SnapshotSample.php
  - tests/_support/issue_worker.php
  - tests/feature/Invoicing/InvoiceDetailTest.php
  - tests/feature/Invoicing/InvoicePdfEndpointTest.php
  - tests/feature/Invoicing/InvoicesListTest.php
  - tests/feature/Invoicing/InvoicingIssueTest.php
  - tests/feature/Invoicing/InvoicingPreviewTest.php
  - tests/feature/Invoicing/InvoicingScreenTest.php
  - tests/feature/Work/DashboardTest.php
  - tests/integration/Invoicing/AllocationLockTest.php
  - tests/integration/Invoicing/BackdateTest.php
  - tests/integration/Invoicing/CounterLockTest.php
  - tests/integration/Invoicing/CurrencyGuardTest.php
  - tests/integration/Invoicing/InvoiceDocumentTest.php
  - tests/integration/Invoicing/InvoiceDraftServiceTest.php
  - tests/integration/Invoicing/InvoiceImmutabilityTest.php
  - tests/integration/Invoicing/InvoiceImmutableAfterChangeTest.php
  - tests/integration/Invoicing/InvoiceIssuerTest.php
  - tests/integration/Invoicing/InvoiceQueriesTest.php
  - tests/integration/Invoicing/InvoiceSchemaTest.php
  - tests/integration/Invoicing/IssueConcurrencyTest.php
  - tests/integration/Invoicing/IssueIdempotencyTest.php
  - tests/integration/Invoicing/IssueRollbackTest.php
  - tests/integration/Invoicing/LockHistoricalTest.php
  - tests/integration/Invoicing/MpdfInvoiceRendererTest.php
  - tests/integration/Invoicing/NumberAllocatorTest.php
  - tests/integration/Invoicing/SnapshotBuilderTest.php
  - tests/integration/Master/LegalProfileTest.php
  - tests/integration/Master/SeriesServiceTest.php
  - tests/integration/Work/AllocationGuardTest.php
  - tests/integration/Work/TaskQueriesDetailTest.php
  - tests/js/inv-issue.test.js
  - tests/unit/Invoicing/InvoiceCalculatorTest.php
  - tests/unit/Invoicing/IssueInputTest.php
  - tests/unit/Invoicing/PdfFormatTest.php
  - tests/unit/Invoicing/PreviewInputTest.php
  - tests/unit/Scripts/BackupScriptTest.php
findings:
  critical: 0
  warning: 10
  info: 8
  total: 18
status: issues_found
---

# Phase 5: Code Review Report

**Reviewed:** 2026-10-27T01:48:09Z
**Depth:** standard
**Files Reviewed:** 109
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I read all of the Phase 5 production code: the issue pipeline, numbering, DB migrations and triggers, the preview and draft path, the PDF renderer and template, the views, `app.js` and the backup script. I skimmed the tests on the critical paths (concurrency, idempotency, rollback, immutability).

The core design holds up under tracing:
- **Money:** all arithmetic uses bcmath strings with `bcround` HalfAwayFromZero, which matches the PG `round()` CHECK.
- **Numbering:** the counter is incremented inside the issue transaction while `invoice_series` is held FOR UPDATE, so a rollback returns the number.
- **Concurrency:** a double submit with the same `request_key` serializes on the task row locks. The second request then sees `invoice_id` set, re-checks idempotency and returns `already_issued`.
- **Immutability:** `immutable_guard` and insert-only grants are in place. `lock_allocations()` is SECURITY DEFINER and narrowly scoped.
- **Views:** all output is escaped. No inline `style=` or `onclick`, so the views are CSP-safe.
- **PDF:** always served from the stored bytes, with an sha256 check and `no-store`/`nosniff`.

I found no BLOCKER-level defects. The remaining problems:
1. The preview token protects totals only, not the other fields the user confirmed (dates, supplier/bank/legal data).
2. Issue dates have no upper bound, which can produce irreversible far-future invoices that disturb numbering order.
3. A lost-update race in "move part from preview".
4. Line ordering is computed two different ways, which can diverge across a DST change.
5. Database-level guarantees are weaker than the comments claim (counter INSERT, TRUNCATE).
6. Test coverage of the concurrent idempotency path is thin.
7. The backup verification check is weak.

## Warnings

### WR-01: Preview token covers only line amounts, not the confirmed dates, supplier, bank or legal data

**File:** `app/Libraries/Invoicing/InvoiceCalculator.php:79-89`, `app/Libraries/Invoicing/InvoiceIssuer.php:136-138`, `app/Views/invoicing/_preview.php:166-179`
**Issue:** `previewToken()` hashes only `client|month|entity|allocation:hours:rate:amount`. The issuer re-reads the following at issue time and freezes them into the immutable snapshot and PDF without checking them against what was previewed:
- entity version (address, IČO, DIČ)
- bank account
- legal text and VAT code
- client address
- the dates

The dates come from hidden inputs rendered with the last preview. The visible date inputs live outside `#inv-issue-form` and only refresh the preview asynchronously. If the user changes a date and taps "Vystaviť" before the htmx refresh returns (or the refresh fails), the old hidden dates are issued. Similarly, a bank account or entity version change made in another tab between preview and confirm is issued unseen. Totals are protected; other legally relevant fields of a document that can never be changed are not.
**Fix:** Hash everything the user confirms into the token: the dates, `entity.version_id`, the bank IBAN, `legal.vat_code`, `exemption_code`, the vat_note body and a hash of the client header. For example, compute it from the snapshot minus `number`:
```php
public static function previewToken(array $snapshot): string
{
    unset($snapshot['number'], $snapshot['variable_symbol']);
    return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
```
Then compare in `issueInTransaction` with the snapshot built from `$cmd` dates. This also catches the stale-hidden-date race.

### WR-02: No upper bound on issue or delivery date: a mistyped year creates an irreversible future invoice

**File:** `app/Libraries/Invoicing/PreviewInput.php:96-112`, `app/Libraries/Invoicing/IssueInput.php:89-110`, `app/Libraries/Invoicing/InvoiceGuards.php:97-102`
**Issue:** Any parseable date is accepted. The backdate guard only warns about dates *earlier* than the last invoice. A slip on the iPhone date picker (for example 2062 instead of 2026) produces these effects:
- The invoice is immutable.
- With a yearly or monthly format it opens a counter for that far-future period.
- `lastIssue()` then makes every subsequent correct invoice a "backdate" that needs confirmation, permanently breaking the numbers-follow-dates ordering of the series.

The delivery date (which becomes `turnover_date`, the basis for turnover limits in later phases) is not validated against the billing month at all.
**Fix:** Reject `issue_date > today + N days` (for example 7), or require an explicit confirmation like the backdate flow. Constrain `delivery_date` to within or near the billing month, or at least to `<= issue_date + N`:
```php
$today = service('clock')->today();
if ($v['issue_date'] > service('clock')->parseDate($today)->modify('+7 days')->format('Y-m-d')) {
    $errors['issue_date'] = lang('Invoicing.errorFutureDate');
}
```

### WR-03: Moving a part from the preview overwrites hours and rate with values read before the lock (lost update)

**File:** `app/Controllers/Invoicing.php:58-80`
**Issue:** `move()` reads the allocation in `loadPart()` without a lock. It then calls `AllocationService::update($id, ['hours' => $allocation['hours'], 'rate' => $allocation['rate'], 'billing_month' => $target])`. `update()` locks the row and writes all three columns plus a recomputed `amount`. If the part was edited in another tab or device between the read and the lock (for example hours changed from 8 to 10), the move silently restores the old hours and amount. It also recomputes `amount` from rate × hours, which silently rewrites any stored amount that differed (for example legacy imports).
**Fix:** Add a dedicated `AllocationService::moveMonth(int $id, string $month)` that locks the row and updates only `billing_month`, or pass the values read under the lock:
```php
'UPDATE billing_allocations SET billing_month = :month:, updated_at = :now: WHERE id = :id: AND invoice_id IS NULL'
```

### WR-04: Line order is computed twice in different ways; the issued PDF can differ from the preview across a DST change

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:96-116`, `app/Libraries/Invoicing/InvoiceParts.php:34-39`
**Issue:** The preview orders lines in SQL (`ORDER BY t.created_at, a.id`, comparing real instants). The issuer selects `ORDER BY a.id` (for lock ordering) and re-sorts in PHP by the *text* of `created_at`. The DB timezone is `Europe/Bratislava` (`provision.sql:26`), so timestamps created during the October fall-back hour carry different offsets. For example, `02:30:00+02` is earlier than `02:10:00+01`, but string comparison orders them the other way. Positions on the immutable invoice then differ from what the preview showed. The token does not catch this because it sorts by allocation id.
**Fix:** Let the database do the ordering in one place, and lock in a separate statement:
```php
$this->db->query('SELECT a.id FROM billing_allocations a WHERE a.id = ANY(...) ORDER BY a.id FOR UPDATE');
$rows = $this->db->query('SELECT ... FROM billing_allocations a JOIN tasks t ... WHERE a.id = ANY(...) AND ... ORDER BY t.created_at, a.id')->getResultArray();
```
Alternatively, select `extract(epoch from t.created_at)` and compare numerically.

### WR-05: Variable symbol is the raw document number, including letters and separators

**File:** `app/Libraries/Invoicing/SnapshotBuilder.php:99-100,169-173`, `app/Views/pdf/invoice_inv1.php:50`
**Issue:** `variable_symbol` is set to `$number`. `SeriesFormat` allows `A-Za-z/_.-` (for example `FA-2026/001`). A Slovak or Czech variable symbol must be numeric, at most 10 digits, or bank payments cannot be matched. Because the snapshot and PDF are immutable, every invoice issued with such a format carries an unusable VS forever.
**Fix:** Derive the VS as `preg_replace('/\D/', '', $number)`, reject it at issue time if the result is empty or longer than 10 digits, or validate in `SeriesService::update` that the rendered format yields a valid VS.

### WR-06: Gapless guarantee is enforced only on counter UPDATE; INSERT is unguarded at DB level

**File:** `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php:147-159,192`
**Issue:** `counter_guard` fires `BEFORE UPDATE OR DELETE`. `app_rw` has `INSERT` on `invoice_counters` through the default privileges, so any code path or bug can insert a counter row for any period with any `last_value` (for example 500), creating a gap that the DB accepts. The comment says counters allow "only +1 updates". The real invariant ("starts at 1, or at `initial_number` for the first document of the series") is enforced only in PHP (`NumberAllocator`).
**Fix:** Add `BEFORE INSERT` to the guard and allow only `NEW.last_value = 1`, or `NEW.last_value = (SELECT initial_number FROM invoice_series WHERE id = NEW.series_id)` when no invoices exist for the series. Alternatively, `REVOKE INSERT ON invoice_counters FROM app_rw` and allocate through a SECURITY DEFINER function, like `lock_allocations()`.

### WR-07: "Immutable even for the owner" does not cover TRUNCATE

**File:** `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php:11-13,189-192`
**Issue:** The immutability triggers are row-level `BEFORE UPDATE OR DELETE`. `TRUNCATE invoices, invoice_lines, invoice_documents CASCADE` (or `TRUNCATE invoice_counters`, which resets numbering) by `app_owner` bypasses them entirely. The migration and table comments claim protection "aj pre ownera". `app_rw` is correctly denied (the test covers that), but the owner path, which is what migrations and ad-hoc maintenance run as, is open.
**Fix:**
```sql
CREATE TRIGGER invoices_no_truncate BEFORE TRUNCATE ON invoices FOR EACH STATEMENT EXECUTE FUNCTION immutable_guard();
-- same for invoice_lines, invoice_documents, invoice_counters
```
Then add an owner-TRUNCATE assertion to `InvoiceImmutabilityTest`.

### WR-08: Unique-violation races map to a generic 500; the concurrent idempotency path is untested

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:56-67`, `app/Controllers/Invoicing.php:118-130`, `tests/integration/Invoicing/IssueIdempotencyTest.php:55-67`
**Issue:**
- `issue()` translates only `DatabaseException` messages containing "is locked". The issuer itself never triggers that, so the branch is effectively dead (see IN-01).
- A `unique_violation` on `request_key`, `(series_id, number)` or `(entity_id, number)` falls through to `Throwable`, and the user sees "issue failed" (500). htmx then does not swap the response, because `[45]..` has `swap:false`. In that state an invoice may in fact already exist for that key.
- The double-submit protection relies on a subtle READ COMMITTED behaviour: the step-4 re-read after the task lock, followed by the second `assertNotIssued`. Only the sequential case is tested. `IssueConcurrencyTest` uses disjoint parts and distinct keys.

**Fix:** On SQLSTATE 23505 for `invoices_request_key_key`, re-query by `request_key` and throw `IssueRejected('already_issued', …)`. Add a two-process test that submits the *same* command (same key, same parts) concurrently and asserts exactly one invoice, one counter increment and an `already_issued` result for the loser.

### WR-09: Backup "verification" checks only the archive table of contents; the output path is relative to the repo root, not the caller's directory

**File:** `scripts/backup.sh:6,8,17`, `docs/ops/backup.md:3,28-31`
**Issue:** `pg_restore --list` reads only the TOC of a custom-format archive. It does not read or validate the data blocks, which contain all the stored invoice PDFs. A dump with corrupt data passes, yet the docs call it "overený dump". Also, `cd "$(dirname "$0")/.."` runs before `OUT=${1:-backups}`, so `scripts/backup.sh ./mybackups` run from another directory (for example cron with a relative path) writes somewhere other than the operator expects.
**Fix:** Verify the data with `pg_restore -f /dev/null < "$OUT/$NAME.partial"`, which reads every data block, or restore into a scratch DB as in the doc's manual procedure. Resolve `OUT` to an absolute path before the `cd`:
```sh
OUT=$(mkdir -p "${1:-backups}" && cd "${1:-backups}" && pwd)
cd "$(dirname "$0")/.."
```
Adjust the docs wording to match what is actually verified.

### WR-10: Historical external lock selects by a proxy (all imported unlocked parts of the month) and is irreversible

**File:** `app/Libraries/Invoicing/ExternalInvoiceLocker.php:168-177`, `docs/ops/import-excel.md` ("Obmedzenie výberu")
**Issue:** The command locks *every* unlocked allocation of an imported task in the month, including parts that were never billed in Excel and parts added in-app to imported tasks after the import. The only safeguard is that the operator's `--expect-*` values match. The resulting external invoice and locks are immutable, with no correction path in this phase. The docs acknowledge the limitation, but the data needed to select precisely ("Fakturované = ÁNO") is discarded at import.
**Fix:** Persist the source flag at import (for example `billing_allocations.import_billed boolean` or a column on `tasks`) and select on it. At minimum, also restrict to allocations with `created_at <= import timestamp`, and print and require confirmation of the exact allocation id list (`--expect-ids`).

## Info

### IN-01: Dead "is locked" translation in the issuer

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:60-63`
**Issue:** `lock_allocations()` filters `invoice_id IS NULL`, so `alloc_guard`'s "is locked" error cannot arise during issue. Substring matching on exception text is also fragile.
**Fix:** Remove it, or switch to SQLSTATE-based mapping as part of WR-08.

### IN-02: Duplicated logic

**File:** `app/Libraries/Invoicing/InvoiceGuards.php:33-50` vs `app/Libraries/Invoicing/InvoiceCalculator.php:27-35`; `app/Controllers/Invoicing.php:271-279` vs `tests/_support/issue_worker.php:46-50`
**Issue:**
- The currency uniformity check is implemented twice.
- UUIDv4 generation is copy-pasted into the test worker.

**Fix:** Have the calculator use `InvoiceGuards::partsCurrency()`. Extract UUID generation into a small `Uuid::v4()` helper.

### IN-03: Missing PDF label silently frozen as the raw key

**File:** `app/Libraries/Invoicing/SnapshotBuilder.php:88-92`
**Issue:** If a key is missing in `InvoicePdf` for the document language, `lang()` returns `InvoicePdf.xxx`, and that string is stored permanently in the snapshot and printed on the PDF.
**Fix:** Throw `IssueRejected('legal_text_missing')` (or a new reason) when `lang()` returns the key itself.

### IN-04: Alpine `invIssue.toggle` flips state instead of reading the checkbox

**File:** `public/assets/js/app.js:232`
**Issue:** If the browser restores a checked box (bfcache or form restore), `confirmed` starts out false while the box shows checked, and the Issue button disables when the user unchecks it. The server enforces the rule, so this is UX only.
**Fix:** `toggle: function () { this.confirmed = this.$event.target.checked; }`.

### IN-05: Snapshot schema inconsistency between issued and external invoices

**File:** `app/Libraries/Invoicing/ExternalInvoiceLocker.php:108`
**Issue:** `totals.line_count` is a string here but an int in `SnapshotBuilder`. Both are `snapshot_schema = 1`.
**Fix:** Cast to int.

### IN-06: Requested entity silently replaced in the draft

**File:** `app/Libraries/Invoicing/InvoiceDraftService.php:87-95`
**Issue:** If the requested entity is not active at the (changed) issue date, the draft falls back to `entities[0]` without any notice. The user may not notice the supplier switch.
**Fix:** Add a draft notice when `$entityId !== null && $selected !== $entityId`.

### IN-07: Issue failures logged without trace or request key

**File:** `app/Controllers/Invoicing.php:126-130`
**Issue:** Only the class and message are logged. Deferred-trigger and constraint failures on the most critical path are hard to diagnose, and the request key is absent.
**Fix:** Log `request_key`, `client_id`, `entity_id` and `$e->getTraceAsString()` (or pass `exception` in the context).

### IN-08: Size and limits

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:69-200`, `app/Controllers/Invoices.php:18`
**Issue:**
- `issueInTransaction()` is about 130 lines with 14 steps.
- The invoice list is hard-capped at 100 rows with no pagination; the user only sees a "limit" hint.

**Fix:** Extract the lock and load steps (2-4) and the persist steps (10-12) into private methods. Add paging, or a month filter, before the list grows.

---

_Reviewed: 2026-10-27T01:48:09Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
