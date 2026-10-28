---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 05
subsystem: invoicing-qr
tags: [pay-by-square, qr, xz, lzma, mpdf, rollback]
requires: [06-04]
provides:
  - Qr package (Lzma, XzBinaryLzma, PayBySquare, QrPaymentBuilder SK branch, QrSvg, QrPaymentFailed)
  - payment.qr filled in SnapshotBuilder::withNumber
  - vector QR block in inv-2, IssueRejected qr_failed with UI message
affects: [06-06, 06-09]
key-files:
  created:
    - app/Libraries/Invoicing/Qr/Lzma.php
    - app/Libraries/Invoicing/Qr/XzBinaryLzma.php
    - app/Libraries/Invoicing/Qr/PayBySquare.php
    - app/Libraries/Invoicing/Qr/QrPaymentBuilder.php
    - app/Libraries/Invoicing/Qr/QrPaymentFailed.php
    - app/Libraries/Invoicing/Qr/QrSvg.php
    - tests/_support/PayBySquareDecoder.php
    - tests/unit/Invoicing/Qr/PayBySquareTest.php
    - tests/unit/Invoicing/Qr/QrPaymentBuilderTest.php
    - tests/integration/Invoicing/Qr/XzBinaryLzmaTest.php
  modified:
    - Dockerfile
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - app/Libraries/Invoicing/IssueRejected.php
    - app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Views/pdf/invoice_inv2.php
    - resources/pdf/invoice-inv2.css
    - app/Controllers/Invoicing.php
    - app/Language/*/InvoicePdf.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
key-decisions:
  - "PayBySquare::VERSION = 0x01 (1.1.0) in one constant; real-bank UAT in 06-09 decides"
  - "Amount trimmed string-wise (rtrim of zeros) because ForbiddenCallsRule bans bcmath outside Money/Hours"
  - "SnapshotBuilder takes optional QrPaymentBuilder as 5th ctor arg so tests inject a failing Lzma"
  - "QR data URI is passed through esc() (html), not esc(...,'attr'): attr escaping encodes + / = and mPDF then shows a broken-image icon"
requirements-completed: [INV-11]
completed: 2026-10-28
---

# Phase 6 Plan 05: PAY by square QR Summary

SK client invoices with payment method transfer now carry a locally computed PAY by square QR (xz raw LZMA1 via `proc_open` without a shell, bacon SVG, no external service) stored as `payment.qr = {standard, payload}` in the snapshot and rendered as a 32 mm vector QR on inv-2. QR failure aborts issuing (number and parts roll back) with a clear 500 message.

## Tasks

1. PAY by square encoder, xz LZMA, standard selection, Dockerfile `tzdata xz` pin - commit 53c2a18.
2. QR at issue (withNumber), inv-2 QR block, `qr_failed` rollback and UI mapping - commit 723ef5c.

## Verification

- Full suite (`vendor/bin/phpunit` in php container): **OK, 1193 tests, 7849 assertions**, 0 failures.
- `composer stan`: no errors. php-cs-fixer dry-run: 0 files to fix.
- Round-trip test decodes payload (base32hex, header 0x01 0x00, length, CRC32, tab fields) and matches `tabFields`; payload string itself is not asserted (xz output varies by version).
- Rendered PDF visually checked (QR right of payment block, caption below).

## Deviations from Plan

- **[Rule 1 - Bug]** `esc($uri, 'attr')` corrupted the base64 data URI so mPDF drew a broken-image icon; switched to `esc($uri)`. Found during Task 2.
- **[Rule 3]** Caption "PAY by square" wraps onto two lines in the narrow QR column in mPDF (nowrap/nbsp not honoured), so the renderer test asserts "PAY" and "by square" separately. Cosmetic only.
- Vector-ness test asserts no `/Subtype /Image` plus presence of QR caption instead of `/Subtype /Form` (mPDF writes the SVG into the page stream here, no Form dictionary found).
- Dockerfile changed but `docker compose build php` was not re-run; `xz` was already present in the running image (tests pass).

## Known Stubs

None. CZ SPAYD and PL ZBP branches (returns null for those countries) are deferred to 06-06 as planned.

## Threat Flags

None beyond the plan threat model (T-06-17..21 mitigated: no shell, local QR, round-trip test, rollback test, xz pinned).

## Self-Check: PASSED
