---
phase: 12
slug: lokaliz-cia-a-produkcia
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-11-04
---

# Phase 12 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 10.5 s CI4 `CIUnitTestCase` (skupiny `unit`, `integration`, `performance`), `node --test` pre JS |
| **Config file** | `phpunit.dist.xml` |
| **Quick run command** | `make test-unit` (v kontajneri `php`) |
| **Full suite command** | `make check` (phpunit + phpstan + cs) |
| **Estimated runtime** | ~30 s quick, ~3 min full |

---

## Sampling Rate

- **After every task commit:** Run `make test-unit` (alebo cielene `phpunit tests/unit/I18n tests/unit/Scripts`)
- **After every plan wave:** Run `make check`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 12-TBD | TBD | TBD | UI-02 | — | parita kľúčov sk=pl=cs=en, rovnaké placeholdery | unit | `vendor/bin/phpunit tests/unit/I18n/LangKeysTest.php` | ✅ rozšíriť | ⬜ pending |
| 12-TBD | TBD | TBD | UI-02 | T-12 locale POST | whitelist `supportedLocales`, CSRF, PRG, audit | feature | `vendor/bin/phpunit tests/feature/Settings/LocaleTest.php` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | UI-02 | — | čísla, meny, percentá, mesiace v 4 jazykoch | unit | `vendor/bin/phpunit tests/unit/Platform/MoneyFormatLocaleTest.php` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | UI-02 | — | zmena locale nemení vystavené PDF | integration | `vendor/bin/phpunit --group integration --filter Locale` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-05 | T-12 secrets | restic skript: forget 14/8/12, ping OK/fail, heslá sa nevypisujú, dump zmazaný | unit (fake docker/restic/curl) | `vendor/bin/phpunit tests/unit/Scripts/ResticBackupScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-05 | — | `forget` zachová snapshot `archive`, stabilné cesty | shell test s reálnym restic (skip ak chýba) | `vendor/bin/phpunit --filter ResticRetention` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-09 | T-12 DB delete | DELETE/TRUNCATE ako app_rw aj app_owner zlyhá pre každú tabuľku dokladov | integration | `vendor/bin/phpunit tests/integration/Database/ImmutableDocumentsTest.php` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-09 | — | `archive:year`: ZIP so všetkými PDF roka, CSV sha256 sedí, prázdny rok | integration | `vendor/bin/phpunit tests/integration/Export/YearArchiveTest.php` | ❌ W0 | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-01 | — | PDF < 2 s, p95 < 100 ms | performance | `vendor/bin/phpunit --group performance` | ✅ | ⬜ pending |
| 12-TBD | TBD | TBD | NFR-01 | — | HTTP/2, gzip (aj JS), immutable cache, CSP | smoke | `scripts/smoke-server.sh` | ✅ rozšíriť | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/feature/Settings/LocaleTest.php`
- [ ] `tests/unit/Platform/MoneyFormatLocaleTest.php`
- [ ] `tests/unit/Scripts/ResticBackupScriptTest.php` (vzor `BackupScriptTest`)
- [ ] `tests/integration/Database/ImmutableDocumentsTest.php`
- [ ] `tests/integration/Export/YearArchiveTest.php`
- [ ] jazykové súbory `pl` a `cs` musia vzniknúť v tom istom pláne ako rozšírenie parity testu (inak červený test)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Obnova na čistom prostredí s reálnymi dátami | NFR-05 | vyžaduje reálne prod dáta a čisté prostredie | `scripts/restore-test.sh` podľa `docs/ops/backup.md`, porovnať počty riadkov a posledné číslo faktúry |
| Upozornenie pri vynechanej zálohe | NFR-05 | závisí od externého healthchecks/e-mailu | vynechať beh, overiť doručenie upozornenia |
| p95 < 100 ms, RAM < 512 MB v pokoji na cieľovom VPS | NFR-01 | meranie na produkčnom serveri s 2FA session | `scripts/measure-p95.sh` (najprv staging), `docker stats --no-stream` |
| Manuálny pull zálohy na PC | NFR-05 | beží na PC vlastníka | rsync podľa `docs/ops/backup.md`, bez `--delete` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
