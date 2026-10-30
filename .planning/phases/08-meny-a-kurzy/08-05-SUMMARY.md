---
phase: 08-meny-a-kurzy
plan: 05
subsystem: invoicing
tags: [fx, snapshot, pdf, mpdf, i18n]
requires: [08-02, 08-04]
provides:
  - "SnapshotBuilder::build(..., ?FxQuote $fx) with snapshot schema 3 and fx block (core, total, pre-formatted text)"
  - "SnapshotBuilder::fxBlock (public static), reused by SampleSnapshots::fxInvoice"
  - "IssueRejected reasons fx_missing, fx_unavailable, fx_invalid, fx_overflow"
  - "inv-2 PDF rate lines and total in entity currency"
affects: [08-06, 08-10]
key-files:
  created:
    - tests/integration/Invoicing/SnapshotBuilderFxTest.php
  modified:
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/IssueRejected.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Language/{sk,pl,cs,en}/InvoicePdf.php
    - app/Views/pdf/invoice_inv2.php
    - resources/pdf/invoice-inv2.css
    - tests/integration/Invoicing/SampleSnapshotShapeTest.php
    - tests/integration/Invoicing/SnapshotBuilderTest.php
    - tests/integration/Invoicing/MpdfInvoiceInv2RendererTest.php
key-decisions:
  - "fx block built in a public static method so DB-less sample snapshots share the exact code path"
  - "PDF fx rows live in the same totals table; a td.fxnote CSS class (7pt, same size as .small) styles the rate lines"
requirements-completed: []
completed: 2026-10-30
---

# Phase 8 Plan 05: Snapshot fx and PDF Summary

Snapshot schema 3 carries the full `fx` block (FxQuote core, total converted once from the invoice total via FxConverter, text pre-formatted in the document language) and the inv-2 PDF prints rate, date/table and "Spolu v {currency}" under the total, rendered only from the snapshot.

Commits: `e4b0a9a` (snapshot, labels, rejections, samples, tests), `27dd732` (PDF template, CSS, renderer tests).

## Deviations from Plan
- Added `td.fxnote` rule to `resources/pdf/invoice-inv2.css` (not in the plan's file list) so the rate rows are small and non-bold inside the totals table. No new font size (7pt = `.small`).
- `SampleSnapshots::fxInvoice` keeps the PLN ZBP QR from the base sample (sample only, not a real document).
- Existing test `testSnapshotIsSchemaTwoWithPrintRules` renamed/updated to schema 3.

## Known Stubs
None.

## Self-Check: PASSED
Targeted tests and `make stan` green; full `make test` result reported to orchestrator.
