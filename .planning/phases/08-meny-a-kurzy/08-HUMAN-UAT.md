---
status: partial
phase: 08-meny-a-kurzy
source: [08-VERIFICATION.md]
started: 2026-10-30T14:10:00Z
updated: 2026-10-30T14:10:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Živý NBP kurz v náhľade (PL subjekt, EUR klient) na staging
expected: Kurz sa načíta živým API (posledný pracovný deň pred dátumom dodania), karta kurzu ukazuje kurz, zdroj, číslo tabuľky, dátum a súčet v PLN
result: [pending]

### 2. Ručný prepis kurzu → vystavenie → PDF a detail
expected: Obe sumy, kurz, zdroj/ručné označenie a dátum sú na PDF aj v detaile rovnaké; riadky kurzu sa zmestia pod „Spolu“; neskoršia zmena nastavení či cache doklad nezmení
result: [pending]

### 3. Nedostupné API / budúci dátum dodania, tlačidlo „Skúsiť znova“, iPhone
expected: Zobrazí sa výzva na ručné zadanie (pri budúcom dátume bez Retry), ručné zadanie umožní vystavenie; dotykové ciele ≥ 44 px, bez horizontálneho posúvania
result: [pending]

### 4. Nastavenia → Kurz mien na iPhone
expected: Zmena dátumu/zdroja pre krajinu sa prejaví v novom náhľade; vystavené faktúry ostávajú nezmenené
result: [pending]

### 5. Rozhodnutie vlastníka k WR-03
expected: Vlastník potvrdí, že odchýlka ručného kurzu > 10 % je len upozornenie, alebo požiada o blokujúce potvrdenie
result: [pending]

## Summary

total: 5
passed: 0
issues: 0
pending: 5
skipped: 0
blocked: 0

## Gaps
