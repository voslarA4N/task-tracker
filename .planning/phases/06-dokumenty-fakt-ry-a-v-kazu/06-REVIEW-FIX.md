---
phase: 06-dokumenty-fakt-ry-a-v-kazu
fixed_at: 2026-10-28T09:48:31Z
review_path: .planning/phases/06-dokumenty-fakt-ry-a-v-kazu/06-REVIEW.md
iteration: 1
findings_in_scope: 8
fixed: 8
skipped: 0
status: all_fixed
---

# Phase 6: Code Review Fix Report

**Fixed at:** 2026-10-28T09:48:31Z
**Source review:** .planning/phases/06-dokumenty-fakt-ry-a-v-kazu/06-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 8 (CR-01, WR-01 to WR-07; the Info findings IN-01 to IN-06 are out of scope)
- Fixed: 8
- Skipped: 0

**Verification after all fixes:**
- `composer test`: 1275 tests, 8136 assertions, OK (1 skipped, already skipped before these fixes)
- `composer stan`: no errors (app + views + selftest `app.forbiddenTime=18 app.forbiddenFloat=20 app.forbiddenBcmath=2`)
- `composer cs`: 0 of 459 files need fixing
- `node --test tests/js/`: 51 of 51 pass

## Fixed Issues

### CR-01: ZBP QR amount overflows the 6-digit field for totals of 10 000 PLN or more

**Files modified:** `app/Libraries/Invoicing/Qr/PolishZbpQr.php`, `app/Libraries/Invoicing/Qr/QrPaymentBuilder.php`, `app/Libraries/Invoicing/InvoiceDraftService.php`, `app/Libraries/Invoicing/Draft.php`, `app/Views/invoicing/_preview.php`, `app/Language/{sk,en}/Invoicing.php`, tests
**Commits:** dc530f6, 03b81a3 (PHPStan tightening of the new test)
**Applied fix:** This follows the guard the owner approved.
- Added `PolishZbpQr::AMOUNT_MAX_GROSZE_DIGITS = 6` and `amountFits()`.
- `encode()` now throws `QrPaymentFailed` if the grosze have more than 6 digits.
- `QrPaymentBuilder::build()` returns `null` (no QR) for a PL/PLN transfer above 9 999.99 PLN. This is the same as the other "no QR" cases, so the invoice still issues.
- The draft has a new `zbpAmountWarning` flag. The preview shows a non-blocking `Invoicing.zbpAmountTooHigh` warning next to the existing `zbpTitleWarning`.
- Tests cover these cases:
  - 9 999.99 gives a QR with `|999999|`; 10 000.00 gives no QR (unit tests for the encoder and the builder).
  - An integration issue at 10 000.00 PLN produces an invoice with `payment.qr = null`.
  - The draft warning is set for a transfer and not for cash.
  - The preview HTML shows the warning.
- Note for the owner: 06-RESEARCH.md Open Question 2 was not edited. Its RESOLVED note should now say that totals above 9 999.99 PLN have no ZBP QR plus a preview warning.

### WR-01: A payment note containing TAB passes validation but crashes IssueCommand

**Files modified:** `app/Libraries/Invoicing/IssueInput.php`, `tests/unit/Invoicing/IssueInputTest.php`
**Commit:** 8558b59
**Applied fix:** `normalizeNote()` now turns TAB into a space, so its rules match `IssueCommand`. The preview uses the same normalizer, so it shows the same text. The regression test sends a note with `\t` through `validate()` and `toCommand()`.

### WR-02: QR hints are rendered inverted on the server

**Files modified:** `app/Views/invoicing/_preview.php`, `tests/feature/Invoicing/InvoicingPreviewTest.php`
**Commit:** 36b1f2d
**Applied fix:** Swapped the server-side `hidden` conditions so they match the Alpine bindings. The feature test checks the rendered markup for both transfer and cash. The test was confirmed to fail on the old view.

### WR-03: QR failures are invisible in the preview and the error message names the wrong cause

**Files modified:** `app/Libraries/Invoicing/Qr/QrPaymentFailed.php`, `PolishZbpQr.php`, `Spayd.php`, `XzBinaryLzma.php`, `app/Libraries/Invoicing/InvoiceIssuer.php`, `app/Libraries/Invoicing/InvoiceDraftService.php`, `app/Controllers/Invoicing.php`, `app/Language/{sk,en}/Invoicing.php`, tests
**Commit:** 5705ad9
**Applied fix:**
- `QrPaymentFailed` now carries a reason code: `nip_invalid`, `iban_invalid`, `amount_invalid`, `title_too_long`, `payload_too_long`, `compression_failed` or `encode_failed`. Every throw site sets one.
- `InvoiceIssuer` passes the code as `IssueRejected('qr_failed', ['qr_reason' => ...])`.
- `InvoiceDraftService::draft()` now does a test run of `SnapshotBuilder::withNumber()` when the method is transfer and there are no other errors. It uses the longest possible series number. A deterministic failure becomes a `qr_failed` preview error, so `canIssue` is false.
- `errorQrFailed` (at issue) and the new `errorQrPreview` both add a specific sentence from `Invoicing::qrReasonMessage()`. The message no longer always says "check the IBAN".
- Tests:
  - draft: a PL entity without NIP gives a `nip_invalid` error; it can issue with cash or once the NIP is set
  - preview: the HTML names the NIP
  - issue: the context includes `qr_reason`
  - feature: the xz failure message names the xz tool, not the IBAN
- **Requires human verification:**
  - For SK clients the test run calls xz on every preview with a transfer. That is one small subprocess, a few ms. Please check that p95 for the preview stays under 100 ms in production.
  - The preview now blocks issue for a PL entity without a valid NIP when the method is bank transfer. Before, this failed only at issue time.

### WR-04: The xz bridge has no timeout, and a broken pipe escapes the `qr_failed` mapping

**Files modified:** `app/Libraries/Invoicing/Qr/XzBinaryLzma.php`, `tests/integration/Invoicing/Qr/XzBinaryLzmaTest.php`
**Commits:** 6dffdb9, 7810c53
**Applied fix:**
- Pipes are non-blocking and handled with a `stream_select` loop. It writes stdin in chunks and reads stdout and stderr together, so there is no deadlock.
- Every write is checked. Any exception, including `ErrorException` from a broken pipe, is caught. The process is killed (`proc_terminate` with signal 9) and the error becomes `QrPaymentFailed(compression_failed)`.
- The timeout is a 5000 ms budget of idle time, counted in 50 ms `stream_select` slices with no activity. The project's PHPStan rules forbid `microtime()`/`hrtime()` and float literals outside Clock/Money, so a wall-clock deadline was not possible. A hung xz still gets killed after about 5 s. The constructor accepts the command and the timeout so tests can inject them.
- Tests:
  - a hung process (`sleep 10`, 300 ms budget) times out
  - an early exit with 1 MB of input gives `QrPaymentFailed`
  - a non-zero exit gives `QrPaymentFailed`
  - 300 KB on stdout plus 200 KB on stderr does not deadlock (confirmed to hang with the old code)

### WR-05: The `AddLegalTextsRevision::down()` migration deletes legal texts

**Files modified:** `app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php`, `tests/integration/Database/LegalTextsRevisionRollbackTest.php` (new)
**Commit:** f8cff26
**Applied fix:** `down()` now counts texts longer than 1000 characters and throws `RuntimeException` before changing anything. It no longer deletes them. `up()` is unchanged. The new integration test runs `down()` with a 1500-character text and checks two things: the exception is thrown, and the text and the `revision` column are still there. Only the refusal path is tested, because the success path would change the shared test schema.

### WR-06: After a stale-revision error the legal-text form can never be saved

**Files modified:** `app/Controllers/Settings/LegalTexts.php`, `app/Views/settings/legal_texts/edit.php`, `app/Language/{sk,en}/LegalTexts.php`, `tests/feature/Settings/LegalTextsControllerTest.php`
**Commit:** a0c7736
**Applied fix:** On a 409 the controller reloads the row and re-renders the form with the current `revision`. The user's draft stays in the textarea, and the current saved wording is shown read-only above it (`LegalTexts.currentBody`). The `errorStale` text now tells the user to compare and save again. The feature test covers this: after a stale post, the form carries revision 2 and both texts, and resaving succeeds.

### WR-07: Moving a part refreshes the preview from a fixed URL, so a typed payment note is lost

**Files modified:** `app/Controllers/Invoicing.php`, `app/Views/invoicing/_preview.php`, `tests/feature/Invoicing/InvoicingPreviewTest.php`
**Commit:** 4cc60d9
**Applied fix:**
- The refresh URL for `allocationChanged` no longer includes the payment fields (it uses `PAYMENT_FIELDS` with `array_diff_key`).
- `#inv-preview` now has `hx-include="#inv-payment-method, #inv-payment-note, #inv-payment-note-dirty"`, so the live values are sent and nothing can override them.
- The feature test checks the attribute and that the URL contains no `payment_` fields. It then simulates the refresh with new included values and checks they are rendered.
- Side benefit: the note no longer appears in this refresh URL. This partly covers IN-02, which is out of scope.

## Process notes

- The work was done in an isolated worktree (`gsd-reviewfix/06-63978`). Tests ran in a one-off `docker compose run` php container, with the worktree mounted at `/var/www/html` and the shared `vendor`/`writable` volumes. `main` was fast-forwarded to 7810c53 and the worktree, temp branch and recovery sentinel were removed.
- The worktree directory held files owned by the container user, so `git worktree remove` could not delete it. It was removed with a throwaway `alpine` container and then `git worktree prune`.
- Deploy and docs were not touched (there is no staging; work-tracker.vostack.dev is production).

---

_Fixed: 2026-10-28T09:48:31Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
