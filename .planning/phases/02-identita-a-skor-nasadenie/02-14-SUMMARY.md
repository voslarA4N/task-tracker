---
phase: 02-identita-a-skor-nasadenie
plan: 14
subsystem: auth
tags: [totp, replay-protection, lockout, 2fa-verify]
requires: [02-10, 02-12]
provides: [2fa-verify-route, registerSecondFactorFailure, lockedResponse, auth/totp_verify-view]
affects: [02-15, 02-16]
key-files:
  created:
    - app/Views/auth/totp_verify.php
    - tests/integration/Auth/TotpReplayTest.php
    - tests/feature/Auth/TotpVerifyTest.php
  modified:
    - app/Controllers/Auth/TwoFactor.php
    - app/Config/Routes/auth.php
    - app/Language/sk/TwoFactor.php
    - app/Language/en/TwoFactor.php
key-decisions:
  - "Wrong codes (incl. malformed) share the 5/15 min lockout with passwords; one generic message for wrong, reused and old-step codes"
  - "Helpers take an optional setup secret so activate() and check() share one failure path"
duration: 25min
completed: 2026-10-24
---

# Phase 02 Plan 14: TOTP verification Summary

**`/2fa/verify` logs the owner in with a six-digit code after the password; the step is claimed by one atomic UPDATE so a code cannot be reused (also across connections), and bad codes feed the shared lockout with audit and e-mail.**

## Commits
- f7e35ca: test(02-14) RED
- b0cdfe5: feat(02-14) verify/check, view, translations, routes

## What was built
- `TwoFactor::verify` / `check` (matchStep, then claimStep, then completeLogin), `registerSecondFactorFailure`, `lockedResponse`, `failurePage`; `activate()` now uses the same helpers.
- View `auth/totp_verify` (one-time-code input, verify button, sign-out form); sk/en strings.
- Tests: replay claim (monotonic, second `app_rw` connection, no TOTP) and 13 feature tests.

## Deviations from Plan
- [Rule 1] `verify`/`check` redirect an already logged-in user to `/` explicitly (pendingUserOrRedirect would send them to `/login`).
- Plan interface signatures got an optional `$setupSecret` parameter so the setup page can be re-rendered on failure.

## Verification
`composer check` green (258 tests, PHPStan, cs). No browser or nginx smoke run.

## Known Stubs
None (backup code link is 02-15, remember device is 02-16).

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
