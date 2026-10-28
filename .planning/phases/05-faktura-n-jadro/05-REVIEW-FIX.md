---
phase: 05-faktura-n-jadro
fixed_at: 2026-10-27T20:40:00Z
review_path: .planning/phases/05-faktura-n-jadro/05-REVIEW.md
iteration: 1
findings_in_scope: 10
fixed: 8
skipped: 2
status: all_fixed
---

# Phase 5: Code Review Fix Report

**Fixed at:** 2026-10-27T20:40:00Z
**Source review:** .planning/phases/05-faktura-n-jadro/05-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 10 (WR-01..WR-10, fix_scope critical_warning)
- Fixed in this run: 8
- Skipped: 2 (WR-01 and WR-04 were already fixed in quick task 261027-jjx)

Status is `all_fixed` because every in-scope finding is now resolved. The two skipped findings were fixed earlier, not left open.

**Final verification (after all fixes):**
- `make test` (includes `make test-db` with the new migrations 000005 and 000006): OK, 1093 tests, 7410 assertions
- `composer stan`: OK, no errors
- `composer cs`: OK, 0 of 407 files fixable
- `node --test tests/js/`: 47 passed, 0 failed

**Deployment note:** two new migrations need to run in production (`make migrate`):
- `2026-10-27-000005_GuardInvoiceCounterInsert`
- `2026-10-27-000006_GuardInvoiceTruncate`

They do not touch existing rows. The already-applied Phase 5 migrations were not edited.

## Fixed Issues

### WR-02: No upper bound on issue or delivery date

**Files modified:** `app/Libraries/Invoicing/PreviewInput.php`, `app/Language/sk/Invoicing.php`, `app/Language/en/Invoicing.php`, `tests/unit/Invoicing/PreviewInputTest.php`, `tests/unit/Invoicing/IssueInputTest.php`
**Commit:** 6407402
**Applied fix:** `PreviewInput::validate()` now enforces three bounds. `IssueInput` uses the same validation, so preview and issue share them.
- Issue date: at most today + 7 days (`ISSUE_MAX_AHEAD_DAYS`).
- Delivery date: must fall in the billing month or one month either side (`DELIVERY_MONTH_SLACK`). The default (last day of the month) still works, including for next month.
- Due date: at most 12 months after the issue date (`DUE_MAX_AHEAD_MONTHS`).

New sk and en lang keys: `errorIssueFuture`, `errorDeliveryRange`, `errorDueFar`. Unit tests cover the boundaries and the 2062 typo case.

### WR-03: Moving a part from the preview overwrites hours and rate (lost update)

**Files modified:** `app/Libraries/Work/AllocationService.php`, `app/Controllers/Invoicing.php`, `tests/integration/Work/AllocationServiceTest.php`
**Commit:** cbdbddd
**Applied fix:** new `AllocationService::moveMonth(int $id, string $month)`.
- It locks the row in task -> part order and runs `UPDATE ... SET billing_month, updated_at WHERE id AND invoice_id IS NULL`.
- It does not recompute the amount. The audit log records only the `billing_month` change.
- `Invoicing::move()` now calls it instead of `update()` with the stale hours and rate.

Tests cover: a stale read followed by an edit in another tab keeps the new hours and amount, moving to the same month returns false, and a locked part throws.

### WR-05: Variable symbol is the raw document number

**Files modified:** `app/Libraries/Invoicing/SnapshotBuilder.php`, `app/Views/pdf/invoice_inv1.php`, `tests/integration/Invoicing/SnapshotBuilderTest.php`
**Commit:** 8390453 (+ e176f47, a cs alignment fix)
**Applied fix:**
- New `SnapshotBuilder::variableSymbol()`: keeps only the digits of the number, and only the last 10 of them (`FA-2026/001` -> `2026001`). It returns null if the number has no digits.
- The invoice number format is unchanged.
- The PDF template prints the stored `variable_symbol` when the key exists and falls back to the number only for old snapshots that lack the key.
- Already issued invoices keep their stored snapshot and PDF bytes. Only new snapshots are affected.

### WR-06: Gapless guarantee enforced only on counter UPDATE

**Files modified:** `app/Database/Migrations/2026-10-27-000005_GuardInvoiceCounterInsert.php` (new), `tests/integration/Invoicing/InvoiceImmutabilityTest.php`
**Commit:** 885c2bc
**Applied fix:** a new migration replaces `counter_guard()` and re-creates the trigger as `BEFORE INSERT OR UPDATE OR DELETE`.
- A new period row must start at 1, or at `invoice_series.initial_number` when the series has no invoice yet. This is the same rule `NumberAllocator` uses, so the `INSERT ... ON CONFLICT` upsert keeps working.
- It applies to `app_rw` and to the owner. The existing grants are unchanged.
- `down()` restores the original function and trigger.

Tests cover: an insert of 500 or 2 into a series that already has invoices is rejected, and a fresh series with `initial_number` 7 rejects 1 and accepts 7.

### WR-07: "Immutable even for the owner" does not cover TRUNCATE

**Files modified:** `app/Database/Migrations/2026-10-27-000006_GuardInvoiceTruncate.php` (new), `tests/_support/MasterFixtures.php`, `tests/integration/Invoicing/InvoiceImmutabilityTest.php`
**Commit:** 144896f
**Applied fix:** a new migration adds `truncate_guard()` and `BEFORE TRUNCATE ... FOR EACH STATEMENT` triggers on `invoices`, `invoice_lines`, `invoice_documents` and `invoice_counters`.
- Truncating a non-empty table raises `<table> is immutable`. This includes a truncate reached via CASCADE, for example `TRUNCATE clients CASCADE`.
- An empty table may be truncated. Nothing is lost that way, and it keeps the existing `TRUNCATE users ... CASCADE` cleanup in the auth tests working.
- The test fixture `resetMasterTables()` disables the triggers only inside its own transaction (BEGIN, DISABLE, TRUNCATE, ENABLE, COMMIT, with ROLLBACK on error).

The new test checks that an owner TRUNCATE is blocked on each table, directly and via CASCADE.

### WR-08: Unique-violation races map to a generic 500; concurrent idempotency untested

**Files modified:** `app/Libraries/Invoicing/InvoiceIssuer.php`, `tests/_support/issue_worker.php`, `tests/integration/Invoicing/IssueIdempotencyTest.php`, `tests/integration/Invoicing/IssueConcurrencyTest.php`
**Commit:** b6201b6
**Applied fix:**
- When issuing fails with a unique violation on `invoices_request_key_key`, `InvoiceIssuer::issue()` now re-reads the invoice by `request_key` after the rollback and throws `IssueRejected('already_issued', ...)`. The controller already turns that into a redirect to the existing invoice.
- The CI4 Postgre driver does not expose SQLSTATE, so the check matches the constraint name in the pg error message (`isUniqueViolation()`).

Two new tests:
- **Deterministic unique-violation path:** a renderer hook issues a competing invoice with the same key on a second physical connection. The loser gets `already_issued`, and its counter and part locks are rolled back.
- **Two-process test:** both processes submit the *same* command (same key, same parts). The result is exactly one invoice, counter 1, both parts locked, and `already_issued` for the loser.

The worker gained a `--command <json>` mode and reports `rejected` separately. The existing 50-invoice concurrency test now also asserts that there were no rejections.

I confirmed that the deterministic test fails when the new branch is disabled.

### WR-09: Backup verification checks only the TOC; output path relative to repo root

**Files modified:** `scripts/backup.sh`, `docs/ops/backup.md`, `tests/unit/Scripts/BackupScriptTest.php`
**Commit:** 71e090a
**Applied fix:**
- The script now verifies the dump with `pg_restore -f /dev/null`, which reads every data block, instead of `--list`. Checked manually: a truncated dump passes `--list` (exit 0) and fails the new check ("could not read from input file").
- A relative output directory given as an argument is resolved against the caller's cwd before the script changes to the repo root. The default is still `<repo>/backups`.
- The docs now say exactly what is verified and that logical data checks need the restore into a temporary DB.

Tests: the fake `docker` rejects any other pg_restore call. New test: a relative output path is resolved against the caller's directory.

### WR-10: Historical external lock selects by proxy and is irreversible

**Files modified:** `app/Libraries/Invoicing/ExternalInvoiceLocker.php`, `app/Commands/InvoicesLockHistorical.php`, `docs/ops/import-excel.md`, `tests/integration/Invoicing/LockHistoricalTest.php`
**Commit:** 139ea66 (+ 52398f9, a cs alignment fix)
**Applied fix:**
- `ExternalInvoiceLocker` now locks only parts that the import itself created. The marker is that the part's `created_at` is at most 1 minute after its imported task's `created_at`; the import creates the task and the part in one transaction.
- Parts the owner added to an imported task later in the app are left out by default. The dry run lists them by id, and they are included only with the explicit `--include-app-parts` flag. In that case `--expect-*` must also match the larger totals.
- Re-running behaves as before (`nothing_to_lock`).
- On the dev DB, all 12 imported parts have a 0 s gap to their task, so the 2026-09 production run would select the same 11 parts.

Tests cover the default exclusion, the dry-run listing, a commit that skips the in-app part, and the explicit flag including it.

## Skipped Issues

### WR-01: Preview token covers only line amounts

**File:** `app/Libraries/Invoicing/InvoiceCalculator.php:79-89`
**Reason:** skipped: already fixed in 261027-jjx (commits ed09bdf, 2aa58c5, cef426f). Not touched here.
**Original issue:** previewToken hashed only line amounts. The dates, entity version, bank, legal and client data were not checked against the preview.

### WR-04: Line order computed twice; issued PDF can differ from preview across DST

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:96-116`, `app/Libraries/Invoicing/InvoiceParts.php:34-39`
**Reason:** skipped: already fixed in 261027-jjx (commits ed09bdf, 2aa58c5, cef426f). Not touched here.
**Original issue:** the preview sorted lines in SQL by instant, while the issuer sorted by the text of `created_at`. The two can diverge across the October fall-back hour.

## Notes for human verification

- WR-02 bounds (+7 days, ±1 month, +12 months) are product choices. Adjust the constants in `PreviewInput` if the owner wants different windows.
- The WR-10 import marker is time-based (1-minute window). It does not persist "Fakturované = ÁNO"; that data was discarded at import. Imported but unbilled parts of a month are still guarded only by `--expect-*`.
- WR-08 matches on the constraint name in the error text, because the driver gives no SQLSTATE. If the constraint is ever renamed, update `InvoiceIssuer::REQUEST_KEY_CONSTRAINT`.

---

_Fixed: 2026-10-27T20:40:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
