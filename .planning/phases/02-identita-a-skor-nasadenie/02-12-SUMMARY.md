---
phase: 02-identita-a-skor-nasadenie
plan: 12
subsystem: auth
tags: [totp, otphp, sodium, qr-svg, shield-action, enrolment]
requires: [02-10, 02-11]
provides: [TotpService, PsrClock, TwoFactor-setup, totp-columns, Sodium-encryption, AuthTestCase-enrollTotp]
affects: [02-14, 02-15, 02-16, 02-18]
key-files:
  created:
    - app/Database/Migrations/2026-10-24-000005_AddTotpToUserSecurity.php
    - app/Libraries/Platform/PsrClock.php
    - app/Libraries/Auth/TotpService.php
    - app/Controllers/Auth/TwoFactor.php
    - app/Views/auth/totp_setup.php
    - app/Language/sk/TwoFactor.php
    - app/Language/en/TwoFactor.php
    - tests/unit/Auth/TotpServiceTest.php
    - tests/unit/Config/EncryptionConfigTest.php
    - tests/feature/Auth/TotpSetupTest.php
  modified:
    - app/Config/Encryption.php
    - app/Config/Routes/auth.php
    - public/assets/css/app.css
    - public/assets/js/app.js
    - .env.example
    - .github/workflows/ci.yml
    - tests/_support/AuthTestCase.php
key-decisions:
  - "Code match is a custom loop over steps -1/0/+1 with hash_equals (otphp leeway is in seconds); never verify()"
  - "Encrypter is created per call (SodiumHandler zeroes its key after use); stored value is base64 of the secretbox"
  - "Enrolment window 30 min (ENROL_TTL), normal 2FA window 10 min (PENDING_TTL)"
duration: 40min
completed: 2026-10-24
---

# Phase 02 Plan 12: TOTP enrolment Summary

**After the password the owner gets a locally generated inline SVG QR plus a manual secret, activates 2FA with the first code, the secret is stored Sodium-encrypted, and the Shield `totp_gateway` action is completed so the owner is logged in.**

## Commits
- ba0a6bb test(02-12) RED
- b67def2 feat(02-12) Sodium, PsrClock, TotpService, migration
- f4b559c feat(02-12) TwoFactor setup

## What was built
- Migration adds `totp_secret_enc`, `totp_enabled_at`, `totp_last_step` to `user_security` (applied to dev and test DB).
- `Config\Encryption` driver is Sodium; key comes from `.env` (`key:generate`, CI generates its own random key).
- `TotpService`: `newSecret`, `provisioningUri`, `qrSvg`, `matchStep` (replay floor via `lastStep`), `claimStep` (atomic UPDATE for 02-14), `encryptSecret`/`decryptSecret`, `formatSecretForDisplay`.
- `TwoFactor` controller: `index`, `setup`, `activate`, private `pendingUserOrRedirect(maxAge)` and `completeLogin()` (checkAction, session regenerate outside testing, lockout clear, audit). Wrong codes feed the 5/15 lockout shared with the password and are audited.
- View with `role="img"` QR box, grouped secret, copy button (Alpine CSP component `copySecret`), one-time-code input; CSS and sk/en translations.
- `AuthTestCase::enrollTotp()` and `totpCode()` helpers.

## Deviations from Plan
- [Rule 1] otphp 11.5 `setLabel/setIssuer` are deprecated (PHPStan); used `withLabel/withIssuer`.
- [Rule 3] Feature tests start with a blank session, so the test class uses `withSession()` before each request to carry the pending state; test helper `count()` renamed (final in PHPUnit).
- Controller strips whitespace from the entered code before matching (so "123 456" works); the service itself still rejects spaces as specified.
- `LockoutService::clear` is called with the e-mail of the pending user in `completeLogin`.

## Verification
`composer check` green (237 tests, PHPStan, cs), `node --test tests/js` green. The `.env` key was generated locally (not versioned). No stack smoke run against nginx; the browser flow login -> QR -> activation was covered by feature tests only.

## Known Stubs
None. `/2fa/verify` (used for users who already have TOTP) is a redirect target implemented by 02-14, and backup codes by 02-15.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
