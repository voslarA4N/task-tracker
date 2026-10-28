---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 04
subsystem: invoicing-payment
tags: [payment-method, payment-note, invoice-note, preview, alpine]
requires: [06-03]
provides:
  - IssueCommand::PAYMENT_METHODS, paymentMethod and paymentNote with validation
  - LegalProfileResolver::optionalText
  - snapshot payment and legal.texts.invoice_note
  - preview card "Platba a poznamka", confirm sheet rows, provisional warning
affects: [06-05, 06-06, 06-07]
key-files:
  created:
    - tests/integration/Invoicing/PaymentOptionsTest.php
    - tests/js/inv-pay.test.js
  modified:
    - app/Libraries/Invoicing/IssueCommand.php
    - app/Libraries/Invoicing/IssueInput.php
    - app/Libraries/Invoicing/PreviewInput.php
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/InvoiceCalculator.php
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - app/Libraries/Invoicing/InvoiceDraftService.php
    - app/Libraries/Invoicing/Draft.php
    - app/Libraries/Master/LegalProfileResolver.php
    - app/Controllers/Invoicing.php
    - app/Views/invoicing/_preview.php
    - app/Views/invoicing/_confirm_sheet.php
    - app/Views/invoicing/_picker.php
    - app/Views/invoicing/_entity_field.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
    - public/assets/js/app.js
    - public/assets/css/app.css
key-decisions:
  - "previewToken omits the whole payment section (method and note are direct POST input shown in the confirm sheet); legal.texts.invoice_note stays in the token so a changed default wording gives preview_stale"
  - "invPay Alpine component wraps card, items, form and confirm sheet inside invIssue so the confirm sheet reads method and note via x-text"
  - "Control characters other than newline are stripped from the note in IssueInput::normalizeNote; IssueCommand rejects them"
requirements-completed: [INV-12]
completed: 2026-10-27
---

# Phase 6 Plan 04: Payment method and note Summary

The owner picks a payment method (transfer default, cash, card, other) and an optional note of up to 500 characters in the preview. The note is prefilled from the legal `invoice_note` text; both values travel validated into `invoices.payment_method`, `invoices.payment_note`, the snapshot `payment` and the inv-2 PDF.

## Tasks

1. Payment method and note from input to snapshot, columns and PDF - commit 9e83c40.
2. Payment card in preview, confirm sheet rows, provisional warning, 422 field errors - commit 17d4847.

## Verification

- Full suite (`make test`): **OK, 1173 tests, 7786 assertions**, 0 failures.
- `node --test tests/js/`: 51 pass, 0 fail.
- `make stan`: no errors. `make cs`: 0 files to fix.
- CSP grep (`style=|onclick=|<script`) on both views: 0.

## Planner decision documented

`InvoiceCalculator::previewToken()` drops the whole `payment` section, not only `payment.qr`. Payment method and note are validated user input, not DB-derived data that could go stale. The default wording `legal.texts.invoice_note` remains in the token (tested: changed wording gives `preview_stale`; changed method and note do not).

## Deviations from Plan

- **[Rule 3 - Blocking]** `LangKeysTest` scans for literal keys, so the view uses an explicit method-to-label map instead of `lang('Invoicing.payment' . ucfirst(...))`.
- Extra files touched not in the plan list: `_picker.php`, `_entity_field.php` (payment ids added to `hx-include` so a client, month or entity change keeps the note) and `PreviewInputTest` (updated expected keys, added defaulting test).
- Seeded legal texts are all `provisional = true`, so the provisional warning appears in previews by default (vat_note); the feature test sets vat_note non-provisional to isolate the invoice_note branch and restores it.

## Known Stubs

None. The "Upravit predvolene znenie" link points to `/settings/legal-texts`, a route delivered by 06-07 (plain `<a href>`).

## Self-Check: PASSED

Commits 9e83c40 and 17d4847 exist; created files present; full suite green.
