---
phase: 02-identita-a-skor-nasadenie
plan: 19
subsystem: auth
tags: [settings, password-change, backup-codes, reauth, lockout]
requires: [02-15, 02-17, 02-18]
provides: [Settings-changePassword, Settings-regenerateCodes, password-card, codes-page]
affects: []
key-files:
  created:
    - app/Views/settings/_password.php
    - app/Views/settings/backup_codes.php
    - tests/feature/Settings/ChangePasswordTest.php
    - tests/feature/Settings/RegenerateCodesTest.php
  modified:
    - app/Controllers/Settings/Security.php
    - app/Config/Routes/settings.php
    - app/Views/settings/security.php
    - app/Views/settings/_twofactor.php
    - app/Language/sk/Account.php
    - app/Language/en/Account.php
    - tests/feature/Settings/SecurityPageTest.php
key-decisions:
  - "reauthenticate() takes a third argument ($form) so the error is rendered in the right card (password or codes)"
  - "After a password change the current session stays, but device_id is dropped and auth_epoch refreshed so it is no longer treated as a trusted device"
duration: 35min
completed: 2026-10-24
---

# Phase 02 Plan 19: Password change and code regeneration Summary

**Settings > Security now lets the owner change the password and regenerate backup codes, both gated by the current password (wrong attempts feed the account lockout, audit and lockout e-mail); a password change keeps only the current session and revokes all other sessions and trusted devices.**

## Commits
- bdb3cb5: test(02-19) RED
- a009b10: feat(02-19): actions, views, routes, translations (tasks 2 and 3 committed together because they share one controller and one test run)

## What was built
- `Security::changePassword` (validation, reauth, `AccountRecovery::setPassword(..., 'settings')`, epoch refresh, expired device cookie, flash notice).
- `regenerateCodes` / `showCodes` / `codesTxt` / `confirmCodes`: codes held in session slot `settings_codes_display` (Sodium-encrypted), shown once, `.txt` with `no-store`, acknowledgement required, slot deleted afterwards; audit and `BACKUP_CODES_REGENERATED` e-mail.
- Password card first on the page, regenerate button plus password modal in the 2FA card, sk/en strings.

## Deviations from Plan
- [Rule 3] `SecurityPageTest` CSRF-token count updated from 2 to 4 (new password form and regenerate modal each carry a token).
- [Rule 1] PHPStan: `service('passwords')` is not a known service, so `new Passwords(config(Auth::class))` is used; the post-failure lock re-check uses a fresh clock call to avoid a false "always null" narrowing.
- Tasks 2 and 3 were committed as one feat commit.

## Verification
`composer check` green (335 tests, PHPStan, cs); `node --test tests/js` green. No browser or staging smoke.

## Known Stubs
None.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
