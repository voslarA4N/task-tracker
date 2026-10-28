---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 02
subsystem: invoicing-pdf
tags: [mpdf, report, rep-1, snapshot]
requires: [06-01]
provides:
  - ReportPdf labels (sk, en, pl, cs)
  - ReportRows (ready report strings, safe portal links, uniform rate)
  - snapshot report_template_version, labels.report, report
  - MpdfReportRenderer rep-1, report_rep1 template, RendererRegistry rep-1
affects: [06-03]
key-files:
  created:
    - app/Libraries/Invoicing/ReportRows.php
    - app/Libraries/Invoicing/MpdfReportRenderer.php
    - app/Views/pdf/report_rep1.php
    - resources/pdf/report-rep1.css
    - app/Language/{sk,en,pl,cs}/ReportPdf.php
  modified:
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Libraries/Invoicing/RendererRegistry.php
key-decisions:
  - "Report content (including '16 z 40 h' strings and period) is built once in the snapshot; template only prints it"
  - "Totals row reads snapshot.totals, never recomputed (REP-02)"
  - "portal_url only for http(s); everything escaped (T-06-08)"
requirements-completed: [REP-01, REP-02]
duration: ~35 min
completed: 2026-10-27
---

# Phase 6 Plan 02: Report content and rep-1 renderer Summary

New invoice snapshots now carry the finished work report content (labels in the document language, period, per-row strings, uniform rate), and a `rep-1` mPDF renderer produces the immutable report from the snapshot alone, with the same rows and totals as the invoice.

## Tasks

1. Report labels, ReportRows, snapshot report content - commit bebf701.
2. MpdfReportRenderer, template, CSS, registry entry, tests - commit 0d44314.

## Verification

- Full suite: **OK, 1133 tests, 7628 assertions** (0 failures).
- `composer stan` no errors; `composer cs` clean.
- `InvoiceIssuer.php` unchanged (issuing of the report document comes in 06-03).
- Template grep gate (lang(, service(, db_connect, style) is clean.

## Deviations from Plan

- Plan named a `REPORT_LABEL_KEYS` constant on SnapshotBuilder; the key list lives as `ReportRows::LABEL_KEYS` with a `ReportRows::labels()` helper shared by SnapshotBuilder and SampleSnapshots (same effect, single source). Both call `ReportRows::build` via a small `report()` helper plus `ReportRows::period()`.
- Test expectation for the truncated portal label corrected during the run (own miscount, not a code bug).

## Known Stubs

None. The report document is not stored yet (06-03).

## Self-Check: PASSED

Files and commits (bebf701, 0d44314) exist; full suite green.
