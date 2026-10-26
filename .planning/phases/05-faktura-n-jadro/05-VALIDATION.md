---
phase: 5
slug: faktura-n-jadro
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-26
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5 + CI4 `CIUnitTestCase` (`failOnDeprecation/Notice/Warning/Risky`) |
| **Config file** | `phpunit.dist.xml` (suites `unit`, `integration`, `feature`) |
| **Quick run command** | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter <Trieda>` |
| **Full suite command** | `make test` (potom `composer stan`, `composer cs`) |
| **Estimated runtime** | ~120 seconds (full), < 15 s (filter) |

---

## Sampling Rate

- **After every task commit:** Run `phpunit --filter <dotknutá trieda>` + `composer stan`
- **After every plan wave:** Run `make test`
- **Before `/gsd-verify-work`:** `make test`, `composer stan`, `composer cs`, `node --test tests/js/` must be green
- **Max feedback latency:** 15 seconds (filtered run)

---

## Per-Task Verification Map

Kľúč = `{plán}-T{task}`; príkaz presne zodpovedá `<automated>` v PLAN.md (`phpunit` = `docker compose exec -T -u www-data php vendor/bin/phpunit`, `composer` = `docker compose exec -T -u www-data php composer`). Každý task má automatizované overenie, žiadne tri po sebe idúce tasky bez neho.

| Task ID | Wave | Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|-------------|----------|-----------|-------------------|-------------|--------|
| 05-01-T1 | 1 | INV-03, INV-08, INV-13 | schéma M1–M16, CHECK `issued`/`external`, UNIQUE; UPDATE/DELETE na `invoices*` odmietnuté; sha256 CHECK, faktúra bez PDF sa nedá commitnúť | integration | `make test-db && phpunit --filter 'InvoiceSchemaTest\|InvoiceImmutabilityTest\|InvoiceDocumentTest'` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-01-T2 | 1 | ALC-03 | FK zámku, `alloc_guard`, `lock_allocations()`, `invoice_status_v`; Phase 4 testy zelené | integration + feature | `make test-db && phpunit --testsuite integration && phpunit --testsuite feature (AllocationLockTest, AllocationGuardTest)` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-01-T3 | 1 | INV-09 | predbežný `vat_code` v profile (R1) | integration | `make test-db && phpunit --filter LegalProfileTest && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-02-T1 | 1 | INV-02, INV-09 | zaokrúhlenie, súčet = súčet riadkov, kód DPH, `preview_token` | unit | `phpunit --filter InvoiceCalculatorTest && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-02-T2 | 1 | INV-13 | formátovanie a popisky PDF v 4 jazykoch | unit | `phpunit --filter 'PdfFormatTest\|LangKeysTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-02-T3 | 1 | INV-13 | renderer `inv-1` zo snapshotu, CSP šablóny, Fake/FaultInjecting renderer | integration | `phpunit --filter 'MpdfInvoiceRendererTest\|ViewsCspTest\|ViewsHardcodedTextTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-03-T1 | 2 | INV-02, INV-08 | snapshot zo SnapshotBuilder, zákonný text a účet v snapshote, IssueFixtures | integration (rw) | `phpunit --filter SnapshotBuilderTest && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-03-T2 | 2 | INV-04 | `202610001`, `202610002`, `202611001`, initial_number, reset; DocumentStore | integration (rw) | `phpunit --filter NumberAllocatorTest && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-03-T3 | 2 | INV-03, INV-13, INV-14, ALC-03 | vystavenie v jednej transakcii; rollback vráti číslo aj časti; opakované vystavenie / zmenený token | integration | `phpunit --filter 'InvoiceIssuerTest\|IssueRollbackTest\|IssueIdempotencyTest' && phpunit --testsuite integration && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-04-T1 | 2 | ALC-03, D-22 | `invoices:lock-historical` dry-run, `--expect-*` guard, idempotencia | integration (CLI) | `phpunit --filter LockHistoricalTest && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-04-T2 | 2 | D-22 (WR-07) | dev DB 09/2026 zamknutá (11 častí, 2403.00) alebo zdokumentovaný blocker; postup s `--expect-*` z riadku „Fakturované = ÁNO“ | CLI + grep | `psql … `SELECT count(*), sum(line_count), sum(total) FROM invoices WHERE origin='external' AND billing_month='2026-09'` ; grep -c 'invoices:lock-historical' docs/ops/import-excel.md` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-04-T3 | 2 | D-24 | `scripts/backup.sh` (`pg_dump --format=custom`) | unit | `phpunit --filter BackupScriptTest && sh -n scripts/backup.sh && test -x scripts/backup.sh` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-05-T1 | 3 | INV-15, D-10, D-01 | mena ≠ mena subjektu → `currency_mismatch` (kontrola pred `SnapshotBuilder::build`), zmiešané meny, ukončený subjekt, spätný dátum bez potvrdenia | integration | `phpunit --filter 'CurrencyGuardTest\|BackdateTest\|InvoiceIssuerTest\|IssueIdempotencyTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-05-T2 | 3 | INV-01, INV-02, D-13 | náhľad bez zápisu, predvolené dátumy, `errors[0]` = `currency_mismatch`; formát radu zamknutý po faktúre | integration | `phpunit --filter 'InvoiceDraftServiceTest\|SeriesServiceTest' && phpunit --testsuite integration && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-06-T1 | 3 | INV-04, INV-14 | 50 vystavení na 2 procesoch bez medzier a duplicít; B čaká na A, po ROLLBACK A dostane B rovnaké číslo | integration (proc_open, rw) | `phpunit --filter 'IssueConcurrencyTest\|CounterLockTest'` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-06-T2 | 3 | INV-03, INV-13 | zmeň všetko po vystavení ⇒ rovnaké bajty PDF | integration | `phpunit --filter InvoiceImmutableAfterChangeTest` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-07-T1 | 3 | ALC-03, INV-08 | InvoiceQueries; číslo faktúry pri zamknutej časti v detaile úlohy | integration | `phpunit --filter 'InvoiceQueriesTest\|TaskQueriesDetailTest\|TaskDetailSummaryTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-07-T2 | 3 | INV-03, INV-08 | zoznam a detail faktúr zo snapshotu, `external_ref` bez `javascript:` odkazu, navigácia | feature | `phpunit --filter 'InvoicesListTest\|InvoiceDetailTest\|LayoutTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-07-T3 | 3 | INV-13 | uložené PDF inline a na stiahnutie, bajty = uložené | feature | `phpunit --filter InvoicePdfEndpointTest && phpunit --testsuite feature && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-08-T1 | 4 | INV-01, INV-15 | PreviewInput, obrazovka Fakturácia mesiaca, predvoľby, ukončený subjekt | feature | `phpunit --filter 'PreviewInputTest\|InvoicingPreviewTest\|LangKeysTest\|ViewsCspTest\|ViewsHardcodedTextTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-08-T2 | 4 | INV-01, INV-02 | pohľady náhľadu, sticky lišta, Alpine `invIssue`/`invMove` | feature + JS | `node --test tests/js/ && phpunit --filter 'InvoicingPreviewTest\|ViewsCspTest\|ViewsHardcodedTextTest'` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-08-T3 | 4 | INV-01 | presun časti z náhľadu do iného mesiaca | feature | `phpunit --filter InvoicingPreviewTest && phpunit --testsuite feature && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-09-T1 | 5 | INV-04, INV-14, INV-15 | IssueInput, mapovanie odmietnutí (409, currency_mismatch, backdate) | feature | `phpunit --filter 'IssueInputTest\|InvoicingIssueTest\|LangKeysTest' && composer stan` | ❌ W0 (vytvorí task) | ⬜ pending |
| 05-09-T2 | 5 | INV-01, INV-02, INV-03, INV-13 | zhoda súčtov obrazovka = faktúra = snapshot = PDF (end-to-end) | feature + full | `phpunit --filter 'InvoicingScreenTest\|InvoicingIssueTest\|InvoicingPreviewTest\|…' && make test && composer stan && composer cs && node --test tests/js/` | ❌ W0 (vytvorí task) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Každý chýbajúci test vytvára task, ktorý ho overuje (TDD RED krok), preto netreba samostatný Wave 0 plán:

- [ ] `tests/_support/IssueFixtures.php` — 05-03-T1; nové tabuľky v `resetMasterTables()` — 05-01-T2
- [ ] `tests/_support/issue_worker.php` + bootstrap pre proces bez PHPUnit — 05-06-T1
- [ ] `tests/_support/FakePdfRenderer.php` a `FaultInjectingRenderer` — 05-02-T3
- [ ] migrácie a prebudovanie testovacej DB (`make test-db`) — 05-01-T1 až T3

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Obrazovka „Fakturácia mesiaca“ ovládateľná jednou rukou na iPhone | INV-01 | vizuálne/dotykové | otvoriť PWA na iPhone, vybrať klienta a mesiac, vystaviť |
| Čitateľnosť PDF faktúry | INV-13 | vizuálne | otvoriť uložené PDF, skontrolovať položky a súčty |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 15s (filtrované behy; plné sady len na konci plánu)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** planned (2026-10-27)
