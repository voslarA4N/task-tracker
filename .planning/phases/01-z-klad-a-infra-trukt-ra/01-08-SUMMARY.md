---
phase: 01-z-klad-a-infra-trukt-ra
plan: 08
subsystem: web
tags: [htmx, csrf, codeigniter, cookies, demo]
requires: [01-02, 01-03, 01-04]
provides:
  - HtmxCsrf filter (alias csrf, global before) returning 403, never a redirect
  - HtmxVary filter (global after) adding Vary: HX-Request
  - htmx helper is_htmx(), is_htmx_fragment_request(), htmx_view()
  - /demo calculator (htmx form -> Money) as the pattern for later forms
  - Secure/HttpOnly/SameSite=Lax cookies, session CSRF with tokenRandomize
affects: [01-09, all Phase 2+ forms]
tech-stack:
  added: []
  patterns: [422 + fragment on validation error, 403 + HX-Retarget #app-alert on CSRF failure, fragment vs page via htmx_view]
key-files:
  created:
    - app/Filters/HtmxCsrf.php
    - app/Filters/HtmxVary.php
    - app/Helpers/htmx_helper.php
    - app/Controllers/Demo.php
    - app/Views/demo/index.php
    - app/Views/demo/_calculator.php
    - app/Views/errors/csrf_403.php
    - app/Language/sk/Demo.php
    - app/Language/en/Demo.php
    - tests/feature/HtmxCsrfTest.php
    - tests/unit/Config/SecurityConfigTest.php
  modified:
    - app/Config/Filters.php
    - app/Config/Security.php
    - app/Config/Cookie.php
    - app/Config/Routes.php
    - app/Controllers/BaseController.php
    - app/Views/home/index.php
    - scripts/smoke.sh
key-decisions:
  - "FeatureTestTrait runs the real filters, so 403 tests use real requests (no direct filter calls needed)"
  - "In feature tests read bodies via $result->response()->getBody(); TestResponse::getBody() returns DOM-normalised HTML (wraps fragments in <html>)"
requirements-completed: [UI-01]
duration: ~25 min
completed: 2026-10-23
---

# Phase 1 Plan 08: htmx + CSRF filters and demo page Summary

Custom CSRF filter (403, htmx fragment into `#app-alert`, no redirect), `Vary: HX-Request` on every response, secure cookies and a `/demo` htmx calculator computing via `Money::timesHours`, with 422 validation fragments in Slovak.

## Tasks
1. RED tests (feature + security config): c494f5a
2. Filters, Security/Cookie/Filters config, htmx helper, csrf_403 view, BaseController helpers: 7de1f45
3. Demo controller, views, lang files, routes, smoke checks (+ phpstan/cs fixes): 0410042

## Verification
- Full PHPUnit: 82 tests OK; `composer check` (stan + cs + test) green; `make smoke` ends `SMOKE OK` (includes /demo 200, cookie flags, 403 without token with `HX-Retarget: #app-alert`).

## Deviations from Plan
- [Rule 1 - Bug] Test helper: `TestResponse::getBody()` returns DOM-normalised HTML, so tests read `response()->getBody()`.
- Filter methods got native `?ResponseInterface` return types to satisfy PHPStan (no behaviour change).
- php-cs-fixer reformatted Filters.php, htmx_helper.php and the test; `composer check` clean.

## Known Stubs
None. The demo does not persist (DB storage is plan 01-09).

## Notes for later plans
- Use `htmx_view($fragment, $data, $page)` in controllers; return 422 with the fragment on validation errors; htmx config swaps 422 and 403.
- Money errors: `InvalidArgumentException` / `OverflowException` map to 422.
- Input normalisation: comma to dot before validation (`1,25` accepted).
- `Security::$redirect` is false regardless of ENVIRONMENT; session CSRF, token is the same across requests (`regenerate=false`).
- Human check deferred to 01-10 (browser: result 33.75 without reload, CSP console clean, cookie flags).

## Self-Check: PASSED
