---
status: complete
phase: 08-meny-a-kurzy
source: [08-VERIFICATION.md]
started: 2026-10-30T14:10:00Z
updated: 2026-11-03T12:00:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Živý NBP kurz v náhľade (PL subjekt, EUR klient) na staging
expected: Kurz sa načíta živým API (posledný pracovný deň pred dátumom dodania), karta kurzu ukazuje kurz, zdroj, číslo tabuľky, dátum a súčet v PLN
result: pass (vlastník označil ako otestované, 2026-11-03)

### 2. Ručný prepis kurzu → vystavenie → PDF a detail
expected: Obe sumy, kurz, zdroj/ručné označenie a dátum sú na PDF aj v detaile rovnaké; riadky kurzu sa zmestia pod „Spolu“; neskoršia zmena nastavení či cache doklad nezmení
result: pass (vlastník označil ako otestované, 2026-11-03)

### 3. Nedostupné API / budúci dátum dodania, tlačidlo „Skúsiť znova“, iPhone
expected: Zobrazí sa výzva na ručné zadanie (pri budúcom dátume bez Retry), ručné zadanie umožní vystavenie; dotykové ciele ≥ 44 px, bez horizontálneho posúvania
result: pass (vlastník označil ako otestované, 2026-11-03)

### 4. Nastavenia → Kurz mien na iPhone
expected: Zmena dátumu/zdroja pre krajinu sa prejaví v novom náhľade; vystavené faktúry ostávajú nezmenené
result: pass (vlastník označil ako otestované, 2026-11-03)

### 5. Rozhodnutie vlastníka k WR-03
expected: Vlastník potvrdí, že odchýlka ručného kurzu > 10 % je len upozornenie, alebo požiada o blokujúce potvrdenie
result: resolved: vlastník označil ako otestované (2026-11-03), ponecháva sa súčasné správanie: odchýlka ručného kurzu > 10 % je len upozornenie

## Summary

total: 5
passed: 5
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps
