---
phase: 09-dobropisy
plan: 02
subsystem: invoicing
tags: [credit-note, snapshot, enum, validation, i18n]
requires: [09-01]
provides:
  - CorrectionType enum (5 types, pdfLabelKey, uiLabelKey)
  - CreditNoteCommand (validated input), CreditNoteRejected (closed REASONS list)
  - CreditNoteSnapshotBuilder::build() and previewToken(), schema 4, template cn-1
  - cn* PDF labels in sk, en, pl, cs
affects: [09-03, 09-04, 09-06]
tech-stack:
  added: []
  patterns: [negative copy of stored invoice, no live table reads]
key-files:
  created:
    - app/Libraries/Invoicing/CorrectionType.php
    - app/Libraries/Invoicing/CreditNoteCommand.php
    - app/Libraries/Invoicing/CreditNoteRejected.php
    - app/Libraries/Invoicing/CreditNoteSnapshotBuilder.php
    - tests/unit/Invoicing/CreditNoteCommandTest.php
    - tests/integration/Invoicing/CreditNoteSnapshotBuilderTest.php
  modified:
    - app/Language/sk/InvoicePdf.php
    - app/Language/en/InvoicePdf.php
    - app/Language/pl/InvoicePdf.php
    - app/Language/cs/InvoicePdf.php
key-decisions:
  - "Separate CreditNoteSnapshotBuilder::LABEL_KEYS; SnapshotBuilder::LABEL_KEYS (43 keys) untouched. Deviates from UI-SPEC G."
  - "Credit note lines: source_allocation_id null, source_task_id copied (A1)."
  - "legal block copied from the original snapshot (D-18)."
  - "doc_language taken from the original row column (fallback snapshot)."
requirements-completed: [INV-07, INV-10]
duration: ~25 min
completed: 2026-11-01
---

# Phase 9 Plan 02: Credit note building blocks Summary

Credit note snapshot builder producing a deterministic negative copy of a stored invoice (lines, totals, fx total) with a `correction` block, plus validated command, correction type enum and closed rejection reasons.

## Commits
- ee6c9c8 test: failing command tests
- 65b2803 feat: CorrectionType, CreditNoteCommand, CreditNoteRejected
- 80728bc test: failing snapshot builder tests
- b23e392 feat: CreditNoteSnapshotBuilder and cn* labels (also includes cs-fix formatting of the tests)

## Verification
- `composer test --filter CreditNote|SnapshotBuilderTest|SampleSnapshotShapeTest|LangKeysTest|PreviewTokenScopeTest`: 76 tests OK
- `composer stan`: no errors; `composer cs`: clean

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] bcmath forbidden outside Money/Hours/Fx (custom PHPStan rule)**
- **Issue:** plan said to negate/sum hours via `bcmul`/`bcadd`; the repo's ForbiddenCallsRule rejects that in the builder.
- **Fix:** hours negated and summed through `Money` (2 decimal places, same as hours); `Hours` not used since it rejects negatives.
- **Files:** CreditNoteSnapshotBuilder.php

**2. [Rule 3 - Blocking] PHPStan alreadyNarrowedType on tautological test assertions**
- **Fix:** reshaped a few assertions in the new tests (no behavior change).

### Planned deviation
- `SnapshotBuilder::LABEL_KEYS` not extended (orchestrator decision; differs from UI-SPEC G). New labels live in `CreditNoteSnapshotBuilder::LABEL_KEYS`; snapshot labels contain both sets.

## Known Stubs
None. PL/CS/EN label texts are provisional pending accountant confirmation (per UI-SPEC).

## Self-Check: PASSED
