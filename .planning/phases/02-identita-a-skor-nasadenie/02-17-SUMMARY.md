---
phase: 02-identita-a-skor-nasadenie
plan: 17
subsystem: auth
tags: [settings, security-page, trusted-devices, audit-log, ui]
requires: [02-10, 02-15, 02-16]
provides: [More-page, Settings-Security-page, AuditLog-latestForUser, Account-lang, device-revoke-route]
affects: [02-19]
key-files:
  created:
    - app/Config/Routes/settings.php
    - app/Controllers/More.php
    - app/Controllers/Settings/Security.php
    - app/Views/more/index.php
    - app/Views/settings/security.php
    - app/Views/settings/_twofactor.php
    - app/Views/settings/_devices.php
    - app/Views/settings/_events.php
    - app/Language/sk/Account.php
    - app/Language/en/Account.php
    - tests/feature/Settings/SecurityPageTest.php
  modified:
    - app/Libraries/Auth/AuditLog.php
    - app/Views/layouts/_nav_items.php
    - public/assets/css/app.css
    - tests/feature/Auth/AuthGateTest.php
key-decisions:
  - "Times are formatted with IntlDateFormatter from a unix timestamp obtained via service('clock')->now()->modify(), because PHPStan forbids strtotime and new DateTimeImmutable outside Clock"
  - "Mobile event list is cards (data-event-row), the table from 768 px has no data-event-row so rows are not counted twice"
duration: 30min
completed: 2026-10-24
---

# Phase 02 Plan 17: Security page Summary

**Viac in the tab bar opens a More page; Settings > Security shows 2FA state with remaining backup codes, trusted devices with a confirm-modal revoke (revoking the current device logs out and clears the cookie), and the last 50 sign-in events as cards on mobile and a table from 768 px.**

## Commits
- 59015cd: test(02-17) RED
- e945d66: feat(02-17) More, Security page, AuditLog::latestForUser, translations, CSS
- 380f237: feat(02-17) revokeDevice, cs fixes, test fixes

## What was built
- Routes `GET more`, `GET settings/security`, `POST settings/security/devices/(:num)/revoke`.
- `Security::pageData(User)` ready for 02-19 to add the password card and actions; view includes `_twofactor`, `_devices`, `_events` (02-19 adds `_password` first).
- `AuditLog::latestForUser()` returns only time, event, success, ip, device label (no hash, UA, detail).
- `revokeDevice`: foreign or already revoked id gives 404, audit `device_revoked` (detail `user`), flash `app_notice` or `auth_info` plus expired cookie when current.
- sk/en `Account.php` with all 20 `event_*` keys.

## Deviations from Plan
- [Rule 3] `tests/feature/Auth/AuthGateTest.php`: the every-route-denied test now maps the `([0-9]+)` placeholder (CI4 expands `(:num)`), otherwise the new parametrised POST route broke it.
- [Rule 1] PHPStan forbids `strtotime`; formatting goes via the clock instead.
- In feature tests `PageNotFoundException` propagates, so the IDOR test uses try/catch instead of `assertStatus(404)`.
- Test group for the second user is `accountant` (provisioner allows only admin/accountant).

## Verification
`composer check` green (307 tests, PHPStan, cs). No browser or staging smoke.

## Known Stubs
None. Password change and backup-code regeneration on this page are 02-19.

## Threat Flags
None beyond the plan threat model.

## Self-Check: PASSED
