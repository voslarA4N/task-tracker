---
status: complete
phase: 09-dobropisy
source: [09-VERIFICATION.md]
started: 2026-11-01T12:00:00Z
updated: 2026-11-02T00:00:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Na staging (work-tracker-stage.vostack.dev) vystav dobropis zo seedovanej faktúry (aj v cudzej mene) a otvor PDF
expected: Záporné položky, odkaz na číslo a dátum pôvodnej faktúry, dôvod, typ opravy, vlastné číslo, kurz a záporný súčet v mene subjektu; rozloženie PDF je čitateľné
result: pass

### 2. Na iPhone (PWA) prejdi tok faktúra, dobropis, náhľad, potvrdenie a vystavenie
expected: Ovládateľné jednou rukou, ciele aspoň 44 px, bez horizontálneho posúvania. Pri písaní dôvodu sa nestrácajú znaky (WR-05)
result: pass

### 3. Zobraz stornovanú faktúru a stiahni jej PDF
expected: Banner a odznak „stornovaná“, odkaz na dobropis, PDF faktúry je bajt po bajte rovnaké ako pred stornom
result: pass

### 4. Odošli dobropis e-mailom na staging
expected: E-mail s PDF dobropisu v prílohe, šablóna dobropisu, správny názov súboru
result: pass

### 5. Po storne prefakturuj uvoľnené časti do iného mesiaca
expected: Časti sa ponúkajú na fakturáciu, flash odkaz na uvoľnené časti funguje
result: pass

### 6. Rozhodnutie vlastníka k WR-01 (odoslanie stornovanej faktúry klientovi), WR-02 a WR-04
expected: Rozhodnúť, či sa opravia pred nasadením
result: pass — WR-01 opravené (d00c006); WR-02 a WR-04 odložené na koniec všetkých fáz (.planning/todos/pending/2026-11-02-phase9-review-wr02-wr04.md)

## Summary

total: 6
passed: 6
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
