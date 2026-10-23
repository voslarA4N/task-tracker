---
phase: 01-z-klad-a-infra-trukt-ra
plan: 06
subsystem: pdf
tags: [mpdf, spike, php85, fpm, fonts]
requires: [01-01]
provides:
  - pinned DejaVu Sans fonts in resources/fonts
  - mPDF spike with measured time, memory and deprecations (CLI and FPM)
affects: [phase-05, phase-06, 01-10]
tech-stack:
  added: []
  patterns: [mPDF with pinned fontDir, useSubstitutions/autoLangToFont/autoScriptToLang off, tempDir writable/mpdf]
key-files:
  created:
    - resources/fonts/DejaVuSans.ttf
    - resources/fonts/DejaVuSans-Bold.ttf
    - resources/fonts/LICENSE-DejaVu.txt
    - scripts/spikes/mpdf.php
    - scripts/spikes/mpdf-fpm.sh
    - tests/integration/MpdfSpikeTest.php
key-decisions:
  - "mPDF config: DejaVu Sans pinned in repo, substitutions off, tempDir writable/mpdf/<name>"
  - "memory_limit 256M is ample (peak 28 MB); no vendor deprecation suppression needed"
requirements-completed: [NFR-07]
duration: 15min
completed: 2026-10-23
---

# Phase 1 Plan 06: mPDF spike Summary

mPDF 8.3.1 under PHP 8.5.12 with E_ALL renders a 40-row sample invoice with ł ń ě ť ž ô in about 0.3 s (CLI and FPM), peak 28 MB, zero deprecations or notices.

## Measurements

| Run | elapsed_ms | peak_memory_mb | deprecations | pdf_bytes |
|-----|-----------|----------------|--------------|-----------|
| CLI cold | 286 | 22 | 0 | 48958 |
| CLI warm | 229 | 22 | 0 | 48958 |
| FPM cold | 383 | 28 | 0 | 48958 |
| FPM warm | 161 | 6 | 0 | 48958 |

(PHPUnit runs: cold 308 ms, warm 271 ms.) memory_limit 256M, issues_count 0 everywhere. `pdftotext` returns the diacritics (A8 confirmed). FPM warm peak is low because the worker is already warm (peak counted per request after opcache and memory reuse).

## Recommendation for Phase 5/6

- Keep memory_limit 256M; PDF peak is under 30 MB for 40 rows, so no per-request raise is needed (revisit with a much larger report).
- No suppression of vendor deprecations needed in prod (none emitted).
- Use the same config: pinned `resources/fonts`, `useSubstitutions`, `autoScriptToLang`, `autoLangToFont` all false, `tempDir` under `writable/mpdf`.
- Run PDF scripts as `www-data` so tempDir stays writable by FPM.

## Task commits

1. Task 1 (fonts + spike script): bd2e6ef
2. Task 2 (FPM runner + MpdfSpikeTest): 075a5d8

## Deviations from Plan

None. Notes: DejaVu licence taken from `vendor/mpdf/mpdf/ttfonts/DejaVuinfo.txt`. Vendor lives in a named volume, so fonts were copied out of the container. `/scripts/spikes/mpdf.php` over HTTPS returns 404.

## Verification

- `make spike-pdf`: 4 measurements printed
- MpdfSpikeTest OK (3 tests), also with `CI=true`
- Full suite: OK (68 tests, 535 assertions); `make smoke`: SMOKE OK

## Known Stubs

None.

## Self-Check: PASSED
