---
phase: 06-dokumenty-fakt-ry-a-v-kazu
verified: 2026-10-28T12:00:00Z
status: human_needed
score: 4/4 roadmap success criteria verified in code (QR acceptance by real bank apps and print quality need human check)
overrides_applied: 0
gaps: []
human_verification:
  - test: "Run `php spark documents:samples` on staging and open the 5 sample PDFs (sk-sk-faktura, sk-sk-vykaz, pl-pl-faktura, pl_eu_b2b-sk-faktura, cz-cs-faktura)"
    expected: "Legally correct content per profile; VZOR label visible; accountant confirms legal texts (SK non-VAT 'Nie som platiteľ DPH.' + registration line, PL art. 113, EU B2B)"
    why_human: "Legal correctness of wording is an accountant decision"
  - test: "Scan the SK QR with at least two Slovak bank apps (Tatra banka, SLSP)"
    expected: "IBAN, amount, VS and due date match the sample; record which PayBySquare::VERSION/EOPM values were accepted (Open Question 3, A1/A2)"
    why_human: "Needs real bank apps"
  - test: "Scan the CZ QR (Czech app) and the PL QR (Polish app); issue a PL invoice above 9 999.99 PLN"
    expected: "Amount, account and payment title match; the ZBP QR is accepted for large amounts. If rejected, this is a gap (CR-01) to fix in PolishZbpQr"
    why_human: "Real app acceptance; ZBP amount field limit (CR-01) is unverified"
  - test: "Print the SK invoice and report on A4"
    expected: "Readable, totals match, VZOR label visible"
    why_human: "Visual output"
  - test: "On an iPhone (installed PWA) issue a test invoice on staging and open 'Faktúra PDF' and 'Výkaz PDF' from the detail"
    expected: "Both open in a new tab"
    why_human: "Device and PWA behaviour"
  - test: "Settings -> Legal texts: edit one text, issue another invoice"
    expected: "The earlier invoice keeps the old wording; the new one uses the new wording"
    why_human: "End-to-end UX flow (backend is also covered by LegalTextVersioningTest)"
---

# Phase 6: Dokumenty faktúry a výkazu - Verification Report

**Phase Goal:** Každá vystavená faktúra má profesionálne PDF so zákonnými náležitosťami podľa krajiny, režimu a jazyka, QR kódom na platbu a nemenný PDF výkaz práce, a zákonné texty sa dajú upraviť bez zásahu do kódu.
**Status:** human_needed
**Re-verification:** No, initial verification

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | PDF from snapshot in client or entity language, only fields per profile (SK non-VAT, PL art. 113, EU B2B) | VERIFIED | `SnapshotBuilder.php:64` resolves `doc_language` as client language, falling back to the entity's. `LegalProfile.php` (77 lines) drives `legal.print`. `MpdfInvoiceInv2Renderer` and `app/Views/pdf/invoice_inv2.php` (140 lines) render from the snapshot. Tests pass: `LegalProfilePrintTest`, `MpdfInvoiceInv2RendererTest`, `RendererRegistryTest`, `SnapshotBuilderTest`. |
| 2 | Payment method (default transfer), optional note, VS = invoice number, QR (SK PAY by square, CZ SPAYD, PL ZBP) from the snapshot | VERIFIED (real-app acceptance and the ZBP large-amount case are human items) | `Qr/PayBySquare.php`, `Spayd.php`, `PolishZbpQr.php`, `QrPaymentBuilder.php` and `XzBinaryLzma.php` exist and are substantive. `InvoiceIssuer` computes the QR inside the issue transaction and maps `QrPaymentFailed` to `qr_failed` with rollback. `PaymentOptionsTest`, `QrIssueTest` and the `Qr/` unit tests pass, including a PAY by square decode round-trip. |
| 3 | Each invoice gets a PDF report (header, table with "16 z 40 h", totals, VAT note) with exactly the invoice parts, stored immutably | VERIFIED | `MpdfReportRenderer`, `ReportRows`, `report_rep1.php`. `InvoiceIssuer` renders and stores `kind='report'` in the same transaction (lines 213-254). Migration `RequireReportDocument` adds the deferred trigger. Tests pass: `ReportDocumentTest`, `ReportTotalsMatchTest`, `MpdfReportRendererTest`, `InvoiceDocumentTest`. |
| 4 | User edits legal texts per country, VAT regime, billing mode and language; new wording affects only later documents | VERIFIED | `Settings/LegalTexts.php` (251 lines), `LegalTextService.php` (262 lines), views `settings/legal_texts/*`, migration `AddLegalTextsRevision` (grants limited to body, provisional, revision, updated_at). Texts are copied into the snapshot at issue. `LegalTextServiceTest` and `LegalTextVersioningTest` pass. |

**Score:** 4/4 truths verified in code. The orchestrator's full run is green: `make test` 1257 tests, `make stan` and `make cs` clean. I re-ran `tests/unit/Invoicing` and `tests/integration/Invoicing`: 244 tests, 1082 assertions, OK.

### Requirements Coverage

| Requirement | Source Plan | Status | Evidence |
|-------------|-------------|--------|----------|
| INV-05 | 06-01, 06-08 | SATISFIED | Truth 1 |
| INV-11 | 06-04, 06-05, 06-08 | SATISFIED in code; real-app acceptance is human (see CR-01) | Truth 2 |
| INV-12 | 06-03/06-04 (payment options) | SATISFIED | `PaymentOptionsTest`; method and note stored in `invoices` and the snapshot, printed on the PDF |
| REP-01 | 06-02, 06-03, 06-08 | SATISFIED | Truth 3 |
| REP-02 | 06-02, 06-03, 06-08 | SATISFIED | Totals are taken from `snapshot.totals`; `ReportTotalsMatchTest` passes; the report is stored immutably |
| SET-01 | 06-06, 06-07 | SATISFIED | Truth 4 |

All 6 requirement IDs appear in plan frontmatter and are marked complete in REQUIREMENTS.md. No orphaned requirements.

### Anti-Patterns

A grep for TBD, FIXME and XXX in `app/Libraries/Invoicing`, `app/Libraries/Master`, `app/Views/pdf` and `app/Controllers` returned no matches.

Code review (06-REVIEW.md), advisory:

| Item | Severity | Impact on goal |
|------|----------|----------------|
| CR-01: ZBP amount above 9 999.99 PLN is padded to 7 digits (`PolishZbpQr.php:62-63`) | Warning here (research Open Question 2 resolved this as "keep the QR", to be checked in UAT) | A possibly non-compliant QR would be frozen into an immutable PL invoice. The UAT step is mandatory. Recommended fix before PL production use: skip the QR or reject when the amount exceeds the limit, and add a test at 10 000.00 |
| WR-01: a payment note containing TAB passes validation but throws in `IssueCommand` (500) | Warning | Edge case; issue fails cleanly with no write |
| WR-02, WR-03: inverted QR hints in the preview, late QR failure message | Warning | UX only |
| WR-04: no timeout on the xz bridge | Warning | Robustness |
| WR-05: destructive `down()` migration | Warning | Rollback only |
| WR-06, WR-07: stale-revision loop in the editor, payment note lost on preview refresh | Warning | UX defects |

None of these breaks a success criterion on its own. CR-01 is the only one that could invalidate SC2 for the PL profile, and it is unconfirmed pending a real-app check.

### Human Verification Required

See the frontmatter. These are the 06-09 `<human-check>` items: QR scans (SK, CZ, PL including above 9 999.99 PLN), A4 print, iPhone PWA, and accountant review of the samples.

### Gaps Summary

No code-level gaps. The phase goal is implemented and tested. The status is `human_needed` because of the mandatory end-of-phase human checks. If the PL large-amount QR is rejected during UAT, record it as a gap for `/gsd-plan-phase 6 --gaps` (CR-01).

---

_Verified: 2026-10-28_
_Verifier: Claude (gsd-verifier)_
