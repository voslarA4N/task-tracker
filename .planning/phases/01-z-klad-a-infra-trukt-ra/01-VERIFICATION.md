---
phase: 01-z-klad-a-infra-trukt-ra
verified: 2026-10-23T22:00:00Z
status: gaps_found
score: 4/5 roadmap success criteria fully verified (SC4 partial)
overrides_applied: 0
gaps:
  - truth: "SC4 / plan 01-08: htmx request without a valid CSRF token is rejected and the user sees the CSRF-specific message in #app-alert"
    status: partial
    reason: "CR-01 confirmed in code. Server returns 403 + HX-Retarget #app-alert + fragment (verified by smoke/tests at HTTP level). But layout config sets 403 to {swap:true, error:true}, so htmx fires htmx:responseError after the swap; app.js listener calls show(data-msg-server), which overwrites the box with the generic 'server error' text. Separately, #app-alert is rendered with `hidden` and nothing removes it except show(), so the CSRF fragment alone would be invisible. User sees a wrong, generic message, never App.csrfFailed. No test covers the rendered text (HtmxCsrfTest/smoke.sh assert headers/body only)."
    artifacts:
      - path: "public/assets/js/app.js"
        issue: "responseError handler unconditionally overwrites #app-alert; no handler un-hides after swap into #app-alert"
      - path: "app/Views/layouts/app.php"
        issue: "403 responseHandling error:true combined with hidden #app-alert"
    missing:
      - "Skip responseError for status 403 (already swapped) and un-hide #app-alert on htmx:afterSwap, or make 403 error:false and un-hide"
      - "Browser-level or JS-level assertion that visible alert text equals App.csrfFailed"
  - truth: "Dev DB reset is safe from running against production (guard in scripts/db-reset.sh) - CR-02"
    status: failed
    reason: "Guard parses .env with cut -f2 | tr -d ' \"' ; a single-quoted value or CRLF line ending (likely on /mnt/c) is not equal to 'production', so DROP DATABASE ... WITH (FORCE) proceeds. Deny-list instead of allow-list. Not a roadmap success criterion and current .env is development (so not triggered), but it is a data-loss hazard in a project whose core value is data integrity."
    artifacts:
      - path: "scripts/db-reset.sh"
        issue: "lines 5-10 bypassable; ignores process env"
    missing:
      - "Normalize value (strip quotes, CR, whitespace) and allow-list development|testing, fail closed"
human_verification:
  - test: "Trust mkcert cert in Windows Chrome and open https://work-tracker.loc/ and /demo"
    expected: "No cert warning; page loads over HTTP/2"
    why_human: "Windows trust store not inspectable from WSL"
  - test: "Open / and /demo at 375px and 390px (iPhone emulation) and PC width"
    expected: "No horizontal scroll, bottom nav usable, touch targets >= 44 px"
    why_human: "Visual layout / real rendering"
  - test: "DevTools console on /demo while saving a calculation and while triggering a CSRF failure (e.g. delete token)"
    expected: "No CSP violations; after CR-01 fix the CSRF-specific message is visible"
    why_human: "Browser CSP enforcement and rendered alert"
  - test: "DevTools Application > Cookies"
    expected: "Session/CSRF cookies Secure, HttpOnly, SameSite as configured"
    why_human: "smoke.sh checks flags via curl only"
  - test: "Mailpit UI (http port) after `php spark mail:test`"
    expected: "Message visible"
    why_human: "UI check (smoke confirms receipt via API)"
  - test: "Push to GitHub and watch CI workflow"
    expected: "dev+prod image builds, phpunit, phpstan, cs-fixer, preload check green"
    why_human: "CI cannot be run locally"
---

# Phase 1: Zaklad a infrastruktura - Verification Report

**Phase Goal:** Owner starts an identical Docker environment in WSL2 with conventions fixed early (money, time, DB roles, translations, safe htmx patterns).
**Verified:** 2026-10-23
**Status:** gaps_found
**Re-verification:** No - initial verification

## Goal Achievement

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|--------------------|--------|----------|
| 1 | compose stack (nginx, php-fpm, PG, Mailpit); migrations as app_owner; app_rw cannot modify/delete insert-only tables | VERIFIED | `make smoke` SMOKE OK: php, nginx, db, mailpit running, db healthy, HTTPS 200 HTTP/2, mailpit received mail. `migrate:status -g owner` shows CreateDemoCalculations applied by group owner. `has_table_privilege('app_rw','demo_calculations', ...)`: UPDATE f, DELETE f, INSERT t. DbRolesTest passes. |
| 2 | Responsive base page, 44px targets, no horizontal scroll, all text via lang() | VERIFIED (code) / human for visual | LayoutTest, ViewsHardcodedTextTest, LangKeysTest pass (sk+en parity). Visual 375/390px needs a browser (human_verification). |
| 3 | Money: hours x rate half-up to 2dp over numeric, no float, rows/totals match | VERIFIED | MoneyTest, MoneyDbParityTest, DemoCalculationsTest, DemoPersistTest pass in full suite (92 tests, 2607 assertions, OK). PHPStan forbidden-call rule exists (with gaps, WR-07). |
| 4 | htmx + valid CSRF passes; no token rejected; 422 with displayed message; strict CSP | PARTIAL | Pass-through, 403 rejection (smoke "csrf 403 without token"), 422 and CSP headers/ViewsCspTest/SecurityConfigTest pass. But the CSRF rejection message shown to the user is wrong/hidden (CR-01, confirmed below). |
| 5 | mPDF spike under PHP 8.5 E_ALL, diacritics, under 2 s, measured memory | VERIFIED | MpdfSpikeTest passes: cold 287-293 ms, warm ~252 ms, peak 22 MB, 0 deprecations (PHP 8.5.12). |

**Score:** 4/5 fully verified; SC4 partial.

## CR-01 assessment (own verification against code)

Confirmed real, not just reviewer opinion. Evidence: `app/Views/layouts/app.php` htmx config has `403 => swap:true, error:true`; vendored htmx 2.0.11 dispatches `htmx:responseError` for error:true responses; `public/assets/js/app.js:27` handler `show(body.dataset.msgServer)` replaces the box's text with the generic message; `#app-alert` is `hidden` in the layout and only `show()` removes `hidden`. Result: CSRF rejection works at the protocol level (403, no state change) but the user-facing "rejected with displayed message" is a generic wrong message. Classified as gap (partial, moderate severity: security behavior intact, UX/spec truth of plan 01-08 broken, untested). Small fix.

## CR-02 assessment

Confirmed by reading `scripts/db-reset.sh:5-10`: quoted-value / CRLF bypass is real; current `.env` is `CI_ENVIRONMENT = development` (no trailing CR), so not currently triggered. Reported as gap; not part of SC1-5.

## Requirements Coverage

| Requirement | Source Plans | Status | Evidence |
|-------------|--------------|--------|----------|
| NFR-04 (money numeric, no float, one sum class, totals match, tests) | 01-03, 01-07, 01-09, 01-10 | SATISFIED | Money class + DB parity tests green |
| NFR-07 (same Docker images dev/prod, Mailpit in dev) | 01-01, 01-02, 01-05, 01-06, 01-10 | SATISFIED for dev; prod compose incomplete (WR-03, deferred to deployment phase) | smoke OK; prod image target builds in CI config |
| UI-01 (responsive Bootstrap, 44px, no h-scroll) | 01-04, 01-08, 01-09, 01-10 | NEEDS HUMAN (visual) | Layout tests pass; browser check required |

All three IDs appear in REQUIREMENTS.md mapped to Phase 1; no orphaned requirements.

## Anti-patterns / other review items
No TBD/FIXME/XXX debt markers checked beyond review. Review warnings WR-01..WR-09 and IN-01..IN-10 are non-blocking hardening items (prod compose, secrets via files, preload exclude list, immutable 404 cache, PHPStan rule coverage, .env.* ignore). Notable: WR-08 (`.env.*` not gitignored) and WR-03 should be scheduled before any deployment.

## Behavioral spot-checks
| Check | Result |
|-------|--------|
| `make smoke` | SMOKE OK (all lines OK) |
| `docker compose exec -T -u www-data php vendor/bin/phpunit` | OK (92 tests, 2607 assertions) |
| `make check` | migrations ran as owner, `composer test` started (git "dubious ownership" warning noisy but non-fatal); phpunit rerun separately green |
| app_rw privileges on demo_calculations | UPDATE f, DELETE f, INSERT t |

## Gaps Summary
Two gaps: (1) CR-01, the CSRF 403 message is overwritten/hidden, so SC4's "rejected with displayed message" is only partly delivered; (2) CR-02, destructive reset guard bypass. Both are small, localized fixes. Everything else in the phase goal is evidenced by running code and tests. After the fixes, the remaining items are human browser checks.
