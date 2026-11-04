---
phase: 11
slug: preh-ady-exporty-a-audit
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-11-04
---

# Phase 11 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5 (CI4 `CIUnitTestCase`, `PgTestCase`, `AuthTestCase`) |
| **Config file** | `phpunit.dist.xml` (suity `unit`, `integration`, `feature`; `failOnDeprecation`, `failOnWarning`, `failOnNotice`) |
| **Quick run command** | `docker compose exec -T -u www-data php vendor/bin/phpunit <dotknutý adresár>` |
| **Full suite command** | `make test-db && make test && make stan && make cs` (resp. `make check`) |
| **Estimated runtime** | ~120 seconds |

---

## Sampling Rate

- **After every task commit:** quick run nad dotknutým adresárom + `composer stan` pri zmene `Reporting/` alebo `Export/`
- **After every plan wave:** `make test` + `make stan` + `make cs`
- **Before `/gsd-verify-work`:** `make check` zelené
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

Plánovač doplní Task ID podľa PLAN.md. Mapa požiadaviek na testy (z RESEARCH.md):

| Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|----------|-----------|-------------------|-------------|--------|
| RPT-01 | mesačné/ročné súčty po klientoch, dobropisy v mesiaci dodania | integration | `phpunit tests/integration/Reporting/ReportCalculatorTest.php` | ❌ W0 | ⬜ pending |
| RPT-01 | úhrady podľa `paid_on`, voidy, kumulatívne zaokrúhlenie | integration | `phpunit tests/integration/Reporting/ReportPaymentsTest.php` | ❌ W0 | ⬜ pending |
| RPT-01 / D-05 | prehľad = SUM(faktúry) = `TurnoverCalculator` = dashboard = CSV = XLSX | integration | `phpunit tests/integration/Reporting/ReportTotalsMatchTest.php` | ❌ W0 | ⬜ pending |
| RPT-01 | obrazovky mesiac/rok, prázdny stav, neplatné obdobie, výber subjektu | feature | `phpunit tests/feature/Reports/ReportsPageTest.php` | ❌ W0 | ⬜ pending |
| RPT-02 | CSV: BOM, `;`, čiarka, prefix `'` len na texte, záporné sumy bez prefixu | unit | `phpunit tests/unit/Export/CsvWriterTest.php` | ❌ W0 | ⬜ pending |
| RPT-02 | `FormulaGuard` (`= + - @ \t \r`) | unit | `phpunit tests/unit/Export/FormulaGuardTest.php` | ❌ W0 | ⬜ pending |
| RPT-02 | XLSX: číselné bunky `#,##0.00`, žiadny `<f>`, hodnoty = Money | integration | `phpunit tests/integration/Export/XlsxWriterTest.php` | ❌ W0 | ⬜ pending |
| RPT-02 | endpointy `.csv/.xlsx` (hlavičky, názov súboru, filtre faktúr) | feature | `phpunit tests/feature/Reports/ExportEndpointsTest.php` | ❌ W0 | ⬜ pending |
| RPT-02 | PHPStan: `(float)` povolený len v `XlsxNumber.php` | selftest | `composer stan` | ✅ rozšíriť | ⬜ pending |
| RPT-03 | ZIP: PDF z `bytea` (sha256), `zoznam-dokladov.csv`, `DocumentFilename`, storno zahrnuté | integration | `phpunit tests/integration/Export/AccountantBundleTest.php` | ❌ W0 | ⬜ pending |
| RPT-03 | balík = prehľad, limit 12 mesiacov, 0 dokladov | feature | `phpunit tests/feature/Reports/AccountantBundleTest.php` | ❌ W0 | ⬜ pending |
| AUD-02 | filtre, stránkovanie, detail diff | integration/feature | `phpunit tests/integration/Audit/AuditQueriesTest.php tests/feature/Settings/AuditViewerTest.php` | ❌ W0 | ⬜ pending |
| AUD-02 | read-only, 404 neexistujúce ID, 403 ne-admin | feature | `phpunit tests/feature/Settings/AuditViewerTest.php` | ❌ W0 | ⬜ pending |
| UI-03 | tabuľka aj karty, whitelist triedenia, `aria-sort` | feature | `phpunit tests/feature/Invoicing/InvoicesListTableTest.php tests/feature/Work/TasksListTableTest.php` | ❌ W0 | ⬜ pending |
| UI-03 | `/settings/users`, menu „Viac“ | feature | `phpunit tests/feature/Settings/UsersListTest.php tests/feature/LayoutTest.php` | ❌ W0 | ⬜ pending |
| NFR | CSP/texty/lang kľúče nových views | unit | `phpunit tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/ReportFixtures.php` — faktúry, úhrady, dobropisy v rôznych mesiacoch a klientoch
- [ ] `tests/_support/XlsxReader.php` — rozbalenie XLSX cez `ZipArchive`, bunky zo `sheet1.xml`
- [ ] Rozšírenie `ForbiddenCallsRule` + selftest (`(float)` v `XlsxNumber.php`, bcmath v `ReportMath.php`)
- [ ] `composer require openspout/openspout:^5.13` (+ `composer.lock`)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| CSV a XLSX sa v Exceli (SK lokalita) otvoria so sumami ako čísla a bez vzorcov | RPT-02 | závisí od lokality Excelu | na staging stiahnuť CSV/XLSX mesačného prehľadu, otvoriť v Exceli, skontrolovať sumy a klienta začínajúceho `=` |
| ZIP balík sedí s prehľadom | RPT-03 | vizuálna kontrola PDF | na staging stiahnuť balík za mesiac, porovnať počet a sumy s prehľadom |
| Tabuľkové zoznamy na PC a karty na iPhone | UI-03 | layout | otvoriť `/invoices` a `/tasks` na PC aj na iPhone |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
