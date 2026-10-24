---
phase: 02-identita-a-skor-nasadenie
plan: 16
subsystem: auth
tags: [trusted-device, cookie, session, idle-timeout, auth-epoch]
requires: [02-13, 02-14, 02-15]
provides: [trusted_devices-table, TrustedDeviceService, DeviceLogin-filter, AuthGate-session-checks, remember-checkbox]
affects: [02-17, 02-18, 02-19]
key-files:
  created:
    - app/Database/Migrations/2026-10-24-000007_CreateTrustedDevices.php
    - app/Libraries/Auth/TrustedDeviceService.php
    - app/Filters/DeviceLogin.php
    - tests/feature/Auth/TrustedDeviceTest.php
    - tests/feature/Auth/IdleTimeoutTest.php
  modified:
    - app/Filters/AuthGate.php
    - app/Config/Filters.php
    - app/Config/Session.php
    - app/Controllers/Auth/TwoFactor.php
    - app/Controllers/Auth/Login.php
    - app/Views/auth/totp_verify.php
    - app/Views/auth/backup_verify.php
    - app/Views/auth/backup_codes.php
    - app/Language/sk/TwoFactor.php
    - app/Language/en/TwoFactor.php
    - app/Language/sk/Login.php
    - app/Language/en/Login.php
    - public/assets/css/app.css
    - tests/_support/AuthTestCase.php
key-decisions:
  - "O-1 = revoke: explicit logout revokes the current trusted device (reason `logout`) and deletes its cookie"
  - "O-5 = yes: remember checkbox also on the backup-codes confirm step"
  - "rotate() returns '' when the presented token is already the previous one, so concurrent requests inside the 60 s grace never rotate twice"
duration: 45min
completed: 2026-10-24
---

# Phase 02 Plan 16: Trusted devices Summary

**A device remembered at the code step logs in with `__Host-wt_device` for a fixed 30 days (no sliding expiry, token rotated with 60 s grace, only sha256 in DB), AuthGate re-checks auth epoch, device revocation and 2 h idle on every request, and logout revokes the device.**

## Commits
- e128ebf: test(02-16) RED
- d6f922e: feat(02-16) migration, TrustedDeviceService, DeviceLogin, AuthGate checks, Session 30 d
- e4d38be: feat(02-16) completeLogin remember, logout O-1, checkboxes, translations

## What was built
- `trusted_devices` (mutable for app_rw), `TrustedDeviceService` per the plan contract; `findValid` also returns `expires_ts` (extra key). 30 days are added as seconds, not `+30 days`, so DST cannot shift the expiry.
- `DeviceLogin` runs first in globals before; logs in from a valid cookie, drops stale TOTP action identity, rotates the token, audits `device_login`. Invalid cookie is cleared. Its `after` copies cookies from the shared response to `redirect()` responses (CI4 does not do this, rotation would otherwise be lost).
- `AuthGate`: epoch mismatch, inactive device (also clears cookie), 2 h idle for untrusted sessions (audit `idle_logout`, flash `Login.infoIdleLogout`); `last_activity` written at most once per 60 s.
- `TwoFactor::completeLogin($user, $remember)`; `verify`, `backup` and the codes confirm step pass the `remember` post value; e-mail `DEVICE_TRUSTED` and audit `device_trusted`.
- Checkbox (checked by default, 44 px target CSS) on `/2fa/verify`, `/2fa/backup`, `/2fa/backup-codes`; `Session::$expiration = 2592000`.

## Deviations from Plan
- [Rule 3] `AuthTestCase::loginAs()` now calls `withSession()` (Shield caches the user in the authenticator, tests previously passed without a session; the new gate needs real session keys).
- [Rule 1] `users.active` is smallint in this schema, query uses `= 1`.
- [Rule 1] `DeviceLogin::after` and `withCookies()` in the AuthGate redirect added after the tests showed cookies set in `before` were lost on `redirect()` responses.
- Max-Age assertion: CI4 `Cookie::getMaxAge()` uses real time and the project PHPStan rule forbids `Time::setTestNow`, so the test asserts `Expires` = T0 + 30 d and that `Max-Age=` is present.
- `rotate()` returns an empty string (no rotation) when the old token is only within grace; interface type stays `string`.

## Verification
`composer check` green (297 tests, PHPStan, cs). No browser or staging smoke run (T-02-16-07 session fixation via Shield `login()` is untested because it regenerates only outside `testing`).

## Known Stubs
None. Device list and revoke UI is 02-17; epoch bump on password change and 2FA reset is 02-18/02-19.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
