---
phase: 08-meny-a-kurzy
plan: 03
subsystem: fx
tags: [tdd, nbp, ecb, cnb, http-client, fixtures]
requires: [08-02]
provides:
  - "RateProvider + NbpRateProvider, EcbRateProvider, CnbRateProvider (latestOnOrBefore)"
  - "RateProviders::for registry, RateProviders::HOME"
  - "RateHttpClient, CurlRateHttpClient (timeout 3 s), HttpResult"
  - "RateNotFound vs RateUnavailable (D-12)"
  - "FakeRateHttpClient + real API fixtures in tests/_support/fixtures/fx"
affects: [08-04]
tech-stack:
  added: []
  patterns: ["rate as published string (number wrapped before json_decode)", "API decides business day, no holiday calendar"]
key-files:
  created:
    - app/Libraries/Fx/RateProvider.php
    - app/Libraries/Fx/RateProviders.php
    - app/Libraries/Fx/NbpRateProvider.php
    - app/Libraries/Fx/EcbRateProvider.php
    - app/Libraries/Fx/CnbRateProvider.php
    - app/Libraries/Fx/RateHttpClient.php
    - app/Libraries/Fx/HttpResult.php
    - app/Libraries/Fx/CurlRateHttpClient.php
    - app/Libraries/Fx/RateNotFound.php
    - app/Libraries/Fx/RateUnavailable.php
    - app/Libraries/Fx/RateWindow.php
    - tests/_support/FakeRateHttpClient.php
    - tests/_support/fixtures/fx/ (nbp_eur_range.json, nbp_400.txt, ecb_pln.csv, cnb_daily.json, cnb_daily.txt)
    - tests/unit/Fx/NbpRateProviderTest.php
    - tests/unit/Fx/EcbRateProviderTest.php
    - tests/unit/Fx/CnbRateProviderTest.php
key-decisions:
  - "Fixtures are real live responses fetched 2026-10-30 (NBP range 10-19..10-29, ECB csvdata, CNB JSON and TXT)"
  - "RateWindow helper holds currency validation (^[A-Z]{3}$) and the bound-10 window via service('clock')->parseDate (PHPStan forbids DateTimeImmutable statics)"
  - "CNB TXT date header parsed with a month map, no DateTime"
  - "CNB TXT fallback only on RateUnavailable from JSON, never on RateNotFound"
requirements-completed: []
duration: ~30min
completed: 2026-10-30
---

# Phase 8 Plan 03: Rate providers Summary

NBP (multiply, table no), ECB (divide, SDMX csvdata by header names) and CNB (JSON with daily.txt fallback, amount preserved) providers over a swappable HTTP client, tested only on real fixtures.

Commits: RED `1049270`, GREEN `0c75299` (no REFACTOR needed). 24 new tests (71 in tests/unit/Fx).

## Deviations from Plan

**1. [Rule 3] Added `RateWindow.php`** (not in frontmatter file list) for shared currency validation and window arithmetic, avoiding triplicated code and the PHPStan DateTime restriction.

**2. Test tweaks for PHPStan:** `assertTrue(true)` replaced with `addToAssertionCount(1)`, tautological HOME assertion dropped (HOME is a const).

Note: NBP fixture's last element at bound 2026-10-29 is 4.3951 (not 4.4090 as the plan sketch); trailing-zero preservation is tested with an inline body.

## Self-Check: PASSED
Full `make test`: OK (1662 tests, 24086 assertions); `make stan` OK; no `file_get_contents`/`curl_init` in app/Libraries/Fx.
