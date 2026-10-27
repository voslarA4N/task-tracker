---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 06
subsystem: invoicing-qr
tags: [spayd, zbp, qr, preview-warning]
requires: [06-05]
provides:
  - Spayd (CZ QR Platba) and PolishZbpQr (PL, PLN, PL NRB) encoders
  - QrPaymentBuilder CZ/PL branches and ECC map
  - Draft::zbpTitleWarning non-blocking preview warning
affects: [06-09]
key-files:
  created:
    - app/Libraries/Invoicing/Qr/Spayd.php
    - app/Libraries/Invoicing/Qr/PolishZbpQr.php
    - tests/unit/Invoicing/Qr/SpaydTest.php
    - tests/unit/Invoicing/Qr/PolishZbpQrTest.php
    - tests/integration/Invoicing/QrIssueTest.php
  modified:
    - app/Libraries/Invoicing/Qr/QrPaymentBuilder.php
    - app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php
    - app/Libraries/Invoicing/InvoiceDraftService.php
    - app/Libraries/Invoicing/Draft.php
    - app/Views/invoicing/_preview.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
    - tests/unit/Invoicing/Qr/QrPaymentBuilderTest.php
    - tests/integration/Invoicing/MpdfInvoiceInv2RendererTest.php
    - tests/integration/Invoicing/IssueIdempotencyTest.php
    - tests/feature/Invoicing/InvoicingPreviewTest.php
key-decisions:
  - "D-19 refinement: ZBP invoice number over 32 chars gives no QR (null), missing/invalid NIP still qr_failed with rollback"
  - "NIP validated as 10 digits only (no checksum) because sample snapshots use 1234567890"
requirements-completed: [INV-11]
completed: 2026-10-28
---

# Phase 6 Plan 06: SPAYD and ZBP QR Summary

CZ clients with transfer now get SPAYD 1.0, PL clients in PLN with a Polish NRB IBAN get a ZBP QR (ECC L, NIP, grosze, invoice number as title); other combinations stay without QR. Missing PL NIP aborts issue with `qr_failed` and rollback.

## Tasks

1. Spayd, PolishZbpQr, builder branches and ECC map - commit a911a1a.
2. Renderer/issue tests for CZ, PL, SK, DE, no-NIP rollback - commit d62737f (renderer already used `QrPaymentBuilder::ECC`, wired in task 1 commit).
3. Preview warning `zbpTitleWarning`, view, sk/en strings, tests - commit ecad32b.
4. Fix to IssueIdempotencyTest (PL entity needs NIP) - see final commit.

## Verification

- Full PHPUnit suite: **OK, 1217 tests, 7895 assertions**, 0 failures.
- `make stan`: no errors; `make cs`: 0 files to fix.

## Deviations from Plan

- **ZBP trailing separators:** the plan's behavior vector had 4 trailing pipes after the title; the interface (9 fields) and ZBP spec give 3 (`title|||`). Implemented and tested 9 fields.
- **[Finding] Warning unreachable in practice:** `SeriesFormat::MAX_OUTPUT = 30`, so any valid series format renders at most 30 characters, below the 32 ZBP limit. The `zbpTitleWarning` logic and the builder's `null` for >32 chars are implemented (defensive, unit-tested at builder level), but a positive preview/issue test with a >32 char number cannot be built through valid formats. Feature tests cover the boundary (longest valid format shows no warning), SK client and cash.
- **[Rule 1]** IssueIdempotencyTest used a PL entity without NIP; now ZBP legitimately fails with `qr_failed`, so the test sets the NIP.
- Spayd test labels: CZ/PL caption assertions are split ("QR", "ZBP") because mPDF wraps captions in the narrow QR column.
- `IssueFixtures` needed no change (country/client override already supported).

## Known Stubs

None.

## Threat Flags

None beyond the plan (T-06-22..25 mitigated: `*` to `%2A`, `|` stripped, ZBP only for PL+PLN+NRB, string-only amounts, qr_failed on missing NIP).

## Self-Check: PASSED
