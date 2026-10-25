---
phase: 03-master-d-ta-subjekty-a-klienti
fixed_at: 2026-10-25T20:32:34Z
review_path: .planning/phases/03-master-d-ta-subjekty-a-klienti/03-REVIEW.md
iteration: 1
findings_in_scope: 12
fixed: 12
skipped: 0
status: all_fixed
---

# Phase 3: Code Review Fix Report

**Fixed at:** 2026-10-25T20:32:34Z
**Source review:** .planning/phases/03-master-d-ta-subjekty-a-klienti/03-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 12 (WR-01 to WR-11, plus IN-02)
- Fixed: 12
- Skipped: 0

**Final verification (inside the review-fix worktree, dev php image, freshly migrated test DB):**
- `composer test`: OK, 651 tests and 5644 assertions. The baseline was 614, so 37 tests are new.
- `composer stan` (app, views, selftest): no errors.
- `composer cs`: 0 files to fix.
- `node --test tests/js`: 29 of 29 passed.

No applied migration (2026-10-25-000001..000005) was edited.

## Fixed Issues

### WR-01: Transaction helpers clobber shared connection state

**Files modified:** `app/Libraries/Platform/Transaction.php` (new), `app/Libraries/Master/EntityService.php`, `app/Libraries/Master/BankAccountService.php`, `app/Libraries/Master/ClientService.php`, `app/Libraries/Master/SeriesService.php`, `tests/integration/Database/TransactionTest.php` (new)
**Commit:** 8a76156
**Applied fix:** Added a single `Transaction::run(BaseConnection, callable)` helper and replaced all 15 copies of the transaction code in the master services with it. The helper:
- reads the caller's `transException` mode (through reflection, because CI4 has no getter) and restores it in `finally`;
- resets `transStatus` only at depth 0;
- on a nested failure, rolls back only its own level if that level is still open, then calls `handleTransStatus()` so the outer `transComplete()` rolls back;
- throws if `transComplete()` returns false.

The pre-`transStart()` `resetTransStatus()` in `SeriesService::update` is gone. A reset now happens only at the top level, where no outer transaction can be hurt.

**Not changed:** the Phase 2 auth services (`AccountRecovery`, `UserProvisioner`) still use the old pattern. They were outside this review's scope and can move to the helper later.
**Status:** fixed: requires human verification (transaction-state logic)

### WR-02: Audit "before" images and decisions are read outside the transaction

**Files modified:** `app/Libraries/Master/EntityService.php`, `app/Libraries/Master/BankAccountService.php`, `app/Libraries/Master/ClientService.php`, `app/Libraries/Master/SeriesService.php`, `tests/integration/Master/RowLockTest.php` (new)
**Commit:** 5ceba9e
**Applied fix:** All reads that feed audit "before" values, no-op checks or decisions now happen inside `Transaction::run` on a locked row.
- **Clients:** `FOR UPDATE`.
- **Entities:** `updateBase`, `end` and `newVersion` lock with `FOR NO KEY UPDATE`, which does not conflict with child-table FK checks. The range, duplicate-date and no-change checks now run under the lock.
- **Bank accounts:** the entity row is locked first, then the account row with `FOR UPDATE`. This serializes every default-account change for the entity, so it also closes the IN-06 race on concurrent "first account" adds.
- **Series:** both series rows of the entity are locked in id order, so `same_as_other`, `isFormatLocked` and the update see one consistent state and are serialized with Phase 5 number allocation.

The tests prove the lock is held even on a no-op call, using `FOR UPDATE NOWAIT` from a second session.
**Status:** fixed: requires human verification (concurrency logic)

### WR-03: Client unarchive audit fabricates the "before" value

**Files modified:** `app/Libraries/Master/ClientService.php`, `tests/integration/Master/ClientArchiveTest.php`
**Commit:** 4fbc79f
**Applied fix:** `setArchived` now reads `archived_at` with `FOR UPDATE` inside the transaction, formatted as `YYYY-MM-DD HH24:MI:SS+TZ:TZ` to match `$now`. The audit records that real value as "before". The test checks that the "before" value is the original archive instant, not the time of the unarchive.

### WR-04: Adding a new default bank account silently demotes the previous default

**Files modified:** `app/Libraries/Master/BankAccountService.php`, `tests/integration/Master/BankAccountTest.php`
**Commit:** b880347
**Applied fix:** `add()` reads `currentDefaultId()` before `clearDefault()`. If a previous default existed, it writes a `set_default` audit row (`default_account_id` old to new) for the new account.

### WR-05: `same_as_other` guard only compares format strings

**Files modified:** `app/Libraries/Master/SeriesFormat.php`, `app/Libraries/Master/SeriesService.php`, `app/Language/en/Entities.php`, `app/Language/sk/Entities.php`, `tests/unit/Master/SeriesFormatTest.php`, `tests/integration/Master/SeriesServiceTest.php`
**Commit:** 08f550a
**Applied fix:** Added `SeriesFormat::mayCollideWith()`. Every token has a fixed width, so each format renders a fixed-length shape where every position is either a fixed character or a digit.
- Two formats can collide only if they have the same length and are compatible at every position: equal fixed characters, digit against digit, or a fixed digit against a digit.
- Letters are compared without regard to case.
- The check is conservative: a "no" means the two series can never produce the same number.

`SeriesService` uses this check instead of string equality. If a stored format no longer parses, it falls back to a case-insensitive string comparison. The error message now tells the user to add text that tells the two series apart.

**Not done:** the DB-level `UNIQUE (entity_id, number)` belongs to the Phase 5 documents table and is left for that phase. The variabilný symbol derivation (non-digits stripped) is also a Phase 5 concern.
**Status:** fixed: requires human verification (collision rule)

### WR-06: SeriesFormat silently turns literal text containing `RR`/`MM` into date tokens

**Files modified:** `app/Libraries/Master/SeriesFormat.php`, `app/Libraries/Master/InvalidSeriesFormat.php`, `app/Controllers/Settings/Series.php`, `app/Language/en/Entities.php`, `app/Language/sk/Entities.php`, `tests/unit/Master/SeriesFormatTest.php`, `tests/feature/Master/SeriesTest.php`
**Commit:** fa5723c
**Applied fix:** The parser now raises `ambiguous_literal` when a letter (either case) sits directly next to an `RRRR`, `RR` or `MM` token. Examples that are now rejected: `COMM-RRRR###`, `ERR-RRRR###`, `FVRRRR###`, `RRRRR###`, `RRRRMMA###`. Text must be separated from date tokens by `- / _ .` or a digit.

The controller shows a dedicated message for this case, and the format help text was updated.

**Design choice:** I chose rejection over an escape syntax. It keeps rendering and the UI unchanged, and the series locks after the first document (D-10), so an explicit error is safer than guessing what the user meant.

**Side effect to note:** the previously accepted form `FVRRRR###` is now rejected. An already stored format is not migrated. If one stops parsing, the detail page shows an empty preview, which the existing catch already handles.

### WR-07: `initial_number` can still be changed after the series is locked

**Files modified:** `app/Libraries/Master/SeriesService.php`, `app/Views/settings/entities/series_form.php`, `app/Language/en/Entities.php`, `app/Language/sk/Entities.php`, `tests/integration/Master/SeriesServiceTest.php`
**Commit:** c626984
**Applied fix:**
- A locked series now rejects any change to the format or to `initial_number`. A save with no changes is still allowed.
- The starting-number input is `readonly` when the series is locked.
- The lock hint and error text now mention the starting number.
- The old test that allowed changing the initial number on a locked series was replaced.

### WR-08: `GET .../versions/notice` returns 500 for non-htmx requests

**Files modified:** `app/Controllers/Settings/EntityVersions.php`, `tests/feature/Master/EntityVersionsTest.php`
**Commit:** 58cfa59
**Applied fix:** Non-fragment requests (direct navigation, `HX-Boosted`, history restore) now redirect to `/settings/entities/{id}/versions/new`. Fragment requests render only `_version_notice`.

### WR-09: Changing the country on "New entity" does not refresh the identity fields

**Files modified:** `app/Controllers/Settings/Entities.php`, `app/Views/settings/entities/new.php`, `app/Views/settings/entities/_country_fields.php` (new), `tests/feature/Master/EntitiesCreateTest.php`
**Commit:** 4a0eda2
**Applied fix:**
- The identity block is wrapped in `#entity-identity-fields`.
- The country select sends `hx-include="#f-country, #entity-identity-fields"`. The CSRF token is deliberately left out of the GET URL.
- `regimeFields()` merges the values the user already typed into the country defaults. Currency, VAT regime and document language are re-derived from the new country.
- The response is the new `_country_fields` partial: regime fields swapped in place, identity fields swapped out-of-band (`hx-swap-oob`), so REGON, the NIP/IČO label and the registry hint follow the selected country.
- A non-htmx request renders the full "New entity" page with the merged data.

**Status:** fixed: requires human verification. Please check the out-of-band swap and `hx-include` of a container in a real browser, including iPhone Safari. The server responses are covered by feature tests.

### WR-10: Client identifier of type "other" is mangled by normalization

**Files modified:** `app/Libraries/Master/ClientInput.php`, `tests/unit/Master/ClientInputTest.php`
**Commit:** 26977b5
**Applied fix:** For `IdentifierType::Other`, `normalize()` keeps case and single spaces and only collapses repeated whitespace (`HRB 12345 B` and `Reg. no 12/a` are stored as entered). Local IDs and EU VAT numbers are still stripped and uppercased.

### WR-11: Legal-text fallback can pair a reverse-charge exemption code with the generic wording

**Files modified:** `app/Libraries/Master/LegalProfileResolver.php`, `tests/integration/Master/LegalProfileTest.php`
**Commit:** f762517
**Applied fix:** I applied the stricter option from the review. `text()` resolves the profile first and looks up text only for that profile's billing mode: the requested language first, then `en`, otherwise `LegalTextMissing`. As a result, `ART_28B_RC` is always paired with the reverse-charge wording.

The new test inserts a `cs` generic text with no `cs` eu_b2b text and confirms that the English reverse-charge text is used.

**Behaviour change to note:** a mode-specific profile no longer falls back to `NULL`-mode texts. With the current seed every combination has matching texts, so all existing tests pass. Phase 6 (SET-01, text editing) has to keep the texts complete for each profile mode.
**Status:** fixed: requires human verification (legal-text selection rule)

### IN-02: `clients.hourly_rate` is `numeric(10,2)`, not `numeric(12,2)`

**Files modified:** `app/Database/Migrations/2026-10-25-000006_WidenClientsHourlyRate.php` (new), `app/Libraries/Master/ClientInput.php`, `tests/integration/Master/MasterRolesTest.php`
**Commit:** 526b645
**Applied fix:**
- New migration: `ALTER TABLE clients ALTER COLUMN hourly_rate TYPE numeric(12,2)`. Raising the precision at the same scale needs no table rewrite, and the column-level grant for `app_rw` stays in place. The down migration reverts to `numeric(10,2)`.
- The applied `CreateClients` migration was not touched.
- The 8-integer-digit input cap in `ClientInput` is now documented as deliberate.
- The new test checks precision 12 and scale 2, and that `app_rw` can still write a value with 10 integer digits.

**Deployment:** production and the dev DB need `make migrate` to apply 000006.

## Skipped Issues

None.

---

_Fixed: 2026-10-25T20:32:34Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
