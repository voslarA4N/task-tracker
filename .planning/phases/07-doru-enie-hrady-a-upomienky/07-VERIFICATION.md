---
phase: 07-doru-enie-hrady-a-upomienky
verified: 2026-10-29T19:00:00Z
status: human_needed
score: 5/5 roadmap truths verified (code level); 5 human UAT items pending
overrides_applied: 0
human_verification:
  - test: "UAT-01 Resend from iPhone in at most 2 taps (staging)"
    expected: "Resend sheet, own address, flash says test environment, history shows transport log"
    why_human: "Real device and PWA. NOTE: first fix WR-05 (staging fromEmail domain vs allowedFromDomains) or this will fail with sender_invalid"
  - test: "UAT-02 Reminder and payment from the Overdue dashboard on iPhone"
    expected: "Reminder in history, payment changes balance/status, fully paid row disappears"
    why_human: "Touch UX, real device"
  - test: "UAT-03 One-hand use, 44 px targets, no horizontal scroll"
    expected: "Holds on dashboard, list, detail, send sheet, payment sheet"
    why_human: "Visual/layout"
  - test: "UAT-04 Byte-identical attachments and reminder threading in Mailpit"
    expected: "sha256 of downloaded attachment equals sha256 on invoice detail; reminder in same thread"
    why_human: "Needs Mailpit inspection"
  - test: "UAT-05 Production deliverability (SPF/DKIM/DMARC PASS)"
    expected: "All PASS in Gmail Show original; attachment sha256 matches"
    why_human: "External service, production SMTP"
---

# Phase 7: Delivery, payments and reminders - Verification Report

**Phase Goal:** Owner sends invoice+report in one step, resends from mobile, records payments, sees what is overdue and sends a reminder in one click.
**Status:** human_needed (no blocking gaps found at code level; review findings listed as advisory with recommendations)
**Re-verification:** No, initial.
**Note on mode:** ROADMAP marks phase `mode: mvp` but the goal is not in "As a..., I want..., so that..." form, so the user-story guard could not be applied (`gsd-sdk` is not installed in this environment, so I also could not run the validator). Standard goal-backward verification was used instead.

## Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Send invoice + report by e-mail from template in client language; date and recipients stored; failure does not change the invoice | VERIFIED (code) | `InvoiceMailer.php` (438 lines): `draft()` pulls template by language, attaches `['invoice','report']` from `DocumentStore` (stored bytes with sha256), inserts insert-only `invoice_sends` row with recipients, bcc, subject, attachments, smtp_response; failures map to `SendRejected` / record_failed and leave the invoice untouched. Routes `invoices/(:num)/send` wired in `Routes/invoices.php`. Tests: DeliverySendTest, InvoiceMailerTest, InvoiceMailerFailureTest, MailpitDeliveryTest. |
| 2 | Resend stored PDF to any address, also from mobile, identical bytes | VERIFIED (code), mobile UX human | `kind` resend, `resendForm/resend` routes, `_delivery_sheet.php`, attachments loaded via `DocumentStore` bytes (no regeneration); DeliveryResendTest, ResendTest. Byte equality in Mailpit = UAT-04. |
| 3 | Edit templates per language; reminder in one click with invoice PDF; send date stored | VERIFIED (code) | `Settings\EmailTemplates` controller + views + routes (`settings/email-templates`), `EmailTemplateService`; reminder kind attaches only invoice, threads via In-Reply-To, rejected when balance <= 0, stored in `invoice_sends` (`kind=reminder`); routes `invoices/(:num)/remind`. Tests: EmailTemplatesControllerTest, ReminderTest, ReminderMailerTest. |
| 4 | Statuses issued -> sent -> paid; overdue derived; dashboard lists overdue | VERIFIED | Migration `000006_ExtendInvoiceStatusViewPayments` (invoice_status_v v2), `InvoiceStatusQueries::overdue()` called from `Home.php:64`, `_overdue.php` view; tests InvoiceStatusViewTest, OverdueQueriesTest, OverdueDashboardTest, InvoicesListStatusTest. |
| 5 | One or more payments (date, optional amount); partially_paid and balance derived from sum | VERIFIED | `PaymentService` (282 lines, advisory lock, request_key replay), insert-only `payments` + `payment_voids`, `Payments` controller/routes, balance derived in the view; PaymentServiceTest, PaymentVoidTest, PaymentsTest, PaymentRolesTest. |

**Score:** 5/5. Last full suite run per orchestrator: OK, 1564 tests (not re-run by me). No TBD/FIXME/XXX markers in phase code (grep of Delivery, Receivables, Mail, controllers, invoice/home views: none).

## Requirements Coverage

| Requirement | Status | Evidence |
|-------------|--------|----------|
| MAIL-01 | SATISFIED (deliverability human) | Truth 1; UAT-04/05 |
| MAIL-02 | SATISFIED (mobile human) | Truth 2; UAT-01 |
| MAIL-03 | SATISFIED | Truth 3; UAT-02/04 |
| MAIL-04 | SATISFIED | Truth 3 |
| PAY-01 | SATISFIED | Truth 5 |
| PAY-02 | SATISFIED | Truth 4 |
| PAY-03 | SATISFIED | Truth 5 |
| INV-06 | SATISFIED | Truth 4, subject to WR-01 edge case |

All 8 IDs are in REQUIREMENTS.md (marked Complete, mapped to Phase 7). No orphaned requirements.

## Assessment of code review findings (07-REVIEW.md)

None of the findings negates a roadmap truth on the main path, but several are real defects. I classify them as WARNINGS, not BLOCKERS. I did not run the faulty paths, but confirmed the code at the cited places.

- **WR-05 (confirmed in code and docs): fix before UAT-01.** `docs/ops/server.md:154` sets staging `email.fromEmail = noreply@$DOMAIN` (work-tracker-stage.vostack.dev) while staging keeps `allowedFromDomains = vostack.dev` (line 493 text, step 3). `SenderIdentity::assertAllowed` (line 97) does an exact domain match, so every staging send will fail with `sender_invalid`. Production config (`noreply@vostack.dev`) is fine. The seed hides this. This will make UAT-01/02 fail on staging as configured.
- **CR-01 (confirmed by review description, not reproduced): advisory but should be fixed.** Entity edit form posts resolved defaults, so the first save freezes sender values and defeats NULL=default (D-27); combined with WR-05 it can block entity edits. It does not stop sending, so it does not defeat the phase goal. It does weaken the "correct and unchanging" spirit for outgoing mail identity.
- **WR-01 (confirmed: `ManualSendService.php:178`, `InvoiceDelivery.php:138`, `show.php:49` all gate on `status === 'issued'`):** an invoice paid before being sent cannot be marked sent and the primary Send button is hidden (only Resend remains, without CC). This is an edge case against truth 4 / INV-06 (issued -> sent -> paid sequence); recommend fixing by gating on `sent_at`.
- **WR-02 (idempotent replay returns 404 after state change), WR-03 (possible double SMTP send on concurrent same request_key / retry after record_failed), WR-04 (subject control chars), WR-06 (no lower date bound):** robustness defects; data stays consistent, the primary flows work. Recommend fixing WR-03 (client could receive a duplicate invoice) soonest.
- IN-01..IN-05: informational.

Decision needed from owner: accept these as follow-up (suggested gap-closure plan: WR-05, CR-01, WR-01, WR-03, WR-02) or require fixes before closing the phase. Because the owner asked to reflect this honestly: no override was applied; status is `human_needed` because the 5 pending UAT items remain and cannot be verified programmatically.

## Anti-Patterns
No debt markers. No stubs found in the main artifacts (all substantive: 130-440 lines, wired through routes and controllers, data from real DB queries).

## Human Verification
See the 5 pending items in `07-HUMAN-UAT.md` (UAT-01..05); same list as in frontmatter, no duplicates added.

## Gaps Summary
No blocking gaps. Pending: human UAT, and a recommended follow-up for WR-05/CR-01/WR-01/WR-03.

_Verifier: Claude (gsd-verifier)_
