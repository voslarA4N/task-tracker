---
phase: 09-dobropisy
plan: 01
subsystem: database
tags: [postgres, migration, credit-note, invoice_status_v, email-templates]
requires: []
provides:
  - invoices_one_credit_note_uq, invoices_credit_note_fields_check, invoices_credit_note_total_check
  - credit_note_target_guard() trigger invoices_credit_note_target
  - release_allocations(bigint) SECURITY DEFINER + alloc_guard exception
  - invoice_complete_check without report for credit notes
  - invoice_status_v status 'cancelled' + 6 appended columns
  - email_templates key credit_note (4 seeded templates)
  - TemplateRenderer placeholders original_number, original_date
affects: [09-02, 09-07, 09-08]
tech-stack:
  added: []
  patterns: [raw-owner fixture trait for DB-only tests]
key-files:
  created:
    - app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php
    - tests/_support/CreditNoteFixtures.php
    - tests/integration/Invoicing/CreditNoteSchemaTest.php
    - tests/integration/Work/CreditNoteAllocationReleaseTest.php
    - tests/integration/Receivables/CreditNoteStatusViewTest.php
  modified:
    - app/Libraries/Delivery/TemplateRenderer.php
    - app/Libraries/Delivery/EmailTemplateService.php
    - app/Views/settings/email_templates/edit.php
    - app/Views/settings/email_templates/index.php
    - app/Language/sk/EmailTemplates.php
    - app/Language/en/EmailTemplates.php
    - tests/integration/Delivery/InvoiceStatusViewColumnsTest.php
    - tests/integration/Delivery/EmailTemplatesSeedTest.php
    - tests/feature/Settings/EmailTemplatesControllerTest.php
key-decisions:
  - "Cancelled invoice and credit note have balance 0.00 in invoice_status_v (A2)"
  - "credit_note_target_guard skips NULL corrected_invoice_id so the existing CHECK keeps reporting that case"
  - "credit_note added to EmailTemplateService::KEYS so the editor lists and labels the new templates"
requirements-completed: [INV-07, INV-10]
duration: ~45 min
completed: 2026-11-01
---

# Phase 9 Plan 01: Credit note database guarantees Summary

One migration makes the DB reject a second, invalid or foreign credit note, allows releasing allocated parts only via `release_allocations()` for an existing credit note, and exposes a `cancelled` status plus a `credit_note` e-mail template.

## Tasks

| Task | Commits |
|------|---------|
| 1: index, CHECKs, target guard, alloc_guard exception, release_allocations, complete_check | 60ef378 (RED), df86cf4 (GREEN) |
| 2: invoice_status_v cancelled, credit_note template, placeholders | 019191a (RED), 5e1b717 (GREEN) |

## Verification

- `make test-db` migrates (up); down() written to restore previous function bodies and the 22-column view.
- Full suite `composer test`: OK (1816 tests, 24771 assertions). `composer stan` and `composer cs` clean.

## Deviations from Plan

**1. [Rule 1 - Bug] Target guard fired on NULL corrected_invoice_id**
- Found during: Task 1 (existing InvoiceSchemaTest::testCreditNoteNeedsCorrectedInvoice failed)
- Fix: guard runs only when `corrected_invoice_id IS NOT NULL`; existing CHECK handles the NULL case.
- Files: app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php
- Commit: df86cf4

**2. [Rule 3 - Blocking] Existing tests and editor UI affected by 12 templates / 14 placeholders**
- Found during: Task 2
- Fix: added `credit_note` to `EmailTemplateService::KEYS`, kind label `EmailTemplates.kindCreditNote` (sk, en) in index/edit views; updated EmailTemplatesControllerTest counts (8 -> 12 rows, 12 -> 14 variables) and the 22 -> 28 column assertion in InvoiceStatusViewColumnsTest.
- Commit: 5e1b717

**3. [Test fix]** One of my own tests tried a second credit note on the same invoice, hitting the unique index before the completeness check; changed to use a separate invoice (df86cf4).

## Known Stubs

None. `TemplateContext` values for `original_number`/`original_date` are intentionally left to plan 09-08 (plain invoices render empty strings).

## Self-Check: PASSED

Files and commits 60ef378, df86cf4, 019191a, 5e1b717 verified present.
