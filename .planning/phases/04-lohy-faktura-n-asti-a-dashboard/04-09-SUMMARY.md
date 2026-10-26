---
phase: 04-lohy-faktura-n-asti-a-dashboard
plan: 09
subsystem: import
tags: [spark, excel, import, phpspreadsheet, idempotency, dry-run]
requires:
  - phase: 04-03
    provides: ExcelReader a ImportPlan
  - phase: 04-08
    provides: TaskService::create a markDone, AllocationService
provides:
  - "spark import:excel <súbor> --client <id> [--commit] (suchý beh a ostrý import v jednej transakcii)"
  - "ImportRunner, ImportResult, ImportReportFormatter"
  - "Runbook docs/ops/import-excel.md"
affects: [phase-04-uat]
tech-stack:
  added: []
  patterns:
    - "Import cez doménové služby (TaskService, AllocationService), nie surový INSERT"
    - "Idempotencia cez import_key"
    - "class_exists guard na PhpSpreadsheet, produkcia bez neho nepadá"
key-files:
  created:
    - app/Libraries/Work/Import/ImportRunner.php
    - app/Libraries/Work/Import/ImportResult.php
    - app/Libraries/Work/Import/ImportReportFormatter.php
    - app/Commands/ImportExcel.php
    - tests/integration/Work/ImportExcelTest.php
    - tests/unit/Work/Import/ImportReportFormatterTest.php
    - docs/ops/import-excel.md
key-decisions:
  - "ÁNO = zapracovaná úloha s jednou nezamknutou časťou v mesiaci bloku; NIE = úloha V práci bez časti"
  - "Report vypíše nečitateľný súčet z Excelu ako 'nenačítané', nikdy ho potichu nevynechá"
requirements-completed: [IMP-01]
duration: n/a
completed: 2026-10-26
---

# Phase 4 Plan 09: Excel Import Summary

Jednorazový import `All4net-fakturovanie-prace.xlsx` cez `spark import:excel` so suchým behom, jednou transakciou cez doménové služby a idempotenciou cez `import_key`; živý import overený na vývojovej DB.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Failing import tests (RED) | 8c93039 |
| 2 | ImportRunner, ImportResult, ImportReportFormatter, spark import:excel (GREEN) | 02c33b6 |
| 2 | Runbook docs/ops/import-excel.md | 42b0e74 |
| 3 | Checkpoint human-verify: živý import | bez kódu (vlastník zvolil "spusti za mňa", overil orchestrátor) |
| - | Oprava poznámky o rozsahu vzorca v runbooku | viď git log (`docs(04-09): correct formula range note in runbook`) |

## Live import result (dev DB, klient 1, 27 EUR)

- Ostrý beh vytvoril 16 úloh a 12 častí; report zhodný so suchým behom: 09/2026 89 h / 2 403,00 € (Excel = prepočet), 10/2026 82 h / 2 214,00 € (zapracované 1 h / 27,00 €).
- Upozornenia: vzorce súčtu E19:E22 a F19:F22 nepokrývajú riadky bloku 20 až 24 (chyba v Exceli vlastníka, súčty sa napriek tomu zhodujú); 11 riadkov bez špecifického odkazu.
- Kontrola DB: 12 zapracovaných úloh, 12 častí, 90,00 h / 2 430,00 €, 0 zamknutých; 4 úlohy V práci bez častí. 2026-09: 11 častí 89,00 h 2 403,00 €; 2026-10: 1 časť 1,00 h 27,00 €. Každá zapracovaná úloha má práve jednu časť.
- Idempotencia: druhý `--commit` dal vytvorené 0, preskočené 16, časti 0; počet úloh zostal 16.
- Dočasný /tmp/real.xlsx odstránený z kontajnera.

## Deviations from Plan

**1. [Rule 3 - Blocking] Syntax volieb CI4 CLI** - voľby sa zadávajú `--client <id>` (s medzerou), `--commit` musí nasledovať za cestou a `--client`. Runbook upravený.

**2. [Rule 3 - Blocking] Premenovanie testových pomocníkov** - `count`/`run` sú v PHPUnit final, helpery premenované.

**3. [Rule 2 - Missing critical] Formatter vypisuje nečitateľné súčty ako "nenačítané"** - namiesto tichého vynechania.

**4. [Rule 1 - Doc bug] Runbook uvádzal E25/F25** - skutočné rozsahy sú E19:E22/F19:F22, opravené samostatným commitom.

## Deferred

- Otvorenie stránky /tasks/{id} v prehliadači po importe nebolo overené (vyžaduje prihlásenie); presunuté do záverečného ľudského UAT fázy 4.

## Known Stubs

None.

## Self-Check: PASSED
