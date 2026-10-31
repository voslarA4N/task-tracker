---
phase: 09-dobropisy
plan: 03
subsystem: invoicing
tags: [credit-note, pdf, mpdf, renderer]
requires: [09-02]
provides:
  - MpdfCreditNoteCn1Renderer (template cn-1), pdf/credit_note_cn1 view, credit-note-cn1.css
  - RendererRegistry resolves cn-1
  - DocumentFilename kind credit_note (ASCII prefixes in 4 languages)
affects: [09-04]
tech-stack:
  added: []
  patterns: [snapshot-only PDF view, no QR / payment block]
key-files:
  created:
    - app/Libraries/Invoicing/MpdfCreditNoteCn1Renderer.php
    - app/Views/pdf/credit_note_cn1.php
    - resources/pdf/credit-note-cn1.css
    - tests/integration/Invoicing/MpdfCreditNoteCn1RendererTest.php
  modified:
    - app/Libraries/Invoicing/RendererRegistry.php
    - app/Libraries/Invoicing/DocumentFilename.php
    - tests/unit/Invoicing/RendererRegistryTest.php
    - tests/unit/Invoicing/DocumentFilenameTest.php
key-decisions:
  - "cn-1 view omits payment note, payment method, bank and QR; shows correction line, type, reason (nl2br(esc)), full-storno scope, negative lines/total and fx block."
requirements-completed: [INV-07, INV-10]
duration: ~20 min
completed: 2026-11-01
---

# Phase 9 Plan 03: cn-1 credit note PDF Summary

Credit note PDF renderer cn-1 built only from the schema 4 snapshot (reference to the original invoice, correction type, escaped reason, negative lines and total, fx copy, no QR), registered in RendererRegistry with ASCII download filenames.

## Commits
- c0bcd2f test: failing cn-1 renderer tests
- 15b8f2c feat: cn-1 renderer, view, css
- 1d8e99c test: failing registry and filename tests
- 1af8925 feat: register cn-1 and credit_note filenames

## Verification
- phpunit filter MpdfCreditNoteCn1RendererTest|RendererRegistryTest|DocumentFilenameTest|MpdfInvoiceInv2RendererTest: 44 tests OK
- composer stan: no errors; composer cs: clean

## Deviations from Plan

**1. [Rule 3 - Blocking] No timing assertion in renderer test**
- `hrtime`/float arithmetic are forbidden by the repo PHPStan rules outside Clock, and the clock is mocked in these tests. The < 2 s check was dropped (whole 7-test file runs in ~1.5 s).

**2. [Cosmetic] Acceptance grep `'cn-1' =>` (single space) returns 0**
- php-cs-fixer aligns the match arm as `'cn-1'  =>`; cs rule wins. Behavior verified by RendererRegistryTest.

## Known Stubs
None.

## Self-Check: PASSED
