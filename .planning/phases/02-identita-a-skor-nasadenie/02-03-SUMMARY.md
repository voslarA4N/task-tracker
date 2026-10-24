---
phase: 02-identita-a-skor-nasadenie
plan: 03
subsystem: auth
tags: [shield, totp-gateway, argon2id, spark, grants]
requires: [02-01]
provides: [shield-pending-state, TotpAction, UserProvisioner, user-create-cli, user_security, groups-admin-accountant]
affects: [02-12, 02-14, 02-15, 02-16]
tech-stack:
  added: [codeigniter4/shield 1.4.1, codeigniter4/settings 2.4.0, spomky-labs/otphp 11.5.0, bacon/bacon-qr-code 3.1.1, psr/clock 1.0.0, dasprid/enum 1.0.7, paragonie/constant_time_encoding 3.1.3]
  patterns: [Shield login action as 2FA gateway, array-only Settings handler]
key-files:
  created:
    - app/Authentication/Actions/TotpAction.php
    - app/Config/Auth.php
    - app/Config/AuthGroups.php
    - app/Config/Settings.php
    - app/Libraries/Auth/UserProvisioner.php
    - app/Commands/UserCreate.php
    - app/Database/Migrations/2026-10-24-000001_GrantShieldTables.php
    - app/Database/Migrations/2026-10-24-000002_CreateUserSecurity.php
    - tests/integration/Auth/ShieldPendingStateTest.php
    - tests/integration/Auth/RolesTest.php
    - tests/unit/Config/AuthConfigTest.php
  modified: [composer.json, composer.lock, app/Config/Autoload.php, app/Config/Session.php, app/Database/Support/GrantsPrivileges.php, tests/integration/DbRolesTest.php]
key-decisions:
  - "Spike passed: TOTP hangs on Shield as a login action (ConditionalActionInterface), no fallback B/C needed"
  - "Settings handler is array only, Shield reads Config values without DB reads"
duration: 50min
completed: 2026-10-24
---

# Phase 02 Plan 03: Shield spike and identity foundation Summary

**Shield 1.4.1 runs on PG 18 under app_rw; a correct password leaves the session pending and only `checkAction` with the `totp_gateway` nonce completes login. Accounts are created only via `spark user:create` (interactive password, Argon2id).**

## Spike result

All six criteria passed with no fallback: (1) migrations as owner plus grants let app_rw do identity update/delete, group add and user_security updates while `auth_logins` stays insert-only; (2) after password `loggedIn()` is false and `isPending()` true; (3) only `checkAction` with the right nonce logs in (wrong nonce stays pending, identity removed after success); (4) logout then new attempt works; (5) `login()` works after stale identity cleanup; (6) the provisioner and `spark user:create` run as app_rw.

## Package diff check

`composer.lock` gained exactly 7 packages: shield, settings, otphp, bacon-qr-code, psr/clock, dasprid/enum, paragonie/constant_time_encoding. This matches the approved table from 02-01 (`symfony/deprecation-contracts` was already installed). Nothing unexpected. `spark routes` shows no Shield or register routes.

## Commits

- 6adb95a test(02-03): failing spike tests (RED)
- e517b27 feat(02-03): Shield install, config, TotpAction, migrations, UserProvisioner
- a05e857 feat(02-03): spark user:create

## Deviations from Plan

1. **[Rule 3 - Blocking] Stale php container bind mount.** The php container saw an empty project directory; recreated with `docker compose up -d --force-recreate php`. No file changes.
2. **[Rule 1 - Bug] Test password.** `Spike-Heslo-2026!` is rejected by NothingPersonalValidator for `spike@example.test`; tests use `Zelena-Luka-2026!`.
3. **[Rule 1 - Bug] Test SQL.** Shield `users.active` and `auth_logins.success` are smallint (not boolean) and `auth_logins.id_type` is NOT NULL; DbRolesTest adjusted.
4. **UserProvisioner created in Task 2 commit** (not Task 3) because the spike tests depend on it. Task 3 commit holds `UserCreate`.
5. **[Rule 3 - Blocking] PHPStan.** Added `assert($this->db instanceof BaseConnection)` in `GrantsPrivileges` and the new migration, phpdoc `@return RedirectResponse` in TotpAction, and typed authenticator helper in tests. `UserProvisioner` builds `Passwords` directly (`new Passwords(config(Auth::class))`) instead of `service('passwords')`.
6. The dev DB was truncated (owner) after the manual `user:create` check, so it is clean.

## Verification

`composer check` (stan, cs, full phpunit: 154 tests) is green. Manual: `printf` pipe created the user with EXIT 0, the repeat returned EXIT 1 ("už existuje"), and a missing `--admin/--accountant` flag gives a usage error.

## Known Stubs

None. `TotpAction::show/handle/verify` redirect to `/2fa`, which a later plan (02-12 onward) implements by design.

## Threat Flags

None beyond the plan threat model.

## Self-Check: PASSED
