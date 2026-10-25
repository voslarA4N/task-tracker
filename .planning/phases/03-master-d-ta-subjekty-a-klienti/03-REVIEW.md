---
phase: 03-master-d-ta-subjekty-a-klienti
reviewed: 2026-10-25T17:27:24Z
depth: standard
files_reviewed: 72
files_reviewed_list:
  - app/Config/Routes/clients.php
  - app/Config/Routes/entities.php
  - app/Controllers/Settings/BankAccounts.php
  - app/Controllers/Settings/Clients.php
  - app/Controllers/Settings/Entities.php
  - app/Controllers/Settings/EntityVersions.php
  - app/Controllers/Settings/Series.php
  - app/Database/Migrations/2026-10-25-000001_CreateAuditLog.php
  - app/Database/Migrations/2026-10-25-000002_CreateEntities.php
  - app/Database/Migrations/2026-10-25-000003_CreateClients.php
  - app/Database/Migrations/2026-10-25-000004_CreateEntityBankAccounts.php
  - app/Database/Migrations/2026-10-25-000005_CreateLegalProfiles.php
  - app/Database/Seeds/DevSeeder.php
  - app/Database/Support/GrantsPrivileges.php
  - app/Language/en/Clients.php
  - app/Language/en/Entities.php
  - app/Language/sk/Clients.php
  - app/Language/sk/Entities.php
  - app/Libraries/Audit/AuditLogger.php
  - app/Libraries/Master/BankAccountInput.php
  - app/Libraries/Master/BankAccountSelector.php
  - app/Libraries/Master/BankAccountService.php
  - app/Libraries/Master/BillingMode.php
  - app/Libraries/Master/CcEmails.php
  - app/Libraries/Master/ClientInput.php
  - app/Libraries/Master/ClientService.php
  - app/Libraries/Master/Country.php
  - app/Libraries/Master/Currency.php
  - app/Libraries/Master/DocLanguage.php
  - app/Libraries/Master/EntityDirectory.php
  - app/Libraries/Master/EntityInput.php
  - app/Libraries/Master/EntityService.php
  - app/Libraries/Master/EntityVersionResolver.php
  - app/Libraries/Master/Iban.php
  - app/Libraries/Master/IdentifierType.php
  - app/Libraries/Master/InvalidSeriesFormat.php
  - app/Libraries/Master/LegalProfileResolver.php
  - app/Libraries/Master/LegalTextMissing.php
  - app/Libraries/Master/NoBankAccount.php
  - app/Libraries/Master/PortalUrlTemplate.php
  - app/Libraries/Master/SeriesFormat.php
  - app/Libraries/Master/SeriesRejected.php
  - app/Libraries/Master/SeriesService.php
  - app/Libraries/Master/VatRegime.php
  - app/Libraries/Master/VersionDiff.php
  - app/Libraries/Master/VersionNotFound.php
  - app/Libraries/Master/VersionRejected.php
  - app/Libraries/Platform/Clock.php
  - app/Libraries/Platform/DbBool.php
  - app/Views/layouts/app.php
  - app/Views/more/index.php
  - app/Views/settings/clients/_portal_preview.php
  - app/Views/settings/clients/form.php
  - app/Views/settings/clients/index.php
  - app/Views/settings/entities/_bank_accounts.php
  - app/Views/settings/entities/_end_modal.php
  - app/Views/settings/entities/_history.php
  - app/Views/settings/entities/_identity_fields.php
  - app/Views/settings/entities/_legal_profile.php
  - app/Views/settings/entities/_regime_fields.php
  - app/Views/settings/entities/_series.php
  - app/Views/settings/entities/_series_preview.php
  - app/Views/settings/entities/_version_notice.php
  - app/Views/settings/entities/bank_account_form.php
  - app/Views/settings/entities/edit.php
  - app/Views/settings/entities/index.php
  - app/Views/settings/entities/new.php
  - app/Views/settings/entities/series_form.php
  - app/Views/settings/entities/show.php
  - app/Views/settings/entities/version_new.php
  - public/assets/css/app.css
  - public/assets/js/app.js
findings:
  critical: 0
  warning: 11
  info: 8
  total: 19
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-10-25T17:27:24Z
**Depth:** standard
**Files Reviewed:** 72
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I reviewed every file in the Phase 3 master-data scope: controllers, services, migrations and grants, views, and the language, JS and CSS changes. These areas checked out:

- **IDOR:** every bank-account, series and version query is scoped by `entity_id`.
- **CSRF:** every POST form has a CSRF field.
- **XSS:** I found no unescaped output in the views or the htmx fragments.
- **CSP:** there are no inline styles or scripts, and the Alpine bindings use plain property names, which the CSP build supports.
- **Money:** amounts are never handled as `float`.
- **Grants:** the column-level grants match D-02 and D-15. The legal tables are read-only for `app_rw`.

I found no blockers. These are the main concerns:

1. **Audit-log accuracy (D-18).** Several audit entries contain wrong or missing "before" values. In other places, the "before" image is read outside the transaction.
2. **Transactions in shared helpers.** The helpers reset `transException` and `transStatus` on the shared connection. This will quietly break atomicity once Phase 5 wraps these services in an outer transaction, for example when issuing an invoice.
3. **Numbering-series guards.** The check that keeps invoice and credit-note numbers apart is incomplete. The format parser also silently turns literal text into tokens.
4. **Small functional defects.** These include a 500 error on one htmx endpoint, a create form that does not refresh fields when the country changes, and input normalization that destroys "other" client identifiers.

## Warnings

### WR-01: Transaction helpers clobber shared connection state (unsafe for nesting in Phase 5)

**File:** `app/Libraries/Master/EntityService.php:48,96,170,182,228,242,266,287`; `app/Libraries/Master/BankAccountService.php:324,370,398,421,441,452,466,493`; `app/Libraries/Master/ClientService.php:349,366,394,409,430,456`; `app/Libraries/Master/SeriesService.php:325-341`
**Issue:** Every service method calls `transException(true)`, then unconditionally calls `transException(false)` in `finally`. In the `catch` it calls `resetTransStatus()`. `SeriesService::update` goes further and calls `resetTransStatus()` *before* `transStart()`. All of these act on the shared `Database::connect()` connection.

When a Phase 5 caller (for example, issuing an invoice inside its own transaction) calls one of these services, three things go wrong:
- (a) The caller's `transException(true)` is switched off after the first nested call, so later query failures in the outer transaction no longer throw.
- (b) A failure inside the nested call is rolled back only at depth > 1. CI4 just decrements `transDepth`, then `resetTransStatus()` erases the failure flag. If the outer code catches the exception and continues, `transComplete()` commits partial work.
- (c) `SeriesService::update` clears an outer failure flag before it even starts.

This conflicts with the core requirement that issuing must be atomic.
**Fix:** Save the previous state and restore it, and reset the status only at depth 0:
```php
$prevException = $this->db->transException; // or track via a small TxRunner helper
$outer = $this->db->transDepth > 0;
$this->db->transException(true)->transStart();
try { ...; $this->db->transComplete(); }
catch (Throwable $e) {
    $this->db->transRollback();
    if (! $outer) { $this->db->resetTransStatus(); }
    throw $e;
} finally { $this->db->transException($prevException); }
```
Put this pattern in one `Transaction::run(BaseConnection, callable)` helper instead of 15 copies, and remove the pre-`transStart()` `resetTransStatus()` in `SeriesService::update`.

### WR-02: Audit "before" images and decisions are read outside the transaction (stale before_data)

**File:** `app/Libraries/Master/ClientService.php:377-401`, `app/Libraries/Master/BankAccountService.php:381-396,429,458`, `app/Libraries/Master/EntityService.php:111-135,204-235,254-279`, `app/Libraries/Master/SeriesService.php:298-333`
**Issue:** `find()` runs before `transStart()` and without `FOR UPDATE`. The result is then used for three things:
- the audit `before` values,
- no-op short-circuits (`$old === $new`, `$after === auditValues($before)`),
- decisions: `$promote`, `$account['is_default']` in `archive()`, `is_default` early return in `setDefault()`, the `duplicate_date` pre-check, and the `isFormatLocked` and `same_as_other` checks.

The app is used from both a PC and an iPhone, so two concurrent saves are realistic. In that case `audit_log.before_data` records values that were never actually in the row. Once Phase 5 makes `isFormatLocked` real, the lock check becomes a TOCTOU race with invoice issuing. D-18 requires the audit to be an accurate before/after record.
**Fix:** Move the read inside the transaction and lock the row:
```php
$this->db->transException(true)->transStart();
$before = $this->db->query('SELECT * FROM clients WHERE id = :id: FOR UPDATE', ['id' => $id])->getRowArray();
```
`app_rw` has column-level UPDATE on these tables, which is enough for `FOR UPDATE`. For `SeriesService`, lock the `invoice_series` row so that the lock check and the update are serialized with Phase 5 number allocation.

### WR-03: Client unarchive audit fabricates the "before" value

**File:** `app/Libraries/Master/ClientService.php:433-446`
**Issue:** For unarchive, the audit records `before = ['archived_at' => $now]`. That is the current request time, not the timestamp at which the client was actually archived. The append-only log therefore stores a value that never existed (D-18, AUD-01).
**Fix:** Return the old value from the UPDATE:
```php
$row = $this->db->query(
    'UPDATE clients c SET archived_at = NULL, updated_at = :now: FROM clients o
     WHERE c.id = o.id AND c.id = :id: AND c.archived_at IS NOT NULL RETURNING o.archived_at AS old',
    ['now' => $now, 'id' => $id])->getRow();
// audit before = ['archived_at' => $row->old]
```
Alternatively, `SELECT archived_at ... FOR UPDATE` inside the transaction before updating.

### WR-04: Adding a new default bank account silently demotes the previous default without an audit record

**File:** `app/Libraries/Master/BankAccountService.php:332-362`
**Issue:** When `add()` creates an account with `is_default = true` while another default exists, `clearDefault()` flips the old account's `is_default` and `updated_at`. No `set_default` audit row is written. The only record is the new account's `create` entry. Every other path (`setDefault`, `update` promote, `archive`) records `default_account_id` before and after through `applyDefault()`, so this path is an AUD-01 gap.
**Fix:** In `add()`, read `currentDefaultId()` before `clearDefault()`. After the insert, record:
```php
$this->audit->record('bank_account', $id, 'set_default', ['default_account_id' => $oldDefault], ['default_account_id' => $id]);
```
Do this only when `$oldDefault !== null`.

### WR-05: `same_as_other` guard only compares format strings, so invoice and credit-note numbers can still collide

**File:** `app/Libraries/Master/SeriesService.php:304-308`
**Issue:** The guard rejects only the *identical* format string, but different formats can render identical numbers. Two examples for the same entity:
- `RRRR####` at sequence 1001 and `RRRRMM##` in October at sequence 01 both give `20261001`.
- `RRRR0###` and `RRRR####` both give `20260001`.

The document number is also used as the variabilný symbol, so a credit note and an invoice with the same number make payment matching ambiguous. That is exactly what the guard is meant to prevent.
**Fix:** Require the two series to be distinguishable by a literal. For example, require that one format contains a non-digit literal the other lacks at the same position, or simply require at least one non-digit literal character in the credit-note format that does not appear in the invoice format. At minimum, render both formats over a sample of dates and sequences and reject them if any outputs overlap. Also add a DB-level `UNIQUE (entity_id, number)` across both kinds in the Phase 5 documents table.

### WR-06: SeriesFormat silently turns literal text containing `RR`/`MM` into date tokens

**File:** `app/Libraries/Master/SeriesFormat.php:43-79`
**Issue:** The UI says `A-Z 0-9 / _ . -` are allowed as fixed text (`series_form.php:19`), but there is no escaping. Literal letters are consumed greedily as tokens:
- `COMM-RRRR###` parses as `CO` + **month** + `-` + year + seq and renders `CO10-2026001`.
- `ERR-RRRR###` fails with `two_years`, an error that is hard to understand.

Once documents are issued the format is locked (D-10), so the wrong pattern becomes permanent. The preview helps, but the parser accepts the format silently.
**Fix:** Either support an escape (for example, quote literals: `'COMM'-RRRR###`), or reject formats where a literal run is directly adjacent to a token letter sequence. Another option is to restrict literal letters to those that cannot form `RR`/`MM` and show a specific error (`ambiguous_literal`). Add tests for `COMM`/`ERR`.

### WR-07: `initial_number` can still be changed after the series is locked

**File:** `app/Libraries/Master/SeriesService.php:292-333`, `app/Controllers/Settings/Series.php:622-639`
**Issue:** `isFormatLocked()` gates only a *format* change. When Phase 5 makes the lock real, a series that already has issued documents will still accept a new `initial_number` (lower or higher). That can produce duplicate numbers (lower) or gaps (higher), depending on how the allocator combines `initial_number` with the counter. CLAUDE.md requires numbering "bez medzier" (without gaps). The form also marks only the format field `readonly`.
**Fix:** Reject any change to `initial_number` when the series is locked:
```php
if ($this->isFormatLocked($seriesId) && ($series['format'] !== $parsed->format() || (int) $series['initial_number'] !== $initial)) {
    throw new SeriesRejected('locked');
}
```
Also make the `initial_number` input `readonly` when `$locked`.

### WR-08: `GET /settings/entities/{id}/versions/notice` returns 500 for non-htmx requests

**File:** `app/Controllers/Settings/EntityVersions.php:425-447`
**Issue:** For a non-fragment request (direct navigation, a bookmark, or an `HX-Boosted` or history-restore request), `htmx_view()` renders the full page `settings/entities/version_new` with only `['future', 'later']`. That view dereferences `$entity['id']`, `$country`, `$values`, `$minDate` and `$maxDate` (version_new.php:11,30,45). CI4 turns the undefined-variable warning into an `ErrorException`, so the response is a 500.
**Fix:** Either redirect non-fragment requests:
```php
if (! is_htmx_fragment_request()) {
    return redirect()->to('/settings/entities/' . $id . '/versions/new');
}
```
or build the full `formData()` for the page case. Apply the same approach to `BankAccounts::archived`, which already redirects.

### WR-09: Changing the country on "New entity" does not refresh the identity fields (no REGON for PL, wrong ID label)

**File:** `app/Views/settings/entities/new.php:27-50`, `app/Controllers/Settings/Entities.php:54-62`
**Issue:** The htmx swap on the country select replaces only `#entity-regime-fields` (currency and VAT regime). `_identity_fields` is rendered once with the default `Country::SK`. When the user switches to PL:
- the REGON input never appears, so REGON can only be entered after a failed submit or in a later version;
- the company-ID label still says IČO with the SK hint, while the server validates a 10-digit NIP (`EntityInput.php:456`);
- the registry hint stays SK.

The first version of a PL entity is therefore created without REGON in the normal flow.
**Fix:** Put `_identity_fields` inside the swapped container, or swap a wrapper that contains both partials. Preserve values the user already typed by using `hx-include="closest form"` and merging the GET values into `$values` in `regimeFields()`.

### WR-10: Client identifier of type "other" is mangled by normalization (spaces and case removed)

**File:** `app/Libraries/Master/ClientInput.php:141-145` vs `236-241`
**Issue:** For `IdentifierType::Other`, validation deliberately allows spaces, lowercase, `.` and `/` (`/^[A-Za-z0-9 .\/-]{1,30}$/`) and checks the *raw* value. `normalize()` then applies the same strip-whitespace-and-uppercase treatment as local IDs and VAT numbers. Foreign register numbers such as `HRB 12345 B` or `Reg. no 12/a` are stored as `HRB12345B` / `REG.NO12/A` and will be printed that way on invoices. The stored value differs from what the user entered and from what validation checked.
**Fix:** Branch on the identifier type when normalizing:
```php
case 'identifier_value':
    $value = ($v['identifier_type'] ?? '') === IdentifierType::Other->value
        ? (string) preg_replace('/\s+/u', ' ', $value)
        : strtoupper((string) preg_replace('/\s+/u', '', $value));
    break;
```

### WR-11: Legal-text fallback can pair a reverse-charge exemption code with the generic exemption wording

**File:** `app/Libraries/Master/LegalProfileResolver.php:127-147,159-181`
**Issue:** `profile()` resolves the exemption code by the exact billing mode first (for example PL + `eu_b2b` gives `ART_28B_RC`). `text()` tries `(language, exact mode)` → `(language, NULL)` → `(en, exact mode)`. If a language has the generic `NULL`-mode text but no `eu_b2b` text, the invoice gets code `ART_28B_RC` together with the "zwolniony ... art. 113" wording. That is a legally inconsistent pair on an immutable document. With the current seed every PL language has both rows, so the case cannot happen yet. It will become reachable as soon as SET-01 (Phase 6) allows editing or adding texts per language.
**Fix:** Prefer the exact mode over the language, or require the text to come from the same profile row as the code:
```php
$attempts = [[$language, $billingMode], ['en', $billingMode], [$language, null], ['en', null]];
```
A stricter option: if `profile()` matched a non-NULL mode, allow only texts with that same mode, and otherwise throw `LegalTextMissing`.

## Info

### IN-01: `end` endpoint has no server-side guard and writes duplicate audit rows

**File:** `app/Controllers/Settings/Entities.php:200-227`, `app/Libraries/Master/EntityService.php:252-289`
**Issue:** `canEnd` only hides the button in the view. POST `/end` on an already-ended entity, or with the same date, still runs the UPDATE and writes an `end` audit row even when nothing changed. There is no upper bound on `active_to` (for example `9999-12-31`).
**Fix:** Skip the update and audit when `active_to` is unchanged (use `recordChange`). Optionally bound the date to the same `+366 days` window or a sane maximum.

### IN-02: `clients.hourly_rate` is `numeric(10,2)`, not the project-wide `numeric(12,2)`

**File:** `app/Database/Migrations/2026-10-25-000003_CreateClients.php:149`
**Issue:** CLAUDE.md requires money to be `numeric(12,2)`. `Money::of` accepts 10 integer digits, but the column accepts 8. This is safe today only because `ClientInput` limits input to 8 digits. The two limits are coupled implicitly.
**Fix:** Use `numeric(12,2)`, or document the deliberate cap next to the validation regex.

### IN-03: `w-sm-auto` is not a Bootstrap 5.3 utility

**File:** `app/Views/settings/entities/_end_modal.php:22-23`, `_bank_accounts.php:68-69`, `clients/form.php:224,228`
**Issue:** Bootstrap 5.3 has no responsive width utilities, so modal buttons stay `w-100` on desktop.
**Fix:** Add `.w-sm-auto` in `app.css` inside `@media (min-width:576px)`, or drop the class.

### IN-04: Date formatting duplicated and locale-independent

**File:** `app/Controllers/Settings/Entities.php:354-373`, `EntityVersions.php:562-581`, `EntityInput.php:511-516`
**Issue:** There are three copies of the date formatting. All of them hard-code `d. M. yyyy` / `j. n. Y`, even for the `en` locale.
**Fix:** Extract a single `DateFormat::short($iso, $locale)` helper.

### IN-05: `entity_versions` text columns have no DB length checks

**File:** `app/Database/Migrations/2026-10-25-000002_CreateEntities.php:73-87`
**Issue:** `clients.name`, `entities.name_internal` and `legal_texts.body` have `CHECK char_length`, but `trade_name`, `address_*`, `city`, `registry_note`, `email` and `phone` do not. These versions feed invoice snapshots, and the limits are enforced only in PHP.
**Fix:** Add `CHECK (char_length(...) BETWEEN ...)` that mirrors `EntityInput::validateVersion`.

### IN-06: Concurrent "first account" adds produce an unhandled 500

**File:** `app/Libraries/Master/BankAccountService.php:327-336`, `app/Controllers/Settings/BankAccounts.php:138-145`
**Issue:** Two parallel adds on an entity with no accounts both see `active = 0`. Both insert `is_default = true`, and the second hits `entity_bank_accounts_one_default`. `duplicateOrRethrow` maps only the IBAN index, so the second request returns a 500.
**Fix:** Lock the entity row (`SELECT id FROM entities WHERE id = :e: FOR UPDATE`) at the start of `add()`, or map the `one_default` violation to a retryable form error.

### IN-07: `audit_log.user_id ... ON DELETE SET NULL` mutates append-only rows

**File:** `app/Database/Migrations/2026-10-25-000001_CreateAuditLog.php:18`
**Issue:** Deleting a user rewrites existing audit rows. This conflicts with D-18, which says the log is append-only. It also applies to `entity_versions.created_by`.
**Fix:** Use `ON DELETE RESTRICT`, or store a denormalized `user_email` or `user_label` snapshot without an FK.

### IN-08: Minor duplication and robustness

**File:** `app/Libraries/Master/EntityDirectory.php:201`, `app/Database/Seeds/DevSeeder.php:52-71`, `app/Views/settings/clients/form.php:224`
**Issue:**
- `forSelect()` reimplements `DbBool::of()`.
- DevSeeder is idempotent only per entity. If the bank `add()` fails after the entity is created, a rerun never creates the accounts.
- The client archive form lacks `data-submit-once`, unlike every other mutating form.

**Fix:**
- Use `DbBool::of($row['ended'])`.
- Check for accounts separately in the seeder.
- Add `data-submit-once` to the archive form.

---

_Reviewed: 2026-10-25T17:27:24Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
