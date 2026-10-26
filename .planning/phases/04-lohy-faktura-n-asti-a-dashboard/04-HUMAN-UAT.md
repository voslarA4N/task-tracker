---
status: partial
phase: 04-lohy-faktura-n-asti-a-dashboard
source: [04-VERIFICATION.md]
started: 2026-10-26T12:30:00Z
updated: 2026-10-26T15:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. iPhone PWA: nová úloha jednou rukou → „Označiť ako zapracovanú“ → „Aktuálny“ → potvrdiť
expected: celý tok cca 30 s jednou rukou; panel sa vysunie zospodu, suma sa živo prepočíta, panel sa zavrie a detail ukáže novú nezamknutú časť
result: passed (vlastník na produkcii work-tracker.vostack.dev, 2026-10-26)

### 2. iPhone: /tasks filtre, FAB, bez horizontálneho posúvania
expected: filtre sa zbalia/rozbalia, FAB nie je prekrytý spodnou lištou, na /, /tasks a /tasks/{id} nie je horizontálne posúvanie
result: passed (vlastník na produkcii work-tracker.vostack.dev, 2026-10-26)

### 3. iPhone: dashboard, prepínač mesiaca a klienta
expected: výber mesiaca/klienta zmení URL aj obsah; dlaždice „Na fakturáciu“ a „V práci“, skupiny, progress bar a spodná lišta sa zobrazia správne
result: passed (vlastník na produkcii work-tracker.vostack.dev, 2026-10-26)

### 4. Otvoriť importovanú úlohu /tasks/{id}
expected: detail sa zobrazí bez chyby, odkaz na portál funguje, súhrn hodín a zoznam častí sedí
result: [pending]

### 5. Rozhodnutie vlastníka k WR-07 (riadky „Fakturované = ÁNO“)
expected: vlastník potvrdí „importovať ako nezamknuté“ (podľa textu špecifikácie), alebo požiada, aby sa už fakturované riadky (napr. 09/2026: 11 častí, 89 h, 2 403,00 €) neimportovali ako fakturovateľné časti pred začiatkom Phase 5
result: resolved: vlastník zvolil C (2026-10-26). Časti sa vo Phase 5 zamknú na historickú externú faktúru; zaznamenané v STATE.md (Decisions, Pending Todos) a v ROADMAP Phase 5

## Summary

total: 5
passed: 4
issues: 0
pending: 1
skipped: 0
blocked: 0

## Gaps
