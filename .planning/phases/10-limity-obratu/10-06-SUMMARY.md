---
phase: 10-limity-obratu
plan: 06
subsystem: invoicing
tags: [limits, turnover, invoice-preview, precheck]
requires: [10-02]
provides:
  - LimitPrecheck::evaluate / forDraft (before/after threshold evaluation)
  - service('limitPrecheck')
  - invoicing/_limit_warning partial in the invoice preview
affects: [10-07]
key-files:
  created:
    - app/Libraries/Reporting/LimitPrecheck.php
    - app/Views/invoicing/_limit_warning.php
    - tests/unit/Reporting/LimitPrecheckTest.php
    - tests/feature/Invoicing/InvoicingLimitWarningTest.php
  modified:
    - app/Config/Services.php
    - app/Controllers/Invoicing.php
    - app/Views/invoicing/_preview.php
    - app/Language/sk/Invoicing.php
    - app/Language/en/Invoicing.php
key-decisions:
  - "LimitPrecheck takes an optional callable $limitsFor (default TurnoverCalculator::forEntityId) so forDraft is testable without a DB and a failure can be injected via Services::injectMock('limitPrecheck', new LimitPrecheck(throwing closure))"
  - "A6 confirmed: snapshot is built whenever an entity is chosen and parts exist, but with a foreign currency and fx.state not in {ok, manual} snapshot['fx'] is null, so the precheck returns unavailable/rate and never adds foreign-currency totals to turnover"
  - "Precheck failure returns state failed (info only); status, canIssue and the issue path are untouched"
requirements-completed: [LIM-04, CUR-04]
duration: 25min
completed: 2026-11-02
---

# Phase 10 Plan 06: Invoice limit precheck Summary

The invoice preview now warns, under the FX card, when issuing would bring the entity's turnover (window by delivery date, amount in the entity currency from the preview rate) to any limit threshold. It shows percent and sums before and after, never blocks issuing, and stores nothing.

## Tasks

| Task | Commit |
|------|--------|
| 1 LimitPrecheck + service | 635abfe (test and implementation in one commit) |
| 2 Preview warning | 52fe064 (test, controller, view, lang in one commit) |

## Verification

- `LimitPrecheckTest`: 9 tests OK. `InvoicingLimitWarningTest`: 10 tests OK.
- `phpunit tests/feature/Invoicing tests/unit/I18n`: 196 tests OK.
- PHPStan on Reporting, Invoicing controller, Services, the new view and tests: no errors.
- Acceptance greps: no fx/float references in LimitPrecheck, no `style=|onclick=|<script` in `_limit_warning.php`, `InvoiceIssuer`/`IssueCommand` untouched.
- Human check on staging remains from the plan.

## Deviations from Plan

**1. [Process]** The RED test commits were folded into the feature commits (one commit per task instead of test + feat).

**2. [Rule 1 - Static analysis]** `$draft->fx['state'] ?? null` triggered a PHPStan nullCoalesce error, since the key always exists on the typed shape. Changed to `$draft->fx['state']`.

**3.** The rolling-window test uses the delivery date 2026-10-31 for an included turnover (2025-11) and a second test with 2025-10 turnover (outside the window) instead of a future delivery date, which avoids depending on delivery date validation.

**4.** `testFailureIsSafe` injects a real `LimitPrecheck` with a throwing closure because the service has a `LimitPrecheck` return type and the class is final.

## Known Stubs

None.

## Threat Flags

None. T-10-21 (try/catch Throwable, tested), T-10-22 (amount only from the server-built snapshot), T-10-23 (`esc()`, no inline, `rel="noopener"`), T-10-25 (issuer untouched, tested that nothing new is stored) are mitigated.

## Self-Check: PASSED
