---
phase: 03-master-d-ta-subjekty-a-klienti
verified: 2026-10-25T00:00:00Z
status: human_needed
score: 5/5 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Browser and iPhone walkthrough of entities and clients (installed PWA or Safari)"
    expected: "Touch targets at least 44 px, one-handed use, no horizontal scroll, native date picker for valid-from, create SK and PL entity, new version, end entity, client create/edit/archive"
    why_human: "Visual and device behavior; Plan 03-10 recorded this as pending owner"
---

# Phase 3: Master data (entities and clients) Verification Report

**Phase Goal:** Owner manages entities (versioned data, bank accounts, immutable country and currency) and clients with everything that later enters the invoice data copy; every change is logged.
**Status:** human_needed (all automated checks pass)
**Re-verification:** No, initial verification

## Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SK (EUR) and PL (PLN) entity with period, series, due days, VAT-UE, multiple bank accounts; country and currency immutable | VERIFIED | Migration 000002: `entities` country/currency CHECKs, `grantUpdateColumns('entities', [name_internal, active_from, active_to, updated_at])` with no country/currency, so DB blocks changes. Bank accounts migration 000004 (iban/swift/currency CHECKs, currency and entity_id not updatable). Controller tests EntityImmutableTest (forged key, version currency, end country) and EntitiesCreateTest, BankAccountsTest. |
| 2 | Change creates new version "valid from"; history visible; versions undeletable | VERIFIED | `entity_versions` is insert-only (no UPDATE/DELETE grant). `EntityService` creates versions with audit in same transaction. `_history.php` and `version_new.php` views. EntityVersionTest and EntityVersionsTest. |
| 3 | Ended entity remains viewable and selectable | VERIFIED | `EntityDirectory::forSelect` returns all entities including ended (testForSelectReturnsAllEntitiesIncludingEnded, testActiveAtPast...). |
| 4 | Client add, edit, archive (no delete) with all fields | VERIFIED | Migration 000003 columns: address, identifier_type/value, rate, currency, doc_language, due_days, portal name/template, billing_mode, count-toward-limit flag. `ClientService`, `Settings/Clients` controller, views, ClientsTest, ClientArchiveTest, ClientsArchivePortalTest. |
| 5 | Exemption code structured in legal profile; every change in audit log with before and after | VERIFIED | Migration 000005 `legal_profiles.exemption_code` (seeded `ART_113_1`, `ART_113_9`), read-only for app_rw, resolved via `LegalProfileResolver` and shown in entity detail. `AuditLogger` (insert-only `audit_log`) called in Entity, Client, BankAccount and Series services in the same transaction. Client-facing settings editing does not exist in this phase, so legal-profile changes are migration-only. |

**Score:** 5/5

## Requirements Coverage

All 16 IDs (ENT-01..07, CLI-01..07, SET-02, AUD-01) are claimed across the 10 plans and are marked complete in REQUIREMENTS.md. No orphaned requirements. Each maps to the artifacts above.

| Requirement | Status |
|-------------|--------|
| ENT-01..ENT-07 | SATISFIED |
| CLI-01..CLI-07 | SATISFIED |
| SET-02 | SATISFIED (the "stored into data copy" part belongs to Phase 5/6) |
| AUD-01 | SATISFIED for Phase 3 entities (entity, version, bank account, series, client). Task, part, invoice and limit logging come in later phases. |

## Behavioral Checks

Orchestrator ran `make test`: 614 tests OK, 5544 assertions. I did not re-run the suite. Executors report stan and cs clean (not re-run).

## Anti-Patterns

No TBD, FIXME or XXX markers in the Phase 3 code, views, migrations or seeder.

## Human Verification Required

1. **Browser and iPhone walkthrough** (pending per 03-10-SUMMARY).
   - Test: on desktop and iPhone, create SK and PL entity, add a version, end an entity, manage bank accounts, create, edit and archive a client.
   - Expected: 44 px touch targets, one-handed use, no horizontal scroll, native date picker.
   - Why human: visual and device behavior.

## Gaps Summary

No gaps. Only the pending manual device walkthrough remains.

_Verified: 2026-10-25_
_Verifier: Claude (gsd-verifier)_
