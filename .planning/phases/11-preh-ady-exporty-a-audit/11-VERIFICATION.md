---
phase: 11-preh-ady-exporty-a-audit
verified: 2026-11-04T00:00:00Z
status: gaps_found
score: 4/5 roadmap truths verified (SC3 partial due to WR-03)
overrides_applied: 0
gaps:
  - truth: "Exports (accountant bundle CSV, invoice list CSV/XLSX) match the issued documents (core value: issued doc immutable, later client changes must not alter it)"
    status: failed
    reason: "BundleSelector.php:35 and InvoiceQueries.php:105 select c.name from the live clients table instead of invoices.snapshot->'client'->>'name'. Renaming a client after issue makes zoznam-dokladov.csv and the invoice export disagree with the PDF. Confirmed in code (review WR-03)."
    artifacts:
      - path: "app/Libraries/Reporting/BundleSelector.php"
        issue: "c.name AS client_name (live)"
      - path: "app/Libraries/Invoicing/InvoiceQueries.php"
        issue: "c.name AS client_name in list() used by DocumentListExport"
    missing:
      - "COALESCE(f.snapshot->'client'->>'name', c.name) in both queries (live name only for external invoices)"
      - "Test: rename client after issue, assert bundle CSV and list export keep original name"
  - truth: "Report/accountant screens and exports handle any period input without server error"
    status: partial
    reason: "WR-02: ReportPeriod::month()/range() lack the 2000..2100 bound that year() has; /reports/month?period=0000-01 or 9999-12, .csv/.xlsx variants and /reports/accountant?from=0000-01 return HTTP 500. Authenticated admin-only, no data corruption."
    artifacts:
      - path: "app/Libraries/Reporting/ReportPeriod.php"
        issue: "month()/range() only check BillingMonth::parse"
      - path: "app/Controllers/Reports.php"
        issue: "prev/next URL computation throws on boundary"
    missing:
      - "Bound month/range to 2000-01..2100-12; redirect/404/error key in controllers"
  - truth: "Accountant ZIP generation leaves no residue on error paths"
    status: partial
    reason: "WR-01: ZipArchive destructor re-writes the temp archive after unlink in finally, leaving partial invoice-PDF ZIPs in writable/exports on exception paths (empirically reproduced by reviewer). Warning level, not a user-facing goal failure."
    artifacts:
      - path: "app/Libraries/Export/AccountantBundle.php"
        issue: "unlink before ZipArchive closed"
    missing:
      - "Close/discard ZipArchive before unlink; test with forced DocumentIntegrityError"
human_verification:
  - test: "Staging (work-tracker-stage.vostack.dev): PC tables of invoices and tasks (11-02, 11-03) - sorting, filters, single-fragment htmx swap"
    expected: "Table renders on wide screen, sort/filter update without full reload, sums match screen"
    why_human: "Visual layout and interaction"
  - test: "Staging iPhone: invoice/task cards, audit detail sheet, settings screens (11-02, 11-03, 11-05, 11-07)"
    expected: "Cards on phone, no horizontal scroll, 44 px touch targets"
    why_human: "Real device rendering"
  - test: "Staging: audit log filters (entity, user, time, action) and before/after detail on PC and iPhone (11-05)"
    expected: "Read-only, filters narrow results, before/after values shown"
    why_human: "UX/visual"
  - test: "Staging: monthly and yearly report (invoiced, paid, per client) compared with invoices, statements and dashboard (11-01, 11-04, 11-06, 11-08)"
    expected: "Totals identical across screens"
    why_human: "Cross-screen comparison with real data"
  - test: "Staging: download report CSV and XLSX and invoice-list CSV/XLSX; open in Excel with SK locale (11-08, 11-09, 11-11)"
    expected: "Semicolon + BOM correct, diacritics ok, numbers numeric, text starting with = + - @ shown as text, not formula; matches filtered screen"
    why_human: "Excel behaviour cannot be tested headlessly"
  - test: "Staging: download previous-month accountant ZIP, open PDFs, compare CSV count and sum with monthly report; open CSV in Excel SK (11-10)"
    expected: "PDFs open, CSV row count/sum match report"
    why_human: "Real documents and Excel"
  - test: "Staging: settings on PC (entities, clients, numbering, limits, templates, legal texts, users, 2FA, devices) (11-07)"
    expected: "Usable desktop layout, action buttons correct width"
    why_human: "Visual"
---

# Phase 11 Verification Report

**Goal:** Owner sees monthly/yearly reports, exports CSV/XLSX, prepares an accountant bundle, browses the audit log, and works in desktop tables.
**Status:** gaps_found (no human-only blockers; one core-value defect in code)
**Re-verification:** No

## Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Month/year report: invoiced and paid, per client, entity currency, sums match | VERIFIED (code) | ReportCalculator/ReportMath/ReportPeriod, views reports/*; review confirms parity with TurnoverCalculator (origin='issued', turnover_date, total_entity_ccy), cumulative paid shares via bcmath. Cross-screen check goes to human. |
| 2 | CSV (`;` + BOM) and XLSX export of reports and invoice list, formula guard | VERIFIED | Export/CsvWriter, XlsxWriter (OpenSpout explicit String/Numeric cells), FormulaGuard prefixes `'` for `= + - @ \t \r`; ReportTable, DocumentListExport, routes in reports.php/invoices.php. Caveat: list export client name (WR-03), status column omits overdue (IN-08). |
| 3 | Accountant bundle ZIP with PDFs and CSV list | PARTIAL | AccountantBundle, BundleSelector, Accountant controller and views exist and are wired. CSV client name uses live name (WR-03, core-value defect); temp ZIP leak on error (WR-01). |
| 4 | Read-only audit log with filters, before/after | VERIFIED | Settings/Audit controller, AuditFilter/AuditQueries/AuditDiff, views settings/audit/*; GET routes only. |
| 5 | PC table lists of tasks and invoices, billing-month screen, settings | VERIFIED (code), visual to human | tasks/_list.php, invoices/_list.php with sort/filter, settings views reworked, app.css phase rules. |

Score: 4/5 truths fully verified; truth 3 partial.

## Honest assessment of review warnings

- WR-03 (blocker): confirmed by grep, `c.name AS client_name` in both BundleSelector.php:35 and InvoiceQueries.php:105. Violates the project core value (exports must match immutable issued documents). Needs a fix before the phase is considered done.
- WR-02 (warning): confirmed in code (ReportPeriod::month lacks the bound that year() at line 43 has). Crafted-URL 500 for the authenticated admin only; not a goal failure but should be fixed.
- WR-01 (warning): temp-file residue of issued PDFs on error paths only.
- WR-04 (warning, Users screen "last login" counts password-only step) relates to phase 2/UI-03 users screen; misleading security info, should be fixed.
- IN-08: export status never shows "overdue" even when filtered by overdue; consider fixing with WR-03.

## Requirements coverage

| ID | Plans | Status |
|---|---|---|
| RPT-01 | 11-01, 04, 06, 08 | SATISFIED (human cross-check pending) |
| RPT-02 | 11-08, 09, 11 | SATISFIED with WR-03 defect in list export |
| RPT-03 | 11-10 | PARTIAL (WR-03, WR-01) |
| AUD-02 | 11-05 | SATISFIED |
| UI-03 | 11-02, 03, 07 | SATISFIED (human visual checks pending) |

No orphaned requirements: all five IDs in REQUIREMENTS.md appear in plan frontmatter.

## Not independently re-run
Full suite (2297 tests green per orchestrator) was not re-run by the verifier. Code review found no critical issues; no TBD/FIXME debt-marker scan was performed beyond review.

_Verifier: Claude (gsd-verifier)_
