---
phase: 11-preh-ady-exporty-a-audit
reviewed: 2026-11-04T00:00:00Z
depth: standard
files_reviewed: 95
files_reviewed_list:
  - app/Config/Routes/accountant.php
  - app/Config/Routes/invoices.php
  - app/Config/Routes/reports.php
  - app/Config/Routes/settings.php
  - app/Controllers/Accountant.php
  - app/Controllers/Invoices.php
  - app/Controllers/Reports.php
  - app/Controllers/Settings/Audit.php
  - app/Controllers/Settings/Users.php
  - app/Controllers/Tasks.php
  - app/Language/en/AuditLog.php
  - app/Language/en/Bundle.php
  - app/Language/en/Export.php
  - app/Language/en/Invoices.php
  - app/Language/en/Reports.php
  - app/Language/en/Tasks.php
  - app/Language/en/Users.php
  - app/Language/sk/AuditLog.php
  - app/Language/sk/Bundle.php
  - app/Language/sk/Export.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Reports.php
  - app/Language/sk/Tasks.php
  - app/Language/sk/Users.php
  - app/Libraries/Audit/AuditDiff.php
  - app/Libraries/Audit/AuditFilter.php
  - app/Libraries/Audit/AuditQueries.php
  - app/Libraries/Auth/UserDirectory.php
  - app/Libraries/Export/AccountantBundle.php
  - app/Libraries/Export/BundleRejected.php
  - app/Libraries/Export/CsvWriter.php
  - app/Libraries/Export/DocumentListExport.php
  - app/Libraries/Export/ExportCell.php
  - app/Libraries/Export/ExportFilename.php
  - app/Libraries/Export/ExportResponse.php
  - app/Libraries/Export/ExportTable.php
  - app/Libraries/Export/FormulaGuard.php
  - app/Libraries/Export/ReportTable.php
  - app/Libraries/Export/XlsxNumber.php
  - app/Libraries/Export/XlsxWriter.php
  - app/Libraries/Invoicing/InvoiceFilter.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Reporting/BundleSelector.php
  - app/Libraries/Reporting/ReportCalculator.php
  - app/Libraries/Reporting/ReportMath.php
  - app/Libraries/Reporting/ReportPeriod.php
  - app/Libraries/Reporting/ReportResult.php
  - app/Libraries/Work/TaskFilter.php
  - app/Libraries/Work/TaskQueries.php
  - app/Views/invoices/_list.php
  - app/Views/invoices/_status_badges.php
  - app/Views/invoices/index.php
  - app/Views/invoicing/_fx_card.php
  - app/Views/layouts/_nav_items.php
  - app/Views/layouts/app.php
  - app/Views/more/index.php
  - app/Views/reports/_export.php
  - app/Views/reports/_header.php
  - app/Views/reports/_month_body.php
  - app/Views/reports/_year_body.php
  - app/Views/reports/accountant/_preview.php
  - app/Views/reports/accountant/index.php
  - app/Views/reports/month.php
  - app/Views/reports/year.php
  - app/Views/settings/_devices.php
  - app/Views/settings/audit/_detail.php
  - app/Views/settings/audit/_detail_sheet.php
  - app/Views/settings/audit/_list.php
  - app/Views/settings/audit/index.php
  - app/Views/settings/audit/show.php
  - app/Views/settings/backup_codes.php
  - app/Views/settings/clients/form.php
  - app/Views/settings/clients/index.php
  - app/Views/settings/email_templates/edit.php
  - app/Views/settings/email_templates/index.php
  - app/Views/settings/entities/_bank_accounts.php
  - app/Views/settings/entities/bank_account_form.php
  - app/Views/settings/entities/edit.php
  - app/Views/settings/entities/index.php
  - app/Views/settings/entities/new.php
  - app/Views/settings/entities/series_form.php
  - app/Views/settings/entities/show.php
  - app/Views/settings/entities/version_new.php
  - app/Views/settings/fx/index.php
  - app/Views/settings/legal_texts/edit.php
  - app/Views/settings/legal_texts/index.php
  - app/Views/settings/legal_texts/new.php
  - app/Views/settings/security.php
  - app/Views/settings/totp_setup.php
  - app/Views/settings/users/index.php
  - app/Views/tasks/_list.php
  - app/Views/tasks/index.php
  - composer.json
  - public/assets/css/app.css
  - public/assets/js/app.js
findings:
  critical: 0
  warning: 4
  info: 8
  total: 12
status: issues_found
---

# Phase 11: Code Review Report

**Reviewed:** 2026-11-04
**Depth:** standard
**Files Reviewed:** 95 source files (tests/ used as context only)
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I traced the monetary paths end to end: ReportCalculator, ReportMath::paidShare, BundleSelector, then into ReportTable, CsvWriter, XlsxWriter and the accountant ZIP.

- **Sums:** they agree with TurnoverCalculator. Both use `origin='issued'`, `turnover_date` and `total_entity_ccy`, and `counted` uses `counts_toward_limit`.
- **Payments:** voided payments are excluded before the window `SUM`, so cumulative shares are correct. Payments are capped at the invoice total, and only `type='invoice'` with positive amounts is payable, so the credit-note sign edge case cannot occur. Rounding happens once per cumulative share, using bcmath. The only float is in XlsxNumber, and it is whitelisted.
- **SQL:** sort and filter input goes only through fixed whitelists (InvoiceQueries::SORT_SQL, TaskQueries::SORT_SQL, AuditFilter against `DISTINCT` values). Values are bound with CI4 named binds, which use single-pass `strtr`.
- **Access:** all new routes are GET only. AuthGate makes every non-allowlisted route admin-only.
- **Views:** escaping is correct, with no inline `style`, `script` or `on*` handlers.
- **Formula injection:** FormulaGuard covers `= + - @ \t \r`. XLSX uses only explicit StringCell and NumericCell.
- **Download filenames:** they go through ExportFilename, which allows only `[a-z0-9-]`, so header injection is not possible.

**About the test that disables the trigger** (`tests/integration/Export/AccountantBundleTest.php:98-113`): it does **not** roll back. It COMMITs the `DELETE`. However, the `DISABLE TRIGGER`, `DELETE` and `ENABLE TRIGGER` all run in one explicit transaction, and PostgreSQL DDL is transactional. So the trigger is either re-enabled by the COMMIT or restored by the ROLLBACK in the catch. If the process dies, the connection drops and PostgreSQL rolls back implicitly. It runs only on the `tests_owner` group (the test DB), and `resetMasterTables()` clears the rows in tearDown. The trigger cannot leak in a disabled state, so this is not a finding.

Defects found: four WARNINGs and eight INFO items.
1. On error paths the accountant ZIP leaves a partial archive of invoice PDFs on disk. This was confirmed empirically.
2. Year/month input that is not range-checked causes HTTP 500.
3. The bundle and invoice-list CSV take the client name from the live `clients` table instead of the immutable snapshot.
4. The Users screen counts a password-only first factor as "last login".

## Warnings

### WR-01: AccountantBundle leaks a partial ZIP with invoice PDFs on any exception path

**File:** `app/Libraries/Export/AccountantBundle.php:81-125`
**Issue:** If an exception is thrown after `$zip->open()`, the `finally` block runs `unlink($tmp)` while the `ZipArchive` object is still alive. Possible exceptions include `BundleRejected('missing_pdf')` inside the loop, `DocumentIntegrityError` from `DocumentStore::read()` on a sha256 mismatch, and the `RuntimeException`s. When the frame unwinds, PHP's `ZipArchive` destructor calls `zip_close()`. That writes the archive, with every PDF added so far, back to the same path after it was deleted.

I reproduced this locally: open the archive, add an entry, throw, unlink in `finally`. The file `zipXXXX` is still in the directory afterwards.

The result is that copies of issued invoice PDFs build up in `writable/exports/` with no cleanup job, which contradicts the temp-file design in D-11. The DocumentIntegrityError path is exactly the case where something is already wrong.
**Fix:** Discard or close the archive before unlinking, for example:
```php
$zip = null;
try {
    $zip = new ZipArchive();
    // ...
    if (! $zip->close()) { $zip = null; throw new RuntimeException('Cannot finalize zip archive.'); }
    $zip = null;
    // read bytes...
} finally {
    if ($zip instanceof ZipArchive) {
        @$zip->close();   // flush now, so the unlink below removes it
        $zip = null;
    }
    if (is_file($tmp)) {
        unlink($tmp);
    }
}
```
Add a test that forces `DocumentIntegrityError` mid-loop and asserts `writable/exports` is empty afterwards.

### WR-02: Month/period input is not range-checked, so crafted URLs cause HTTP 500

**File:** `app/Controllers/Reports.php:31-36, 63-71, 188-194`; `app/Controllers/Accountant.php:96-108`; `app/Libraries/Reporting/ReportPeriod.php:33-40`
**Issue:** `BillingMonth::parse()` accepts any `\d{4}-MM`, including `0000-01` and `9999-12`. The year view and year export bound the year to 2000..2100, but these paths do not:
- `/reports/month?period=0000-01`: `ReportCalculator::forPeriod` sends `'0000-01-01'` to PostgreSQL, which rejects it ("date/time field value out of range", verified against the dev DB). Even before that, `$report->previous()` computes `0000-00`, which fails `parse()`, so `ReportPeriod::month` throws an uncaught `InvalidArgumentException`.
- `/reports/month?period=9999-12`: `$report->next()` produces `10000-01`, so `InvalidArgumentException` is thrown and the result is a 500.
- `/reports/month.csv?period=0000-01` and `.xlsx`: the database error causes a 500.
- `/reports/accountant?from=0000-01&to=0000-01`: `previewData()` triggers the database error, so the preview and index pages return 500.
**Fix:** Apply one bound in `ReportPeriod::month()` and `range()`, the same 2000..2100 range that `year()` uses. Then, in the controllers, treat out-of-range input like other invalid input: redirect for the screens, 404 for exports, and the `error` key for Accountant.
```php
public static function month(string $ym): self
{
    if (BillingMonth::parse($ym) === null || $ym < '2000-01' || $ym > '2100-12') {
        throw new InvalidArgumentException('month');
    }
    ...
}
```
In `Reports::month()`, check the bound next to `BillingMonth::parse`, and compute `prevUrl`/`nextUrl` only when the neighbour is inside the bound.

### WR-03: Bundle CSV and invoice-list export use the live client name, not the issued snapshot

**File:** `app/Libraries/Reporting/BundleSelector.php:35-44`; `app/Libraries/Invoicing/InvoiceQueries.php:105-114` (consumed by `DocumentListExport::row()` at `app/Libraries/Export/DocumentListExport.php:83`)
**Issue:** Both queries select `c.name AS client_name` from the mutable `clients` table. The issued document stores the buyer name in `invoices.snapshot->'client'->>'name'` (see `SnapshotBuilder.php:140-142`; credit notes copy it). If a client is renamed after issue, `zoznam-dokladov.csv` in the accountant ZIP shows a name that does not match the PDF next to it, and so does the CSV/XLSX invoice export.

This breaks the project's core value: a later change to client data must not change what was issued, and exports must match the documents. The accountant matches documents by number and buyer.
**Fix:** For issued documents, take the name from the snapshot and fall back to the live name only for external invoices, which have no snapshot client:
```sql
COALESCE(f.snapshot->'client'->>'name', c.name) AS client_name
```
Use this in BundleSelector, and in InvoiceQueries::list for the export path (or for both the table and the export, for consistency). Add a test that renames the client after issuing and asserts that the bundle CSV keeps the original name.

### WR-04: Users screen shows a "last login" for sessions that only passed the password step

**File:** `app/Libraries/Auth/UserDirectory.php:36-38`
**Issue:** `last_login` is `MAX(occurred_at)` over `login_password_ok`, `login_totp_ok`, `login_no_totp` and `device_login`. `login_password_ok` is recorded in `Login.php:115` **before** the redirect to `/2fa`, so it marks only the first factor.

If someone with a leaked password but no TOTP code stops at the 2FA prompt, the admin sees that attempt as a successful "last login". It also hides the real last completed login. For a security overview this is misleading. A password success without a following second factor is a signal to surface, not something to merge into "last login".
**Fix:** Count only completed logins: `e.event IN ('login_totp_ok', 'login_no_totp', 'device_login')`. `completeLogin()` records one of these for the TOTP, backup-code and no-TOTP paths. Optionally show the last `login_password_ok` that was not followed by completion as a separate warning.

## Info

### IN-01: Bundle CSV `Súbor` column can disagree with the deduplicated ZIP entry name

**File:** `app/Libraries/Export/AccountantBundle.php:96-103`, `app/Libraries/Export/DocumentListExport.php:96-98`
**Issue:** On a filename collision, the ZIP entry becomes `...-{id}.pdf`. The CSV still lists `DocumentListExport::pdfName($d)` without the suffix and without the `doklady/` prefix. Collisions are rare because `(entity_id, number)` is unique, but they can happen when the `[^A-Za-z0-9._-]` to `_` sanitization maps two numbers to the same name.
**Fix:** Build a `id => entryName` map in the loop and pass it to `DocumentListExport::table()` for the file column.

### IN-02: The year export mixes month rows, client rows and the total in one column

**File:** `app/Libraries/Export/ReportTable.php:26-42`
**Issue:** If the user sums the "Vyfakturované" column in Excel, the result is 3x the real total: months, plus clients, plus the total row. Text cells such as `2026-01` in the CSV are also auto-converted to dates by SK/PL Excel.
**Fix:** Put the client breakdown in a separate block with an empty separator row, or in a second sheet for XLSX. Consider labelling months by name (`BillingMonth::label`) rather than `YYYY-MM`.

### IN-03: Hardcoded, non-localized export strings; unused lang keys

**File:** `app/Controllers/Invoices.php:86` (`'vsetky'`), `app/Libraries/Export/AccountantBundle.php:103,106` (`'doklady/'`, `'zoznam-dokladov.csv'`)
**Issue:** `Export.allEntities` and `Export.sheetInvoices` exist in both lang files but are never used. The invoice export uses `sheetDocuments` and the literal `'vsetky'`.
**Fix:** Use `lang('Export.allEntities')` and `lang('Export.sheetInvoices')`, and move the ZIP entry names into `Export`/`Bundle` lang keys, or remove the unused keys.

### IN-04: Invoice filters hidden on mobile still apply

**File:** `app/Views/invoices/index.php:36-66`
**Issue:** The entity, client, q, from and to inputs are inside `.dt-only-lg`. On a phone, a URL carrying those filters (for example, one pushed from a desktop session) narrows the list with no visible control to clear them. Only `emptyFiltered` hints at it.
**Fix:** Show a "Filtre aktívne (n), zrušiť" link when `activeCount()` counts any hidden filter on small screens.

### IN-05: External invoices show a generic title in the new table although `external_ref` exists

**File:** `app/Views/invoices/_list.php:96-98` (and the pre-existing card at 52-54)
**Issue:** For `origin='external'`, `number` is always NULL (CHECK `invoices_origin_shape`), so the title is always `Invoices.externalTitle`. The `q` filter matches `external_ref` and the export shows `external_ref`, so searching by reference returns rows you cannot tell apart.
**Fix:** `$title = $external ? ((string) ($row['external_ref'] ?? '') ?: lang('Invoices.externalTitle')) : $number;`

### IN-06: Hardcoded timezone in the task table

**File:** `app/Views/tasks/_list.php` (new `$dateFmt = new IntlDateFormatter(..., 'Europe/Bratislava')`)
**Issue:** The rest of the phase uses `config('App')->appTimezone` (Audit, Users).
**Fix:** Pass `config('App')->appTimezone`.

### IN-07: `formatTime()` duplicated in two controllers

**File:** `app/Controllers/Settings/Audit.php:99-118`, `app/Controllers/Settings/Users.php:28-46`
**Issue:** The method body is identical in both controllers, so a future format change has to be made twice.
**Fix:** Extract it to a small helper, for example `App\Libraries\Platform\DateTimeLabel::format($ts, $locale)`.

### IN-08: Export status column omits "overdue"; XlsxWriter temp folder on error

**File:** `app/Libraries/Export/DocumentListExport.php:104-118`; `app/Libraries/Export/XlsxWriter.php:36-82`
**Issue:**
- `statusLabel()` never reports overdue, although the screen shows an overdue badge and the list can be filtered by `status=overdue`. An export of the "Po splatnosti" filter shows "Vystavená" or "Odoslaná" on every row.
- Separately, if `XlsxNumber::fromCell()` throws mid-write, the OpenSpout writer is never closed. Its working files (default `sys_get_temp_dir()`) are left behind.
**Fix:**
- Add an overdue suffix or column using the `overdue`/`days_overdue` that InvoiceQueries already returns.
- Wrap the writer in try/finally with `$writer->close()`, and set `Options::$TEMP_FOLDER` (or the equivalent) to `WRITEPATH . 'exports'`.

---

_Reviewed: 2026-11-04_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
