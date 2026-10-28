---
phase: 06-dokumenty-fakt-ry-a-v-kazu
reviewed: 2026-10-28T00:24:32Z
depth: standard
files_reviewed: 68
files_reviewed_list:
  - Dockerfile
  - app/Commands/DocumentsSamples.php
  - app/Config/Routes/invoices.php
  - app/Config/Routes/settings.php
  - app/Controllers/Invoices.php
  - app/Controllers/Invoicing.php
  - app/Controllers/Settings/LegalTexts.php
  - app/Database/Migrations/2026-10-28-000001_RequireReportDocument.php
  - app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php
  - app/Language/cs/InvoicePdf.php
  - app/Language/cs/ReportPdf.php
  - app/Language/en/InvoicePdf.php
  - app/Language/en/Invoices.php
  - app/Language/en/Invoicing.php
  - app/Language/en/LegalTexts.php
  - app/Language/en/ReportPdf.php
  - app/Language/pl/InvoicePdf.php
  - app/Language/pl/ReportPdf.php
  - app/Language/sk/InvoicePdf.php
  - app/Language/sk/Invoices.php
  - app/Language/sk/Invoicing.php
  - app/Language/sk/LegalTexts.php
  - app/Language/sk/ReportPdf.php
  - app/Libraries/Invoicing/Draft.php
  - app/Libraries/Invoicing/InvoiceCalculator.php
  - app/Libraries/Invoicing/InvoiceDraftService.php
  - app/Libraries/Invoicing/InvoiceIssuer.php
  - app/Libraries/Invoicing/InvoiceQueries.php
  - app/Libraries/Invoicing/IssueCommand.php
  - app/Libraries/Invoicing/IssueInput.php
  - app/Libraries/Invoicing/IssueRejected.php
  - app/Libraries/Invoicing/LegalProfile.php
  - app/Libraries/Invoicing/MpdfFactory.php
  - app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php
  - app/Libraries/Invoicing/MpdfReportRenderer.php
  - app/Libraries/Invoicing/PreviewInput.php
  - app/Libraries/Invoicing/Qr/Lzma.php
  - app/Libraries/Invoicing/Qr/PayBySquare.php
  - app/Libraries/Invoicing/Qr/PolishZbpQr.php
  - app/Libraries/Invoicing/Qr/QrPaymentBuilder.php
  - app/Libraries/Invoicing/Qr/QrPaymentFailed.php
  - app/Libraries/Invoicing/Qr/QrSvg.php
  - app/Libraries/Invoicing/Qr/Spayd.php
  - app/Libraries/Invoicing/Qr/XzBinaryLzma.php
  - app/Libraries/Invoicing/RendererRegistry.php
  - app/Libraries/Invoicing/ReportRows.php
  - app/Libraries/Invoicing/Samples/SampleSnapshots.php
  - app/Libraries/Invoicing/SnapshotBuilder.php
  - app/Libraries/Master/LegalProfileResolver.php
  - app/Libraries/Master/LegalTextRejected.php
  - app/Libraries/Master/LegalTextService.php
  - app/Views/invoices/show.php
  - app/Views/invoicing/_confirm_sheet.php
  - app/Views/invoicing/_entity_field.php
  - app/Views/invoicing/_picker.php
  - app/Views/invoicing/_preview.php
  - app/Views/more/index.php
  - app/Views/pdf/invoice_inv2.php
  - app/Views/pdf/report_rep1.php
  - app/Views/settings/legal_texts/edit.php
  - app/Views/settings/legal_texts/index.php
  - app/Views/settings/legal_texts/new.php
  - docs/ops/staging.md
  - phpunit.dist.xml
  - public/assets/css/app.css
  - public/assets/js/app.js
  - resources/pdf/invoice-inv2.css
  - resources/pdf/report-rep1.css
findings:
  critical: 1
  warning: 7
  info: 6
  total: 14
status: issues_found
---

# Phase 6: Code Review Report

**Reviewed:** 2026-10-28T00:24:32Z
**Depth:** standard
**Files Reviewed:** 68
**Status:** issues_found

## Summary

I reviewed the 68 production files that phase 6 changed (diff `ffe9229..HEAD`): QR encoders (PAY by square, SPAYD, ZBP), the xz LZMA bridge, the inv-2 and rep-1 PDF templates and renderers, the InvoiceIssuer transaction (number, QR, two PDFs, completeness trigger), the legal-text settings (migration, grants, service, controller, views), the payment method/note flow (preview, issue, Alpine), and the PDF download endpoints.

What holds up:
- The issue path is a single `Transaction::run`. The number, QR, both PDFs, rows, part locks and audit all roll back together, and the deferred `invoice_complete_check` now also requires the `report` document for `snapshot_schema >= 2`.
- PDFs are rendered only from the snapshot and served from stored bytes with a sha256 check.
- Download filenames are sanitized.
- Templates escape all output, and the report only links `http(s)` URLs.
- No inline `style` or `onclick` was added to HTML views.
- The new settings routes are POST-only and covered by the global `csrf` and `authgate` filters.
- `proc_open` uses the array form, so no shell is involved.
- The PAY by square header, length, CRC and field order match the 1.1.0 layout.

Problems found:
- **Blocker:** the ZBP encoder writes a non-compliant amount field for invoices of 10 000 PLN or more. That bad QR is then frozen into an invoice that can no longer be changed.
- **Validation mismatch:** a payment note containing a TAB passes form validation but crashes `IssueCommand`, producing a generic 500.
- **Wrong hints in HTML:** the server renders the QR/no-QR hints inverted.
- **Late QR failures:** QR problems only show up at issue time, with an error message that names the wrong cause.
- **xz bridge robustness:** no timeout, and a broken pipe escapes the `qr_failed` mapping.
- **Destructive down-migration:** rolling back the legal-texts migration deletes data.
- **UI defects:** a stale-revision loop in the legal-text editor, and a lost payment note when the preview refreshes.

## Critical Issues

### CR-01: ZBP QR amount overflows the 6-digit field for totals of 10 000 PLN or more

**File:** `app/Libraries/Invoicing/Qr/PolishZbpQr.php:62-63`
**Issue:** Rekomendacja ZBP (2D standard) defines field 4, the amount in grosze, as exactly 6 digits, which caps it at 9 999.99 PLN. The code does `str_pad($grosze, 6, '0', STR_PAD_LEFT)`. That pads short values but never rejects long ones, so a total of `12500.00` produces `1250000` (7 digits). The payload still passes the 160-character check and is embedded into the invoice PDF, which cannot be changed after issue. Banking apps then either reject the code or misparse the fields. For a freelancer invoicing monthly in PLN, totals of 10 000 PLN or more are realistic. The phase 6 runbook (`docs/ops/staging.md` section 16, step 5) asks for a manual UAT with an amount above 9 999.99 PLN, which shows the limit is a known open question. Even so, the code ships with no guard, and `PolishZbpQrTest` has no test case at or above 10 000 PLN. The ZBP preview warning (`zbpTitleWarning`) only checks the title length, not the amount.
**Fix:** Decide the behavior explicitly, in line with D-19 and the existing "no QR instead of truncation" choice for long titles. Skip the QR in the builder and add a preview warning:
```php
// PolishZbpQr
public const AMOUNT_MAX_GROSZE_DIGITS = 6;

public static function amountFits(string $total): bool
{
    return preg_match('/^(\d+)\.(\d{2})$/', $total, $a) === 1
        && strlen(ltrim($a[1] . $a[2], '0')) <= self::AMOUNT_MAX_GROSZE_DIGITS;
}

// encode(): after computing $grosze
if (strlen($grosze) > 6) {
    throw new QrPaymentFailed('ZBP amount exceeds 9999.99 PLN.');
}

// QrPaymentBuilder::build(), PL branch
if (! PolishZbpQr::titleFits($number) || ! PolishZbpQr::amountFits((string) $snapshot['totals']['total'])) {
    return null;
}
```
Also extend `InvoiceDraftService::draft()` so `zbpWarning` covers the amount case, and add a unit test with `totals.total = '10000.00'`.

## Warnings

### WR-01: A payment note containing TAB passes validation but crashes IssueCommand (generic 500)

**File:** `app/Libraries/Invoicing/IssueInput.php:155` and `app/Libraries/Invoicing/IssueCommand.php:75`
**Issue:** `IssueInput::normalizeNote()` strips `[\x00-\x08\x0B-\x1F\x7F]`, so TAB (`\x09`) is kept, and its docblock says so. `IssueCommand` then rejects `[\x00-\x09\x0B-\x1F\x7F]`, which includes TAB, by throwing `InvalidArgumentException`. `IssueInput::validate()` only checks the length, so a note pasted from a spreadsheet or e-mail with a TAB passes validation. `toCommand()` then throws inside `Invoicing::issue()`, which is caught by the generic `catch (Throwable)`. The user gets "issue failed" (HTTP 500) with no indication that the note is the cause. `PreviewInput` uses the same normalizer, so the preview accepts the note too.
**Fix:** Make the two rule sets identical. Either strip TAB in `normalizeNote()` (or replace it with a space):
```php
$note = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace("\t", ' ', $note));
```
or allow `\x09` in `IssueCommand` (`/[\x00-\x08\x0B-\x1F\x7F]/`). Add a test that sends a note with `"\t"` through `IssueInput::validate()` and then `toCommand()`.

### WR-02: QR hints are rendered inverted on the server

**File:** `app/Views/invoicing/_preview.php:161-162`
**Issue:** `paymentHintQr` ("the invoice will carry a QR code") gets `hidden` when the method is `transfer`. `paymentHintNoQr` ("no QR code is printed") is shown when the method is `transfer`. That is the opposite of the meaning, and the opposite of the Alpine bindings (`x-bind:hidden="isNotTransfer"` / `"isTransfer"`). With JS the wrong hint shows briefly on every htmx swap and is then corrected. Without JS, or if Alpine fails, a bank-transfer user is told no QR will be printed.
**Fix:**
```php
<div class="form-text"<?= $draft->paymentMethod === 'transfer' ? '' : ' hidden' ?> x-bind:hidden="isNotTransfer"><?= esc(lang('Invoicing.paymentHintQr')) ?></div>
<div class="form-text"<?= $draft->paymentMethod === 'transfer' ? ' hidden' : '' ?> x-bind:hidden="isTransfer"><?= esc(lang('Invoicing.paymentHintNoQr')) ?></div>
```

### WR-03: QR failures are invisible in the preview and the error message names the wrong cause

**File:** `app/Libraries/Invoicing/InvoiceDraftService.php:155-161`, `app/Libraries/Invoicing/Qr/PolishZbpQr.php:46-48`, `app/Language/sk/Invoicing.php:48`
**Issue:** The preview reports `canIssue = true` without trying the QR encoders. Deterministic failures only appear after the user confirms the issue, as `qr_failed`. Examples:
- a PL entity with an invalid or missing NIP (`tax_id`) invoicing a PL client in PLN
- a SPAYD amount longer than 10 characters
- xz missing from the image

The message (`errorQrFailed`) always says "Check the entity IBAN". For the NIP case that sends the user to the wrong field, and they can repeat the attempt indefinitely without fixing the actual problem.
**Fix:** In `draft()`, when a snapshot exists and the method is `transfer`, run `QrPaymentBuilder::build()` on a copy with a placeholder number of the series' maximum length (as `longestNumber()` already does). Turn a `QrPaymentFailed` into a preview error with a specific reason. Also give `QrPaymentFailed` a reason code (e.g. `nip_invalid`, `amount_too_long`, `xz_unavailable`) and map each code to its own message in `Invoicing::rejected()`.

### WR-04: The xz bridge has no timeout, and a broken pipe escapes the `qr_failed` mapping

**File:** `app/Libraries/Invoicing/Qr/XzBinaryLzma.php:16-27`
**Issue:**
1. `stream_get_contents()` blocks with no timeout. `max_execution_time` does not count time blocked on I/O. The call runs inside the issue transaction, which holds `FOR UPDATE` locks on tasks, allocations, the series and the counter, so a hung xz keeps those locks until FPM kills the worker.
2. `fwrite($pipes[0], $raw)` is not checked or suppressed. If xz exits early (exec failure exits with 127, or a bad option), the write can raise "Broken pipe". Under `error_reporting = E_ALL`, CI4's handler turns that into `ErrorException`. That exception is not a `QrPaymentFailed`, so `InvoiceIssuer` (line 198) does not map it to `qr_failed`. The user gets the generic error instead of the documented `qr_failed` path from runbook step 6.
3. stdout is drained fully before stderr. That is safe for today's small payloads, but it is a known deadlock pattern.
**Fix:** Use non-blocking pipes with `stream_select()` and a hard deadline (e.g. 5 s), then `proc_terminate()` on timeout. Check the `fwrite` result and turn any failure, including `ErrorException`, into `QrPaymentFailed`:
```php
$written = @fwrite($pipes[0], $raw);
if ($written !== strlen($raw)) { /* close pipes, proc_terminate */ throw new QrPaymentFailed('xz stdin write failed'); }
```

### WR-05: The `AddLegalTextsRevision::down()` migration deletes legal texts

**File:** `app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php:31`
**Issue:** `DELETE FROM legal_texts WHERE char_length(body) > 1000` permanently deletes wording the owner entered through the new UI, including possibly the only `vat_note` for a profile and language. After a rollback, issuing invoices fails with `legal_text_missing`, and the wording is gone with no record outside the audit log. A rollback should not silently destroy configuration data.
**Fix:** In `down()`, refuse to run if any row exceeds 1000 characters:
```php
$n = (int) $this->db->query('SELECT count(*) AS n FROM legal_texts WHERE char_length(body) > 1000')->getRow()->n;
if ($n > 0) { throw new \RuntimeException("$n legal texts exceed 1000 chars; shorten them before rolling back."); }
```
Alternatively, keep the 2000-character check in `down()` and only revoke the grants.

### WR-06: After a stale-revision error the legal-text form can never be saved

**File:** `app/Controllers/Settings/LegalTexts.php:115-123`
**Issue:** On `stale`, the form is re-rendered with `$revision` set to the stale revision the user posted (`formData($text, $body, $provisional, $revision, ...)`). It is not set to the current `$text['revision']`. Every resubmission of that form fails with 409 again. The only way out is to leave the page, which discards the user's edited body.
**Fix:** Re-render with the current revision, and show the current DB body next to the user's draft so they can reconcile:
```php
$current = (new LegalTextService())->find($id) ?? $text;
return $this->response->setStatusCode($status)->setBody(view('settings/legal_texts/edit',
    $this->formData($current, $body, $provisional, $stale ? (int) $current['revision'] : $revision, $error, $stale)));
```

### WR-07: Moving a part refreshes the preview from a fixed URL, so a typed payment note is lost

**File:** `app/Views/invoicing/_preview.php:28` and `app/Controllers/Invoicing.php:369`
**Issue:** `#inv-preview` refreshes on `allocationChanged` with `hx-get="<?= $refresh ?>"`. That URL is built at render time from `selection($draft)` and has no `hx-include` of the payment fields. If the user types a note or changes the payment method and then moves a part via the move sheet, the preview is replaced with the old values and the input is silently lost. If they then issue, the invoice goes out with the default note or method. The confirm sheet does show the values, so this is caught only if the user reads it.
**Fix:** Add `hx-include="#inv-payment-method, #inv-payment-note, #inv-payment-note-dirty"` to the `allocationChanged` refresh. The included values then override the stale query parameters, so the server should give included fields precedence (or the refresh URL should leave the payment fields out).

## Info

### IN-01: Encoder and renderer exceptions do not map to `qr_failed` / `report_failed` consistently

**File:** `app/Libraries/Invoicing/InvoiceIssuer.php:196-220`
**Issue:**
- `PayBySquare::amount()` and `tabFields()` throw `InvalidArgumentException`, and BaconQrCode errors (e.g. data too long) are thrown from inside the invoice renderer. Neither is a `QrPaymentFailed`, so both end in the generic "failed" message.
- An invoice-PDF render failure (line 205) is not mapped at all, while a report-PDF failure is mapped to `report_failed`.
- The `catch (DatabaseException $e) { throw $e; }` around the report render is dead code, because renderers do not touch the DB.

All of these still roll back correctly.
**Fix:** Have QR encoders throw only `QrPaymentFailed`. Wrap the invoice render the same way as the report (e.g. an `invoice_render_failed` reason). Drop the redundant catch.

### IN-02: The payment note is pushed into the URL, browser history and access logs

**File:** `app/Views/invoicing/_preview.php:26`, `app/Views/invoicing/_entity_field.php:13`, `app/Controllers/Invoicing.php:369`
**Issue:** `hx-push-url="true"` together with including `payment_note` in GET requests means free-text notes of up to 500 characters (possibly client-confidential) end up in the address bar, history and nginx access logs.
**Fix:** Leave `payment_note` out of the pushed URL (send it only as an `hx-include` value), or switch the preview fragment to POST for these fields.

### IN-03: PAY by square beneficiary address fields are not truncated to the spec length

**File:** `app/Libraries/Invoicing/Qr/PayBySquare.php:56-58`
**Issue:** The name is truncated to 70 characters, but `address_line1` and `postal_code + city` are not. PAY by square limits the beneficiary address lines to 70 characters each, and longer values can make bank apps reject the code. The title truncation via `substr()` could also cut a multibyte character if the transliterator is unavailable.
**Fix:** `substr(self::ascii(...), 0, 70)` for both address fields. Use `mb_substr` when the result is not pure ASCII.

### IN-04: The PDF labels the invoice number as the variable symbol when it has no digits

**File:** `app/Views/pdf/invoice_inv2.php:59`
**Issue:** `$s['variable_symbol'] ?? $s['number']` prints a non-numeric invoice number under the "Variabilný symbol" label, which is not a valid VS. The QR payloads then leave VS empty, so the printed VS and the QR disagree.
**Fix:** If `variable_symbol` is null, print the payment title (`paymentTitle: number`) instead of a VS row. Alternatively, reject series formats without digits for SK/CZ entities.

### IN-05: The report PDF omits supplier and customer tax identifiers

**File:** `app/Views/pdf/report_rep1.php:13-29`
**Issue:** The `$party` closure only handles `trade_name`/`name`, `address`, `company_id` and `identifier`. For PL profiles (`tax_id` = NIP is the only identifier in `legal.print`) the report shows no identifier for either party. The `$supplier` and `$labels` parameters are unused.
**Fix:** Reuse the invoice template's `partyRows` logic (including the `nip`/`taxId` label switch), or document that the report intentionally shows only names and addresses.

### IN-06: SPAYD values do not escape `%`

**File:** `app/Libraries/Invoicing/Qr/Spayd.php:47`
**Issue:** Only `*` is encoded as `%2A`. A literal `%` followed by two hex digits in `MSG` or `RN` (from a trade name or a label) may be URL-decoded by readers.
**Fix:** `str_replace(['%', '*'], ['%25', '%2A'], $value)`, with `%` replaced first.

---

_Reviewed: 2026-10-28T00:24:32Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
