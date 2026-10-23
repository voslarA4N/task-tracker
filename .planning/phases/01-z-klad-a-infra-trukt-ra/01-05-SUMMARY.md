---
phase: 01-z-klad-a-infra-trukt-ra
plan: 05
subsystem: database
tags: [postgres, roles, grants, icu, phpunit]
requires: ["01-01"]
provides:
  - "app_owner/app_rw roles, DBs task_tracker and task_tracker_test via one idempotent provision.sql"
  - "Config\\Database groups default, owner, tests, tests_owner"
  - "GrantsPrivileges::grantMutable(), DevSeeder::assertAllowed(), PgTestCase (owner/rw/assertPermissionDenied)"
  - "make reset, make test-db"
affects: [01-09, phase-3, phase-5]
tech-stack:
  added: []
  patterns: ["insert-only by default privileges, UPDATE/DELETE only via grantMutable", "integration tests without CI4 DB test trait, two connections (owner, rw)"]
key-files:
  created: [docker/postgres/provision.sql, docker/postgres/init/10-provision.sh, scripts/db-reset.sh, scripts/test-db.sh, app/Database/Support/GrantsPrivileges.php, app/Database/Seeds/DevSeeder.php, tests/_support/PgTestCase.php, tests/integration/DbRolesTest.php, tests/integration/TimezoneConsistencyTest.php, tests/unit/Database/DevSeederGuardTest.php]
  modified: [app/Config/Database.php, composer.json]
key-decisions:
  - "ICU sk-SK collation used for DB creation (spike passed)"
  - "spark migrate -g owner|tests_owner also switches defaultGroup (Config\\Database constructor), because MigrationRunner creates the migrations table on the default connection"
requirements-completed: [NFR-07]
duration: ~35 min
completed: 2026-10-23
---

# Phase 1 Plan 05: DB roles, test DB and reset Summary

app_rw is insert-only by default (SELECT, INSERT), proven by 11 PHPUnit tests under the app_rw connection against a separate `task_tracker_test` DB; `make reset` and `make test-db` work and DevSeeder refuses to run in production.

## ICU spike result (for 01-10 / PROJECT.md)

Run in isolated compose project `tt-probe`:
`CREATE DATABASE icu_probe LOCALE_PROVIDER icu ICU_LOCALE 'sk-SK' LOCALE 'C.UTF-8' TEMPLATE template0` succeeded (PG 18 alpine);
`SELECT 'Č' > 'C' AND 'Č' < 'D' AS cz_ok, 'ch' > 'h' AS ch_after_h` returned `cz_ok = t`, `ch_after_h = t`.
So provision.sql creates DBs with ICU `sk-SK` + `LOCALE 'C.UTF-8'`. Init on a clean volume (tt-probe) created roles `app_owner,app_rw` and both DBs (count 2); project and volume removed afterwards, dev volume untouched.

## Tasks
1. provision.sql, init script, db-reset.sh, test-db.sh: `cbf9229`
2. RED tests `7072c34`, GREEN groups/trait/seeder `68bf72c`

## Deviations from Plan
**1. [Rule 3 - Blocking] `test:migrate` no longer sets `CI_ENVIRONMENT=testing`**
- spark in testing env crashes with undefined `SUPPORTPATH` (only defined by the PHPUnit bootstrap). The `tests_owner` group works in any environment, so `@putenv` was removed from composer.json.

**2. [Rule 1 - Bug] migrations table created by wrong role**
- CI4 `spark migrate -g X` only filters migrations; `MigrationRunner` creates `migrations` on the default group (app_rw -> permission denied). `Config\Database::__construct` now switches `defaultGroup` to `owner`/`tests_owner` when argv is `migrate* -g owner|tests_owner`. Migration classes should therefore always be run with `-g owner` / `-g tests_owner` (the Makefile already does).

**3. Minor:** `pg_default_acl` acceptance query needs `defaclobjtype::text`; result `r:app_rw=ar/app_owner`, `S:app_rw=rU/app_owner`.

## Notes for later plans
- Dev DB was dropped/recreated by `make reset` and test DB by `make test-db` (dev data only).
- Migrations: use trait `App\Database\Support\GrantsPrivileges` and `grantMutable('table')` only for non-document tables; no TRUNCATE ever for app_rw.
- Integration tests extend `Tests\Support\PgTestCase`; call `sh scripts/test-db.sh` (or `make test-db`) before them. Full suite: 65 tests OK; `make smoke` = SMOKE OK.
- Collation sk-SK is the DB default, so ordering of diacritics works without `COLLATE`.

## Known Stubs
DevSeeder inserts no data in phase 1 (intentional; Phase 3+ adds samples).

## Self-Check: PASSED
Commits cbf9229, 7072c34, 68bf72c exist; created files present; PHPUnit 65 tests OK; make smoke OK.
