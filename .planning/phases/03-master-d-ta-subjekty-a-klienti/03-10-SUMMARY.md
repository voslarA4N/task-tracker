---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 10
subsystem: master-data
tags: [legal-profiles, exemption-code, dev-seeder, phpunit]
requires: [03-07, 03-09]
provides:
  - legal_profiles and legal_texts tables with provisional seed in migration (6 profiles, 16 texts), read-only for app_rw
  - LegalProfileResolver (profile, exemptionCode, text) with exact -> NULL billing mode -> en -> LegalTextMissing fallback
  - Read-only Legal profile section on entity detail with Provisional wording badge
  - DevSeeder::seedWith idempotent sample SK and PL entities, bank accounts and All4Net client
affects: [phase-05, phase-06]
tech-stack:
  added: []
  patterns: [seed data in migration, IS NOT DISTINCT FROM with CAST for nullable lookup keys]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000005_CreateLegalProfiles.php
    - app/Libraries/Master/LegalProfileResolver.php
    - app/Libraries/Master/LegalTextMissing.php
    - app/Views/settings/entities/_legal_profile.php
    - tests/integration/Master/LegalProfileTest.php
    - tests/feature/Master/LegalProfileSectionTest.php
    - tests/integration/Database/DevSeederTest.php
  modified:
    - app/Controllers/Settings/Entities.php
    - app/Views/settings/entities/show.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
    - app/Database/Seeds/DevSeeder.php
key-decisions:
  - "Legal wording and code mapping stay provisional until the accountant confirms (R1); corrected by a new migration or Phase 6 UI"
  - "Missing legal profile/text renders alert-danger on detail, never an empty string"
requirements-completed: [SET-02]
duration: 40min
completed: 2026-10-25
---

# Phase 3 Plan 10: Legal Profiles and Dev Seeder Summary

The VAT exemption reason is now a structured `exemption_code` (ART_113_1, ART_113_9, ART_28B_RC) in a legal profile keyed by country, VAT regime and billing mode. Texts are looked up with a defined fallback and never silently empty. Entity detail shows the profile read-only, and a dev seeder creates idempotent sample data.

## Tasks
1. Legal profiles TDD: RED 590f643, GREEN 0153250 (10 integration + 2 feature tests)
2. Dev seeder TDD: RED b6d561f, GREEN 28d9cc8 (3 tests); `db:seed DevSeeder` run twice on dev DB, counts stable (2 entities, 1 client, 3 accounts)
3. Final check: style fix dfd66c7

## Deviations from Plan
**1. [Rule 1 - Style] php-cs-fixer alignment in `Entities::legalRows`.** Fixed in dfd66c7.

**2. Added lang keys `legalCode` and `legalWording`** (dt labels in the section), besides the planned legalTitle/legalProvisional/legalHint/legalMissing.

**3. Section id.** The `<section>` carries `id="entity-legal"` with the heading `entity-legal-title`, used by the feature test.

## Final verification (Task 3)
- Full PHPUnit suite: 614 tests, 5544 assertions, green. JS tests: 29 pass. `composer stan` and `composer cs` clean.
- Roadmap criteria 1-5: covered by automated feature/integration tests across plans 03-01..03-10 (entities, versions, bank accounts, series, clients, archive, audit, legal profile code), all green.

## Manual checks NOT performed
The executor is an autonomous agent without a browser or device. The browser walkthrough on `https://work-tracker.loc` and the **iPhone check** (44 px targets, one-handed use, no horizontal scroll, native date picker) were NOT done and must be performed by the owner. Result: pending owner.

## Known Stubs
None. Legal texts are intentionally provisional (flagged in DB and UI).

## Threat Flags
None. T-03-53 (REVOKE INSERT, permission denied tested), T-03-54 (provisional badge, LegalTextMissing), T-03-55 (assertAllowed retained), T-03-56 (esc output), T-03-57 (.example domains, public test IBANs) covered.

## Self-Check: PASSED
Created files exist; commits 590f643, 0153250, b6d561f, 28d9cc8, dfd66c7 exist.
