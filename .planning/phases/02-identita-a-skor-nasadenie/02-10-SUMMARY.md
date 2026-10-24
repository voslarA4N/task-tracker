---
phase: 02-identita-a-skor-nasadenie
plan: 10
subsystem: auth
tags: [lockout, audit-log, brute-force, insert-only]
requires: [02-05, 02-08]
provides: [LockoutService, AuditLog, AuthEvent, DeviceLabel, auth_lockouts, auth_events]
affects: [02-12, 02-14, 02-15, 02-16, 02-18, 02-19]
key-files:
  created:
    - app/Database/Migrations/2026-10-24-000003_CreateAuthLockouts.php
    - app/Database/Migrations/2026-10-24-000004_CreateAuthEvents.php
    - app/Libraries/Auth/AuthEvent.php
    - app/Libraries/Auth/AuditLog.php
    - app/Libraries/Auth/DeviceLabel.php
    - app/Libraries/Auth/LockoutService.php
    - tests/integration/Auth/LockoutTest.php
    - tests/integration/Auth/AuditLogTest.php
  modified:
    - app/Controllers/Auth/Login.php
    - app/Views/auth/login.php
    - app/Language/sk/Login.php
    - app/Language/en/Login.php
    - app/Commands/UserCreate.php
    - tests/feature/Auth/LoginTest.php
    - tests/_support/AuthTestCase.php
key-decisions:
  - "Lockout upsert takes all times as PHP parameters (no now() in SQL); an expired lock resets the counter to 1, so a boundary-instant retry cannot re-lock"
  - "DeviceLabel maps iPhone/iPad from getMobile() because getPlatform() reports iOS"
duration: 35min
completed: 2026-10-24
---

# Phase 02 Plan 10: Lockouts and audit log Summary

**Five wrong passwords in 15 minutes lock the IP (/64 for IPv6) and the account for 15 minutes via an atomic ON CONFLICT upsert, the owner gets an e-mail, and every attempt lands in the insert-only `auth_events` log with a hashed identifier.**

## Commits
- fa7c0e8: test(02-10) RED
- a7a2a08: feat(02-10) migrations and services
- 597df98: feat(02-10) Login wiring, translations, user:create audit

## Verification
`composer check` green (208 tests, PHPStan, cs). Migrations applied in dev (`make migrate`) and test DB. Acceptance greps: no `clear(` in Login, no `now()` in LockoutService.

## Deviations from Plan
- [Rule 3] `tests/_support/AuthTestCase.php` now also truncates `auth_lockouts` and `auth_events` (not in plan file list, needed for test isolation).
- [Rule 1] Expired lock branch added to the upsert (reset to count 1) to avoid immediate re-lock at the exact expiry instant.
- Tests set `HTTP_USER_AGENT` via `service('superglobals')` and inject an `IncomingRequest` mock (CLI request has no UA); direct `$_SERVER` writes are forbidden by PHPStan.

## Known Stubs
None.

## Deferred hardening
`auth_lockouts` riadky pre neexistujúce e-maily (kind `account`) a staré IP sa nikdy nemažú; v Phase 12 (hardening) doplniť periodické čistenie riadkov s `locked_until` a `window_start` staršími ako 24 h (napr. spark príkaz v cron-e).

## Self-Check: PASSED
