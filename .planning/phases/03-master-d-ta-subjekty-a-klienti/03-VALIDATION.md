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
| TBD | — | — | ENT-01 | — | entita + prvá verzia + 2 rady + audit v 1 transakcii | integration | `phpunit tests/integration/Master/EntityServiceTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-01 | T-CSRF | vytvorenie cez UI POST | feature | `phpunit tests/feature/Master/EntitiesTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-02, ENT-05 | T-Input | validácie polí, VAT-UE formát | unit | `phpunit tests/unit/Master/EntityValidationTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-03 | T-Tamper | nová verzia, duplicitný `valid_from` 422, UPDATE/DELETE `entity_versions` denied | integration | `phpunit tests/integration/Master/EntityVersionTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-03 | — | história a `VersionDiff` | unit+feature | `phpunit tests/unit/Master/VersionDiffTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-04 | — | ukončený subjekt vo `forSelect()` | integration | `phpunit tests/integration/Master/EntityDirectoryTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-06 | T-IDOR | viac účtov, `forCurrency`, jedno predvolené, IBAN mod-97 | integration+unit | `phpunit tests/integration/Master/BankAccountTest.php tests/unit/Master/IbanTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-07 | T-MassAssign | podvrhnutý POST odmietnutý, `UPDATE entities SET country/currency` permission denied | feature+integration | `phpunit tests/feature/Master/EntityImmutableTest.php tests/integration/Master/MasterRolesTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | ENT-01 (D-09..D-11) | — | formát radu, rok povinný, invoice ≠ credit_note | unit | `phpunit tests/unit/Master/SeriesFormatTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | CLI-01 | T-Destroy | pridať, upraviť, archivovať, obnoviť; DELETE denied | integration+feature | `phpunit tests/integration/Master/ClientServiceTest.php tests/feature/Master/ClientsTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | CLI-02, CLI-03, CLI-05, CLI-06, CLI-07 | T-HeaderInj | validácie klienta, e-maily v kópii bez CR/LF | unit | `phpunit tests/unit/Master/ClientValidationTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | CLI-04 | T-XSS | `PortalUrlTemplate` len http(s), `{id}` práve raz | unit | `phpunit tests/unit/Master/PortalUrlTemplateTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | SET-02 | — | seed profilov a textov, `exemption_code`, fallback | integration | `phpunit tests/integration/Master/LegalProfileTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | AUD-01 | T-Repudiation | audit v tej istej transakcii, diff, denylist, insert-only | integration+unit | `phpunit tests/integration/Master/AuditLoggerTest.php tests/unit/Master/AuditDiffTest.php` | ❌ W0 | ⬜ pending |
| TBD | — | — | UI | T-XSS | bez inline skriptov/štýlov, texty cez `lang()` | unit | `phpunit tests/unit/Security/ViewsCspTest.php tests/unit/I18n` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*
*Task IDs are filled in by the planner once PLAN.md files exist.*

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
