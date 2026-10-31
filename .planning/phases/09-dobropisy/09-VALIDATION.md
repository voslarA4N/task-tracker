---
phase: 9
slug: dobropisy
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-31
---

# Phase 9 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 + `CIUnitTestCase` / `Tests\Support\PgTestCase` |
| **Config file** | `phpunit.xml.dist` (suites `unit`, `integration`; skupina `integration` na PG) |
| **Quick run command** | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit --filter 'CreditNote|InvoiceStatusView|AllocationGuard'` |
| **Full suite command** | `docker exec task-tracker-php-1 composer test` |
| **Estimated runtime** | ~60 seconds |

Poznámka: testy len v kontajneri (hostiteľské PHP nemá `bcround`). Pred integračnými testmi `make test-db` (migrácie na `task_tracker_test`).

---

## Sampling Rate

- **After every task commit:** Run quick run command
- **After every plan wave:** Run `docker exec task-tracker-php-1 composer test`
- **Before `/gsd-verify-work`:** `composer check` must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

Vyplní planner / executor podľa PLAN.md (Task ID = `09-PP-TN`). Mapa požiadaviek na testy z RESEARCH.md:

| Requirement | Behavior | Test Type | Test File | Status |
|-------------|----------|-----------|-----------|--------|
| INV-07 | záporné riadky, `total = -orig`, súčet 0,00 (aj `total_entity_ccy`) | integration | `CreditNoteIssuerTest` | ⬜ pending |
| INV-07 | rad `credit_note`, `DB-RRRRMM###`, obdobie podľa dátumu dobropisu, bez medzier | integration | `CreditNoteNumberingTest` | ⬜ pending |
| INV-07 | súbeh: 2 procesy, 1 úspech + `already_credited`, bez medzery | integration | `CreditNoteConcurrencyTest` + `credit_note_worker.php` | ⬜ pending |
| INV-07 | `release_allocations` len pri existujúcom dobropise, `alloc_guard` výnimka len `X -> NULL` | integration | `CreditNoteAllocationReleaseTest` | ⬜ pending |
| INV-07 | faktúra -> dobropis -> presun do iného mesiaca -> nová faktúra | integration | `CreditNoteReissueTest` | ⬜ pending |
| INV-07 | pôvodná faktúra, PDF bajty, sha256, snapshot nezmenené | integration | `CreditNoteImmutabilityTest` | ⬜ pending |
| INV-07 | `invoice_status_v` `cancelled`, nie po splatnosti, bez úhrad | integration | `InvoiceStatusViewColumnsTest` (rozšíriť), `CreditNoteStatusTest` | ⬜ pending |
| INV-07 | úhrady a upomienky na stornovanú faktúru odmietnuté | integration | `PaymentServiceTest` (rozšíriť) | ⬜ pending |
| INV-07 | externá faktúra a dobropis sa nedajú stornovať; ukončený subjekt áno | integration | `CreditNoteGuardsTest` | ⬜ pending |
| INV-07 | zmena subjektu/klienta nezmení dobropis | integration | `CreditNoteSnapshotImmutableTest` | ⬜ pending |
| INV-07 | kurz prevzatý z pôvodnej, žiadne volanie `FxService` | integration | `CreditNoteFxTest` | ⬜ pending |
| INV-07 | `invoice_complete_check` výnimka pre dobropis bez reportu | integration | `CreditNoteCompletenessTest` | ⬜ pending |
| INV-07 | rollback pri zlyhaní renderera | integration | `CreditNoteRollbackTest` | ⬜ pending |
| INV-10 | dôvod 1-500 znakov, typ z enumu, dátum >= pôvodná | unit | `CreditNoteCommandTest` | ⬜ pending |
| INV-10 | blok `correction` v snapshote, texty v 4 jazykoch | integration | `CreditNoteSnapshotBuilderTest` | ⬜ pending |
| INV-10 | PDF `cn-1`, vlastné sha256, žiadny report, `cn*` kľúče | integration/unit | `MpdfCreditNoteCn1RendererTest`, `LangKeysTest` | ⬜ pending |
| INV-07/10 | e-mail `credit_note`, príloha len PDF dobropisu, reminder odmietnutý | integration | `CreditNoteMailerTest`, `EmailTemplatesSeedTest` (8 -> 12) | ⬜ pending |
| INV-07 | nové views bez inline skriptov a literálov | unit | `ViewsCspTest`, `ViewsHardcodedTextTest` | ⬜ pending |
| INV-07 | controller: náhľad, potvrdenie, POST (CSRF, PRG), 404/409 | feature | `CreditNotesControllerTest` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/CreditNoteFixtures.php` — trait: vystaví faktúru cez `IssueFixtures`, potom dobropis; asercia súčet = 0
- [ ] `tests/_support/credit_note_worker.php` — vzor `issue_worker.php` pre test súbehu
- [ ] aktualizácia: `InvoiceStatusViewColumnsTest`, `EmailTemplatesSeedTest`, `RendererRegistryTest`, `DocumentFilenameTest`, `LangKeysTest`

TDD úlohy vytvárajú svoje testy v RED kroku, samostatná Wave 0 nie je potrebná.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Vizuálna podoba PDF dobropisu (SK/PL/CS/EN) | INV-10 | rozloženie mPDF | Na stagingu vystaviť dobropis, otvoriť PDF, skontrolovať odkaz na pôvodnú faktúru, dôvod, záporné riadky, kurz |
| Odoslanie e-mailom cez Mailpit/staging | INV-07 | doručenie | Odoslať dobropis, skontrolovať prílohu a predmet |
| Ovládanie na iPhone (tlačidlo Stornovať, formulár) | INV-07 | UX | Prejsť tok na mobile, ciele >= 44 px |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
