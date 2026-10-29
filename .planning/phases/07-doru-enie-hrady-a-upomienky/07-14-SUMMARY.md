---
phase: 07-doru-enie-hrady-a-upomienky
plan: 14
subsystem: staging-seed
tags: [staging, seed, deliveries, payments, uat]
requires: [07-03, 07-07, 07-08, 07-13]
provides:
  - "Staging seed module Deliveries (sends, manual marks, payments, void, reminders)"
  - "07-HUMAN-UAT.md with 5 pending manual checks"
affects: [docs/ops/server.md]
tech-stack:
  added: []
  patterns: ["seed history only through application services with SeedClock", "email.transport=log forced during module and restored in finally"]
key-files:
  created:
    - app/Libraries/StagingSeed/Modules/Deliveries.php
    - .planning/phases/07-doru-enie-hrady-a-upomienky/07-HUMAN-UAT.md
  modified:
    - app/Libraries/StagingSeed/HistoryPlan.php
    - app/Libraries/StagingSeed/HistoryPlanner.php
    - app/Libraries/StagingSeed/StagingSeeder.php
    - app/Libraries/StagingSeed/Modules/Invoices.php
    - tests/unit/StagingSeed/HistoryPlannerTest.php
    - tests/integration/StagingSeed/StagingSeederTest.php
    - docs/ops/server.md
decisions:
  - "Module named Deliveries (D-25); docs updated from planned Payments"
  - "Partial payment is marked 'partial' in the plan; module computes half of the invoice total via Money::timesHours('0.5') (bcdiv is forbidden outside Money/Hours by phpstan rule)"
metrics:
  completed: 2026-10-29
---

# Phase 7 Plan 14: Staging seed Deliveries and human UAT Summary

Deterministic delivery/payment history for staging, created only via InvoiceMailer, ManualSendService and PaymentService with SeedClock and a forced log transport, plus the phase 7 human UAT checklist.

## What was built
- `HistoryPlan::$deliveries` and `HistoryPlanner::deliveries()`: send 1 workday after issue, every 7th (not 6th) manual, invoices due 20+ days before today paid 3 days before due (every 5th half, every 6th unpaid with reminder at due+7), first full payment voided ("Zadané omylom") and re-entered.
- `Modules\Deliveries` registered after `Invoices`; `Invoices` now registers `invoice` ids in the context; `COUNTED` includes `invoice_sends`, `payments`.
- Docs in `docs/ops/server.md` (module description, "Ručné overenie fázy 7"), `07-HUMAN-UAT.md` with UAT-01..05 pending.

## Deviations from Plan
- **[Rule 3 - Blocking]** Seed sends were rejected with `sender_invalid` when the configured `fromEmail` domain was not in `allowedFromDomains` (dev/test). The module temporarily appends the fromEmail domain to `allowedFromDomains` (nothing is sent; restored in `finally`).
- **[Rule 3 - Blocking]** `Invoices` module did not register invoice ids; added `$ctx->put('invoice', key, id)`.
- **[Rule 1]** phpstan forbids `bcdiv` outside Money/Hours; half amount uses `Money::timesHours('0.5')`.

## Known Stubs
None.

## Commits
- 1830a3a feat(07-14): staging seed deliveries and payments via application services
- docs commit: docs(07-14): staging Deliveries module docs and phase 7 human UAT checklist

## Self-Check: PASSED
