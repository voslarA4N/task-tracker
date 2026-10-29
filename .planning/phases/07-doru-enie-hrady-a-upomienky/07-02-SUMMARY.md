---
phase: 07-doru-enie-hrady-a-upomienky
plan: 02
subsystem: delivery
tags: [email-templates, template-renderer, pdf-filename, postgres]
requires: []
provides:
  - email_templates table with 8 seeded templates (invoice|reminder x sk|pl|cs|en)
  - EmailTemplateService::effective with fallback to en
  - TemplateRenderer (whitelist strtr, escaped HTML)
  - DocumentFilename::for (single PDF naming rule)
affects: [07-04, 07-08]
tech-stack:
  added: []
  patterns: [column-level UPDATE grant, strtr whitelist rendering]
key-files:
  created:
    - app/Database/Migrations/2026-10-29-000001_CreateEmailTemplates.php
    - app/Libraries/Delivery/EmailTemplateService.php
    - app/Libraries/Delivery/TemplateRenderer.php
    - app/Libraries/Invoicing/DocumentFilename.php
    - tests/unit/Delivery/TemplateRendererTest.php
    - tests/integration/Delivery/EmailTemplatesSeedTest.php
    - tests/unit/Invoicing/DocumentFilenameTest.php
  modified:
    - app/Controllers/Invoices.php
    - tests/feature/Invoicing/InvoicePdfEndpointTest.php
decisions:
  - "PDF download names follow D-07 (Faktura_<number>.pdf) instead of the former faktura- prefix"
  - "EmailTemplateService is read-only here; editing and audit come in 07-08"
metrics:
  tasks: 2
  completed: 2026-10-29
---

# Phase 7 Plan 02: E-mail templates and PDF filenames Summary

DB-stored e-mail templates in four languages with an en fallback, rendered only through a placeholder whitelist (strtr), plus one ASCII filename rule for PDF download and attachments.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1. email_templates, service, renderer | 95ff27a | 8 seeded templates; app_rw SELECT, INSERT and UPDATE of subject, body, revision, updated_at only; no DELETE |
| 2. DocumentFilename | c2c528f | `Invoices::serve` uses `DocumentFilename::for`; endpoint test expectations updated |

## Verification

- Full PHPUnit suite: 1392 tests OK. `composer cs` and `composer stan` clean.
- No new dependencies.

## Deviations from Plan

None - plan executed as written. The `$audit` constructor argument of `EmailTemplateService` is kept and exposed through a protected `audit()` accessor so PHPStan stays clean until 07-08 uses it.

## Known Stubs

None.

## Self-Check: PASSED
