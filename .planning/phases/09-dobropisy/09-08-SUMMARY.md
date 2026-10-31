---
phase: 09-dobropisy
plan: 08
subsystem: delivery
tags: [credit-note, email-template, template-context]
requires: [09-01, 09-03, 09-04]
provides:
  - TemplateContext original_number and original_date from snapshot.correction (empty for invoices)
  - tests for credit_note template kind (service, ordering, revision, render)
affects: [09-11]
key-files:
  created:
    - tests/integration/Delivery/CreditNoteTemplateTest.php
  modified:
    - app/Libraries/Delivery/TemplateContext.php
    - tests/unit/Delivery/TemplateRendererTest.php
key-decisions:
  - "Keys original_number/original_date are always present in context (empty string without correction block)"
requirements-completed: [INV-07, INV-10]
completed: 2026-11-01
---

# Phase 9 Plan 08: Credit note email template Summary

TemplateContext now fills `original_number` / `original_date` from the credit note snapshot `correction` block, so the seeded credit_note template renders fully for a real credit note.

## Overlap with 09-01 (Task 1)

09-01 already added `credit_note` to `EmailTemplateService::KEYS`, `kindCreditNote` (sk, en), the settings index `$kindKeys` entry and the placeholder whitelist. Task 1 therefore needed no production change; only tests (service effective, revision bump, ordering) were added and pass.

## Tasks

- Task 1 (tests only, covered by 09-01 production code): test commit with Task 2 RED.
- Task 2: RED `test(09-08): add failing credit note template context tests`, GREEN `feat(09-08): original invoice placeholders in template context`.

## Verification

74 tests green (TemplateRenderer, CreditNoteTemplate, EmailTemplate*, mailers, LangKeys, ViewsHardcodedText, PaymentService); `composer stan` and `composer cs` clean. CR/LF header-injection regression test added (T-09-34). TemplateRenderer.php untouched.

## Deviations from Plan

Task 1 production scope already delivered by 09-01 (see above). Otherwise none.

## Self-Check: PASSED
