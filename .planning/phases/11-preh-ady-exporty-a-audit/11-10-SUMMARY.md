---
phase: 11-preh-ady-exporty-a-audit
plan: 10
subsystem: export
tags: [zip, accountant-bundle, rpt-03, csv, stored-pdf]
requires: [11-07, 11-08]
provides:
  - BundleSelector::documents(entityId, ReportPeriod)
  - DocumentListExport::table(docs, withFile) and pdfName(doc) (for 11-11)
  - AccountantBundle::build(entityId, ReportPeriod) / BundleRejected
  - GET /reports/accountant, /preview, /download
affects: [11-11]
key-files:
  created:
    - app/Libraries/Reporting/BundleSelector.php
    - app/Libraries/Export/DocumentListExport.php
    - app/Libraries/Export/BundleRejected.php
    - app/Libraries/Export/AccountantBundle.php
    - app/Controllers/Accountant.php
    - app/Config/Routes/accountant.php
    - app/Views/reports/accountant/index.php
    - app/Views/reports/accountant/_preview.php
    - app/Language/sk/Bundle.php
    - app/Language/en/Bundle.php
    - tests/integration/Export/AccountantBundleTest.php
    - tests/feature/Reports/AccountantBundleTest.php
  modified:
    - app/Views/more/index.php
    - app/Language/sk/Export.php
    - app/Language/en/Export.php
    - public/assets/js/app.js
key-decisions:
  - "Bundle selects issued documents by turnover_date with the exact ReportCalculator condition; external invoices are excluded (orchestrator decision 1)."
  - "PDFs come only from DocumentStore::read (sha256 verified); missing PDF or >64 MB rejects before any ZIP is written; temp ZIP removed in finally."
  - "AccountantBundle takes an optional DB connection so integration tests use the rw connection."
requirements-completed: [RPT-03]
completed: 2026-11-04
---

# Phase 11 Plan 10: Accountant bundle Summary

Owner opens More -> Balik pre uctovnicku, picks entity and a month range (max 12, default previous month), sees an htmx preview (counts, cancelled, turnover equal to the report) and downloads a ZIP of stored invoice and credit note PDFs (`doklady/...`) plus `zoznam-dokladov.csv` (D-12 columns + Subor). Nothing is regenerated.

## Tasks
1. BundleSelector, DocumentListExport, BundleRejected, AccountantBundle + integration tests - 7481735.
2. Accountant controller, routes, views, lang sk/en, menu link, feature tests - b1260b0.

## Verification
- Integration AccountantBundleTest: 6 tests green; feature AccountantBundleTest: 8 tests green; CSV sum of "Suma v mene subjektu" equals ReportCalculator invoicedTotal.
- `composer stan` OK (forbiddenFloat=20 unchanged); Reports feature, Export, I18n, ViewsCsp: 52 tests green.
- Full suite: OK (2288 tests, 26689 assertions).

## Deviations from Plan
- [Rule 2] Added `Export.colDocAmount` ("Suma v mene dokladu") since only `colAmount` ("Suma") existed and D-12 requires the document-currency label.
- [Rule 3] `public/assets/js/app.js`: added optional `data-submit-reset` (ms) to the data-submit-once handler so the download button is re-enabled after 10 s (a file download fires no htmx event; UI-SPEC says reset after 10 s).
- Tests were written together with implementation in one commit per task rather than a separate RED commit.
- Missing-PDF tests delete the stored PDF by temporarily disabling `invoice_documents_immutable` in an owner transaction (DB blocks invoices without a PDF at insert).

## Known Stubs
None.

## Pending human check
Staging: download previous-month bundle, open PDFs, compare CSV count and sum with the monthly report, open CSV in Excel (SK locale).

## Self-Check: PASSED
