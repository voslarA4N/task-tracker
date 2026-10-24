---
phase: 02-identita-a-skor-nasadenie
plan: 08
subsystem: auth
tags: [login, shield, locale, enumeration-safe, auth-layout]
requires: [02-06]
provides: [Auth\Login, layouts/auth, LocaleNegotiation, Routes/auth.php, pending_since, auth_info-flash, data-submit-once]
affects: [02-12, 02-13, 02-14, 02-15, 02-16, 02-17, 02-18, 02-19]
key-files:
  created:
    - app/Controllers/Auth/Login.php
    - app/Views/layouts/auth.php
    - app/Views/auth/login.php
    - app/Filters/LocaleNegotiation.php
    - app/Config/Routes/auth.php
    - app/Language/sk/Login.php
    - app/Language/en/Login.php
    - tests/feature/Auth/LoginTest.php
    - tests/js/submit-once.test.js
  modified:
    - app/Config/Routes.php
    - app/Config/App.php
    - app/Config/Filters.php
    - public/assets/css/app.css
    - public/assets/js/app.js
key-decisions:
  - "Unknown e-mail runs password_verify against a fixed Argon2id DUMMY_HASH; one generic message for all failures"
  - "LocaleNegotiation also sets the `language` service locale (IncomingRequest::setLocale does not)"
duration: 40min
completed: 2026-10-24
---

# Phase 02 Plan 08: Password login slice Summary

**`/login` takes e-mail and password via Shield into the pending state (`pending_since` in session), `POST /logout` ends session and pending, the page language follows `Accept-Language` (sk default, unsupported falls back to sk) and `user_security.locale` after login; routes are now modular under `app/Config/Routes/*.php`.**

## Commits

- e961fb8 test(02-08): failing login tests (RED)
- ca37beb feat(02-08): login page, auth layout, Login controller, modular routes
- 2f9ccfe feat(02-08): locale negotiation from Accept-Language and user_security.locale

## Deviations from Plan

1. **[Rule 1 - Bug] Language service not switched.** The plan assumed `IncomingRequest::setLocale()` also switches the `language` service. It does not (only `Locale::setDefault`), so `lang()` in views stayed Slovak. `LocaleNegotiation::apply()` now also calls `service('language')->setLocale()`.
2. **[Rule 3 - Blocking] Negotiation in the filter.** `detectLocale()` runs in the request constructor with a shared negotiator, so headers set later (FeatureTestTrait) were ignored. The filter negotiates with a non-shared negotiator from the current headers (idempotent in production). Consequence: the filter runs `Accept-Language` negotiation for every request, then overrides by `user_security.locale` for logged-in/pending users.
3. Tests strip CSRF tokens (form field and `hx-headers`) before comparing the unknown-email and wrong-password bodies; test code captures the first body before the second request because the response object is shared.
4. php-cs-fixer reformatted the route glob comment and aligned test code.

## Verification

`composer check` green (191 tests, PHPStan, cs), `node --test tests/js` green, `HTTPS_PORT=8443 HTTP_PORT=8080 sh scripts/smoke.sh` SMOKE OK, `GET https://localhost:8443/login` returns 200. The plan's `work-tracker.loc` host was not used (dev nginx is on 8443). No inline `style=` or `<script>` in the auth views.

## Known Stubs

None in this plan. `/2fa` (redirect target after password) is implemented in 02-12 by design; the "forgot password" link comes with 02-18.

## Threat Flags

None beyond the plan threat model (T-02-08-01..05 mitigated; 06 transferred to nginx limit and 02-10).

## Self-Check: PASSED
