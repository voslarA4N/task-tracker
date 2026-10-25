---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 08
subsystem: master-data
tags: [bank-accounts, iban, entities, grants, audit, phpunit]
requires: [03-06]
provides:
  - entity_bank_accounts table (partial unique default and active IBAN, column grants without currency, no DELETE)
  - Iban (normalize, isValid mod-97 with SK/PL/CZ lengths, format)
  - BankAccountService (add, update, setDefault, archive, listFor, find), IDOR-safe by (entityId, accountId)
  - BankAccountSelector::forCurrency (currency match, default fallback, NoBankAccount)
  - Settings\BankAccounts controller, bank accounts section on entity detail, account form
affects: [phase-05]
tech-stack:
  added: []
  patterns: [two-statement default switch in transaction, archive instead of delete]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000004_CreateEntityBankAccounts.php
    - app/Libraries/Master/Iban.php
    - app/Libraries/Master/BankAccountInput.php
    - app/Libraries/Master/BankAccountService.php
    - app/Libraries/Master/BankAccountSelector.php
    - app/Libraries/Master/NoBankAccount.php
    - app/Controllers/Settings/BankAccounts.php
    - app/Views/settings/entities/_bank_accounts.php
    - app/Views/settings/entities/bank_account_form.php
    - tests/unit/Master/IbanTest.php
    - tests/integration/Master/BankAccountTest.php
    - tests/feature/Master/BankAccountsTest.php
  modified:
    - app/Controllers/Settings/Entities.php
    - app/Config/Routes/entities.php
    - app/Views/settings/entities/show.php
    - app/Language/sk/Entities.php
    - app/Language/en/Entities.php
key-decisions:
  - "Archived bank accounts return 404 on edit, update and set-default (only active accounts are editable)"
  - "Non-htmx GET of the archived route redirects to detail with ?archived=1 (no-JS fallback)"
  - "Duplicate active IBAN on one entity is rejected with 422 errorIbanDuplicate (unique index violation mapped in controller)"
requirements-completed: [ENT-06]
duration: 40min
completed: 2026-10-25
---

# Phase 3 Plan 08: Entity Bank Accounts Summary

Owner can add several bank accounts per entity (name, IBAN with mod-97 and country length check, SWIFT, currency), edit them (currency fixed), set a single default and archive them with a confirmation modal. Accounts are never deleted, every change is audited, and `BankAccountSelector::forCurrency` returns the account in the invoice currency, else the default, else throws `NoBankAccount`.

## Tasks
1. RED tests (IbanTest, BankAccountTest, BankAccountsTest): 93edda7
2. Migration, Iban, input, service, selector, translations: 00fc6bb
3. Controller, routes, section and form views: f20004a

## Deviations from Plan

**1. [Rule 2 - Missing critical] Duplicate active IBAN handling.** The plan's schema has a unique index on (entity_id, iban) for active accounts, but no user-facing handling; a duplicate would have produced a 500. Controller now maps the violation to a 422 with new key `errorIbanDuplicate` (sk/en). Also added `errorBankLabel`, `errorBankCurrency`, `bankNewTitle`, `bankEditTitle`, `bankArchiveTitle`, `bankSwiftLine`, `bankLabelPlaceholder` keys.

**2. Archived route fallback.** Instead of `htmx_view` with the show page as fallback, a non-htmx request is redirected to `/settings/entities/{id}?archived=1`, which renders archived accounts inline.

**3. Archived accounts are not editable.** The plan did not say; edit, update and set-default on an archived account return 404.

**4. Feature 404 test.** The feature test harness rethrows `PageNotFoundException`, so the IDOR test asserts the exception (same approach as LayoutTest) rather than status 404.

## Known Stubs
None.

## Threat Flags
None. T-03-42 to T-03-47 covered: every query carries `entity_id`, column grant excludes currency and entity_id with no DELETE grant (permission denied test), partial unique index plus two-statement switch, mod-97 plus DB CHECK, audit in the same transaction, POST with csrf_field.

## Verification
Full PHPUnit suite 552 tests green, JS tests 29 green, `composer stan` clean, `composer cs` clean.

## Self-Check: PASSED
All created files exist; commits 93edda7, 00fc6bb and f20004a exist.
