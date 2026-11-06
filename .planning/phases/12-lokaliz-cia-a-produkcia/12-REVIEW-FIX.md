---
phase: 12-lokaliz-cia-a-produkcia
fixed_at: 2026-11-06T00:00:00Z
review_path: .planning/phases/12-lokaliz-cia-a-produkcia/12-REVIEW.md
iteration: 1
findings_in_scope: 8
fixed: 8
skipped: 0
status: all_fixed
---

# Phase 12: Code Review Fix Report

**Fixed at:** 2026-11-06
**Source review:** .planning/phases/12-lokaliz-cia-a-produkcia/12-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 8 (CR-01, WR-01..WR-05, IN-02, IN-04)
- Fixed: 8
- Skipped: 0
- Not in scope by instruction: IN-01 (local RESTORE_KEEP into task_tracker is the documented 12-14 flow), IN-03 (by design)

**Verification caveat:** Docker is not running in this WSL distro and `vendor/` is absent, so PHPUnit, PHPStan and php-cs-fixer could NOT be run. Verification done: `sh -n` on every changed script, `php -l` on every changed PHP file, and ad-hoc shell harnesses that run the real scripts against the fake docker/restic/curl extracted from the tests. Run `phpunit tests/unit/Scripts`, `composer stan` and `composer cs` in the container before relying on this.

## Fixed Issues

### CR-01: restore-test manifest loop lost lines to docker exec stdin

**Files modified:** `scripts/restore-test.sh`, `tests/unit/Scripts/RestoreTestScriptTest.php`
**Commit:** 64623c1
**Applied fix:** `q()` and the createdb/dropdb calls read from `/dev/null`. The fake docker in the test now drains stdin on every call, like the real client. New test `testMismatchOnLaterManifestKeysIsDetected` checks that a mismatch on `last_invoice_number` or `documents_sha256_digest` is detected and that every manifest query was issued. Harness check: the old script reported OK with a changed `last_invoice_number`, the new one fails with `nezhoda last_invoice_number`. Logic fix, requires human verification.

### WR-01: YearArchive leaked the partial ZIP on failure

**Files modified:** `app/Libraries/Export/YearArchive.php`
**Commit:** 8931aca
**Applied fix:** a `$built` flag is set after a successful `close()`. The first `finally` unlinks `$partial` (after closing the zip) whenever it is not set. No unit test added (no PHPUnit available here).

### WR-02 and WR-03: manifest swallowed query failures and could differ from the dump

**Files modified:** `scripts/backup-restic.sh`, `tests/unit/Scripts/ResticBackupScriptTest.php`
**Commit:** 616a67e (one commit, the two changes share the same code)
**Applied fix:**
- WR-02: the manifest values come from a `facts()` function. Each query is assigned to a variable first (so `set -e` fires on a failed query), and a non-numeric or empty count aborts the backup at step `manifest`. Only `last_invoice_number` and the digest may be empty (empty DB).
- WR-03: chosen approach is the simplest correct one. The facts are taken before and after `pg_dump`. If they are identical, nothing changed during the dump and the manifest matches the dump. If they differ, dump and manifest are redone (max 3 attempts), after which the backup fails with an alert. `pg_export_snapshot` was not used because the exported snapshot only lives as long as the exporting session, which is awkward across separate `docker compose exec` calls. Limitation: a change that is reversed within the window (ABA) would go unnoticed.
- New tests: failed psql aborts at step manifest, a change during the dump repeats the dump (2 pg_dump calls), a change on every attempt fails after 3 attempts without a restic backup. Verified with a harness. Logic fix, requires human verification.

### WR-04: failures before the EXIT trap were not alerted

**Files modified:** `scripts/backup-restic.sh`, `scripts/archive-yearly.sh`, `scripts/restore-test.sh`, `docs/ops/backup.md`
**Commit:** a176458
**Applied fix:** the `export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE` moved after the trap is installed, preceded by explicit `${VAR:?message}` checks (step `env`). A missing variable now triggers the ping and e-mail. A missing, wrongly owned or wrongly permissioned env file still cannot alert (no credentials loaded yet): it exits non-zero with a clear stderr message, which cron mails to root. `mktemp` failure before the trap behaves the same. This is documented in `docs/ops/backup.md`, together with the advice to set at least one of `HC_URL` and `ALERT_TO`.

### WR-05: credentials in curl argv

**Files modified:** `scripts/lib-backup-notify.sh`, `scripts/measure-p95.sh`, `scripts/smoke-server.sh`, and the tests `ResticBackupScriptTest`, `RestoreTestScriptTest`, `ArchiveYearlyScriptTest`, `MeasureP95ScriptTest`
**Commit:** d924fae
**Applied fix:** the healthchecks URL goes to curl as `url = "..."` on stdin (`curl -K -`). The P95 cookie goes into a 0600 header file used with `-H @file`. The smoke-test password goes into a 0600 file used with `--data-urlencode password@file`. Fake curl in the tests records stdin config and `@file` contents in separate logs, and the tests now assert that the secrets are absent from the argv log. `smoke-server.sh` was only syntax-checked (it needs a live server).

### IN-02: pull-backups unquoted ssh key path

**Files modified:** `scripts/pull-backups.sh`, `tests/unit/Scripts/PullBackupsScriptTest.php`
**Commit:** b93276f
**Applied fix:** the key path is single-quoted in the rsync `-e` option. `--delete` was deliberately not added, and the default target IP is unchanged (it is overridable with `PULL_SSH_TARGET`). The test expectation was updated to the quoted form.

### IN-04: setLocale audited an UPDATE that matched no row

**Files modified:** `app/Controllers/Settings/Security.php`, `app/Language/{sk,cs,pl,en}/Account.php`
**Commit:** 5575145
**Applied fix:** after the UPDATE, `affectedRows() < 1` returns the settings page with the error `Account.languageNotSaved` (new key in all four languages, HTTP 500). No audit event or success flash is written in that case. Logic fix, requires human verification.

## Skipped Issues

None. IN-01 and IN-03 were excluded from scope by instruction.

---

_Fixed: 2026-11-06_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
