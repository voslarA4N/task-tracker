---
phase: 1
slug: z-klad-a-infra-trukt-ra
status: validated
nyquist_compliant: true
wave_0_complete: true
created: 2026-10-23
audited: 2026-10-24
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
| **Container user** | PHP kód v kontajneri beží ako `www-data` (`docker compose exec -T -u www-data php …`, Makefile `EXEC`), aby `writable/` zostal zapisovateľný pre FPM |

---

## Sampling Rate

- **After every task commit:** Run `make test-unit` a `make stan`
- **After every plan wave:** Run `make check`
- **Before `/gsd-verify-work`:** `make check` zelený lokálne aj v CI, `make smoke` zelený
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

Plné `<automated>` príkazy sú v príslušných PLAN.md; tu skrátene. `DCX` = `docker compose exec -T -u www-data php`.

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 01-01-T1 | 01-01 | 1 | NFR-07 | checkpoint (human-action) | `docker info … && make --version && mkcert.exe -CAROOT && getent hosts work-tracker.loc … && echo PREREQ-OK` | n/a | ✅ green |
| 01-01-T2 | 01-01 | 1 | NFR-07 | checkpoint (blocking-human) | legitimita balíkov, odpoveď `approved` | n/a | ✅ green |
| 01-01-T3 | 01-01 | 1 | NFR-07 | smoke (build) | `docker compose config --quiet && docker compose build php && … php -m … && echo RUNTIME-OK` | ✅ | ✅ green |
| 01-01-T4 | 01-01 | 1 | NFR-07 | unit + build | `… composer test:unit && docker build --target prod … && … opcache.preload … -r 'echo 1;' && echo SCAFFOLD-OK` | ✅ | ✅ green |
| 01-02-T1 | 01-02 | 2 | NFR-07 | static (syntax) | `make help … \| wc -l | grep -qx 13 && sh -n scripts/{doctor,certs,smoke}.sh && echo MAKE-OK` | ✅ | ✅ green |
| 01-02-T2 | 01-02 | 2 | NFR-07 | smoke (e2e) | `make smoke … SMOKE OK && curl … https://work-tracker.loc/ → 200` | ✅ | ✅ green |
| 01-03-T1 | 01-03 | 2 | NFR-04 | unit (TDD) | `DCX vendor/bin/phpunit tests/unit/Platform/MoneyTest.php` | ✅ | ✅ green |
| 01-03-T2 | 01-03 | 2 | NFR-04 | unit (TDD) | `DCX vendor/bin/phpunit tests/unit/Platform/` | ✅ | ✅ green |
| 01-04-T1 | 01-04 | 2 | UI-01 | unit + feature (RED) | `DCX vendor/bin/phpunit tests/feature/LayoutTest.php tests/unit/I18n tests/unit/Security; test $? -ne 0` | ✅ | ✅ green |
| 01-04-T2 | 01-04 | 2 | UI-01 | static (checksums) | `sh scripts/vendor-assets.sh && sha256sum -c SHA256SUMS … && echo ASSETS-OK` | ✅ | ✅ green |
| 01-04-T3 | 01-04 | 2 | UI-01 | unit + feature (GREEN) | `DCX vendor/bin/phpunit tests/feature/LayoutTest.php tests/unit/I18n tests/unit/Security` (vrátane `ViewsHardcodedTextTest`) | ✅ | ✅ green |
| 01-05-T1 | 01-05 | 2 | NFR-07 | integration (provisioning) | `psql … -f provision.sql` (2× pre `task_tracker_test`) `&& … pg_database … && echo DB-PROVISION-OK` (čistý volume v projekte `tt-probe`) | ✅ | ✅ green |
| 01-05-T2 | 01-05 | 2 | NFR-07 | integration (TDD) | `sh scripts/test-db.sh && sh scripts/test-db.sh && DCX vendor/bin/phpunit tests/integration/DbRolesTest.php tests/integration/TimezoneConsistencyTest.php tests/unit/Database/DevSeederGuardTest.php` | ✅ | ✅ green |
| 01-06-T1 | 01-06 | 2 | NFR-07 (SC5) | spike (CLI) | `DCX php scripts/spikes/mpdf.php --cold \| … elapsed_ms<2000 … && pdftotext … łńěťžô && echo SPIKE-CLI-OK` | ✅ | ✅ green |
| 01-06-T2 | 01-06 | 2 | NFR-07 (SC5) | spike (FPM) + integration | `DCX sh scripts/spikes/mpdf-fpm.sh \| grep -c FPM-… = 2 && DCX vendor/bin/phpunit tests/integration/MpdfSpikeTest.php` | ✅ | ✅ green |
| 01-07-T1 | 01-07 | 2 | NFR-04 | static (self-test) | `php tests/_support/PhpStan/selftest.php && vendor/bin/phpstan analyse …ForbiddenCallsRule.php …` | ✅ | ✅ green |
| 01-07-T2 | 01-07 | 2 | NFR-04 | static | `composer validate && composer run-script --list … = 7 && php-cs-fixer fix --dry-run …` | ✅ | ✅ green |
| 01-08-T1 | 01-08 | 3 | UI-01 (SC4) | feature (RED) | `DCX vendor/bin/phpunit tests/feature/HtmxCsrfTest.php tests/unit/Config/SecurityConfigTest.php; test $? -ne 0` | ✅ | ✅ green |
| 01-08-T2 | 01-08 | 3 | UI-01 (SC4) | unit | `DCX vendor/bin/phpunit tests/unit/Config/SecurityConfigTest.php && DCX composer test:unit` | ✅ | ✅ green |
| 01-08-T3 | 01-08 | 3 | UI-01 (SC4) | feature + smoke (GREEN) | `DCX vendor/bin/phpunit tests/feature tests/unit && make smoke … SMOKE OK` | ✅ | ✅ green |
| 01-09-T1 | 01-09 | 4 | NFR-04, UI-01 | integration + feature (RED) | `make test-db && DCX vendor/bin/phpunit tests/integration/MoneyDbParityTest.php tests/integration/DemoCalculationsTest.php tests/feature/DemoPersistTest.php; test $? -ne 0` | ✅ | ✅ green |
| 01-09-T2 | 01-09 | 4 | NFR-04 | integration | `make test-db && make migrate && DCX vendor/bin/phpunit tests/integration/MoneyDbParityTest.php tests/integration/DemoCalculationsTest.php tests/integration/DbRolesTest.php` | ✅ | ✅ green |
| 01-09-T3 | 01-09 | 4 | NFR-04, UI-01 | full + smoke (GREEN) | `make test && make smoke … SMOKE OK && curl … /demo \| grep id="demo-panel"` | ✅ | ✅ green |
| 01-10-T1 | 01-10 | 5 | NFR-04, NFR-07, UI-01 | full suite + smoke | `make check && make smoke … SMOKE OK && ! … SKIP && ! git ls-files … .env/.pem/docker/certs` | ✅ | ✅ green |
| 01-10-T2 | 01-10 | 5 | NFR-07 | CI (static + lokálne prehratie) | `test -f .github/workflows/ci.yml && grep … postgres:18-alpine, composer check, opcache.preload=, --target prod/dev, provision.sql` | ✅ | ✅ green |
| 01-10-T3 | 01-10 | 5 | NFR-04, NFR-07, UI-01 | static (docs) + manual | `grep … bcmath, mPDF, ICU/kolác, HtmxCsrf .planning/PROJECT.md` | n/a | ✅ green |

Sampling continuity: žiadne 3 po sebe idúce úlohy bez `<automated>` (checkpointy 01-01-T1/T2 majú automatickú kontrolu predpokladov resp. sú blokujúce brány pred inštaláciou).

### Requirement → Test Map (z 01-RESEARCH.md § Validation Architecture)

| Requirement | Behavior | Test Type | Automated Command | Covered by | File Exists | Status |
|-------------|----------|-----------|-------------------|------------|-------------|--------|
| NFR-04 | hodiny × sadzba half-up na 2 des., záporné hodnoty, `-0.00`, odmietnutie float | unit | `vendor/bin/phpunit tests/unit/Platform/MoneyTest.php` | 01-03-T1 | ✅ | ✅ green |
| NFR-04 | súčet = súčet zaokrúhlených riadkov, parita s PG `round(numeric)` | integration | `vendor/bin/phpunit tests/integration/MoneyDbParityTest.php` | 01-09-T1, T2 | ✅ | ✅ green |
| NFR-04 | zákaz `float`/`date()`/`time()`/`new DateTime` mimo `Clock`/`Money` | static | `vendor/bin/phpstan analyse` | 01-07-T1, 01-10-T1 | ✅ | ✅ green |
| NFR-04 | `Clock` zmrazený v testoch, TZ konzistencia PHP/CI4/PG | unit+integration | `vendor/bin/phpunit tests/unit/Platform/ClockTest.php tests/integration/TimezoneConsistencyTest.php` | 01-03-T2, 01-05-T2 | ✅ | ✅ green |
| NFR-07 | `app_rw` bez UPDATE/DELETE/TRUNCATE/DDL, migrácie ako `app_owner` | integration | `vendor/bin/phpunit tests/integration/DbRolesTest.php` | 01-05-T2, 01-09-T2 | ✅ | ✅ green |
| NFR-07 | compose zdravý, HTTPS, bezpečnostné hlavičky, Mailpit prijme mail | smoke | `make smoke` (`scripts/smoke.sh`) | 01-02-T2 | ✅ | ✅ green |
| NFR-07 | dev/prod target z jedného Dockerfile, preload nezhodí PHP | CI | `.github/workflows/ci.yml` job `check` | 01-01-T4, 01-10-T2 | ✅ | ✅ green |
| UI-01 | views bez inline script/style/on*, CSP bez `unsafe-*` | unit + smoke | `vendor/bin/phpunit tests/unit/Security/ViewsCspTest.php` | 01-04-T1, T3 | ✅ | ✅ green |
| UI-01 | všetky `lang()` kľúče existujú, parita sk/en | unit | `vendor/bin/phpunit tests/unit/I18n/LangKeysTest.php` | 01-04-T1, T3 | ✅ | ✅ green |
| UI-01 | views neobsahujú text mimo `lang()` (text medzi tagmi aj `title`/`alt`/`placeholder`/`aria-label`) | unit | `vendor/bin/phpunit tests/unit/I18n/ViewsHardcodedTextTest.php` | 01-04-T1, T3 | ✅ | ✅ green |
| UI-01 | layout: viewport-fit, lang, Bootstrap, `htmx-config` meta | feature | `vendor/bin/phpunit tests/feature/LayoutTest.php` | 01-04-T1, T3 | ✅ | ✅ green |
| UI-01 (SC4) | htmx POST s tokenom 200, bez tokenu 403, neplatný vstup 422, `Vary: HX-Request` | feature | `vendor/bin/phpunit tests/feature/HtmxCsrfTest.php` | 01-08-T1, T3 | ✅ | ✅ green |
| SC5 | mPDF s „łńěťžô“ pod PHP 8.5 `E_ALL`, pamäť zmeraná; limit 2 s tvrdo lokálne (`make spike-pdf`), v CI (`CI` env) len zaznamenaný | spike + integration | `make spike-pdf`, `vendor/bin/phpunit tests/integration/MpdfSpikeTest.php` | 01-06-T1, T2, 01-10-T3 | ✅ | ✅ green |
| UI-01 (SC4, CR-01) | CSRF 403 fragment v `#app-alert` je viditeľný a neprepíše ho `responseError` (poradie udalostí, sieťová chyba, iný cieľ) | unit (JS) + feature | `node --test tests/js/*.test.js`, `vendor/bin/phpunit tests/feature/HtmxCsrfTest.php` (`testLayoutSwaps403IntoVisibleAlert`) | REVIEW-FIX CR-01 | ✅ | ✅ green |
| NFR-07 (CR-02) | `db-reset.sh` povolí len `development`/`testing` (úvodzovky, CRLF, procesné env), zlyhá zatvorene, guard pred `DROP` | unit | `vendor/bin/phpunit tests/unit/Scripts/DbResetGuardTest.php` | REVIEW-FIX CR-02 | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] Docker Desktop + WSL integrácia, `make`, mkcert, hosts záznam, CA (checkpoint pre človeka)
- [x] `composer.json` scripts, upravený `phpunit.dist.xml`, `tests/_support/PgTestCase.php`, adresáre `tests/unit`, `tests/integration`, `tests/feature`
- [x] `docker/postgres/provision.sql`, `make test-db`
- [x] Frontend súbory v `public/assets/vendor/`
- [x] Spike ICU kolácie a overenie auto-discovery `codeigniter4/translations`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Bez horizontálneho posúvania (375/390 px) | UI-01 | Žiadny prehliadačový testovací framework (bez build pipeline) | DevTools device mode iPhone SE / iPhone 14 na `/` a `/demo`: v konzole `document.documentElement.scrollWidth <= window.innerWidth` = `true`. Kontrola je nezávislá od CSS: `app.css` nemá `overflow-x: hidden` (01-04 acceptance `grep -c 'overflow-x' = 0`), takže pretečenie sa nemaskuje |
| Dotykové ciele ≥ 44 px | UI-01 | Výška sa počíta až v prehliadači | V konzole `[...document.querySelectorAll('a,button,input,select,textarea,.nav-link')].filter(e => e.offsetParent && e.getBoundingClientRect().height < 44)` = prázdne pole (CSS grep `min-height: 44px` je len doplnok) |
| CSP konzola bez porušení | UI-01 | Hlásenia porušení vidno len v prehliadači | Otvoriť ukážkovú htmx stránku, skontrolovať konzolu |
| mkcert CA dôveryhodná vo Windows prehliadači | NFR-07 | Windows trust store mimo kontajnera | Otvoriť `https://work-tracker.loc` v Chrome/Edge bez varovania |
| mPDF čas < 2 s na referenčnom stroji | SC5 | CI runner je zdieľaný a časovo nestabilný | `make spike-pdf` lokálne (tvrdý limit), čísla zapísané do PROJECT.md (01-10-T3) |
| Cookie flags v prehliadači, Mailpit UI, zelený GitHub CI | NFR-07 | Prehliadač / vzdialený runner | 01-UAT.md testy 9, 10, 12 |

Všetky manuálne overenia prešli v 01-UAT.md (2026-10-24, 12/12 pass).

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 30s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-10-24

---

## Validation Audit 2026-10-24

| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |

Overené behom: `make check` (PHPStan + php-cs-fixer + PHPUnit 11.5.57 na PHP 8.5.12: 138 testov, 2712 assertions, OK), `node --test tests/js/*.test.js` (5/5), `make smoke` (SMOKE OK). Všetky súbory z mapy existujú. Medzery z 01-VERIFICATION.md (CR-01, CR-02) sú opravené a pokryté testami (riadky vyššie).
