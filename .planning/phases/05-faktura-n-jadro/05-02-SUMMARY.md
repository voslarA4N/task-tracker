---
phase: 05-faktura-n-jadro
plan: 02
subsystem: invoicing
tags: [invoice-calculator, mpdf, pdf, i18n, snapshot, bcmath]
requires:
  - phase: 04
    provides: Money, Hours, billing allocations
provides:
  - InvoiceCalculator (compute, previewToken) as the single place for line and total math
  - IssueRejected with reason codes
  - PdfFormat (string-only amount, hours, date formatting)
  - PdfRenderer interface, RenderedPdf, MpdfInvoiceRenderer (template inv-1, snapshot only)
  - InvoicePdf labels in sk, en, pl, cs
  - SnapshotSample, FakePdfRenderer, FaultInjectingRenderer test doubles
affects: [05-03, 05-04, 05-05, 05-06, 05-07, 05-08, 05-09]
tech-stack:
  added: []
  patterns:
    - PDF rendered purely from the snapshot array, labels and dates copied into the snapshot
    - formatting without NumberFormatter or bcmath for byte-stable PDFs
key-files:
  created:
    - app/Libraries/Invoicing/InvoiceCalculator.php
    - app/Libraries/Invoicing/IssueRejected.php
    - app/Libraries/Invoicing/PdfFormat.php
    - app/Libraries/Invoicing/PdfRenderer.php
    - app/Libraries/Invoicing/RenderedPdf.php
    - app/Libraries/Invoicing/MpdfInvoiceRenderer.php
    - app/Views/pdf/invoice_inv1.php
    - resources/pdf/invoice-inv1.css
    - app/Language/sk/InvoicePdf.php
    - app/Language/en/InvoicePdf.php
    - app/Language/pl/InvoicePdf.php
    - app/Language/cs/InvoicePdf.php
    - tests/_support/SnapshotSample.php
    - tests/_support/FakePdfRenderer.php
    - tests/_support/FaultInjectingRenderer.php
    - tests/unit/Invoicing/InvoiceCalculatorTest.php
    - tests/unit/Invoicing/PdfFormatTest.php
    - tests/integration/Invoicing/MpdfInvoiceRendererTest.php
  modified: []
key-decisions:
  - "Renderer version string strips the leading v of the Composer pretty version (mpdf 8.3.1)"
  - "app/Language/pl and app/Language/cs directories created only for InvoicePdf (supportedLocales stays sk, en)"
requirements-completed: [INV-02, INV-09, INV-13, NFR-06]
duration: 35min
completed: 2026-10-27
---

# Phase 5 Plan 02: Invoice calculator and PDF renderer Summary

Single-place invoice math (half-up lines, total as sum of rounded lines, preview token) and an mPDF inv-1 renderer that builds the PDF only from the snapshot, with SK/PL/CZ diacritics and labels in the document language.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | InvoiceCalculator and IssueRejected (TDD) | fbfe30d |
| 2 | PdfFormat and InvoicePdf labels (4 languages) | 57b09b1 |
| 3 | PdfRenderer, MpdfInvoiceRenderer, inv-1 template, test doubles | 35b3ca8 |

## Verification

Full PHPUnit suite green (941 tests, 6725 assertions) after `make test-db`; `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 1 - Bug] strrev on UTF-8 NBSP in PdfFormat** - Found during Task 2. Grouping digits by reversing a string that already contained the multibyte NBSP corrupted bytes. Fixed by grouping with an ASCII placeholder and replacing after reversal. Commit 57b09b1.

**2. [Rule 3 - Blocking] Missing pl and cs language directories** - Only `sk` and `en` existed. Created `app/Language/pl` and `app/Language/cs` containing only `InvoicePdf.php`.

**3. Renderer version string** - Composer pretty version is `v8.3.1`; stripped `v` so the string is `mpdf 8.3.1` as the plan's test expects.

**4. Acceptance grep not literally satisfiable** - The grep for `date\(` in the template matches `PdfFormat::date(` (required by the plan). No `date()` PHP call, `lang(`, `service(`, DB access, `<img`, `<link`, `<style` or `style=` exists in the template.

## Known Stubs

None. pl and cs label translations are intentionally provisional (documented in file docblocks, finalised in Phase 6).

## Threat Flags

None. T-05-08 to T-05-12 mitigated: all values escaped with `esc()`, escaped-HTML test, no external resources, no `date()` in template, mixed-currency and amount-mismatch rejections.

## Self-Check: PASSED

All 18 files exist and commits fbfe30d, 57b09b1, 35b3ca8 are in git log.
