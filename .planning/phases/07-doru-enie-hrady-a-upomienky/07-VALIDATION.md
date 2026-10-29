---
phase: 7
slug: doru-enie-hrady-a-upomienky
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-29
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^10.5.16 (CIUnitTestCase), JS testy `tests/js/*.test.js` |
| **Config file** | `phpunit.xml.dist` (existujúci), `tests/_support/PgTestCase.php` |
| **Quick run command** | `vendor/bin/phpunit --group integration tests/integration/Mail tests/integration/Receivables` (v php kontajneri) |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~90 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit <dotknutý testovací súbor>`
- **After every plan wave:** Run `vendor/bin/phpunit --group integration`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 90 seconds

---

## Per-Task Verification Map

Plánovač doplní Task ID podľa PLAN.md. Mapa požiadaviek na testy z 07-RESEARCH.md:

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| TBD | TBD | 0 | MAIL-01/MAIL-02 | Tampering (bajty) | spike: Message-ID, prílohy, BCC neuniká do hlavičiek | integration | `phpunit tests/integration/Mail/EmailSpikeTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-01 | — | 2 prílohy, šablóna v jazyku dokladu, `invoice_sends` po úspechu | integration | `phpunit tests/integration/Mail/InvoiceMailerTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-01 (D-04) | — | zlyhanie SMTP nezmení faktúru ani nezapíše send | integration | `phpunit tests/integration/Mail/InvoiceMailerFailureTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-02 | Tampering | bajty príloh == `invoice_documents` (sha256), nikdy render | integration | `phpunit tests/integration/Mail/InvoiceMailerAttachmentTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-01 (D-11) | Repudiation | rovnaký `request_key` pošle raz | integration | `phpunit tests/integration/Mail/InvoiceMailerIdempotencyTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-03 | — | upomienka: kind, In-Reply-To, len faktúra, len zostatok>0 | integration | `phpunit tests/integration/Mail/ReminderTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-04 | Template injection | whitelist placeholderov, fallback `en`, audit + revision | unit/integration | `phpunit tests/unit/Mail/TemplateRendererTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | MAIL-04 (D-27) | Spoofing | doména mimo allowlist odmietnutá | unit | `phpunit tests/unit/Mail/SenderIdentityTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | PAY-01/PAY-03 | Repudiation | viac úhrad, preplatenie odmietnuté, bcmath | integration | `phpunit tests/integration/Receivables/PaymentServiceTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | PAY-01 (D-28) | Tampering | storno úhrady, dôvod povinný, audit | integration | `phpunit tests/integration/Receivables/PaymentVoidTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | INV-06/PAY-02 | — | stavy, priorita, overdue hranica s `FrozenClock` | integration | `phpunit tests/integration/Receivables/InvoiceStatusViewTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | NFR insert-only | Tampering | `app_rw` nemôže UPDATE/DELETE sends/payments/voids | integration | `phpunit tests/integration/DbRolesTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | INV-06 | — | pôvodných 17 stĺpcov view nezmenených | integration | `phpunit tests/integration/Receivables/InvoiceStatusViewColumnsTest.php` | ❌ W0 | ⬜ pending |
| TBD | TBD | — | UI | CSRF | dashboard po splatnosti, panel odoslania, CSRF htmx | feature | `phpunit tests/feature/Invoicing/DeliveryTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Spike správania CI4 Email (Message-ID, attach buffer, BCC) cez `MockEmail`/Mailpit API
- [ ] Fixtures: helper na úhrady a odoslania k existujúcim `IssueFixtures`/`InvoiceFixtures`
- [ ] `tests/_support` Mailpit klient (preskočiť, ak `MAILPIT_URL` chýba)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Doručiteľnosť (SPF/DKIM/DMARC PASS) | MAIL-01 | závisí od reálneho SMTP a DNS | staging: odoslať faktúru na Gmail, „Show original“, overiť PASS |
| Resend z iPhonu | MAIL-02 | inštalovaná PWA na reálnom zariadení | staging: otvoriť faktúru v PWA, znova odoslať na vlastný e-mail, porovnať sha256 prílohy |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 90s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
