---
phase: 5
slug: faktura-n-jadro
status: draft
nyquist_compliant: false
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

Filled by the planner per task. Requirement → test map (from 05-RESEARCH.md § Validation Architecture):

| Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|----------|-----------|-------------------|-------------|--------|
| INV-02, INV-09 | zaokrúhlenie, súčet = súčet riadkov, kód DPH | unit | `phpunit --filter InvoiceCalculatorTest` | ❌ W0 | ⬜ pending |
| INV-04 | `202610001`, `202610002`, `202611001`, initial_number, reset | integration (rw) | `phpunit --filter NumberAllocatorTest` | ❌ W0 | ⬜ pending |
| INV-04, INV-14 | 50 vystavení na 2 procesoch bez medzier a duplicít | integration (proc_open, rw) | `phpunit --filter IssueConcurrencyTest` | ❌ W0 | ⬜ pending |
| INV-04 | B čaká na A; po ROLLBACK A dostane B rovnaké číslo | integration | `phpunit --filter CounterLockTest` | ❌ W0 | ⬜ pending |
| INV-14 | zlyhanie po pridelení čísla vráti číslo aj uvoľní časti | integration | `phpunit --filter IssueRollbackTest` | ❌ W0 | ⬜ pending |
| INV-14 | opakované vystavenie / zmenený preview_token = 409 | integration + feature | `phpunit --filter IssueIdempotencyTest` | ❌ W0 | ⬜ pending |
| INV-15 | spätný dátum bez potvrdenia odmietnutý | integration | `phpunit --filter BackdateTest` | ❌ W0 | ⬜ pending |
| ALC-03 | zamknutá časť: server 409 aj DB trigger | integration (rw) | `phpunit --filter AllocationLockTest` (+ `AllocationGuardTest`) | ⚠️ upraviť | ⬜ pending |
| INV-03 | UPDATE/DELETE na `invoices*` pod `app_rw` odmietnuté | integration | `phpunit --filter InvoiceImmutabilityTest` | ❌ W0 | ⬜ pending |
| INV-08 | stĺpce M1–M16, CHECK `issued`/`external`, UNIQUE | integration | `phpunit --filter InvoiceSchemaTest` | ❌ W0 | ⬜ pending |
| INV-13 | sha256 CHECK, faktúra bez PDF sa nedá commitnúť | integration | `phpunit --filter InvoiceDocumentTest` | ❌ W0 | ⬜ pending |
| INV-03, INV-13 | zmeň všetko po vystavení ⇒ rovnaké bajty PDF | integration | `phpunit --filter InvoiceImmutableAfterChangeTest` | ❌ W0 | ⬜ pending |
| INV-01, INV-02 | zhoda súčtov náhľad = faktúra = snapshot = PDF | feature | `phpunit --filter InvoicingScreenTest` | ❌ W0 | ⬜ pending |
| INV-01 | presun časti, predvoľby, ukončený subjekt | feature | `phpunit --filter InvoicingPreviewTest` | ❌ W0 | ⬜ pending |
| D-10 | mena klienta ≠ mena subjektu odmietnutá | integration | `phpunit --filter CurrencyGuardTest` | ❌ W0 | ⬜ pending |
| D-13 | formát radu zamknutý po faktúre | integration | `SeriesServiceTest` (rozšíriť) | ⚠️ upraviť | ⬜ pending |
| D-22 | `invoices:lock-historical` dry-run, guard, idempotencia | integration (CLI) | `phpunit --filter LockHistoricalTest` | ❌ W0 | ⬜ pending |
| D-24 | `scripts/backup.sh` | unit | `phpunit --filter BackupScriptTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/IssueFixtures.php` — subjekt, klient, úlohy, časti, `issueCommand()`; nové tabuľky v `resetMasterTables()`
- [ ] `tests/_support/issue_worker.php` + bootstrap pre proces bez PHPUnit (`system/Test/bootstrap.php`)
- [ ] `tests/_support/FakePdfRenderer.php` a `FaultInjectingRenderer`
- [ ] migrácie a prebudovanie testovacej DB (`scripts/test-db.sh`)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Obrazovka „Fakturácia mesiaca“ ovládateľná jednou rukou na iPhone | INV-01 | vizuálne/dotykové | otvoriť PWA na iPhone, vybrať klienta a mesiac, vystaviť |
| Čitateľnosť PDF faktúry | INV-13 | vizuálne | otvoriť uložené PDF, skontrolovať položky a súčty |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
