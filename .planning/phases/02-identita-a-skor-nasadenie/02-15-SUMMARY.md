---
phase: 02-identita-a-skor-nasadenie
plan: 15
subsystem: auth
tags: [backup-codes, hmac, pepper, 2fa]
requires: [02-12, 02-14]
provides: [BackupCodeService, backup_codes-table, backup-codes-routes, app_notice-flash, _backup_codes_grid]
affects: [02-16, 02-18, 02-19]
key-files:
  created:
    - app/Database/Migrations/2026-10-24-000006_CreateBackupCodes.php
    - app/Libraries/Auth/BackupCodeService.php
    - app/Views/auth/backup_codes.php
    - app/Views/auth/_backup_codes_grid.php
    - app/Views/auth/backup_verify.php
    - tests/integration/Auth/BackupCodeTest.php
    - tests/feature/Auth/BackupCodesFlowTest.php
  modified:
    - app/Controllers/Auth/TwoFactor.php
    - app/Config/Auth.php
    - app/Config/Routes/auth.php
    - app/Views/auth/totp_verify.php
    - app/Views/layouts/app.php
    - app/Language/sk/TwoFactor.php
    - app/Language/en/TwoFactor.php
    - public/assets/css/app.css
    - public/assets/js/app.js
    - .env.example
    - .github/workflows/ci.yml
    - tests/feature/Auth/TotpSetupTest.php
key-decisions:
  - "O-2 = 10: code XXXXX-XXXXX, 30-char Crockford-like alphabet, random_int, HMAC-SHA256 with pepper"
  - "Remember-device checkbox (O-5) on the codes page is left to 02-16, which already specifies it"
duration: 35min
completed: 2026-10-24
---

# Phase 02 Plan 15: Backup codes Summary

**After TOTP activation the owner sees 10 single-use backup codes once (download .txt, mandatory confirmation), and can sign in with one instead of the app code; codes are stored only as peppered HMAC-SHA256 and consumed by one atomic UPDATE.**

## Commits
- e306a75: test(02-15) RED
- 7b7b5d1: feat(02-15) migration, pepper, BackupCodeService
- 52f6776: feat(02-15) UI, controller, routes, translations

## What was built
- `backup_codes` table (mutable for app_rw), `BackupCodeService` (generate, consume, remaining, deleteAll, normalize, format); empty pepper throws (fail closed).
- `Config\Auth::$backupCodePepper`, `.env.example` entry, local `.env` value, CI replaces the line with a random value.
- `TwoFactor`: `activate()` now generates codes, stores them Sodium-encrypted in session slot `backup_codes_display`, refreshes `pending_since` and redirects to `/2fa/backup-codes`; new `backupCodes`, `backupCodesTxt`, `confirmBackupCodes` (ENROL_TTL), `backup`, `checkBackup` (shared lockout; flash `app_notice` with remaining count).
- Views, Alpine `codesAck`, `.code-grid` CSS, sk/en strings, `app_notice` rendering in `layouts/app.php`.

## Deviations from Plan
- Test helper renamed `post` to `postTo` (collides with trait method); FrozenClock has no advance, so the test re-injects a shifted clock.
- CI pepper uses `sed` replacement of the existing `.env.example` line instead of appending (avoids duplicate keys).
- `lockedResponse`/`registerSecondFactorFailure` got a `$kind` parameter so backup failures re-render the backup page.
- A successful backup-code login also records `login_totp_ok` (shared `completeLogin`) in addition to `backup_code_used`.

## Verification
`composer check` green (278 tests, PHPStan, cs); `node --test tests/js` green. No browser or nginx smoke run.

## Known Stubs
None. Regeneration in Settings is 02-19; remember-device is 02-16.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
