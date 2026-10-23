---
phase: 01-z-klad-a-infra-trukt-ra
plan: 10
subsystem: ci
tags: [ci, github-actions, phpstan, php-cs-fixer, decisions]
requires: ["01-02", "01-03", "01-04", "01-05", "01-06", "01-07", "01-08", "01-09"]
provides:
  - ".github/workflows/ci.yml (build dev+prod, PG18 provisioning, composer check, preload check, secrets guard)"
  - "Phase 1 Key Decisions in PROJECT.md (10 rows)"
  - "phpstan-codeigniter schema cache moved to build/phpstan"
affects: [phase-2]
key-files:
  created: [.github/workflows/ci.yml]
  modified: [phpstan.neon.dist, .planning/PROJECT.md]
key-decisions:
  - "phpstan-codeigniter schema cache: parameters.codeigniter.schemaCacheDirectory = build/phpstan (already gitignored), no tmp/ in repo root"
  - "CI .env keeps CI_ENVIRONMENT=development (spark crashes in testing env, see 01-05); PHPUnit forces testing itself"
requirements-completed: [NFR-04, NFR-07, UI-01]
duration: ~30 min
completed: 2026-10-23
---

# Phase 1 Plan 10: CI, final check pass and Phase 1 decisions Summary

GitHub Actions workflow guarding the Phase 1 conventions on every push/PR, a clean final cs/stan pass, and the Phase 1 decisions recorded in PROJECT.md.

## Tasks
1. cs-fix / PHPStan pass + schema cache relocation: `00aeb9e`
2. CI workflow: `280e166`
3. PROJECT.md decisions: `7586d44`

## Task 1 result
`make cs-fix` fixed 0 of 88 files and PHPStan level 6 reported no errors (the 01-07 pass already covered everything), so no code changes were needed. No `ignoreErrors`, no baseline file. The untracked `tmp/` dir came from phpstan-codeigniter's `SchemaCache`; it is configurable, so `phpstan.neon.dist` now sets `codeigniter.schemaCacheDirectory: build/phpstan` and `tmp/` was deleted. `git ls-files` has 0 `.env`/`*.pem`/`docker/certs/` entries, no `-k`/`--insecure` anywhere, `make smoke` has 0 `SKIP`.

## Task 2: local replay of CI steps 3-7
Scratch copy via `git archive` (in scratchpad), temporary `tt-ci-pg` PG 18 container on 127.0.0.1:55432 (port overridden in the scratch `.env` only), dev `.env`/DB untouched:
- dev and prod targets built;
- provision.sql applied for task_tracker and task_tracker_test;
- `composer install && composer test:migrate && composer check` with `CI=true`: PHPStan OK, cs 0 of 88, PHPUnit OK (92 tests, 2607 assertions; MpdfSpike cold 170 ms, warm 153 ms);
- preload command printed `1`;
- workflow YAML parsed with ruby:3-alpine (exit 0);
- cleanup: `tt-ci-pg` removed (count 0), CI images and scratch copy deleted; `make smoke` afterwards = SMOKE OK.
`actions/checkout@v4` is used (v7 not verified). Not run on GitHub itself; the owner pushes.

## Task 3: PROJECT.md
Fresh `make spike-pdf` numbers: CLI cold 299 ms, CLI warm 264 ms (peak 22 MB), FPM cold 218 ms, FPM warm 185 ms, 0 deprecations, memory_limit 256M. Ten rows added with Outcome `✓ Phase 1 (2026-10-23)`: bcmath (half-up, no -0.00, brick/math not needed), mPDF config + measurements, ICU `sk-SK` collation (`LOCALE_PROVIDER icu ICU_LOCALE 'sk-SK' LOCALE 'C.UTF-8'` works on PG18), HtmxCsrf, nginx-only CSP, PHPUnit 11.5, DB roles / `grantMutable()` + `defaultGroup` switch for `spark migrate -g`, translations auto-discovery, mkcert.exe lookup (`scripts/lib-mkcert.sh`), phpstan schema cache. `Last updated` line bumped.

## Deviations from Plan
**1. [Rule 3] CI `.env` uses `CI_ENVIRONMENT = development`, not `testing`** (plan step 5): spark in testing env crashes (undefined SUPPORTPATH, 01-05); PHPUnit sets `testing` with `force`. Verified green in replay.
**2. [Rule 2 scope] No code fixes needed in Task 1** beyond the tmp/ relocation.
**3.** Two extra PROJECT.md rows beyond the planned 8 (mkcert lookup, phpstan cache).

## Final verification
- `make check`: exit 0; PHPStan "No errors" (selftest OK), cs "Found 0 of 88", PHPUnit "OK (92 tests, 2607 assertions)"
- `make smoke`: SMOKE OK

## End-of-phase human checks (for /gsd-verify-work)
- [ ] 1. https://work-tracker.loc in Chrome/Edge on Windows: no certificate warning.
- [ ] 2. DevTools device mode iPhone SE 375 px and iPhone 14 390 px on `/` and `/demo`: no horizontal scroll; buttons, inputs and tab-bar items at least 44 px (Computed -> height). (01-04, 01-10)
- [ ] 3. `/demo`: calculation 1,25 x 27 shows 33.75 without page reload; saving 1,25x27 and 2x10,50 gives total 54.75 without reload; Slovak error message on empty field; works at 375 px. (01-08, 01-09)
- [ ] 4. DevTools Console: no "Refused to ... Content Security Policy" messages. (01-08)
- [ ] 5. Application -> Cookies: Secure, HttpOnly, SameSite=Lax. (01-08)
- [ ] 6. Mailpit UI http://127.0.0.1:8025 shows the e-mail from the smoke test.
- [ ] 7. After push: `gh run list --workflow ci.yml --limit 1` shows `completed success` for the latest commit.

## Known Stubs
None (nav items Úlohy/Faktúry/Viac stay intentionally inactive, see 01-04).

## Threat Flags
None. T-01-45..48 mitigated in ci.yml (git ls-files guard, `permissions: contents: read`, no secrets, no pull_request_target, locked composer.lock, pinned images).

## Self-Check: PASSED
ci.yml exists; commits 00aeb9e, 280e166, 7586d44 exist; make check and make smoke green.
