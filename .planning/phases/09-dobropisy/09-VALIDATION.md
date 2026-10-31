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

Task ID = `09-PP-TN` (plán, úloha). Vyplnené plannerom; executor mení stav.

| Task ID | Requirement | Behavior | Test Type | Test File | Status |
|---------|-------------|----------|-----------|-----------|--------|
| 09-01-T1 | INV-07 | jeden dobropis na faktúru (unique index), CHECK-y dobropisu, target guard, complete_check bez reportu | integration | `CreditNoteSchemaTest` | ⬜ pending |
| 09-01-T1 | INV-07 | `release_allocations` len pri existujúcom dobropise, `alloc_guard` výnimka len `X -> NULL` | integration | `CreditNoteAllocationReleaseTest` | ⬜ pending |
| 09-01-T2 | INV-07 | `invoice_status_v` `cancelled`, balance 0.00, 6 stĺpcov na konci | integration | `CreditNoteStatusViewTest`, `InvoiceStatusViewColumnsTest` (rozšírený) | ⬜ pending |
| 09-01-T2 | INV-07/10 | šablóna `credit_note` v 4 jazykoch (8 -> 12), whitelist `original_number`/`original_date` v `TemplateRenderer` + `sampleValues()` | integration | `EmailTemplatesSeedTest` (rozšírený), `EmailTemplateServiceTest`, `EmailTemplatesControllerTest` | ⬜ pending |
| 09-02-T1 | INV-10 | dôvod 1-500 znakov, typ z enumu, token, uuid, dátum | unit | `CreditNoteCommandTest` | ⬜ pending |
| 09-02-T2 | INV-07/10 | záporné riadky, súčet 0,00, blok `correction`, fx kópia, popisky cn* v 4 jazykoch | integration | `CreditNoteSnapshotBuilderTest` | ⬜ pending |
| 09-03-T1 | INV-10 | PDF `cn-1`: odkaz na pôvodnú, dôvod, typ, záporné riadky, kurz, bez QR, < 2 s | integration | `MpdfCreditNoteCn1RendererTest` | ⬜ pending |
| 09-03-T2 | INV-07 | registrácia `cn-1`, názov súboru `Dobropis_…` | unit | `RendererRegistryTest`, `DocumentFilenameTest` | ⬜ pending |
| 09-04-T1/T2 | INV-07 | vystavenie: záporné riadky, `total = -orig`, súčet 0,00, rad `credit_note` `DB-RRRRMM###`, uvoľnenie častí, audit, idempotencia | integration | `CreditNoteIssuerTest` | ⬜ pending |
| 09-04-T1/T2 | INV-07/10 | externá, dobropis, už stornovaná, dátum pred pôvodnou, budúci dátum, backdate, ukončený subjekt, uhradená, kolízia čísla | integration | `CreditNoteGuardsTest` | ⬜ pending |
| 09-04-T1/T2 | INV-07 | kurz prevzatý z pôvodnej, žiadne volanie kurzovej služby, `total_entity_ccy = -orig` | integration | `CreditNoteFxTest` | ⬜ pending |
| 09-05-T1 | INV-07 | súbeh: 2 procesy, 1 úspech + `already_credited`, bez medzery | integration | `CreditNoteConcurrencyTest` + `credit_note_worker.php` | ⬜ pending |
| 09-05-T1 | INV-07 | rollback pri zlyhaní renderera | integration | `CreditNoteRollbackTest` | ⬜ pending |
| 09-05-T2 | INV-07 | faktúra -> dobropis -> presun do iného mesiaca -> nová faktúra (opravy mimo issuera do SUMMARY) | integration | `CreditNoteReissueTest` | ⬜ pending |
| 09-05-T2 | INV-07 | pôvodná faktúra, PDF bajty, sha256, snapshot nezmenené; zmena subjektu/klienta nezmení dobropis | integration | `CreditNoteImmutabilityTest` | ⬜ pending |
| 09-06-T1 | INV-07/10 | náhľad: token = token vystavenia, úhrady, počet častí, náhľad čísla bez zápisu, validácia vstupu | integration | `CreditNoteDraftServiceTest` | ⬜ pending |
| 09-06-T2/T3 | INV-07/10 | controller: náhľad, htmx prekreslenie, potvrdenie, POST (CSRF, PRG), 404/409/422/500 | feature | `CreditNotesControllerTest` | ⬜ pending |
| 09-07-T1 | INV-07 | stornovaná faktúra nie je po splatnosti, úhrady a storno úhrad odmietnuté | integration | `CreditNoteReceivablesTest` | ⬜ pending |
| 09-07-T1 | INV-07 | zoznam filtre `cancelled`/`credit_note`, detail s väzbou | integration | `CreditNoteQueriesTest` | ⬜ pending |
| 09-07-T2 | INV-07 | PDF dobropisu `Dobropis_…`, výkaz dobropisu 404, PDF pôvodnej nezmenené | feature | `CreditNotePdfEndpointTest` | ⬜ pending |
| 09-08-T1 | INV-07/10 | druh šablóny `credit_note` v službe (KEYS) a Nastaveniach | integration | `CreditNoteTemplateTest`, `EmailTemplatesControllerTest` | ⬜ pending |
| 09-08-T2 | INV-07/10 | `TemplateContext` vyplní `original_number`, `original_date` zo snapshotu dobropisu | unit/integration | `TemplateRendererTest`, `CreditNoteTemplateTest` | ⬜ pending |
| 09-11-T1 | INV-07 | e-mail dobropisu, príloha len PDF dobropisu, resend, mark-sent, reminder odmietnutý | integration | `CreditNoteMailerTest` | ⬜ pending |
| 09-11-T2 | INV-07 | sheet/stránka odoslania s nadpisom dobropisu | feature | `CreditNoteMailerTest` | ⬜ pending |
| 09-09-T1 | INV-07/10 | detail: tlačidlo, banner stornovanej, detail dobropisu, bez platobných akcií | feature | `CreditNoteDetailTest` | ⬜ pending |
| 09-09-T2 | INV-07 | zoznam: odznaky, hinty, filtre | feature | `CreditNoteListTest` | ⬜ pending |
| 09-06/09-09/09-11 | INV-07 | nové views bez inline skriptov a literálov | unit | `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest` | ⬜ pending |
| 09-10-T1 | INV-07 | staging seed: 2 dobropisy cez skutočnú službu; ciele bez úhrad/upomienok v pláne | integration/unit | `StagingSeederTest`, `HistoryPlannerTest` | ⬜ pending |
| 09-10-T2 | INV-07/10 | fázová brána | all | `composer check` | ⬜ pending |

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
