---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 08
subsystem: settings
tags: [legal-texts, resolver, audit, variants]
requires: [06-07]
provides:
  - LegalProfileResolver::resolveText with client-mode variants for general profiles
  - LegalTextService::create and effective
  - Settings add-variant form and Fallback/Missing rows in legal texts list
affects: [06-09]
key-files:
  created:
    - app/Views/settings/legal_texts/new.php
  modified:
    - app/Libraries/Master/LegalProfileResolver.php
    - app/Libraries/Master/LegalTextService.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Controllers/Settings/LegalTexts.php
    - app/Config/Routes/settings.php
    - app/Views/settings/legal_texts/index.php
    - app/Language/sk/LegalTexts.php
    - app/Language/en/LegalTexts.php
key-decisions:
  - "Order (lang, client mode) -> (lang, NULL) -> (en, client mode) -> (en, NULL) only when the profile resolved to the general mode; exact-mode profiles keep (lang, mode) -> (en, mode) so WR-11 holds"
  - "List computes Fallback/Missing rows only for the general mode plus modes that have a profile or own wording (domestic/non_eu without either resolve identically to general)"
requirements-completed: [SET-01]
completed: 2026-10-28
---

# Phase 6 Plan 08: Legal text variants Summary

Owner can add a wording variant for any language or billing mode (e.g. PL non_eu), the next invoice uses it, and the list shows which wording is really used or missing.

## Tasks

1. **Resolver, create, effective** (commit e10aae9): shared `resolveText`, `LegalTextService::create` (whitelist key/language/mode/profile, length limits, audit in the same transaction, duplicate -> `exists` with unique-violation caught outside the transaction), `effective()` with `fallback` flag. `SampleSnapshots` carries `billing_mode` in legal texts. Tests: WR-11 preserved, PL non_eu variant used by the next issued invoice (older invoice unchanged), duplicates, invalid inputs, audit.
2. **Form and list** (commit cb87f03): routes `new`/`create` before `(:num)`, `new.php`, Fallback/Missing badges with `.lt-effective` lines and prefilled add-variant links, "no default note" hint, sk/en strings, feature tests (fallback row, prefill and invalid query ignored, create redirect, duplicate and invalid key 422, no CSRF 403).

## Verification

- Full PHPUnit suite (`make test`): OK, 1250 tests, 8036 assertions.
- `make stan` and `make cs`: clean.

## Deviations from Plan

- None of substance. `.lt-effective` CSS already existed from 06-07, so `app.css` was not changed. `LegalTextService::KEYS` made public for the controller's query whitelist.

## Known Stubs

None.

## Self-Check: PASSED
