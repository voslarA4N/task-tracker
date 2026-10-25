---
phase: 01-z-klad-a-infra-trukt-ra
verified: 2026-10-24T09:00:00Z
status: passed
score: 5/5 roadmap success criteria verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 4/5 (SC4 partial)
  gaps_closed:
    - "CR-01: CSRF 403 fragment visible in #app-alert (e55bf77)"
    - "CR-02: db-reset.sh production guard, allow-list fail-closed (15b3681)"
  gaps_remaining: []
  regressions: []
deferred:
  - truth: "WR-03: production compose is a base file only; fully functional prod compose (state volumes, secrets)"
    addressed_in: "Phase 12"
    evidence: "ROADMAP Phase 12 'Lokalizacia a produkcia' (zalohy, hardening, produkcia); 82d754c applied smallest safe change (base marked not-deployable-alone)"
  - truth: "WR-04: DB role passwords still passed as env vars (psql command line part fixed in 378cfb3)"
    addressed_in: "Phase 12"
    evidence: "ROADMAP Phase 12 hardening; 01-REVIEW-FIX.md lists WR-04 as partially fixed, remainder moves to Phase 12"
human_verification_resolved_by_uat:
  - "mkcert cert / HTTPS in Chrome - UAT (12/12 passed)"
  - "Responsive 375/390/PC layout - UAT test 3"
  - "CSP console on /demo - UAT test 8"
  - "Visible CSRF message in real browser - UAT test 7"
  - "Cookie flags in DevTools - UAT test 9"
  - "GitHub CI green after ee0a5d5 - UAT test 12"
---

# Phase 1: Zaklad a infrastruktura - Verification Report

**Phase Goal:** Owner starts an identical Docker environment in WSL2 with conventions fixed early (money, time, DB roles, translations, safe htmx patterns).
**Verified:** 2026-10-24
**Status:** passed
**Re-verification:** Yes - after gap closure (CR-01, CR-02)

## Goal Achievement

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|--------------------|--------|----------|
| 1 | compose stack (nginx, php-fpm, PG, Mailpit); migrations as app_owner; app_rw cannot modify/delete insert-only tables | VERIFIED | `sh scripts/smoke.sh`: SMOKE OK (php, nginx, db, mailpit running, db healthy, HTTPS 200 HTTP/2, mailpit received mail). DbRolesTest in full suite green. |
| 2 | Responsive base page, 44px targets, no h-scroll, all text via lang() | VERIFIED | LayoutTest, ViewsHardcodedTextTest, LangKeysTest green; visual confirmed by UAT test 3. |
| 3 | Money: hours x rate half-up 2dp over numeric, no float, totals match | VERIFIED | Money/MoneyDbParity/DemoCalculations tests in full suite green. |
| 4 | htmx + valid CSRF passes; missing token rejected; 422 with displayed message; strict CSP | VERIFIED | CR-01 closed (below). smoke "csrf 403 without token" + "js app-alert" OK; CSP headers OK; UAT tests 7 and 8. |
| 5 | mPDF spike PHP 8.5 E_ALL, diacritics, < 2 s, memory measured | VERIFIED | MpdfSpikeTest green: cold 353 ms, warm 286 ms, peak 22 MB, 0 deprecations. |

**Score:** 5/5

## Previous gaps

### CR-01 - CLOSED (commit e55bf77)
- Code: `public/assets/js/app.js` now has `targetsAlert(e)`; `htmx:afterSwap` removes `hidden` from `#app-alert` when the swap target is the alert box; `htmx:responseError` returns early when the target is the alert box, so the server's CSRF fragment is no longer overwritten by the generic message. `htmx:beforeRequest` hides the box on next request.
- Tests run now: `node --test tests/js/*.test.js` 5/5 pass (includes swap into #app-alert un-hides, other targets don't, next request hides). HtmxCsrfTest passes in phpunit. smoke.sh has new "js app-alert" check, OK.
- Browser: UAT test 7 confirmed visible CSRF message.

### CR-02 - CLOSED (commit 15b3681)
- Code: `scripts/lib-env-guard.sh` normalizes the value (inline comment, single/double quotes, CR, tabs, spaces) and allow-lists only `development|testing`; missing/empty value or missing .env is refused. `scripts/db-reset.sh` checks `.env`, the host process env and the php container env, and refuses if the container is unreachable.
- Behavioral check run now with CRLF-terminated .env: `production` REFUSE, `'production'` REFUSE, empty REFUSE, missing file REFUSE, `development` / `'development'` / `testing` ALLOW. DbResetGuardTest is part of the green full suite.

## Regression check

| Check | Result |
|-------|--------|
| `docker compose exec -T -u www-data php vendor/bin/phpunit` | OK (138 tests, 2712 assertions; was 92/2607) |
| `sh scripts/smoke.sh` | SMOKE OK (all 18 lines OK) |
| `node --test tests/js/*.test.js` | 5 pass, 0 fail |

No regressions in previously passing items.

## Requirements Coverage

| Requirement | Status | Evidence |
|-------------|--------|----------|
| NFR-04 (money numeric, no float, tests) | SATISFIED | Money class + DB parity tests green |
| NFR-07 (same Docker images dev/prod, Mailpit in dev) | SATISFIED for Phase 1 scope | smoke OK; full prod compose is Phase 12 (WR-03, deferred) |
| UI-01 (responsive Bootstrap, 44px, no h-scroll) | SATISFIED | Layout tests + UAT test 3 |

No orphaned requirements.

## Deferred Items

| # | Item | Addressed In | Evidence |
|---|------|--------------|----------|
| 1 | WR-03 prod compose functional (state, secrets) | Phase 12 | Phase 12 production/hardening scope; base file marked non-deployable in 82d754c |
| 2 | WR-04 remaining DB password env handling | Phase 12 | Hardening scope; psql command-line exposure fixed in 378cfb3 |

## Anti-Patterns
No blockers. WR-01, 02, 05, 06, 07, 08, 09 fixed per 01-REVIEW-FIX.md (commits present in git log). 01-SECURITY.md: 0 open threats (not independently re-audited).

## Human verification
All prior items resolved by UAT (12/12 passed, see frontmatter). Mailpit UI check was covered by the smoke API check and UAT. None outstanding.

---

_Verified: 2026-10-24_
_Verifier: Claude (gsd-verifier)_
