---
phase: 12-lokaliz-cia-a-produkcia
reviewed: 2026-11-06T00:00:00Z
depth: standard
files_reviewed: 24
files_reviewed_list:
  - app/Commands/ArchiveYear.php
  - app/Config/App.php
  - app/Config/Routes/settings.php
  - app/Controllers/Settings/Entities.php
  - app/Controllers/Settings/EntityVersions.php
  - app/Controllers/Settings/Security.php
  - app/Libraries/Auth/AuthEvent.php
  - app/Libraries/Export/YearArchive.php
  - app/Libraries/Master/EntityInput.php
  - app/Libraries/Platform/DateFormat.php
  - app/Views/settings/_language.php
  - Dockerfile
  - scripts/archive-yearly.sh
  - scripts/backup-restic.sh
  - scripts/backup.sh
  - scripts/install-backup.sh
  - scripts/lib-backup-notify.sh
  - scripts/lib-restic-policy.sh
  - scripts/measure-p95.sh
  - scripts/pull-backups.sh
  - scripts/restore-test.sh
  - scripts/smoke-server.sh
  - tests/unit/Scripts/RestoreTestScriptTest.php
  - tests/_support/PgTestCase.php
findings:
  critical: 1
  warning: 5
  info: 4
  total: 10
status: issues_found
---

# Phase 12: Code Review Report

**Reviewed:** 2026-11-06
**Depth:** standard
**Files Reviewed:** 24 (docs and the remaining tests only skimmed)
**Status:** issues_found

## Summary

The locale endpoint is sound: it is a POST route behind the CSRF filter, the value is checked with strict `in_array` against `supportedLocales`, the DB write uses bound parameters, and the change is audited. The backup and restore scripts are carefully built. They use `set -eu` and an EXIT trap, a 0600 env-file check, SMTP credentials passed through a temporary netrc file instead of argv, and a `restore_check_*` guard on the target DB name in server mode. The main defect is in `restore-test.sh`: its manifest comparison loop probably verifies almost nothing in production. A few other robustness gaps are listed below.

## Critical Issues

### CR-01: restore-test manifest loop: `docker compose exec -T` consumes the manifest from stdin

**File:** `scripts/restore-test.sh:122-141` (`q()` at 119-121)
**Issue:** The `while read ... done < "$MAN"` loop calls `q`, which runs `docker compose exec -T db psql ...`. `exec -T` still attaches stdin. The docker client therefore reads the rest of the manifest file as input for psql and the loop ends after the first `rows.*` line. The result is that only `rows.invoices` (at most) is compared. `last_invoice_number`, `documents_sha256_digest`, `documents_bad_sha256` and the other row counts are silently skipped, and the script reports `OK`. This weakens the core restore-verification requirement (D-15, NFR-05). The unit test does not catch it: its docker stub only drains stdin for `pg_restore` (`RestoreTestScriptTest.php:33`).
**Fix:**
```sh
q() {
  dc exec -T db psql -U "$RESTORE_DB_USER" -d "$TARGET_DB" -At -c "$1" < /dev/null
}
```
Also make the stub in the test read stdin for every `docker` call, or add a test that asserts all manifest keys were queried. The same `< /dev/null` is worth adding to the `dc` calls in the createdb and pg_restore steps, except the one that deliberately feeds `$DUMP`.

## Warnings

### WR-01: YearArchive leaks the partial ZIP when building fails

**File:** `app/Libraries/Export/YearArchive.php:70-136`
**Issue:** `$partial` is deleted only in the `finally` of the second `try`, which starts after the first `try/finally`. Any exception in the first block (missing document version, `DocumentIntegrityError`, ZIP close failure) leaves `writable/archive/<year>/zipXXXX`. The `finally` there even calls `$zip->close()`, which writes a half-built ZIP of PDFs. Every retry leaves another copy in the volume. The yearly cron is a retry candidate precisely when an integrity error occurs.
**Fix:** Wrap the whole body, from `tempnam` to `rename`, in one `try/finally`. Unlink `$partial` in the `finally` whenever the rename did not happen. Alternatively, call `$zip->unchangeAll()` and then `close()` and `unlink` on the failure path.

### WR-02: Backup manifest swallows query failures

**File:** `scripts/backup-restic.sh:84-98`
**Issue:** `echo "rows.$t=$(q ...)"` and `echo "last_invoice_number=$(q ...)"` discard the exit status of `q`, so `set -e` does not fire. If psql fails (container restarting, permission), the manifest gets empty values and the backup is still reported as OK and pinged. The failure only surfaces a month later in restore-test. Only `BAD=$(q ...)` is checked.
**Fix:** Assign first, then write:
```sh
v=$(q "SELECT count(*) FROM $t"); echo "rows.$t=$v" >> "$MAN"
```
Also verify that each value is non-empty for the numeric counts.

### WR-03: Manifest is taken after the dump, not in the same snapshot

**File:** `scripts/backup-restic.sh:73-98`
**Issue:** `pg_dump` completes first and the manifest counts are queried afterwards, in separate transactions. A write in between, such as an invoice issued at 03:10 or the payment/send queue, makes the monthly restore-test fail with a false "nezhoda". That trains the owner to ignore alerts. The risk is small for one user, but the mismatch is real.
**Fix:** Take the manifest queries first and the dump second, or run both in a single `REPEATABLE READ` transaction with `pg_export_snapshot` and `pg_dump --snapshot`. At a minimum, document the race and re-run once in restore-test on a mismatch.

### WR-04: Failures before the EXIT trap are not alerted

**File:** `scripts/backup-restic.sh:13-25`, `scripts/archive-yearly.sh:34-44`, `scripts/restore-test.sh:34-39`
**Issue:** `check_env_file`, sourcing the env file, and `export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE` (with `set -u`, so an unset variable is fatal) all run before the `trap on_exit` is installed. A wrong permission on the env file, a missing variable or a failed mktemp ends the script with no ping or e-mail. Only the healthchecks dead-man ping, if configured, notices. `HC_URL` is empty by default in the installer template, so there is no alert at all by default.
**Fix:** Install the trap right after the env file is loaded. For pre-env failures, at least write to the log and exit non-zero, and document that `HC_URL` is required for alerting. Consider making the installer template highlight `HC_URL`/`ALERT_TO` as mandatory.

### WR-05: Credentials in curl argv

**File:** `scripts/smoke-server.sh:130-134`, `scripts/measure-p95.sh:49-60`, `scripts/lib-backup-notify.sh:37,67`
**Issue:** `SMOKE_PASSWORD` (`--data-urlencode password=...`), `P95_COOKIE` (`-H "Cookie: ..."`) and the healthchecks URL (a secret UUID) are passed as curl arguments and are visible in `ps` and `/proc/*/cmdline`. This contradicts the stated "never shown" policy in the comments. On the server, `notify_fail` and `ping_ok` run as root, but other local users can read argv.
**Fix:** Pass them through a config file or stdin instead. For example, use `curl -K -` with `printf 'header = "Cookie: %s"\n' "$P95_COOKIE"`, and `--data-urlencode "password@-"` or a 0600 temporary file for the password.

## Info

### IN-01: Local-mode restore-test with `RESTORE_KEEP=1` drops any named DB

**File:** `scripts/restore-test.sh:104-107`
**Issue:** In `RESTORE_LOCAL=1` mode, `RESTORE_TARGET_DB=task_tracker RESTORE_KEEP=1` runs `dropdb --if-exists task_tracker` on the dev DB. Server mode is correctly guarded, but local mode has no restriction.
**Fix:** Require a `restore_` prefix in local mode too, or refuse names equal to `BACKUP_DB`/`task_tracker`.

### IN-02: pull-backups mirrors without --delete and builds the ssh command unquoted

**File:** `scripts/pull-backups.sh:16-19`
**Issue:** The key path is interpolated unquoted into `-e "ssh -i $PULL_SSH_KEY ..."`, which breaks with spaces in the path. Without `--delete`, restic's pruned packs and forgotten snapshots are kept on the PC forever, so the 14/8/12 retention is never applied locally and disk use grows without limit. This is the documented trade-off for protection against server-side deletion, but it is worth stating. The default target also hard-codes the production IP.
**Fix:** Use `ssh -i '$PULL_SSH_KEY'` or an `ssh_config` entry, and note the growth or prune the local copy separately.

### IN-03: YearArchive is a point-in-time snapshot

**File:** `app/Libraries/Export/YearArchive.php:44-55`
**Issue:** Document versions (regeneration windows) or credit notes dated in the archived year but created after 2 January are not in the archive. `archive-yearly.sh` also never refreshes an existing `year-$ROK` snapshot. An empty year produces a valid ZIP with only the CSV and README and exit 0, with no warning.
**Fix:** Document the cut-off in `README.txt`/`backup.md`. Optionally warn when `count($rows) === 0`.

### IN-04: setLocale audits an UPDATE that may have matched no row

**File:** `app/Controllers/Settings/Security.php:118-124`
**Issue:** `userLocale()` returns `'sk'` when there is no `user_security` row. Selecting a non-sk locale then runs an UPDATE affecting 0 rows, writes a `locale_changed` audit event and shows a success notice, but the preference is not persisted. In practice every user probably has a row, but the code does not check `affectedRows()`.
**Fix:** Check `db_connect()->affectedRows()` and upsert or return an error if it is 0.

---

_Reviewed: 2026-11-06_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
