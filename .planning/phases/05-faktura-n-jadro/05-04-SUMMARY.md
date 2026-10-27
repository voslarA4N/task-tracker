---
phase: 05-faktura-n-jadro
plan: 04
subsystem: invoicing-ops
tags: [external-invoice, wr-07, cli, backup, pg_dump]
requires:
  - phase: 05-01
    provides: invoices external branch, lock_allocations(), immutability triggers
provides:
  - ExternalInvoiceLocker (plan, lock) and spark invoices:lock-historical
  - scripts/backup.sh and docs/ops/backup.md
  - docs/ops/import-excel.md section on the WR-07 procedure
affects: [05-05, 05-07, 12]
tech-stack:
  added: []
  patterns:
    - dry run by default, commit requires all three --expect-* values
    - backup script tested with a fake docker on PATH (same pattern as DbResetGuardTest)
key-files:
  created:
    - app/Libraries/Invoicing/ExternalInvoiceLocker.php
    - app/Commands/InvoicesLockHistorical.php
    - tests/integration/Invoicing/LockHistoricalTest.php
    - scripts/backup.sh
    - docs/ops/backup.md
    - tests/unit/Scripts/BackupScriptTest.php
  modified:
    - docs/ops/import-excel.md
    - .gitignore
key-decisions:
  - "Locker selects by month and tasks.import_key IS NOT NULL; --expect-* values must come from the import report line, documented"
  - "Dev DB WR-07 executed: client 1, 09/2026, 11 parts, 89 h, 2403.00 EUR locked on external invoice id 1"
requirements-completed: [ALC-03, INV-03, INV-08, INV-13]
duration: ~40min
completed: 2026-10-27
---

# Phase 5 Plan 04: Historical lock and backup Summary

`spark invoices:lock-historical` locks imported parts of a month onto a number-less, PDF-less external invoice (not counted toward limits) with a dry-run default and mandatory `--expect-*` guard, and `scripts/backup.sh` produces a verified `pg_dump --format=custom` with sha256 that includes stored PDFs.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | ExternalInvoiceLocker and invoices:lock-historical | 4f1c683 |
| 2 | WR-07 on dev DB and docs/ops/import-excel.md | 548910b |
| 3 | scripts/backup.sh, docs, test | a38ffae |

## WR-07 on the dev DB

Dry run showed 11 parts, 89.00 h, 2403.00 EUR for client 1 (named "All4Net (ukážka)"), month 2026-09, which equals the verified import values in STATE. The committed run created external invoice id 1 (`Historická faktúra 09/2026 (fakturované mimo aplikácie)`). Check: 1 external invoice, line_count 11, total 2403.00, zero unlocked imported parts in 2026-09. A second run ends with `nothing_to_lock`. The 2026-10 part (1 h, 27.00) is a separate month and was not touched. The expect values were taken from STATE (which came from the import report), because the source Excel is not in git and the import could not be re-run here.

## Verification

Full PHPUnit suite green (979 tests, 6895 assertions) after `make test-db`; `composer stan` and `composer cs` clean. `scripts/backup.sh` was also run once against the real dev DB (output in the scratchpad, 0600, sha256 written).

## Deviations from Plan

**1. [Rule 1 - Bug, test only]** Test helper named `count()` clashed with the final `TestCase::count()`; renamed to `rowCount()`. Test `import_key` values must be 64 chars (DB check constraint).

Otherwise the plan was executed as written. No dump of the dev DB was taken before the lock because the operation is insert-only plus part locking (no destructive change).

## Known Stubs

None.

## Threat Flags

None. T-05-20 to T-05-24 mitigated as planned; T-05-25 accepted (unencrypted local dump until Phase 12).

## Self-Check: PASSED

Created files exist; commits 4f1c683, 548910b, a38ffae are in git log.
