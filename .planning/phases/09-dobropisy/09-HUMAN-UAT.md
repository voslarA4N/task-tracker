---
status: partial
phase: 09-dobropisy
source: [09-VERIFICATION.md]
started: 2026-11-01T12:00:00Z
updated: 2026-11-01T12:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Na staging (work-tracker-stage.vostack.dev) vystav dobropis zo seedovanej faktúry (aj v cudzej mene) a otvor PDF
expected: Záporné položky, odkaz na číslo a dátum pôvodnej faktúry, dôvod, typ opravy, vlastné číslo, kurz a záporný súčet v mene subjektu; rozloženie PDF je čitateľné
result: [pending]

### 2. Na iPhone (PWA) prejdi tok faktúra, dobropis, náhľad, potvrdenie a vystavenie
expected: Ovládateľné jednou rukou, ciele aspoň 44 px, bez horizontálneho posúvania. Pri písaní dôvodu sa nestrácajú znaky (WR-05)
result: [pending]

### 3. Zobraz stornovanú faktúru a stiahni jej PDF
expected: Banner a odznak „stornovaná“, odkaz na dobropis, PDF faktúry je bajt po bajte rovnaké ako pred stornom
result: [pending]

### 4. Odošli dobropis e-mailom na staging
expected: E-mail s PDF dobropisu v prílohe, šablóna dobropisu, správny názov súboru
result: [pending]

### 5. Po storne prefakturuj uvoľnené časti do iného mesiaca
expected: Časti sa ponúkajú na fakturáciu, flash odkaz na uvoľnené časti funguje
result: [pending]

### 6. Rozhodnutie vlastníka k WR-01 (odoslanie stornovanej faktúry klientovi), WR-02 a WR-04
expected: Rozhodnúť, či sa opravia pred nasadením
result: [pending]

## Summary

total: 6
passed: 0
issues: 0
pending: 6
skipped: 0
blocked: 0

## Gaps
