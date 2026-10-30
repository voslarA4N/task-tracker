---
phase: 08-meny-a-kurzy
plan: 02
subsystem: fx
tags: [tdd, bcmath, currency, snapshot]
requires: []
provides:
  - "FxConverter::convert (jediné miesto prepočtu, D-10)"
  - "FxBoundDate anchor/bound (D-05)"
  - "RateQuote, FxQuote::core() (snapshot fx, D-09/D-14)"
  - "FxFormat rate/parts/expression/sourceLabel (D-13/D-15)"
affects: [08-04, 08-05, 08-06, 08-07]
tech-stack:
  added: []
  patterns: ["kurz ako publikovaný reťazec + smer + amount", "jedno half-up zaokrúhlenie na 2 desatinné"]
key-files:
  created:
    - app/Libraries/Fx/RateQuote.php
    - app/Libraries/Fx/FxQuote.php
    - app/Libraries/Fx/FxConverter.php
    - app/Libraries/Fx/FxBoundDate.php
    - app/Libraries/Fx/FxFormat.php
    - tests/unit/Fx/FxConverterTest.php
    - tests/unit/Fx/FxBoundDateTest.php
    - tests/unit/Fx/FxQuoteTest.php
    - tests/unit/Fx/FxFormatTest.php
  modified:
    - tests/_support/PhpStan/ForbiddenCallsRule.php
key-decisions:
  - "bcmath je povolený aj v FxConverter a FxQuote (PHPStan pravidlo rozšírené)"
  - "FxBoundDate parsuje dátum cez service('clock')->parseDate (PHPStan zakazuje DateTimeImmutable mimo Clock)"
  - "Validácia kurzu zdieľaná v FxConverter::assertRate"
requirements-completed: [CUR-02, CUR-05]
duration: ~25min
completed: 2026-10-30
---

# Phase 8 Plan 02: Fx math Summary

Čisté bcmath kamene kurzového prepočtu: multiply (NBP/ČNB) a divide (ECB), jedno half-up zaokrúhlenie, kurz ako publikovaný reťazec, deterministické jadro snapshotu a jednotný text kurzu.

Commits: RED `test(08-02)` x2, GREEN `feat(08-02)` x2 (REFACTOR nebol potrebný). 47 testov v tests/unit/Fx.

## Deviations from Plan

**1. [Rule 3 - Blocking] PHPStan ForbiddenCallsRule** blokoval bcmath v Fx a `DateTimeImmutable::createFromFormat`. Pravidlo rozšírené o FxConverter.php a FxQuote.php; FxBoundDate používa `service('clock')->parseDate`. `make stan` je čistý.

**2. FxConverter overflow:** `Money::of` pri príliš veľkom výsledku hádže InvalidArgumentException, preto je pred ním explicitná kontrola abs > MAX s OverflowException.

## Self-Check: PASSED
Full `make test`: OK (1638 tests, 24017 assertions); `make stan` OK.
