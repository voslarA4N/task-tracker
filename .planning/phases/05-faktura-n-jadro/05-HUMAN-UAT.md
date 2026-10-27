---
status: partial
phase: 05-faktura-n-jadro
source: [05-VERIFICATION.md]
started: 2026-10-27T12:00:00Z
updated: 2026-10-27T12:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. iPhone: fakturácia mesiaca jednou rukou a otvorenie PDF
Na iPhone (inštalovaná PWA): Faktúry → Fakturovať mesiac → výber klienta a mesiaca jednou rukou, presun jednej časti do ďalšieho mesiaca, vystavenie cez spodný potvrdzovací panel, Zobraziť PDF.
expected: Položky, súčet, dátumy a diakritika sú v PDF čitateľné; bez horizontálneho posúvania; dotykové ciele aspoň 44 px.
result: [pending]

### 2. Rozhodnutie k WR-01 (rozsah preview_token)
Zmeniť dátum vystavenia v náhľade a ťuknúť Vystaviť skôr, než sa vráti htmx obnovenie.
expected: Vlastník rozhodne, či je pre túto fázu prijateľné, že sa môžu vystaviť zastarané skryté dátumy (a token nepokrýva subjekt, banku, právny text, hlavičku klienta), alebo či sa má token spevniť.
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
