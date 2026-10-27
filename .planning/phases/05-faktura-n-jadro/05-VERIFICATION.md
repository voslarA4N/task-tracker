---
phase: 05-faktura-n-jadro
verified: 2026-10-27T12:00:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
human_verification:
  - test: "iPhone (installed PWA, staging or dev via mkcert): Faktury -> Fakturovat mesiac -> choose client and month one-handed, move one part to the next month, issue the invoice via the bottom confirm sheet, open Zobrazit PDF"
    expected: "Items, total, dates and diacritics are readable in the PDF; no horizontal scrolling; touch targets at least 44 px"
    why_human: "Visual layout, one-handed iPhone UX and touch target size cannot be verified by grep or PHPUnit (plan 05-09 end-of-phase human-check)"
  - test: "Change an issue date in the preview and tap Vystavit before the htmx refresh returns (WR-01 race)"
    expected: "Decide whether the stale hidden dates being issued is acceptable for the phase, or whether to harden the preview token"
    why_human: "Product and risk decision on the review warning, not a pass/fail code check"
---

# Phase 5: Fakturacne jadro Verification Report

**Phase Goal:** Vlastnik vyberie klienta a mesiac, skontroluje nahlad a vystavi fakturu, ktora je atomovo vytvorena, spravne ocislovana, nemenna a ulozena vratane PDF so sha256; nic, co sa zmeni neskor, ju nezmeni.
**Status:** human_needed (all automated checks pass, human items remain)
**Re-verification:** No, initial verification

Note: ROADMAP marks the phase `mode: mvp`, but the goal is not in "As a ..., I want to ..., so that ..." form. I verified it with standard goal-backward methodology against the five roadmap success criteria. Consider running `/gsd mvp-phase 5` or dropping the mode flag.

## Observable Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Screen "Fakturacia mesiaca": pick client/month/entity, preview of unlocked parts with no number, move a part, backdate warning | VERIFIED | Routes `invoices/new`, `fragments/invoice-preview`, `invoices/preview-move/(:num)`, `invoices/issue` in `app/Config/Routes/invoicing.php`. `InvoiceDraftService` builds the preview with no number. `InvoiceGuards::backdate` runs under the series lock in `InvoiceIssuer` and throws `backdate_unconfirmed` unless confirmed. Covered by `InvoicingScreenTest`, `InvoicingPreviewTest`, `BackdateTest`. |
| 2 | One line per part with VAT code, issue/delivery/due dates; numbers 202610001, 202610002, 202611001 without gaps | VERIFIED | `InvoiceIssuer` steps 6-11 write `invoice_lines` from the snapshot. `NumberAllocator::next` uses a single `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` (no SEQUENCE, no MAX()+1). The 50-issue test asserts `202610050` is among the numbers. Per-period reset and initial number are in `NumberAllocatorTest`. |
| 3 | 50 concurrent issues on two connections give no duplicate or skipped number; later-step failure returns the number and releases parts | VERIFIED | `IssueConcurrencyTest` (COUNT=50, two `proc_open` workers). `IssueRollbackTest` and `IssueIdempotencyTest` exist. `issueInTransaction` runs in one transaction: counter, PDF, invoice, lines, document, `lock_allocations`, then `SET CONSTRAINTS ALL IMMEDIATE`. Rollback also reverts the counter because the allocation happens inside the transaction. |
| 4 | After issue, changes to entity/client/rate/currency do not change the invoice; edit of a locked part is rejected by server and DB; stored PDF has identical bytes and sha256 | VERIFIED | `InvoiceImmutableAfterChangeTest` changes the entity version, client name and rate, client currency, task rate and title, bank IBAN and legal text. It then asserts identical invoice, snapshot and line rows, identical PDF bytes and sha256. `InvoiceImmutabilityTest` covers `app_rw` and owner triggers. `AllocationLockTest` and `AllocationGuardTest` cover locked-part edits. The PDF is served from `DocumentStore` bytea with an sha256 check. |
| 5 | PHPUnit incl. `app_rw` tests confirm total parity, numbering, locking, snapshot, change-everything test | VERIFIED | Full suite re-run in the `php` container: `OK (1062 tests, 7231 assertions)`. Parity is covered by `InvoicingIssueTest`/`InvoicingScreenTest` (221.98 screen = invoice = snapshot = PDF) and by `InvoiceCalculatorTest`. |

**Score:** 5/5 truths verified.

### Requirements Coverage

| Requirement | Status | Evidence |
|---|---|---|
| INV-01 | SATISFIED | Truth 1, `Invoicing` controller and views |
| INV-02 | SATISFIED | Truth 2, `InvoiceIssuer`, `SnapshotBuilder` |
| INV-03 | SATISFIED | JSONB snapshot and `invoices.snapshot`; `InvoiceImmutableAfterChangeTest` |
| INV-04 | SATISFIED | `NumberAllocator`, `SeriesFormat`, `NumberAllocatorTest` |
| INV-08 | SATISFIED | Structured columns in `invoices` and `invoice_lines` (`InvoiceSchemaTest`) |
| INV-09 | SATISFIED | `vat_code` migration `2026-10-27-000001`, line `vat_code` from the legal profile |
| INV-13 | SATISFIED | `DocumentStore` bytea plus sha256 and template version; endpoint `invoices/(:num)/pdf` |
| INV-14 | SATISFIED | Truth 3 |
| INV-15 | SATISFIED | Backdate guard and confirmation, `BackdateTest` |
| ALC-03 | SATISFIED | `lock_allocations()` plus `alloc_guard` trigger, `AllocationLockTest`; the invoice number is shown on a locked part |
| NFR-06 | SATISFIED | Calculator, numbering with concurrency, locking, snapshot tests present and green |

All 11 IDs are marked Complete in REQUIREMENTS.md, and none is orphaned.

### Anti-Patterns

No `TBD`, `FIXME` or `XXX` in the phase's production files (grep of `app/Libraries/Invoicing`, controllers, views and migrations). No stubs found on the issue path.

### Code Review Warnings (05-REVIEW.md, 0 critical, 10 warnings)

None of them fails a success criterion outright. Several weaken guarantees the goal implies:

| WR | Impact on goal | Assessment |
|---|---|---|
| WR-01 | The preview token hashes only line amounts, not dates, entity version, bank or legal data. | Does not break SC1-5, but "check the preview and then issue exactly that" is only partly enforced. Confirmed in `InvoiceIssuer` (token is built from client, month, entity and lines only). Recommend a fix before Phase 6. |
| WR-02 | No upper bound on the issue date. A typo creates an immutable future invoice and disturbs the series. | Not required by any SC. Recommend a fix. |
| WR-03 | Move-from-preview rewrites hours, rate and amount from a pre-lock read (lost update). | Edge case, single-user. |
| WR-04 | Line order differs between preview (SQL) and issuer (PHP string sort) across the DST fall-back hour. | Edge case. |
| WR-05 | The variable symbol is the raw number and can contain letters. | Affects INV-02 only for non-numeric formats. The default `RRRRMM###` is numeric. Recommend a fix before Phase 6/7. |
| WR-06 | `invoice_counters` INSERT is unguarded at DB level, so a gap could be inserted by a bug. | SC3 holds through the application path. DB-level hardening is incomplete. |
| WR-07 | Owner `TRUNCATE` bypasses the immutability triggers. | SC4 is proven for `app_rw`, and the owner is protected only against UPDATE/DELETE. The comments overstate the protection. Hardening recommended. |
| WR-08 | Unique-violation races return a generic 500. The same-key concurrent idempotency path is untested. | Correctness is likely held by the row locks. The test gap is real. |
| WR-09 | Backup check validates only the TOC. | Not part of the SC. |
| WR-10 | The historical external lock is selected by a proxy and is irreversible. | Operational, WR-07 from Phase 4. |

Recommendation: track WR-01, WR-02, WR-05, WR-06 and WR-07 as follow-up hardening (a gap-closure plan or the early part of Phase 6) before real production invoices are issued. They are not blockers for closing the phase as built.

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Full PHPUnit suite | `docker compose exec php vendor/bin/phpunit` | OK (1062 tests, 7231 assertions) | PASS |
| JS tests (`node --test tests/js/`) | not re-run, taken from the executor's report | n/a | SKIP |

## Human Verification Required

1. **iPhone issue flow (plan 05-09 human-check).** Fakturovat mesiac, select client and month, move a part, issue via the bottom sheet, open the PDF. Expected: readable layout and diacritics, no horizontal scroll, touch targets of at least 44 px. Why human: visual and touch UX.
2. **WR-01 decision.** Decide whether to accept the preview-token scope for now or harden it first.

## Gaps Summary

No blocking gaps. Phase goal is achieved in code and tests. Status is `human_needed` solely because of the open iPhone check and the WR-01 risk decision.

---

_Verified: 2026-10-27_
_Verifier: Claude (gsd-verifier)_
