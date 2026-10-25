---
phase: 3
slug: master-d-ta-subjekty-a-klienti
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-25
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit `^10.5.16` (`CIUnitTestCase`, `FeatureTestTrait`, `Tests\Support\PgTestCase`, `AuthTestCase`), skupiny `unit`, `integration`, `feature` |
| **Config file** | `phpunit.xml.dist` (existujúci) |
| **Quick run command** | `make test-unit` (alebo `docker compose exec -T -u www-data php composer test:unit`) |
| **Full suite command** | `make check` (PHPStan + CS + PHPUnit); `make test` len testy |
| **Estimated runtime** | ~60 seconds |

---

## Sampling Rate

- **After every task commit:** Run `make test-unit` a cielený `phpunit --filter <trieda>`
- **After every plan wave:** Run `make test`
- **Before `/gsd-verify-work`:** `make check` must be green
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-T1/T2 | 03-01 | 1 | AUD-01 | T-03-01..05 | audit v tej istej transakcii, diff, denylist, insert-only, stĺpcový grant | integration+unit | `phpunit tests/integration/Audit/AuditLoggerTest.php tests/unit/Audit/AuditLoggerDiffTest.php tests/integration/DbRolesTest.php` | ❌ W0 (03-01-T1) | ⬜ pending |
| 03-02-T1..T3 | 03-02 | 2 | ENT-01, ENT-02, ENT-05, ENT-07 | T-03-06..12 | entita + prvá verzia + 2 rady + audit v 1 transakcii; country/currency denied; UI POST SK aj PL | unit+integration+feature | `phpunit tests/unit/Master/EntityInputTest.php tests/integration/Master/EntityServiceTest.php tests/feature/Master/EntitiesCreateTest.php` | ❌ W0 (03-02-T1) | ⬜ pending |
| 03-03-T1/T2 | 03-03 | 3 | ENT-04 | T-03-14..17 | ukončený subjekt vo `forSelect()`, `activeAt` s prekryvom | integration+feature | `phpunit tests/integration/Master/EntityDirectoryTest.php tests/feature/Master/EntitiesListTest.php` | ❌ W0 (03-03-T1) | ⬜ pending |
| 03-04-T1..T3 | 03-04 | 4 | ENT-03 | T-03-18..23 | nová verzia, duplicitný `valid_from` 422, výber k dátumu, história a `VersionDiff` | unit+integration+feature | `phpunit tests/unit/Master/VersionDiffTest.php tests/integration/Master/EntityVersionTest.php tests/feature/Master/EntityVersionsTest.php` | ❌ W0 (03-04-T1) | ⬜ pending |
| 03-05-T1..T3 | 03-05 | 4 | CLI-01, CLI-02, CLI-03, CLI-05, CLI-06, CLI-07 | T-03-24..31 | validácie klienta, e-maily v kópii bez CR/LF, sadzba string, DELETE denied | unit+integration+feature+js | `phpunit tests/unit/Master/CcEmailsTest.php tests/unit/Master/ClientInputTest.php tests/integration/Master/ClientServiceTest.php tests/feature/Master/ClientsTest.php` + `node --test tests/js/client-form.test.js` | ❌ W0 (03-05-T1) | ⬜ pending |
| 03-06-T1/T2 | 03-06 | 5 | ENT-04, ENT-07 | T-03-32..36 | podvrhnutý POST country/currency → 422; oprávnenia všetkých master tabuliek | feature+integration | `phpunit tests/feature/Master/EntityImmutableTest.php tests/feature/Master/EntityEditTest.php tests/integration/Master/MasterRolesTest.php` | ❌ W0 (03-06-T1) | ⬜ pending |
| 03-07-T1/T2 | 03-07 | 5 | CLI-01, CLI-04 | T-03-37..41 | archivácia s auditom; `PortalUrlTemplate` len http(s), `{id}` práve raz | unit+integration+feature | `phpunit tests/unit/Master/PortalUrlTemplateTest.php tests/integration/Master/ClientArchiveTest.php tests/feature/Master/ClientsArchivePortalTest.php` | ❌ W0 (03-07-T1) | ⬜ pending |
| 03-08-T1..T3 | 03-08 | 6 | ENT-06 | T-03-42..47 | viac účtov, `forCurrency` (3 vetvy), jeden predvolený, IBAN mod-97, IDOR 404 | unit+integration+feature | `phpunit tests/unit/Master/IbanTest.php tests/integration/Master/BankAccountTest.php tests/feature/Master/BankAccountsTest.php` | ❌ W0 (03-08-T1) | ⬜ pending |
| 03-09-T1..T3 | 03-09 | 7 | ENT-01 (D-09..D-11) | T-03-48..52 | formát radu, rok povinný, invoice ≠ credit_note, `isFormatLocked` | unit+integration+feature | `phpunit tests/unit/Master/SeriesFormatTest.php tests/integration/Master/SeriesServiceTest.php tests/feature/Master/SeriesTest.php` | ❌ W0 (03-09-T1) | ⬜ pending |
| 03-10-T1/T2 | 03-10 | 8 | SET-02 | T-03-53..57 | seed profilov a textov, `exemption_code`, fallback, SELECT-only; dev seeder idempotentný | integration+feature | `phpunit tests/integration/Master/LegalProfileTest.php tests/feature/Master/LegalProfileSectionTest.php tests/integration/Database/DevSeederTest.php` | ❌ W0 (03-10-T1) | ⬜ pending |
| 03-10-T3 | 03-10 | 8 | UI, všetky | T-03-09, T-03-28 | bez inline skriptov/štýlov, texty cez `lang()`, celá sada | unit (existujúce) + make check | `make check && node --test tests/js/` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*
*Každý plán začína úlohou RED (Wave 0 pre svoje testy), takže žiadne 3 po sebe idúce úlohy nie sú bez automatického overenia.*

---

## Wave 0 Requirements

- [ ] migrácie Phase 3 (`composer test:migrate`)
- [ ] `tests/_support/MasterFixtures.php` — SK/PL subjekt, klient, čistenie pod `owner()` (app_rw nemá DELETE)
- [ ] `tests/{unit,integration,feature}/Master/*` podľa mapy vyššie

Framework install: netreba.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Formuláre ovládateľné jednou rukou na iPhone, ciele ≥ 44 px, bez horizontálneho posúvania | ENT-01, CLI-01 | vizuálna kontrola na zariadení | otvoriť PWA na iPhone, vytvoriť subjekt a klienta |
| Predbežné právne texty (`legal_texts`) | SET-02 | znenie potvrdí účtovníčka | poslať seed na kontrolu účtovníčke |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
