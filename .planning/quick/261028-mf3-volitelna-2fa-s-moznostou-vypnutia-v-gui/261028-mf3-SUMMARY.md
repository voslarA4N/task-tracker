---
phase: quick-261028-mf3
plan: 01
subsystem: auth
tags: [2fa, totp, shield, settings, staging-seed]
requires: []
provides:
  - optional TOTP 2FA (first-login offer, decline persisted, enable/disable in Settings)
  - user_security.totp_declined_at
  - staging seed admin without TOTP
key-files:
  created:
    - app/Database/Migrations/2026-10-28-000003_AddTotpDeclinedToUserSecurity.php
    - app/Views/auth/totp_offer.php
    - app/Views/auth/totp_continue.php
    - app/Views/settings/totp_setup.php
    - tests/feature/Auth/TotpOfferTest.php
    - tests/feature/Settings/TwoFactorToggleTest.php
  modified:
    - app/Controllers/Auth/TwoFactor.php
    - app/Controllers/Settings/Security.php
    - app/Libraries/Auth/AccountRecovery.php
    - app/Libraries/Auth/PasswordResetService.php
    - app/Libraries/StagingSeed/*
    - docs/ops/server.md
key-decisions:
  - "2FA optional for everyone (owner 2026-10-28); gateway action still applies to all, TwoFactor::index decides verify / continue / offer"
  - "Owner override 1: password reset by e-mail allowed also for accounts without 2FA (WR-04 restriction removed)"
  - "Owner override 2: trusted device (30 days) allowed also without 2FA; DeviceLogin unchanged, offer + continue pages carry the remember checkbox"
  - "Owner override 3: disabling 2FA needs password + (TOTP code or unused backup code)"
completed: 2026-10-28
---

# Quick 261028-mf3: Optional 2FA with GUI disable Summary

TOTP 2FA is now opt-in: first login offers "Nastavit 2FA" / "Nechcem nastavovat 2FA" (choice persisted in `totp_declined_at`), 2FA can be enabled and disabled in Nastavenia -> Bezpecnost, and the staging seed admin has no TOTP.

## Commits

- 6f62ee1 feat: declined flag migration, disable/reset in AccountRecovery, seed admin without TOTP, e-mail reset for non-2FA
- d994084 feat: first-login offer, decline, password-only login (`/2fa/continue`) with remember-me
- 0d36765 docs: owner decision recorded in REQUIREMENTS, PROJECT, ROADMAP, PRD
- 878d152 feat: enable / disable 2FA in Settings -> Security
- d8c2e5f style: cs-fixer / phpstan fixes

## Owner overrides applied (differences from the plan)

1. Password reset by e-mail: `PasswordResetService` (request, findValid, consume) no longer requires active TOTP. Tests rewritten (`testAccountWithoutTotpGetsTokenAndCanReset`, `testTwoFactorResetDeletesUnusedTokens`), docs/ops/server.md WR-04 paragraph replaced, REQUIREMENTS AUTH-06 updated. Throttling, uniform response, token rules unchanged. `AccountRecovery` still deletes unused reset tokens when 2FA state changes.
2. Remember-me without 2FA: no DeviceLogin guard was added (plan task dropped). Because a declined user would otherwise complete login automatically with no form, a small page `/2fa/continue` ("Dokoncit prihlasenie", remember checkbox checked by default) was added for declined users; the offer page decline form also has a remember checkbox (unchecked). Disabling or enabling 2FA still revokes all devices.
3. Disable: password + TOTP or unused backup code, as in the plan.

## Other notes

- Gateway stays uniform (`TotpAction::appliesTo` true): single completion path `completeLogin()`; new events `totp_declined`, `login_no_totp`, `totp_disabled`; mails `totp_enabled`, `totp_disabled`.
- `spark user:reset-2fa` clears the declined flag (offer reappears), message updated.
- Seed: `SEED_ADMIN_TOTP_SECRET` ignored, `totpSecret` removed, docs and `.env.example` updated.
- Plan task "DeviceLogin refuses device without TOTP" and its TrustedDeviceTest were intentionally not implemented (override 2); existing TrustedDeviceTest already covers non-2FA users.
- `SecurityPageTest` device test adjusted (user enrolled; form count 5 incl. disable modal).

## Deviations

None beyond the owner overrides (auto-fixes: phpstan array docblock, unused constant, cs-fixer style).

## Verification

- `make test`: OK (1356 tests, 22400 assertions) (run before the final style-only commit; affected suites re-run green afterwards: 88 tests)
- `make stan`: No errors
- `make cs`: 0 of 483 files to fix
- `node --test tests/js/`: 51 pass, 0 fail

## Known Stubs

None.

## Threat Flags

None new. Note: password-only accounts can now use e-mail reset and 30-day trusted device (owner-accepted).

## Self-Check: PASSED
