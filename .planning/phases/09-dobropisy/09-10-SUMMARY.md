---
phase: 09-dobropisy
plan: 10
subsystem: staging-seed
tags: [seed, credit-note, uat, validation]
requires: [09-05, 09-08, 09-09, 09-11]
provides:
  - staging seed with 2 credit notes issued through the real CreditNoteIssuer
  - phase gate (composer check) green, VALIDATION.md signed off
affects: [StagingSeed]
tech-stack:
  patterns: [planner reserves credit-note targets so deliveries never touch cancelled invoices]
key-files:
  modified:
    - app/Libraries/StagingSeed/HistoryPlan.php
    - app/Libraries/StagingSeed/HistoryPlanner.php
    - app/Libraries/StagingSeed/Modules/Invoices.php
    - tests/integration/StagingSeed/StagingSeederTest.php
    - tests/unit/StagingSeed/HistoryPlannerTest.php
    - .planning/phases/09-dobropisy/09-VALIDATION.md
key-decisions:
  - "PL credit note target is the latest EUR invoice of the PL entity; deliveries() skips payment/reminder events for reserved targets"
requirements-completed: [INV-07, INV-10]
duration: ~40 min
completed: 2026-11-01
---

# Phase 9 Plan 10: Staging seed s dobropismi a fázová brána Summary

Staging seed vystavuje 2 dobropisy (SK v EUR, PL faktúra v EUR s kurzom NBP) cez CreditNoteDraftService + CreditNoteIssuer; `composer check` je zelený (1952 testov).

## Commits
- f33dc6a feat(09-10): seed credit notes for staging uat (tests + implementation in one commit)
- 985ade4 docs(09): validation results

## Test results
`composer check` (PHPStan, php-cs-fixer, PHPUnit): OK, 1952 tests, 25597 assertions.

## Deviations from Plan

**1. [Rule 1 - Bug/infeasible spec] PL credit note target reserved in the plan**
- Issue: in the full profile every EUR invoice of the PL entity (only client pl_alpen) has a payment or reminder in the plan, so the "no payment/reminder" selection rule alone threw LogicException.
- Fix: the PL target is the latest eligible EUR invoice of the PL entity, and `deliveries()` receives a `$reserved` list and emits only the send event for it. The SK target is still chosen from the unmodified deliveries. The hard rule (no payment/payment_void/reminder on targets) holds and is verified in `HistoryPlannerTest`. Other deliveries are unchanged.
- Commit: f33dc6a

**2. [Rule 1] Existing seeder tests adjusted**
- Table counts of `invoices` and `invoice_documents` now include credit notes; invoice-level asserts filter `type = 'invoice'`; allocation sum is `0` for cancelled invoices.

**3. Test count key**
- The credit note count is exposed as `notes['credit_notes']` (the `counts` map is table-based).

**4.** RED and GREEN were committed together instead of a separate failing-test commit.

## Known Stubs
None.

## Human UAT (not done by the executor)
Staging UAT per the plan's human-check (issue credit note, SK/PL PDF, e-mail, iPhone, released parts). Seeding the staging server remains the owner's step.

## Self-Check: PASSED
