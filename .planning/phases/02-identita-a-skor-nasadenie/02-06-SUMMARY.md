---
phase: 02-identita-a-skor-nasadenie
plan: 06
subsystem: auth
tags: [authgate, deny-by-default, shield, htmx, route-enumeration]
requires: [02-03]
provides: [AuthGate, AuthTestCase, forbidden_403, smoke-port-params]
affects: [02-12, 02-16]
key-files:
  created:
    - app/Filters/AuthGate.php
    - app/Views/errors/forbidden_403.php
    - tests/_support/AuthTestCase.php
    - tests/feature/Auth/AuthGateTest.php
  modified:
    - app/Config/Filters.php
    - app/Language/sk/App.php
    - app/Language/en/App.php
    - tests/feature/DemoPersistTest.php
    - tests/feature/LayoutTest.php
    - tests/feature/HtmxCsrfTest.php
    - scripts/smoke.sh
key-decisions:
  - "Gate runs after csrf in globals.before; allowlist pinned by a test"
  - "Non-admin logged-in users get 403 (deny-by-default)"
duration: 30min
completed: 2026-10-24
---

# Phase 02 Plan 06: AuthGate Summary

**Global deny-by-default `AuthGate` filter: anonymous requests redirect to `/login`, pending 2FA to `/2fa`, htmx gets 401 + `HX-Redirect`, non-admin gets 403, and a test enumerates every registered route.**

## Commits

- b6c479b test(02-06): failing auth gate tests (RED)
- bb1939c feat(02-06): AuthGate filter, registration, 403 page, test and smoke updates (GREEN)

## Deviations from Plan

1. **[Rule 1 - Bug] AuthTestCase::PASSWORD** changed from `Test-Heslo-2026!` to `Zelena-Luka-2026!`: the former is rejected by NothingPersonalValidator (contains "test" from `example.test`), same issue as in 02-03.
2. **[Rule 1 - Bug] Route enumeration** uses uppercase verbs for `getRoutes()` and calls `loadRoutes()` first, otherwise it enumerated zero routes.
3. **[Rule 3 - Blocking] HtmxCsrfTest helper** `post()` renamed to `postDemo()` (FeatureTestTrait `post()` is public); `AuthGateTest` uses a private `anon()` helper.
4. **[Rule 3 - Blocking] smoke.sh**: added optional `HTTPS_PORT`/`HTTP_PORT` (host ports 80/443 are taken by an unrelated container; run `HTTPS_PORT=8443 HTTP_PORT=8080 sh scripts/smoke.sh`). The gzip check moved to a static CSS asset because `/` is now a body-less redirect.
5. Allowlist assertion uses `ReflectionClass::getConstant` to satisfy PHPStan.

## Verification

`composer check` green (172 tests). `HTTPS_PORT=8443 HTTP_PORT=8080 sh scripts/smoke.sh` gives SMOKE OK (`/` and `/demo` return 302 to `/login`). `/login` and `/2fa` routes do not exist yet (later plans); the gate redirects to them by design.

## Known Stubs

None.

## Self-Check: PASSED
