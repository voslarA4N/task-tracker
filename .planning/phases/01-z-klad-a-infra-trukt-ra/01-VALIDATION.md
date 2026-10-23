---
phase: 1
slug: z-klad-a-infra-trukt-ra
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-23
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.x cez CI4 `CIUnitTestCase` / `FeatureTestTrait` |
| **Config file** | `phpunit.dist.xml` (Wave 0 upraví: suites `unit`/`integration`/`feature`, `failOnDeprecation`, PG env) |
| **Quick run command** | `make test-unit` (`composer test:unit`, bez DB) |
| **Full suite command** | `make check` (`phpstan` + `php-cs-fixer --dry-run` + `make test-db` + `phpunit`) |
| **Estimated runtime** | ~5 s (unit), ~60 s (full) |

---

## Sampling Rate

- **After every task commit:** Run `make test-unit` a `make stan`
- **After every plan wave:** Run `make check`
- **Before `/gsd-verify-work`:** `make check` zelený lokálne aj v CI, `make smoke` zelený
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

Task IDs sa doplnia po vytvorení plánov. Mapa požiadaviek na testy (z 01-RESEARCH.md § Validation Architecture):

| Requirement | Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|----------|-----------|-------------------|-------------|--------|
| NFR-04 | hodiny × sadzba half-up na 2 des., záporné hodnoty, `-0.00`, odmietnutie float | unit | `vendor/bin/phpunit tests/unit/Platform/MoneyTest.php` | ❌ W0 | ⬜ pending |
| NFR-04 | súčet = súčet zaokrúhlených riadkov, parita s PG `round(numeric)` | integration | `vendor/bin/phpunit tests/integration/MoneyDbParityTest.php` | ❌ W0 | ⬜ pending |
| NFR-04 | zákaz `float`/`date()`/`time()`/`new DateTime` mimo `Clock`/`Money` | static | `vendor/bin/phpstan analyse` | ❌ W0 | ⬜ pending |
| NFR-04 | `Clock` zmrazený v testoch, TZ konzistencia PHP/CI4/PG | unit+integration | `vendor/bin/phpunit tests/unit/Platform/ClockTest.php tests/integration/TimezoneConsistencyTest.php` | ❌ W0 | ⬜ pending |
| NFR-07 | `app_rw` bez UPDATE/DELETE/TRUNCATE/DDL, migrácie ako `app_owner` | integration | `vendor/bin/phpunit tests/integration/DbRolesTest.php` | ❌ W0 | ⬜ pending |
| NFR-07 | compose zdravý, HTTPS, bezpečnostné hlavičky, Mailpit prijme mail | smoke | `make smoke` (`scripts/smoke.sh`) | ❌ W0 | ⬜ pending |
| NFR-07 | dev/prod target z jedného Dockerfile, preload nezhodí PHP | CI | `.github/workflows/ci.yml` job `check` | ❌ W0 | ⬜ pending |
| UI-01 | views bez inline script/style/on*, CSP bez `unsafe-*` | unit + smoke | `vendor/bin/phpunit tests/unit/Security/ViewsCspTest.php` | ❌ W0 | ⬜ pending |
| UI-01 | všetky `lang()` kľúče existujú, parita sk/en | unit | `vendor/bin/phpunit tests/unit/I18n/LangKeysTest.php` | ❌ W0 | ⬜ pending |
| UI-01 | layout: viewport-fit, lang, Bootstrap, `htmx-config` meta | feature | `vendor/bin/phpunit tests/feature/LayoutTest.php` | ❌ W0 | ⬜ pending |
| UI-01 (SC4) | htmx POST s tokenom 200, bez tokenu 403, neplatný vstup 422, `Vary: HX-Request` | feature | `vendor/bin/phpunit tests/feature/HtmxCsrfTest.php` | ❌ W0 | ⬜ pending |
| SC5 | mPDF s „łńěťžô“ pod PHP 8.5 `E_ALL` do 2 s, pamäť zmeraná | spike + integration | `make spike-pdf`, `vendor/bin/phpunit tests/integration/MpdfSpikeTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Docker Desktop + WSL integrácia, `make`, mkcert, hosts záznam, CA (checkpoint pre človeka)
- [ ] `composer.json` scripts, upravený `phpunit.dist.xml`, `tests/_support/PgTestCase.php`, adresáre `tests/unit`, `tests/integration`, `tests/feature`
- [ ] `docker/postgres/provision.sql`, `make test-db`
- [ ] Frontend súbory v `public/assets/vendor/`
- [ ] Spike ICU kolácie a overenie auto-discovery `codeigniter4/translations`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Dotykové ciele ≥ 44 px, bez horizontálneho posúvania (375/390 px) | UI-01 | Žiadny prehliadačový testovací framework (bez build pipeline) | DevTools device mode + iPhone cez `https://work-tracker.loc` |
| CSP konzola bez porušení | UI-01 | Hlásenia porušení vidno len v prehliadači | Otvoriť ukážkovú htmx stránku, skontrolovať konzolu |
| mkcert CA dôveryhodná vo Windows prehliadači | NFR-07 | Windows trust store mimo kontajnera | Otvoriť `https://work-tracker.loc` v Chrome/Edge bez varovania |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
