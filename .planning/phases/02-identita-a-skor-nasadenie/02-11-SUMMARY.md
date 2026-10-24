---
phase: 02-identita-a-skor-nasadenie
plan: 11
subsystem: pwa
tags: [pwa, manifest, icons, ios, install-hint]
requires: [02-08]
provides: [manifest.webmanifest, icons-v1, WtPwa.shouldShowInstallHint, layouts/_install_hint, Pwa-lang]
affects: [02-13]
key-files:
  created:
    - public/manifest.webmanifest
    - public/assets/icons/icon.svg
    - public/assets/icons/icon-180.v1.png
    - public/assets/icons/icon-192.v1.png
    - public/assets/icons/icon-512.v1.png
    - public/assets/icons/icon-maskable-512.v1.png
    - scripts/make-icons.sh
    - app/Views/layouts/_install_hint.php
    - public/assets/js/pwa.js
    - app/Language/sk/Pwa.php
    - app/Language/en/Pwa.php
    - tests/feature/PwaManifestTest.php
    - tests/js/install-hint.test.js
  modified:
    - app/Views/layouts/app.php
    - app/Views/layouts/auth.php
    - public/assets/css/app.css
key-decisions:
  - "PNG icons are generated from icon.svg by scripts/make-icons.sh in a throwaway alpine container (rsvg-convert); version in file name (.v1) because /assets is immutable"
duration: 20min
completed: 2026-10-24
---

# Phase 02 Plan 11: PWA install slice Summary

**Work Tracker is installable on iPhone: manifest (standalone, `#0d6efd`), WT icon generated from one SVG into 4 PNGs, iOS meta tags in both layouts, and a dismissible "Share -> Add to Home Screen" panel shown only in mobile Safari outside standalone.**

## Commits
- test(02-11) RED: failing pwa install tests
- feat(02-11): WT icon, generated PNGs and web manifest
- feat(02-11): iOS PWA meta, install hint panel and pwa.js

## Verification
`composer check` green (212 tests, PHPStan, cs); `node --test tests/js` green (15 tests). The 192 px icon was viewed and shows white WT on blue.

## Deviations from Plan
- [Rule 3] The Alpine package providing `rsvg-convert` is `rsvg-convert`, not `librsvg`; script uses it. Docker credential helper failed on pull, so the one-off run used `DOCKER_CONFIG` with an empty config (not committed, no file change).
- [Rule 1] PHP test decodes HTML entities before matching, because `esc(..., 'attr')` encodes `/`, `?` and spaces.
- `.install-hint[hidden]` CSS rule added so Bootstrap `d-flex` does not override the `hidden` attribute (needed for the panel to be truly hidden).
- Install panel also included in the auth layout between logo and card, as specified.

## Known Stubs
None. Service worker registration and offline page come in 02-13 (same `pwa.js`).

## Threat Flags
None. T-02-11-01 mitigated (no inline script/style, only `hidden` attribute), T-02-11-03 mitigated (`.v1` in PNG names).

## Self-Check: PASSED
