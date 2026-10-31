---
phase: 09-dobropisy
plan: 06
subsystem: invoicing
tags: [credit-note, ui, htmx, preview, csrf]
requires: [09-03, 09-04]
provides:
  - CreditNoteDraftService::draft/releaseLink/replayFor (read-only preview)
  - CreditNoteInput (fromPost, validate, validateKeys, toCommand)
  - CreditNotes controller (new, preview, confirm, issue) and 4 routes
  - credit_notes views, CreditNotes lang sk/en, .cn-* CSS
affects: [09-07, 09-08, 09-09]
tech-stack:
  added: []
  patterns: [preview via POST so the reason stays out of URLs and logs, PRG with HX-Location, replay by request_key before any validation]
key-files:
  created:
    - app/Libraries/Invoicing/CreditNoteDraftService.php
    - app/Libraries/Invoicing/CreditNoteInput.php
    - app/Controllers/CreditNotes.php
    - app/Views/credit_notes/new_page.php
    - app/Views/credit_notes/_preview.php
    - app/Views/credit_notes/_confirm_sheet.php
    - app/Views/credit_notes/_confirm_body.php
    - app/Views/credit_notes/confirm_page.php
    - app/Language/sk/CreditNotes.php
    - app/Language/en/CreditNotes.php
    - tests/integration/Invoicing/CreditNoteDraftServiceTest.php
    - tests/feature/Invoicing/CreditNotesControllerTest.php
  modified:
    - app/Config/Routes/invoices.php
    - public/assets/css/app.css
key-decisions:
  - "Preview redraw is POST /invoices/{id}/credit-note/preview and confirm is POST .../confirm (UI-SPEC had GET), so the reason never lands in URLs or logs"
  - "issue() checks request_key replay (DraftService::replayFor) before loading the draft, because after the first issue the original is already credited"
  - "Confirm step re-validates, requires confirm_backdate for a backdated date, and answers 409 if a posted preview_token no longer matches the fresh one"
requirements-completed: [INV-07, INV-10]
duration: ~50 min
completed: 2026-11-01
---

# Phase 9 Plan 06: Credit note UI Summary

The owner can open `/invoices/{id}/credit-note`, pick a correction type, enter a reason and date, see negative lines, the 0,00 sum check and the number preview, confirm in a sheet and issue the credit note with PRG to its detail.

## Commits
- 962459a test: failing draft service and input tests
- dd372ef feat: CreditNoteDraftService, CreditNoteInput, CreditNotes lang sk/en
- 52af9c5 test: failing page tests
- fa967b8 feat: preview page (controller, routes, views, CSS)
- d09aaa8 fix: use Money for the paid-amount check in the view (view PHPStan bans bcmath)
- 0e39d14 test: failing issue tests
- 7cbcc15 feat: confirm sheet and issue

## Verification
- `composer test --filter 'CreditNotesControllerTest|CreditNoteDraftServiceTest|ViewsCspTest|ViewsHardcodedTextTest|LangKeysTest|HtmxCsrfTest'`: 54 tests OK
- `composer stan` (src and views configs): no errors; `composer cs`: clean

## Deviations from Plan

### Documented route deviations (from the plan objective)
1. Preview redraw is `POST /invoices/{id}/credit-note/preview` (htmx), not `GET /fragments/credit-note-preview/{id}`.
2. Confirm is `POST /invoices/{id}/credit-note/confirm` (sheet for htmx, full page without JS). The "Vystaviť dobropis" button on the invoice detail is delivered by 09-09; the page is reachable by direct route.

### Other
3. **[Rule 3 - Structure] Extra partial `credit_notes/_confirm_body.php`.** The recap, warning and issue form are shared by `_confirm_sheet.php` and `confirm_page.php` instead of duplicated, so the literal acceptance greps for `btn-danger` / `csrf_field()` in those two files return 0 (they are in `_confirm_body.php`). Behavior is identical.
4. **[Rule 2 - Correctness] `CreditNoteInput::validateKeys`, `CreditNoteDraftService::replayFor` and `releaseLink` added.** Needed for replay-before-validation (issue with an already used request_key must redirect to the same credit note), key format validation and the "released parts" flash link, without the controller touching the DB.
5. **[Rule 1 - Bug] Reason normalization.** `fromPost` converts `\r\n` to `\n` and strips control characters, because `CreditNoteCommand` rejects `\r`, and a browser textarea posts `\r\n`.
6. During live preview, empty type/reason are not shown as errors (only block "Pokračovať"); the confirm step shows all errors.
7. The flash `app_notice` carries an extra `link` key (released parts); the layout ignores it until 09-09 renders it on the detail.
8. Confirm form is a plain POST (no htmx); errors re-render the page (422/409), success is a 302 PRG (HX-Location for htmx).

## Known Stubs
None.

## Self-Check: PASSED
