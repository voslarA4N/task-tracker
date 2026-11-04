---
phase: 12
slug: lokaliz-cia-a-produkcia
status: planned
nyquist_compliant: true
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
| 12-01-01 | 01 | 1 | UI-02 | — | parita kľúčov a zástupných symbolov pre existujúce súbory pl/cs/en | unit | `vendor/bin/phpunit tests/unit/I18n/LangKeysTest.php` | ✅ rozšíriť | ⬜ pending |
| 12-01-02 | 01 | 1 | UI-02 | T-12-01..05 | whitelist `supportedLocales`, CSRF, PRG, audit `locale_changed` | feature | `vendor/bin/phpunit tests/feature/Settings/LocaleTest.php` | ❌ W0 (12-01-01) | ⬜ pending |
| 12-01-03 | 01 | 1 | UI-02 | T-12-22 | PL/CS Account a App úplné | feature + unit | `vendor/bin/phpunit tests/feature/Settings/LocaleTest.php tests/unit/I18n` | ❌ W0 | ⬜ pending |
| 12-02-01 | 02 | 1 | UI-02 | T-12-08 | čísla, meny, percentá, mesiace v 4 jazykoch (ICU v kontajneri) | unit | `vendor/bin/phpunit tests/unit/Platform` | ❌ W0 | ⬜ pending |
| 12-02-02 | 02 | 1 | UI-02 | T-12-07, T-12-08 | dátumy v 4 jazykoch cez DateFormat (aj EntityInput), zmena locale nemení vystavené PDF | unit + integration | `vendor/bin/phpunit tests/unit/Platform/DateFormatTest.php && vendor/bin/phpunit tests/integration/Invoicing/LocaleIndependentDocumentTest.php` | ❌ W0 | ⬜ pending |
| 12-03-01 | 03 | 1 | NFR-09 | T-12-09, T-12-10 | DELETE/TRUNCATE/UPDATE ako app_rw aj app_owner zlyhá pre každú tabuľku dokladov | integration | `vendor/bin/phpunit tests/integration/Database/ImmutableDocumentsTest.php` | ❌ W0 | ⬜ pending |
| 12-03-02 | 03 | 1 | NFR-09 | T-12-11 | nová tabuľka s FK na doklady bez ochrany zhodí test | integration | `vendor/bin/phpunit tests/integration/Database` | ❌ W0 | ⬜ pending |
| 12-04-01 | 04 | 1 | NFR-05 | T-12-13, T-12-14 | kontrakt skriptu: restic príkazy, ping OK/fail, e-mail, utajenie | unit (fake docker/restic/curl) | `vendor/bin/phpunit tests/unit/Scripts/ResticBackupScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-04-02 | 04 | 1 | NFR-05 | T-12-13..17 | backup-restic.sh: overený dump, manifest, forget 14/8/12, heslá sa nevypisujú, dump zmazaný, `BACKUP_DB_USER`/`BACKUP_DB` z env súboru exportované do backup.sh | unit | `vendor/bin/phpunit tests/unit/Scripts` | ❌ W0 | ⬜ pending |
| 12-04-03 | 04 | 1 | NFR-05 | T-12-16 | `forget` zachová snapshot `archive`, stabilné cesty | unit s reálnym restic | `vendor/bin/phpunit tests/unit/Scripts/ResticRetentionTest.php` | ❌ W0 | ⬜ pending |
| 12-05-01 | 05 | 1 | NFR-01 | T-12-19 | p95 výpočet, cookie sa nevypíše | unit | `vendor/bin/phpunit tests/unit/Scripts/MeasureP95ScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-05-02 | 05 | 1 | NFR-01 | T-12-20 | HTTP/2, gzip, immutable, OPcache, classmap, CSP v konfigurácii | unit + smoke | `vendor/bin/phpunit tests/unit/Ops/ProdHardeningConfigTest.php` | ❌ W0 | ⬜ pending |
| 12-06-01..03 | 06 | 2 | UI-02 | T-12-22 | PL súbory časť 1 s paritou | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ (12-01) | ⬜ pending |
| 12-07-01..03 | 07 | 2 | UI-02 | T-12-24 | PL súbory časť 2 s paritou | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ (12-01) | ⬜ pending |
| 12-08-01..03 | 08 | 2 | UI-02 | T-12-26 | CS súbory časť 1 s paritou | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ (12-01) | ⬜ pending |
| 12-09-01..03 | 09 | 2 | UI-02 | T-12-28 | CS súbory časť 2 s paritou | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ (12-01) | ⬜ pending |
| 12-10-01/02 | 10 | 2 | NFR-09 | T-12-30..33 | `archive:year`: ZIP so všetkými PDF a verziami roka, CSV sha256 sedí, prázdny rok | integration | `vendor/bin/phpunit tests/integration/Export/YearArchiveTest.php` | ❌ W0 (12-10-01) | ⬜ pending |
| 12-10-03 | 10 | 2 | NFR-09 | T-12-34 | archive-yearly.sh: tag archive, sha256 pred uložením, upozornenie | unit | `vendor/bin/phpunit tests/unit/Scripts/ArchiveYearlyScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-11-01 | 11 | 2 | NFR-05 | T-12-38, T-12-39 | restore-test.sh porovná manifest, dropdb vždy | unit | `vendor/bin/phpunit tests/unit/Scripts/RestoreTestScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-11-02 | 11 | 2 | NFR-05 | T-12-35, T-12-37 | pull bez --delete, bez locks | unit | `vendor/bin/phpunit tests/unit/Scripts/PullBackupsScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-11-03 | 11 | 2 | NFR-05 | — | runbook záloh, odklon D-05, tajomstvá mimo servera | grep | `grep -c "D-05" docs/ops/backup.md` | ✅ prepísať | ⬜ pending |
| 12-12-01 | 12 | 3 | UI-02 | — | úplná parita 32 súborov x 4 jazyky, nepreložené kópie zakázané | unit | `vendor/bin/phpunit tests/unit/I18n` | ✅ rozšíriť | ⬜ pending |
| 12-12-02 | 12 | 3 | UI-02 | T-12-41 | kľúčové obrazovky v PL/CS bez surových kľúčov a en fallbacku | feature + human-check 375px | `vendor/bin/phpunit tests/feature/I18n` | ❌ W0 | ⬜ pending |
| 12-13-01 | 13 | 3 | NFR-05 | T-12-43..46 | inštalátor: SHA256SUMS, root-owned skripty, bez app.env | unit (DRY_RUN) | `vendor/bin/phpunit tests/unit/Scripts/InstallBackupScriptTest.php` | ❌ W0 | ⬜ pending |
| 12-13-02 | 13 | 3 | NFR-05, NFR-09 | T-12-47 | server.md §21 a reset 2FA (D-14) | grep | `grep -c "## 21\." docs/ops/server.md` | ✅ doplniť | ⬜ pending |
| 12-14-01..03 | 14 | 4 | NFR-05, NFR-09 | T-12-48..51 | zálohy v prevádzke, upozornenia, jednorazový prod beh archive-yearly.sh 2026 (čas, pamäť, DB nezmenená), obnova reálnych dát | manual + smoke | `sh scripts/smoke-server.sh work-tracker.vostack.dev` | ✅ | ⬜ pending |
| 12-15-01..03 | 15 | 5 | NFR-01 | T-12-52, T-12-53 | p95 < 100 ms, PDF < 2 s, < 512 MB na VPS | performance + manual | `vendor/bin/phpunit --group performance` + `scripts/measure-p95.sh` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/feature/Settings/LocaleTest.php`
- [ ] `tests/unit/Platform/MoneyFormatLocaleTest.php`
- [ ] `tests/unit/Platform/DateFormatTest.php` (12-02 Task 2)
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
