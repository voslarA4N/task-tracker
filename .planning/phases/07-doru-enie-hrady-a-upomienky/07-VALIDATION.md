---
phase: 7
slug: doru-enie-hrady-a-upomienky
status: draft
nyquist_compliant: true
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

Pred integračnými testami `make test-db`. JS testy `node --test tests/js/*.test.js`.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 07-01-T1 | 07-01 | 1 | MAIL-01/MAIL-02 | T-07-01, T-07-03, T-07-05, T-07-06 | spike: vlastný Message-ID, prílohy z bufferu, BCC nie v hlavičkách, obálka = SMTP účet, nová inštancia na správu, log bez tela | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'AppMailerMessageTest\|MailpitDeliveryTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-01-T2 | 07-01 | 1 | MAIL-04 | T-07-04, T-07-07 | seed 8 šablón, whitelist strtr, fallback en, ASCII názvy PDF | unit/integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'TemplateRendererTest\|DocumentFilenameTest\|EmailTemplatesSeedTest\|InvoicePdfEndpointTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-02-T1 | 07-02 | 1 | MAIL-01 (D-01) | T-07-10, T-07-11 | overlay siete mail len pri MAIL_NETWORK, chýbajúca sieť zastaví deploy | unit | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'DeployGuardTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-02-T2 | 07-02 | 1 | MAIL-01 (D-01) | T-07-08 | dokumentácia bez hesla | grep + manual | `grep -c '^## 20\.' docs/ops/server.md` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-03-T1 | 07-03 | 2 | INV-06 | T-07-16 | invoice_sends/voids insert-only, view 16 pôvodných stĺpcov, stav sent podľa D-29 | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'DeliveryRolesTest\|InvoiceStatusViewColumnsTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-03-T2 | 07-03 | 2 | MAIL-01 (D-04, D-10, D-11) | T-07-13, T-07-14, T-07-15, T-07-19, T-07-20 | 2 prílohy = invoice_documents sha256, zlyhanie SMTP nič nezapíše, dvojklik raz, allowlist From | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'InvoiceMailerTest\|InvoiceMailerFailureTest\|InvoiceMailerIdempotencyTest\|SenderIdentityTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-03-T3 | 07-03 | 2 | MAIL-01 | T-07-18, T-07-21 | sheet odoslania, CSRF, 422, HX-Location | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'DeliverySendTest\|ViewsCspTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-04-T1 | 07-04 | 3 | MAIL-02 | T-07-22, T-07-24 | resend na ľubovoľnú adresu s rovnakými bajtmi | integration/feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'ResendTest\|DeliveryResendTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-04-T2 | 07-04 | 3 | MAIL-02 | T-07-25 | editor adresátov, upozornenie mimo klienta, história | js/feature | `node --test tests/js/dl-recipients.test.js` + DeliveryResendTest | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-05-T1 | 07-05 | 3 | MAIL-01 (D-27) | T-07-27, T-07-29, T-07-30 | doména mimo allowlist odmietnutá, predvolené hodnoty, audit | unit/integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'SenderIdentityTest\|EntitySenderTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-05-T2 | 07-05 | 3 | MAIL-01 (D-27) | T-07-28 | karta odosielateľa, 422 domény | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'EntitySenderFormTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-06-T1 | 07-06 | 4 | MAIL-04 | T-07-32, T-07-34, T-07-35 | revízia, audit, neznámy placeholder odmietnutý, 409 | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'EmailTemplateServiceTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-06-T2 | 07-06 | 4 | MAIL-04 | T-07-36, T-07-37, T-07-38 | obrazovka šablón, náhľad, vkladanie premenných | js/feature | `node --test tests/js/et-vars.test.js` + EmailTemplatesControllerTest | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-07-T1 | 07-07 | 4 | PAY-03, INV-06 | T-07-41 | payments/voids insert-only, view v2 stavy a zostatok | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'PaymentRolesTest\|InvoiceStatusViewTest\|InvoiceStatusViewColumnsTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-07-T2 | 07-07 | 4 | PAY-01, PAY-03 (D-28) | T-07-39, T-07-40, T-07-42, T-07-44 | viac úhrad, predvolený zostatok, preplatenie, súbeh, storno, hranica dňa | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'PaymentServiceTest\|PaymentVoidTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-07-T3 | 07-07 | 4 | PAY-01 | T-07-43, T-07-45, T-07-46 | karta Úhrady, sheet, storno, odznak po splatnosti | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'PaymentsTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-08-T1 | 07-08 | 5 | INV-06 (D-29) | T-07-47, T-07-48, T-07-49 | ručné označenie a storno insert-only | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'ManualSendServiceTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-08-T2 | 07-08 | 5 | INV-06 (D-29) | T-07-50, T-07-51 | tlačidlá a sheety označenia | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'MarkSentTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-09-T1 | 07-09 | 5 | PAY-02, INV-06 | T-07-52, T-07-53 | po splatnosti z Clock, hranica dňa, filter stavu | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'OverdueQueriesTest\|InvoiceQueriesTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-09-T2 | 07-09 | 5 | PAY-02 | T-07-54 | dashboard Po splatnosti, zoznam so stavmi | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'OverdueDashboardTest\|InvoicesListStatusTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-10-T1 | 07-10 | 6 | MAIL-03 | T-07-57, T-07-60 | reminder: len faktúra, In-Reply-To, len zostatok > 0 | integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'ReminderMailerTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-10-T2 | 07-10 | 6 | MAIL-03 | T-07-58, T-07-59, T-07-61 | upomienka z dashboardu a detailu | feature | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'ReminderTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-11-T1 | 07-11 | 7 | všetky (D-25) | T-07-62, T-07-63, T-07-64 | deterministický seed cez služby, transport log | unit/integration | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter 'HistoryPlannerTest\|StagingSeederTest'` | ❌ W0 (vytvorí úloha) | ⬜ pending |
| 07-11-T2 | 07-11 | 7 | všetky | — | postup ručného overenia | manual | human-check (end-of-phase) | ❌ W0 (vytvorí úloha) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Spike správania CI4 Email (Message-ID, attach buffer, BCC, obálka) cez `CapturingEmail` a Mailpit API — 07-01-T1 (zdroj CI4 4.7.5 overený plánovačom)
- [ ] Fixtures: `tests/_support/CapturingEmail.php` (07-01), `MasterFixtures` reset nových tabuliek (07-03, 07-07)
- [ ] Mailpit integračný test so skipom pri nedostupnom `mailpit` (07-01-T1, `MailpitDeliveryTest`)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Doručiteľnosť (SPF/DKIM/DMARC PASS) | MAIL-01 | závisí od reálneho SMTP a DNS; staging má transport log | produkcia po zapnutí SMTP (docs/ops/server.md sekcia 20): skúšobná faktúra iba na vlastnú adresu, Gmail „Show original“, overiť PASS (07-02-T2, 07-11-T2) |
| Resend z iPhonu | MAIL-02 | inštalovaná PWA na reálnom zariadení | staging: v PWA Odoslať znova najviac 2 klepnutiami (transport log, záznam v histórii); bajtová zhoda je automaticky v ResendTest a ručne v dev cez Mailpit (07-11-T2) |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [ ] Feedback latency < 90s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
