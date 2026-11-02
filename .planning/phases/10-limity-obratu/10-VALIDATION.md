---
phase: 10
slug: limity-obratu
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-11-02
---

# Phase 10 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^11.5 + `CIUnitTestCase` / `Tests\Support\PgTestCase` (integračné triedy `#[Group('integration')]`) |
| **Config file** | `phpunit.dist.xml` (suites `unit`, `integration`) |
| **Quick run command** | `docker exec -u www-data task-tracker-php-1 vendor/bin/phpunit tests/unit/Reporting` |
| **Full suite command** | `docker exec task-tracker-php-1 composer test` |
| **Estimated runtime** | ~60 seconds |

Poznámka: testy len v kontajneri (hostiteľské PHP nemá `bcround`). Pred integračnými testmi `make test-db` (migrácie na `task_tracker_test`).

---

## Sampling Rate

- **After every task commit:** Run quick command + cielený integračný súbor úlohy
- **After every plan wave:** Run `composer test`
- **Before `/gsd-verify-work`:** Full suite must be green, PHPStan bez nových chýb
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

Vyplní planner/executor podľa PLAN.md. Mapovanie požiadaviek na testy (z 10-RESEARCH.md § Validation Architecture):

| Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|----------|-----------|-------------------|-------------|--------|
| LIM-01 | CRUD limitov, validácia, duplicita, revision 409, audit, seed len pri prázdnej tabuľke | integration + feature | `vendor/bin/phpunit tests/integration/Reporting/LimitServiceTest.php tests/feature/Settings/LimitsControllerTest.php` | ❌ W0 | ⬜ pending |
| LIM-01 | schéma: CHECK-y, granty `app_rw`, seed 4 riadky | integration | `vendor/bin/phpunit tests/integration/Reporting/LimitSchemaTest.php` | ❌ W0 | ⬜ pending |
| LIM-02 | SUM faktúry − dobropisy, `counts_toward_limit`, hranice `turnover_date`, externá mimo, `total_entity_ccy`, počiatočný obrat | integration | `vendor/bin/phpunit tests/integration/Reporting/TurnoverCalculatorTest.php` | ❌ W0 | ⬜ pending |
| LIM-05 | okná calendar/rolling/pro-rata (prelom roka, 31. 12., 29. 2., 15. 7. → 111 780,82) | unit | `vendor/bin/phpunit tests/unit/Reporting/PeriodResolverTest.php` | ❌ W0 | ⬜ pending |
| LIM-03 | prahy 80/100 (hrany), viac riadkov, fallback roka, currency mismatch | unit + integration | `vendor/bin/phpunit tests/unit/Reporting/ThresholdTest.php tests/integration/Reporting/LimitSelectorTest.php` | ❌ W0 | ⬜ pending |
| LIM-03 | dashboard blok len s dôvodom, neutíšiteľný | feature | `vendor/bin/phpunit tests/feature/DashboardLimitsTest.php` | ❌ W0 | ⬜ pending |
| LIM-04 | predkontrola v náhľade, neblokuje vystavenie, výnimka = bezpečný stav | feature | `vendor/bin/phpunit tests/feature/Invoicing/InvoicingLimitWarningTest.php` | ❌ W0 | ⬜ pending |
| CUR-04 | obrat nečíta snapshot ani kurzovú cache | integration | `vendor/bin/phpunit --filter testIgnoresRatesAndSnapshot` | ❌ W0 | ⬜ pending |
| UI | CSP, hardcoded text, lang kľúče sk/en | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/_support/TurnoverFixtures.php` — vloženie/vystavenie faktúr a dobropisov s daným `turnover_date`, menou, príznakom klienta
- [ ] `MasterFixtures::resetMasterTables()` — doplniť `entity_opening_turnover`; testy limitov mažú migráciou seedované `turnover_limits` samy
- [ ] Testovacie súbory z mapy vyššie

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Ovládanie jednou rukou, 44px ciele, bez horizontálneho posúvania | LIM-01, LIM-03 | vizuálne na iPhone | staging: `/settings/limits`, dashboard, náhľad faktúry na úzkom displeji |
| SUM p95 < 100 ms | LIM-02 | závisí od dát | EXPLAIN dopytu obratu používa `invoices_turnover_idx` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
