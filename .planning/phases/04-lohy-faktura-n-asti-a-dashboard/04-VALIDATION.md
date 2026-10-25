---
phase: 4
slug: lohy-faktura-n-asti-a-dashboard
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-25
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Zdroj: 04-RESEARCH.md § Validation Architecture a `<verify>` príkazy plánov 04-01 až 04-13.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5 (`CIUnitTestCase`, `PgTestCase`, `failOnDeprecation`, `failOnWarning`), JS testy `node --test` v `tests/js/`, PHPStan (`composer stan`) |
| **Config file** | `phpunit.dist.xml` (suites `unit`, `integration`, `feature`); fixtures `tests/_support/MasterFixtures.php` (rozšírené v 04-01), `tests/_support/ImportFixture.php` (04-03) |
| **Quick run command** | `docker compose exec -T -u www-data php vendor/bin/phpunit <dotknuté testy>` a `node --test tests/js/` |
| **Full suite command** | `make test` (`perms test-db` + `composer test`), `make stan`, `node --test tests/js/` |
| **Estimated runtime** | quick ~10-30 s, full ~2-4 min |

---

## Sampling Rate

- **After every task commit:** spustiť `<automated>` príkaz úlohy (filtrované PHPUnit súbory, prípadne `node --test tests/js/`)
- **After every plan wave:** `composer test` + `composer stan` + `node --test tests/js/`
- **Before `/gsd-verify-work`:** full suite must be green
- **Max feedback latency:** 30 seconds (per-task príkazy)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 4-01-01 | 01 | 1 | ALC-01, ALC-02, TSK-03 | T-04-01..06 | testy zamknutia časti triggerom aj pod `app_rw` (RED) | integration | `phpunit tests/integration/Work/AllocationSchemaTest.php tests/integration/Work/AllocationGuardTest.php tests/integration/Work/TaskBillingViewTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-01-02 | 01 | 1 | ALC-01, ALC-02, TSK-03 | T-04-01..06 | CHECK, `alloc_guard`, `task_billing_v`, granty rolí | integration | `composer test:migrate && phpunit tests/integration/Work tests/integration/Master/MasterRolesTest.php tests/integration/DbRolesTest.php` | ✅ po 4-01-01 | ⬜ pending |
| 4-02-01 | 02 | 1 | TSK-05, ALC-01 | T-04-07..09 | testy Hours/MoneyFormat/BillingMonth (RED) | unit | `phpunit tests/unit/Platform/HoursTest.php tests/unit/Platform/MoneyFormatTest.php tests/unit/Work/BillingMonthTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-02-02 | 02 | 1 | TSK-05, ALC-01 | T-04-07..09 | bcmath, žiadny float, rozsah mesiacov | unit + static | `phpunit tests/unit/Platform tests/unit/Work tests/feature/Master/ClientsTest.php && composer stan` | ✅ po 4-02-01 | ⬜ pending |
| 4-03-01 | 03 | 2 | IMP-01 | T-04-SC | legitimita `phpoffice/phpspreadsheet` (checkpoint) | manual + curl | `curl -s https://repo.packagist.org/p2/phpoffice/phpspreadsheet.json \| grep -c 'github.com/PHPOffice/PhpSpreadsheet'` | n/a | ⬜ pending |
| 4-03-02 | 03 | 2 | IMP-01 | T-04-10..13 | dev závislosť len v `require-dev`, čítanie hyperlinkov | unit | `phpunit tests/unit/Work/Import/ExcelReaderTest.php` + kontrola `composer.json` | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-03-03 | 03 | 2 | IMP-01 | T-04-10..13 | suchý beh, porovnanie súčtov, `import_key` | unit + static | `phpunit tests/unit/Work/Import && composer stan` | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-04-01 | 04 | 2 | TSK-01, TSK-02 | T-04-14..20 | testy vstupu, zmazania a zámku meny (RED) | unit + integration | `phpunit tests/unit/Work/TaskInputTest.php tests/integration/Work/TaskServiceTest.php tests/integration/Work/TaskDeleteTest.php tests/integration/Master/ClientCurrencyLockTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-04-02 | 04 | 2 | TSK-01, TSK-02 | T-04-14..17, 19 | whitelist polí, server prepočíta sumu, audit v transakcii | unit + integration | `phpunit tests/unit/Work/TaskInputTest.php tests/integration/Work/TaskServiceTest.php tests/integration/Work/TaskDeleteTest.php tests/unit/I18n/LangKeysTest.php` | ✅ po 4-04-01 | ⬜ pending |
| 4-04-03 | 04 | 2 | TSK-01 | T-04-18 | zmena meny klienta s akoukoľvek časťou → 422 | integration + feature | `phpunit tests/integration/Master tests/feature/Master/ClientsTest.php tests/feature/Master/ClientsArchivePortalTest.php tests/unit/I18n` | ✅ po 4-04-01 | ⬜ pending |
| 4-05-01 | 05 | 2 | ALC-01, ALC-02, ALC-05 | T-04-21..25 | testy časti a kritéria E5 (RED) | unit + integration | `phpunit tests/unit/Work/AllocationInputTest.php tests/integration/Work/AllocationServiceTest.php tests/integration/Work/AllocationE5Test.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-05-02 | 05 | 2 | ALC-01, ALC-02, ALC-05 | T-04-21..25 | `AllocationLocked`, atómová úprava, mena z klienta | unit + integration + static | `phpunit tests/unit/Work tests/integration/Work tests/unit/I18n/LangKeysTest.php && composer stan` | ✅ po 4-05-01 | ⬜ pending |
| 4-06-01 | 06 | 2 | TSK-03..06, DSH-01 | T-04-26..29 | testy filtra, zoznamu, detailu, dashboardu (RED) | unit + integration | `phpunit tests/unit/Work/TaskFilterTest.php tests/integration/Work/TaskQueries*Test.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-06-02 | 06 | 2 | TSK-03..06, DSH-01 | T-04-26..29 | jeden dotaz, `LIMIT 201`, escapovanie ILIKE, meny nesčítané | unit + integration + static | `phpunit tests/unit/Work tests/integration/Work && composer stan` | ✅ po 4-06-01 | ⬜ pending |
| 4-07-01 | 07 | 3 | TSK-01, TSK-02 | T-04-30..36 | feature + JS testy formulára a zmazania (RED) | feature + js | `phpunit tests/feature/Work/TaskFormTest.php tests/feature/Work/TaskShowDeleteTest.php; node --test tests/js/task-form.test.js` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-07-02 | 07 | 3 | TSK-01, TSK-02 | T-04-30..36 | CSRF, `esc()`, CSP bez inline, AuthGate | feature + unit | `phpunit tests/feature/Work tests/feature/Settings/SecurityPageTest.php tests/feature/LayoutTest.php tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ po 4-07-01 | ⬜ pending |
| 4-07-03 | 07 | 3 | TSK-01 | T-04-30..36 | BigInt prepočet = PHP výsledok, bez `eval` | js + feature | `node --test tests/js/ && phpunit tests/feature/Work/TaskFormTest.php tests/feature/LayoutTest.php` | ✅ po 4-07-01 | ⬜ pending |
| 4-08-01 | 08 | 3 | ALC-04 | T-04-37..39 | testy markDone/reopen (RED) | integration | `phpunit tests/integration/Work/MarkDoneServiceTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-08-02 | 08 | 3 | ALC-04 | T-04-37..39 | stav + časť + audit atómovo, dvojklik | integration + static | `phpunit tests/integration/Work && composer stan` | ✅ po 4-08-01 | ⬜ pending |
| 4-09-01 | 09 | 4 | IMP-01 | T-04-40..44 | testy importu nad anonymizovanou fixture (RED) | integration + unit | `phpunit tests/integration/Work/ImportExcelTest.php tests/unit/Work/Import/ImportReportFormatterTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-09-02 | 09 | 4 | IMP-01 | T-04-40..44 | jedna transakcia, idempotencia, `class_exists` guard, reálny súbor mimo gitu | integration + static | `phpunit tests/integration/Work/ImportExcelTest.php tests/unit/Work/Import && composer stan && php spark list \| grep -c 'import:excel'` + DOC-OK | ✅ po 4-09-01 | ⬜ pending |
| 4-09-03 | 09 | 4 | IMP-01 | T-04-41 | ostrý import reálneho súboru, opakovaný beh „Vytvorené: 0“ | manual (checkpoint) | — (pozri Manual-Only) | n/a | ⬜ pending |
| 4-10-01 | 10 | 4 | ALC-02, ALC-05, TSK-05, TSK-03 | T-04-45..50 | testy panelu, súhrnu, 409 a allocForm (RED) | feature + js | `phpunit tests/feature/Work/AllocationPanelTest.php tests/feature/Work/TaskDetailSummaryTest.php tests/feature/LayoutTest.php; node --test tests/js/alloc-form.test.js` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-10-02 | 10 | 4 | ALC-02 | T-04-45..47, 50 | 409 pri zamknutej časti, `HX-Location` namiesto 302, vstup iba cez `AllocationInput` | feature + unit | `php -l app/Controllers/Allocations.php && phpunit tests/feature/LayoutTest.php tests/unit/I18n && php spark routes \| grep -c allocations` | ✅ po 4-10-01 | ⬜ pending |
| 4-10-03 | 10 | 4 | ALC-02, ALC-05, TSK-05, TSK-03 | T-04-46, 48 | CSRF, `esc()`, žiadny inline štýl/skript | feature + unit | `phpunit tests/feature/Work tests/feature/LayoutTest.php tests/feature/HtmxCsrfTest.php tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ po 4-10-01 | ⬜ pending |
| 4-10-04 | 10 | 4 | ALC-02, ALC-05 | T-04-48 | Alpine CSP build, bez `eval`/`new Function` | js | `node --test tests/js/` | ✅ po 4-10-01 | ⬜ pending |
| 4-11-01 | 11 | 5 | ALC-04 | T-04-51..54 | test panelu Zapracované (RED) | feature | `phpunit tests/feature/Work/MarkDonePanelTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-11-02 | 11 | 5 | ALC-04 | T-04-51..54 | 409 pri už zapracovanej, CSRF, `HX-Location` | feature + unit + js | `phpunit tests/feature/Work tests/unit/Security/ViewsCspTest.php tests/unit/I18n && node --test tests/js/` | ✅ po 4-11-01 | ⬜ pending |
| 4-12-01 | 12 | 4 | TSK-03, TSK-04, TSK-06 | T-04-55..58 | test zoznamu a filtrov (RED) | feature | `phpunit tests/feature/Work/TaskListTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-12-02 | 12 | 4 | TSK-03, TSK-04, TSK-06 | T-04-55..58 | filtre z whitelistu, bez N+1, escapovanie | feature + unit + static | `phpunit tests/feature/Work tests/unit/Security/ViewsCspTest.php tests/unit/I18n && composer stan` | ✅ po 4-12-01 | ⬜ pending |
| 4-13-01 | 13 | 5 | DSH-01 | T-04-59..62 | testy DashboardFilter a dashboardu (RED) | unit + feature | `phpunit tests/unit/Work/DashboardFilterTest.php tests/feature/Work/DashboardTest.php` (RED-OK) | ❌ W0 (vytvára úloha) | ⬜ pending |
| 4-13-02 | 13 | 5 | DSH-01 | T-04-59..62 | dlaždice po menách, fragment s `Vary: HX-Request` | unit + feature + static | `phpunit tests/unit/Work tests/feature tests/unit/Security/ViewsCspTest.php tests/unit/I18n && composer stan` | ✅ po 4-13-01 | ⬜ pending |

Všetky PHPUnit príkazy sa spúšťajú ako `docker compose exec -T -u www-data php vendor/bin/phpunit ...` (v tabuľke skrátené na `phpunit`).

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Wave 0 je v tejto fáze rozložený do prvej (RED) úlohy každého plánu; žiadna implementačná úloha nebeží bez testu napísaného pred ňou.

- [ ] `tests/_support/MasterFixtures.php` — `billing_allocations`, `tasks` v `resetMasterTables()`, helpery `createTask()`, `createAllocation()`, `lockAllocation($id)` (04-01 Task 1)
- [ ] `tests/_support/ImportFixture.php` — generátor anonymizovaného XLSX s hyperlinkami a pokazeným rozsahom vzorca (04-03 Task 2)
- [ ] `tests/js/task-form.test.js` (04-07 Task 1), `tests/js/alloc-form.test.js` (04-10 Task 1)
- [ ] `composer require --dev phpoffice/phpspreadsheet` po checkpointe legitimity (04-03 Task 1 a 2)
- [ ] RED testy pre REQ: TSK-01..06, ALC-01, ALC-02, ALC-04, ALC-05, DSH-01, IMP-01 podľa tabuľky vyššie

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Legitimita balíka `phpoffice/phpspreadsheet` pred inštaláciou | IMP-01 | rozhodnutie vlastníka (package legitimacy gate) | 04-03 Task 1 checkpoint |
| Ostrý import `docs/All4net-fakturovanie-prace.xlsx` (`--commit`) a opakovaný beh | IMP-01 | reálne dáta mimo gitu, zápis potvrdzuje vlastník | 04-09 Task 3 checkpoint: suchý beh, porovnanie s runbookom, `--commit`, kontrola v DB, druhý `--commit` = „Vytvorené: 0“ |
| Panel zospodu na iPhone (otvorenie, živá suma, zatvorenie, posúvanie stránky) | ALC-02, ALC-05 | správanie Bootstrap offcanvas a dotyk v PWA | 04-10 Task 4 `<human-check>` |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 30s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
