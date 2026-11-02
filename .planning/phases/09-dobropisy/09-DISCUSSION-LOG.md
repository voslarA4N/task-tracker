# Phase 9: Dobropisy - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-31
**Phase:** 09-dobropisy
**Areas discussed:** none (owner took the defaults derived from spec/PRD/research plus the proposed defaults for the remaining gaps)

---

## Postup

| Option | Description | Selected |
|--------|-------------|----------|
| Prevziať všetko | CONTEXT.md with the proposed defaults; turnover period and PL KSeF fields stay open questions for the accountant | ✓ |
| Uhradená faktúra | Discuss cancelling a paid or partly paid invoice | |
| Typy opravy a dátumy | Discuss the correction type list and the credit note dates | |
| E-mail a PDF | Discuss sending the credit note and how the PDF looks | |

**User's choice:** Prevziať všetko
**Notes:** Proposed defaults accepted: storno of a paid invoice allowed with a refund warning (D-17); no credit note for external invoices (D-14); fixed correction_type list plus a required free-text reason (D-09, D-10); issue date today, delivery/turnover from the original, due date = issue date (D-07); own `credit_note` e-mail template, PDF-only attachment (D-19); PDF title per language (D-18).

## Claude's Discretion

Negative line shape, sharing code with InvoiceIssuer, view column/renderer names, button placement, shortcut to released parts, staging seed extension, test coverage details.

## Deferred Ideas

Partial credit notes (v2+), refund tracking, KSeF/FA(3) correction export, accountant questions (turnover period, PL requirements, final wording).
