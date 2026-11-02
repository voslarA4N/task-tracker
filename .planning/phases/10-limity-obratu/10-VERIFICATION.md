---
phase: 10-limity-obratu
verified: 2026-11-02T13:00:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Staging, iPhone: Viac > Limity obratu (karty, vzorec, odznak Návrh, sheety pridať/upraviť/zmazať, počiatočný obrat)"
    expected: "Jeden stĺpec, bez horizontálneho posúvania, dotykové ciele aspoň 44 px, čitateľný vzorec"
    why_human: "Vizuálne a dotykové správanie (plány 10-03, 10-07, 10-08)"
  - test: "Staging, iPhone: dashboard upozornenia 80 % / 100 % / chýbajúci riadok roka"
    expected: "Upozornenia sú viditeľné a čitateľné"
    why_human: "Vzhľad (plán 10-05)"
  - test: "Staging: náhľad faktúry s predkontrolou limitu"
    expected: "Varovanie sa zobrazí a nezablokuje vystavenie"
    why_human: "Vizuálne a tok používateľa (plán 10-06)"
---

# Phase 10: Limity obratu — verification report

**Goal:** Vlastník vidí, koľko z ročného limitu obratu za subjekt čerpá, dostane upozornenie včas a pred vystavením faktúry sa dozvie, či by prekročila prah.
**Status:** human_needed (all automated truths pass; the iPhone/staging checks are open; one critical review finding should be fixed first)
**Re-verification:** No, initial verification.

## Observable truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | The limits table is editable in settings (country, year, name, amount, currency, method, thresholds); SK/PL values are only a seeded proposal | VERIFIED | Migration `2026-11-02-000001_CreateTurnoverLimits.php` creates `turnover_limits` with an `is_seed` flag. The seed is inserted only `WHERE NOT EXISTS`. Routes `settings/limits` (new, edit, delete, opening) exist in `app/Config/Routes/settings.php`. `LimitService` and `LimitInput` back them. Feature tests `LimitsControllerTest` and `LimitsCrudTest` exist and pass in the full suite. |
| 2 | Turnover is computed from issued invoices by delivery date minus credit notes, only for clients flagged "count", in entity currency from stored amounts; an opening turnover can be entered | VERIFIED | `TurnoverCalculator` L41-46 sums `total_entity_ccy WHERE counts_toward_limit AND origin='issued' AND turnover_date BETWEEN`. It never reads JSONB or live client settings. `CreditNoteIssuer` L223 writes the negated `total_entity_ccy`. `entity_opening_turnover` and `OpeningTurnoverService` add the opening turnover. This also covers CUR-04. |
| 3 | The calculation method can be chosen (calendar year, rolling 12 months, pro-rata first year); the formula and value used are shown | VERIFIED | `PeriodResolver::METHODS` has all three. `settings/limits/_card.php` L76-85 shows the period, documents, opening turnover and total (the formula). Unit tests in `PeriodResolverTest`. |
| 4 | The dashboard warns at 80 % and 100 % and also when the current-year row is missing (last known limit is used) | VERIFIED | `Home.php` L68 passes `LimitAlerts::fromEntities(...)` into `views/home/_limits.php`. `LimitSelector` provides the year fallback. Tests in `DashboardLimitsTest`, `LimitAlertsTest`, `LimitSelectorTest`. |
| 5 | The invoice preview warns if issuing would exceed a limit threshold | VERIFIED | `Invoicing.php` L397 calls `limitWarning()`, which uses `limitPrecheck`. It is wrapped in try/catch and non-blocking. View `invoicing/_limit_warning.php`. Tests in `InvoicingLimitWarningTest` and `LimitPrecheckTest`. |

**Score:** 5/5

## Requirements coverage

| ID | Status | Evidence |
|---|---|---|
| LIM-01 | SATISFIED | Truth 1 |
| LIM-02 | SATISFIED | Truth 2 |
| LIM-03 | SATISFIED | Truth 4 |
| LIM-04 | SATISFIED | Truth 5 |
| LIM-05 | SATISFIED | Truth 3 |
| CUR-04 | SATISFIED | Truth 2 (uses `total_entity_ccy` only) |

All six IDs appear in REQUIREMENTS.md, mapped to Phase 10 as Complete, and none is orphaned. Orchestrator facts accepted: PHPUnit 2108 tests OK, PHPStan clean. No TBD/FIXME/XXX markers in the phase files.

## Code review findings weighed against must-haves

No finding fails a ROADMAP success criterion. Several violate project hard constraints or quality, and should be fixed.

- **CR-01 (critical, confirmed in code):** `app/Views/settings/limits/_form.php` L43-45 renders `revision` from `$limit['revision']`, the current DB value, on re-render after a 422. After a validation error, a stale form therefore picks up the new revision and silently overwrites a concurrent edit. This breaks the optimistic-lock constraint for limit editing. It is a real defect but does not break SC 1, because editing works in the normal case. **Recommendation:** fix before shipping, preferably through a gap-closure plan.
- **WR-02:** `LimitPrecheck.php` L76 sets `alreadyOver` at the lowest threshold (80 %), so the preview says "Limit už je prekročený" at 80 % or more. This is a misleading message and weakens SC 5, which asks for an upozornenie on a threshold being exceeded. The warning does appear, but the wording is wrong. The existing tests lock in the wrong behaviour.
- **WR-03:** A pro-rata limit rounding to 0.00 causes `DivisionByZeroError`. This is an edge case with tiny amounts.
- **WR-04:** The dashboard `TurnoverCalculator` call at `Home.php` L68 is unguarded, so with WR-03 a bad limit row could make the dashboard return 500. This is the main resilience risk.
- **WR-01, WR-05, WR-06:** the opening-turnover form has a revision not bound to a row, a stale currency symbol when the entity select changes (a risk of storing the wrong currency), and a first-insert race that gives a 500. These are low-probability in a single-user app.
- **IN-01 to IN-05:** minor (non-existent `w-lg-auto` class, dead `EntityLimits::alerts()`, duplicated SELECT mapping).

## Anti-patterns

No blocker markers found. Aside from the review findings above, there are no stubs. Data flows from DB aggregates to views.

## Human verification required

The three iPhone/staging checks are listed in the frontmatter. They were left open by plans 03, 05, 06, 07 and 08.

## Gaps summary

No ROADMAP-level gaps. Status is human_needed because of the open staging checks. Recommended follow-up, with CR-01 most important: CR-01, WR-02, WR-03/WR-04 (guard the dashboard with try/catch and zero-limit handling), WR-05.

_Verifier: Claude (gsd-verifier)_
