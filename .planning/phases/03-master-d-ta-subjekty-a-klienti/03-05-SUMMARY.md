---
phase: 03-master-d-ta-subjekty-a-klienti
plan: 05
subsystem: master-data
tags: [clients, validation, audit, alpine-csp, phpunit]
requires: [03-03]
provides:
  - clients table (column grants, no DELETE, CHECK eu_b2b requires vat_id)
  - ClientService create/update/find/listAll with audit in one transaction
  - ClientInput, CcEmails, BillingMode, IdentifierType
  - Settings\Clients list and form at /settings/clients, More link
  - Alpine clientForm component
affects: [03-07, phase-04, phase-05]
tech-stack:
  added: []
  patterns: [validate raw post then normalize, audit only changed fields via recordChange, display-only money formatting without float]
key-files:
  created:
    - app/Database/Migrations/2026-10-25-000003_CreateClients.php
    - app/Libraries/Master/BillingMode.php
    - app/Libraries/Master/IdentifierType.php
    - app/Libraries/Master/CcEmails.php
    - app/Libraries/Master/ClientInput.php
    - app/Libraries/Master/ClientService.php
    - app/Controllers/Settings/Clients.php
    - app/Config/Routes/clients.php
    - app/Views/settings/clients/index.php
    - app/Views/settings/clients/form.php
    - app/Language/sk/Clients.php
    - app/Language/en/Clients.php
    - tests/unit/Master/CcEmailsTest.php
    - tests/unit/Master/ClientInputTest.php
    - tests/integration/Master/ClientServiceTest.php
    - tests/feature/Master/ClientsTest.php
    - tests/js/client-form.test.js
  modified:
    - app/Views/more/index.php
    - public/assets/js/app.js
    - public/assets/css/app.css
    - tests/_support/MasterFixtures.php
key-decisions:
  - "ClientInput::validate takes raw post values and normalizes identifiers internally; controller order is fromPost, validate, normalize"
  - "Rate display formatted without float: integer part via NumberFormatter, fraction from Money string, currency symbol position from the locale pattern"
requirements-completed: [CLI-01, CLI-02, CLI-03, CLI-05, CLI-06, CLI-07]
duration: 35min
completed: 2026-10-25
---

# Phase 3 Plan 05: Client Create and Edit Summary

Owner opens More -> Clients, sees the active client list and adds or edits a client with address, typed identifier, tax IDs, billing mode (domestic / eu_b2b / non_eu), rate (numeric string), currency, optional document language and due days, turnover-limit flag (default on), invoice e-mail and up to 5 CC e-mails. Clients cannot be deleted (no grant, no route); create and update are audited with only changed fields.

## Tasks
1. RED tests: e94a7ce
2. Migration, enums, CcEmails, ClientInput, ClientService, sk/en language: 57954f0
3. Controller, routes, views, More link, Alpine clientForm, CSS check rows: 3692173

## Deviations from Plan

**1. [Rule 1 - Bug] Rate display without float.** The plan's list view suggested `NumberFormatter::formatCurrency`, which requires a float, and the project PHPStan rule `app.forbiddenFloat` rejects the cast. Implemented a string-based formatter in the controller (identical output, e.g. `27,00 €`).

**2. [Rule 2 - Missing] `.master-check` CSS** added to `public/assets/css/app.css` so radio and checkbox rows meet the 44 px touch target (UI-SPEC). Not in the plan's file list.

**3. validate() input.** The plan's interfaces describe validate after normalize but also validate rates "before normalization"; validate takes raw values and normalizes identifiers itself so the same values can be re-rendered on 422.

## Known Stubs
None. Portal columns (`portal_name`, `portal_url_template`), archivation UI and filter are created in schema only and delivered by 03-07, as planned.

## Threat Flags
None. T-03-24 to T-03-30 covered by CcEmails, grants, whitelist, Money string, esc, CSRF and audit tests.

## Verification
Full suite 472 tests green, `composer stan` clean, `composer cs` clean (0 of 220 files), `node --test tests/js` 29 pass.

## Self-Check: PASSED
Files exist and commits e94a7ce, 57954f0, 3692173 exist.
