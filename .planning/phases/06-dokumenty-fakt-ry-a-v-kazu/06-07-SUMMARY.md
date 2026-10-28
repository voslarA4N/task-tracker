---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 07
subsystem: settings
tags: [legal-texts, audit, optimistic-locking, grants]
requires: [06-04, 06-06]
provides:
  - legal_texts.revision with column-level grants for app_rw
  - LegalTextService::update / find / listGrouped
  - Settings -> Zakonne texty list and edit screens
affects: [06-08, 06-09]
tech-stack:
  added: []
  patterns: [optimistic revision check under FOR UPDATE, recordChange in same transaction]
key-files:
  created:
    - app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php
    - app/Libraries/Master/LegalTextService.php
    - app/Libraries/Master/LegalTextRejected.php
    - app/Controllers/Settings/LegalTexts.php
    - app/Views/settings/legal_texts/index.php
    - app/Views/settings/legal_texts/edit.php
    - app/Language/sk/LegalTexts.php
    - app/Language/en/LegalTexts.php
    - tests/integration/Master/LegalTextServiceTest.php
    - tests/integration/Invoicing/LegalTextVersioningTest.php
    - tests/feature/Settings/LegalTextsControllerTest.php
  modified:
    - app/Libraries/Master/LegalProfileResolver.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Config/Routes/settings.php
    - app/Views/more/index.php
    - public/assets/css/app.css
    - tests/integration/Master/MasterRolesTest.php
    - tests/integration/Master/LegalProfileTest.php
    - tests/integration/Invoicing/SnapshotBuilderTest.php
key-decisions:
  - "legal_texts.body CHECK raised to 1..2000 (UI-SPEC B2); invoice_note limited to 500 in the service"
  - "Edit form is a classic POST with PRG (htmx 4xx would not swap); controller still answers HX-Location for htmx POSTs"
  - "Checkbox 'Potvrdene uctovnickou' maps to provisional = false"
requirements-completed: [SET-01]
duration: ~45 min
completed: 2026-10-28
---

# Phase 6 Plan 07: Legal texts editing Summary

Owner can edit legal wording (and the accountant-confirmed flag) in Settings; each change bumps `revision`, is audited in the same transaction and only affects invoices issued afterwards.

## Tasks

1. **Revision, grants, service** (commit 0c2a0c6): migration adds `revision`, body CHECK 2000, `GRANT INSERT` and column `UPDATE (body, provisional, revision, updated_at)` for app_rw (no DELETE, `legal_profiles` untouched). Resolver returns `revision`, so snapshots carry it; `SampleSnapshots` has `revision => 1`. `LegalTextService::update` validates (empty, too_long, invoice_note > 500), locks row `FOR UPDATE`, rejects stale revisions, skips no-op changes, writes `recordChange('legal_text', ...)`. Tests: role grants, 2000/2001 CHECK, service, and `LegalTextVersioningTest` (invoice A keeps body, revision 1 and byte/sha256 identical PDFs after edit; invoice B gets new body, revision 2).
2. **Screens** (commit 3179a08): `Settings\LegalTexts` index/edit/update, routes, grouped list with Predbezne/Potvrdene badges and read-only exemption/VAT codes, edit form with hidden revision, 422 and 409 handling, menu entry, sk/en language files, `.lt-*` CSS, feature tests (anon redirect, CSRF 403, PRG, HX-Location, 422, 409, no inline style/onclick).

## Verification

- Full PHPUnit suite (`make test`): OK, 1232 tests, 7979 assertions.
- `make stan`: no errors. `make cs`: only pre-existing finding in `tests/integration/Invoicing/IssueIdempotencyTest.php` (from 06-06, out of scope, left untouched).
- Migration applied to dev and test DBs.

## Deviations from Plan

- [Rule 3] Existing `LegalProfileTest::testAppRwIsReadOnly` and the `optionalText`/`invoice_note` snapshot assertions were updated to the new grants and the `revision` key.
- [Rule 1] Migration quotes the constraint name manually because `ConnectionInterface::escapeIdentifiers` fails PHPStan.
- Edit form has no `hx-post` attributes (classic POST + PRG) because htmx does not swap 4xx responses; htmx POST support remains in the controller.

## Known Stubs

None. "Pridat variant" and Chyba/Nahradny rows are delivered by 06-08 as planned.

## Self-Check: PASSED

Created files exist; commits 0c2a0c6 and 3179a08 present.
