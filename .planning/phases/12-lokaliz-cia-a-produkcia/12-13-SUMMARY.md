---
phase: 12-lokaliz-cia-a-produkcia
plan: 13
subsystem: ops
tags: [backup, restic, install, runbook]
requires: [12-04, 12-10, 12-11]
provides:
  - scripts/install-backup.sh (idempotent server backup installer, DRY_RUN mode)
  - docs/ops/server.md section 21 (phase 12 rollout) and 2FA reset note in section 12
affects: [12-14]
tech-stack:
  patterns: [dry-run plan mode, root-owned script copies for root cron]
key-files:
  created: [scripts/install-backup.sh, tests/unit/Scripts/InstallBackupScriptTest.php]
  modified: [docs/ops/server.md]
decisions:
  - Root cron scripts live in /usr/local/lib/work-tracker-backup/scripts (root-owned), never in /opt/work-tracker
metrics:
  tasks: 2
  completed: 2026-11-05
---

# Phase 12 Plan 13: Server backup installer and rollout runbook Summary

One-command idempotent `install-backup.sh` (SHA256SUMS-verified restic 0.19.1, root-owned scripts, 0700 dirs, 0600 env template, backuppull with rrsync -ro and visudo-checked sudoers, printed cron lines incl. yearly archive) plus runbook section 21 and a 2FA reset (encryption.key) note in section 12.

## Commits
- af18d9a feat(12-13): idempotent server installer for backups
- ccaff5d docs(12-13): phase 12 server rollout and 2fa reset runbook

## Verification
- InstallBackupScriptTest: 4 tests, 47 assertions OK; `sh -n` OK; composer stan: no errors.
- Acceptance greps for script and docs pass (no app.env/sed -i/crontab - outside comments; no ping URLs in docs).

## Deviations from Plan
- Cron hint text avoids the literal "crontab -e" so the plan's `crontab -` grep stays at 0. The test method was named `runScript` because `run()` clashes with PHPUnit TestCase::run(). Otherwise none.
- Section 12 now says production sends mail via Stalwart (section 20); intro fixed as the plan required.

## Known Stubs
None. Nothing was run on any server (that is 12-14); requirements not marked complete.

## Self-Check: PASSED
