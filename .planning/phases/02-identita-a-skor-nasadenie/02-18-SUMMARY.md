---
phase: 02-identita-a-skor-nasadenie
plan: 18
subsystem: auth
tags: [password-reset, account-recovery, spark, 2fa-reset]
requires: [02-16]
provides: [password_resets-table, PasswordResetService, AccountRecovery, PasswordReset-controller, user-password-command, user-reset-2fa-command, ReadsSecretTrait]
affects: [02-19]
key-files:
  created:
    - app/Database/Migrations/2026-10-24-000008_CreatePasswordResets.php
    - app/Libraries/Auth/PasswordResetService.php
    - app/Libraries/Auth/AccountRecovery.php
    - app/Controllers/Auth/PasswordReset.php
    - app/Config/Routes/password.php
    - app/Views/auth/forgot.php
    - app/Views/auth/reset.php
    - app/Views/emails/password_reset.php
    - app/Language/sk/PasswordReset.php
    - app/Language/en/PasswordReset.php
    - app/Commands/ReadsSecretTrait.php
    - app/Commands/UserPassword.php
    - app/Commands/UserReset2fa.php
    - tests/integration/Auth/RecoveryTest.php
    - tests/feature/Auth/PasswordResetTest.php
  modified:
    - app/Commands/UserCreate.php
    - app/Views/auth/login.php
key-decisions:
  - "A weak password never consumes the token: the controller validates via AccountRecovery::assertPasswordAcceptable before PasswordResetService::consume"
  - "setPassword audits inside the DB transaction, e-mail is sent only after commit"
duration: 40min
completed: 2026-10-24
---

# Phase 02 Plan 18: Password reset and recovery Summary

**E-mail password reset with a 256-bit single-use 60-minute token (sha256 only in DB, atomic consume) that never logs the user in and revokes all trusted devices and sessions, plus `spark user:password` and `spark user:reset-2fa` as the only emergency paths.**

## Commits
- 3d980c8: test(02-18) RED
- b617647: feat(02-18) migration, services, AccountRecovery, spark commands
- 26b1a8f: feat(02-18) web reset, views, translations, login link

## What was built
- `password_resets` (mutable for app_rw); `PasswordResetService::{request,findValid,consume}` per contract; a new request deletes the user's older unused tokens.
- `AccountRecovery::setPassword` (source reset|cli|settings, one transaction: Shield save, bumpEpoch, revokeAll, delete unused resets, audit; then notification) and `resetTwoFactor` (clears TOTP columns, backup codes, devices, epoch, `totp_gateway` identity, audit `totp_reset_cli`, notice). Added `assertPasswordAcceptable` (used by controller and setPassword). 02-19 can call `setPassword(..., 'settings', ...)` directly.
- `/password/forgot` always answers identically; `/password/reset/<token>` GET/POST; after success redirect to `/login` with flash `auth_info`, any session of that user is logged out, no login()/checkAction.
- `ReadsSecretTrait` extracted from `UserCreate`.

## Deviations from Plan
- [Rule 1] The planned test "unknown e-mail without CSRF token" was written with CSRF (CSRF is global); the equality check strips 64-hex tokens (CSRF/header) before comparing bodies.
- [Rule 1] PHPStan: `$source` documented as string (union type made `in_array` always-true); test uses `new Passwords(config(Auth::class))` instead of `service('passwords')`.

## Verification
`composer check` green (323 tests, PHPStan, cs). Dev smoke: `user:create`, `user:password`, `user:reset-2fa` worked on a temporary user (deleted afterwards); Mailpit received the "zmena hesla" and "reset 2FA" notices. The web reset e-mail with link was verified by tests (MockEmail), not in Mailpit/browser. nginx `limit_req` on `/password*` (T-02-18-08) is 02-04 and was not re-verified.

## Notes for orchestrator (staging runbook, do not edit docs/ops/staging.md here)
- Staging has mail transport `log`, so web reset sends nothing. Use: `docker compose exec php php spark user:password <email>` (interactive, hidden prompt, min 12 chars, revokes trusted devices) and `php spark user:reset-2fa <email> [--yes]` (2FA is set up again at next login).
- Passwords containing the e-mail local part are rejected by Shield (use unrelated passwords).

## Known Stubs
None.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
