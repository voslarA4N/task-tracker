---
phase: 12-lokaliz-cia-a-produkcia
plan: 03
subsystem: database
tags: [postgres, immutability, triggers, grants, tests]
requires: []
provides:
  - "Matica DELETE/TRUNCATE/UPDATE pre každú tabuľku dokladov (app_rw aj app_owner)"
  - "Katalógový poistný test proti novej nechránenej tabuľke s FK na invoices/invoice_documents"
  - "PgTestCase::assertDbError"
affects: [12-04, 12-10]
tech-stack:
  added: []
  patterns: ["PROTECTED_TABLES / EXEMPT_TABLES s dôvodom", "transException(true) pre pokusy ownera v transakcii"]
key-files:
  created:
    - tests/integration/Database/ImmutableDocumentsTest.php
  modified:
    - tests/_support/PgTestCase.php
key-decisions:
  - "Ochrana bola úplná, migrácia 2026-11-05-000001_GuardDocumentTablesComplete nebola potrebná a nevznikla"
requirements-completed: []
duration: 20min
completed: 2026-11-04
---

# Phase 12 Plan 03: Nemazateľnosť dokladov Summary

Integračný test dokazuje, že invoices (aj dobropisy), invoice_lines, invoice_documents, invoice_counters, invoice_sends, invoice_send_voids, payments, payment_voids a invoice_regen_windows sa nedajú zmazať, vyprázdniť ani zmeniť ako app_rw (GRANT) ani ako app_owner (triggery) nad neprázdnymi tabuľkami.

## Commits
- 4290913 test: immutability matrix and catalog guard for document tables (oba tasky v jednom commite, jeden súbor)

## Výsledok
- Ochrana v schéme bola úplná: žiadna tabuľka bez ochrany, migrácia nevznikla.
- invoice_counters: app_rw má grant UPDATE(last_value), preto test overuje, že nezvyšujúci UPDATE zlyhá cez counter_guard.
- Katalógový test: FK na invoices/invoice_documents musí byť v PROTECTED_TABLES alebo EXEMPT_TABLES (billing_allocations s dôvodom). Test tiež overí BEFORE DELETE a BEFORE TRUNCATE trigger a že app_rw nemá DELETE/TRUNCATE.
- Zvyškové riziko (DISABLE TRIGGER, DROP TABLE ako app_owner) zdokumentované v docbloku testu (T-12-12).

## Deviations from Plan
- [Rule 3] CI4 v transakcii nevyhodí výnimku pri zlyhanom dotaze, preto pomocník volá `transException(true)` počas pokusu ownera.
- Oba tasky v jednom commite, lebo je to jeden testový súbor.

## Tests
`phpunit tests/integration/Database tests/integration/DbRolesTest.php`: OK (49 tests, 229 assertions).

## Self-Check: PASSED
