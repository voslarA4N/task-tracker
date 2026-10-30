---
phase: 08-meny-a-kurzy
plan: 01
subsystem: master-data
tags: [currencies, postgres, fk, validation]
requires: []
provides:
  - tabulka currencies (EUR, PLN, CZK) s FK currency_fk v 5 tabulkach
  - CurrencyRepository (codes, isActive, exists, decimals)
affects: [08-04 exchange_rate_cache FK na currencies(code)]
tech-stack:
  added: []
  patterns: ["validatory mien cez volitelny callable s predvolenou DB kontrolou"]
key-files:
  created:
    - app/Database/Migrations/2026-10-30-000001_CreateCurrencies.php
    - app/Database/Migrations/2026-10-30-000002_ReplaceCurrencyChecks.php
    - app/Libraries/Master/CurrencyRepository.php
    - tests/integration/Master/CurrenciesSchemaTest.php
    - tests/integration/Master/CurrencyRepositoryTest.php
    - tests/feature/Master/CurrencyListTest.php
  modified:
    - app/Libraries/Master/{ClientInput,EntityInput,BankAccountInput,Country}.php
    - app/Controllers/Settings/{Clients,Entities,BankAccounts}.php
    - app/Views/settings/clients/form.php
    - app/Views/settings/entities/_regime_fields.php
  deleted:
    - app/Libraries/Master/Currency.php
decisions:
  - "Staticke validate() metody dostali volitelny posledny parameter ?callable $currencyActive (predvolene CurrencyRepository::isActive), unit testy podavaju fake bez DB"
  - "_regime_fields.php si zoznam mien nacita sam ako fallback, lebo ho vkladaju 3 rozne views"
metrics:
  tasks: 2
  completed: 2026-10-30
---

# Phase 8 Plan 01: Meny ako data Summary

Zoznam mien je tabulka `currencies` (read-only pre app_rw) s FK na `currencies(code)` namiesto pevnych CHECK v entities, clients, entity_bank_accounts, billing_allocations a invoices; formulare aj validacia citaju z DB a enum `Currency` je zmazany.

## Commits
- 626bbb8: tabulka currencies, FK namiesto CHECK, CurrencyRepository, testy
- d6e2965: formulare a validacia z DB, zmazanie enumu, feature test

## Deviations from Plan
**1. [Rule 1 - Bug] AllocationSchemaTest::testUnknownCurrencyRejected** ocakaval "violates check constraint"; po zmene na FK sa chyba menila na "foreign key constraint". Test upraveny (tests/integration/Work/AllocationSchemaTest.php).

Testy v Task 1 boli napisane spolu s implementaciou (nie samostatny RED commit).

## Verification
- `make test`: 1591 testov, po oprave jedneho testu zelene (AllocationSchemaTest znovu spusteny, OK); `make stan` OK.

## Known Stubs
None.

## Self-Check: PASSED
