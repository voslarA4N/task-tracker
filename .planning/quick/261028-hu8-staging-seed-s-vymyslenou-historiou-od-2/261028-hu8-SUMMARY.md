---
phase: quick-261028-hu8
plan: 01
subsystem: staging-ops, seed
tags: [staging, seed, deploy, determinism, invoicing]
requires: [InvoiceIssuer, EntityService, ClientService, TaskService, AllocationService, UserProvisioner, TotpService]
provides: [spark staging:seed, STAGING_RESEED deploy step, seed compose service, SeedModule extension point]
affects: [scripts/deploy.sh, compose.server.yaml, docs/ops/server.md]
key-files:
  created:
    - app/Config/Staging.php
    - app/Libraries/StagingSeed/ (SeedGuard, SeedRefused, SeedAdminCredentials, SeedClock, Profile, Catalog, HistoryPlanner, HistoryPlan, SeedContext, SeedModule, StagingSeeder, Modules/AdminUser, MasterData, Tasks, Invoices)
    - app/Commands/StagingSeed.php
    - tests/unit/StagingSeed/{SeedGuardTest,SeedAdminCredentialsTest,HistoryPlannerTest}.php
    - tests/integration/StagingSeed/StagingSeederTest.php
  modified:
    - app/Libraries/Platform/Clock.php (parseDateTime)
    - scripts/deploy.sh, scripts/db-reset.sh, compose.server.yaml, Makefile, .env.example, docs/ops/server.md
    - tests/unit/Scripts/{DeployGuardTest,StagingSecretsLayoutTest,DbResetGuardTest}.php
decisions:
  - D-01..D-05 implemented as planned
  - seed runs as app_rw (default group), owner password stays only in migrate.env
  - pre-migration backup kept on staging
metrics:
  tasks: 3
  completed: 2026-10-28
---

# Quick 261028-hu8: Staging seed with fictional history Summary

Deterministic staging seed (3 entities, 8 clients, ~157 tasks, 41 invoices Jan..Sep 2026 issued through the real InvoiceIssuer with PDFs/report/QR) that is rebuilt on every staging deploy behind several independent production guards.

## What was built

- **Task 1 (d8e42c0):** `Config\Staging` flag, `SeedGuard` (flag, production-host deny-list, pristine DB), `SeedAdminCredentials` (file parse without touching process env, dev fallback), `SeedClock` + `Clock::parseDateTime`, `Profile` (full/small), `Catalog` (fixed fictional data, public test IBANs), pure `HistoryPlanner` (seeded `Mt19937`, no Faker, no wall clock). 26 unit tests.
- **Task 2 (89d4984):** `SeedModule`/`SeedContext`/`StagingSeeder::MODULES` (AdminUser, MasterData, Tasks, Invoices), `spark staging:seed [--profile=full|small]`, integration test as app_rw on the test DB.
- **Task 3 (4777762):** `deploy.sh` guarded `STAGING_RESEED=1` step (DROP + provision + migrate + seed before `up`), one-off `seed` compose service, `make reset-staging`, docs section 19 incl. how-to-add-a-module, script tests.

## Verification (final state)

- `make test`: OK (1326 tests, 22266 assertions)
- `make stan`: OK (0 errors, both configs plus rule self-test)
- `make cs`: OK (0 of 480 files to fix)
- `node --test tests/js/`: 51 pass, 0 fail
- Full profile seed measured in the integration test environment: about 15 s total (admin 0.2 s, master 0.1 s, tasks 0.6 s, invoices 14.9 s). Counts: entities 3, clients 8, tasks 157, billing_allocations 179, invoices 41, invoice_documents 82. Well within the ~60 s budget.
- Determinism covered by unit test (identical serialized plan, differing seed differs).

## Deviations from Plan

**1. [Rule 3 - Blocker] Time measurement and date construction restricted by ForbiddenCallsRule**
- `new DateTimeImmutable`, `microtime`, `hrtime` are forbidden outside Clock. Added `Clock::parseDateTime()` (allowed file) and used `new Clock()` (real time) in the seeder for module timings via `format('Uv')`. SeedClock and the planner use only Clock helpers.

**2. [Rule 2 - Correctness] writable/ ownership after the seed container run**
- The seed container runs as root; deploy.sh runs `sh -c 'php spark staging:seed; rc=$?; chown -R www-data:www-data writable; exit $rc'` instead of the bare command so mPDF temp/font cache and logs stay writable for php-fpm. The compose service `command` is still `php spark staging:seed`.

**3. [Rule 1 - Bug in own plan] Partial invoices could be empty**
- A partial invoice needs at least one part before the 15th. The planner now forces the first task of a partial month to be started and booked before the 14th.

**4. Backup codes not generated**
- No post-login flow forces backup codes and generating them needs `auth.backupCodePepper`; seed skips them (documented).

**5. Added a full-profile integration test** (about 15 s extra) so the shipped profile is proven to issue every planned invoice (ZBP limits, SK entity version 2026-07-01).

`injectMock('clock', ...)` is honored outside the testing environment (BaseService::getSharedInstance checks `$mocks` first), so no Services override hook was needed.

## Limits of verification (please note)

- The `spark staging:seed` command was exercised against the dev DB only to confirm the guard: it ran as app_rw with `staging.seed=true` and was refused with `not_pristine` (dev DB has data, untouched). A full end-to-end run through the command against a second database was not possible because the container `.env` takes precedence over `-e database.default.database`; the same code path is covered by the integration test (app_rw, test DB). `make reset-staging` was NOT run (would wipe the owner's dev DB).
- `deploy.sh` is verified only with the fake-docker script tests (order, refusals, regression, seed failure), not against a real server. Behaviors not provable locally: `dc run seed` with profile on a real compose, `find -perm /077` on the server, cap on time budget in CI.
- Dev `.env` has no `SEED_ADMIN_*` yet; the owner must add them before `make reset-staging` (template in `.env.example`).

## Orchestrator runbook (not executed by me)

See the plan's "Outward-facing runbook": create `secrets/seed_admin.env` on the staging server (docs/ops/server.md section 19), add `STAGING_RESEED = 1` to staging `.env` and `staging.seed = true` to staging `app.env`, verify production has neither, push, open PR to develop, verify the CI log and the seeded staging site.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-hu8-01..07 are mitigated and covered by tests).

## Self-Check: PASSED

- Files exist: app/Libraries/StagingSeed/StagingSeeder.php, app/Commands/StagingSeed.php, tests/integration/StagingSeed/StagingSeederTest.php, scripts/deploy.sh, compose.server.yaml.
- Commits exist: d8e42c0, 89d4984, 4777762.
