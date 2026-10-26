# Phase 5: Fakturačné jadro - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-26
**Phase:** 05-faktura-n-jadro
**Areas discussed:** Rozsah diskusie, Historická externá faktúra (WR-07)

---

## Rozsah diskusie

| Option | Description | Selected |
|--------|-------------|----------|
| Zober zo špecifikácie | Rozhodnutia zo spec/PRD/REQUIREMENTS/research, nejasnosti do Claude's Discretion, technické otázky rieši výskum | ✓ |
| Obrazovka Fakturácia mesiaca | Náhľad, vyradenie časti, predvolené dátumy, potvrdenie a stav po vystavení | |
| Kódy DPH a položky | Predvolený kód DPH podľa profilu, text a poradie položiek | |

**User's choice:** Zober zo špecifikácie
**Notes:** V súlade s predchádzajúcou preferenciou vlastníka (Phase 3).

---

## Historická externá faktúra (WR-07)

| Option | Description | Selected |
|--------|-------------|----------|
| Externé číslo voliteľné | Záznam typu „externá“, voliteľné textové číslo/odkaz, bez čísla z radu, bez PDF, mimo obratu a limitov, v zozname označená | ✓ |
| Poznám číslo, zadám ho | To isté, ale externé číslo je povinné | |
| Len zámok bez faktúry | Technický záznam, ktorý sa v zozname faktúr nezobrazuje | |

**User's choice:** Externé číslo voliteľné

---

## Claude's Discretion

- Technické otázky pre výskum (PDF v `bytea` vs súbor, render v transakcii, `transException`, determinizmus mPDF).
- Predvolený mesiac a klient na obrazovke, poradie položiek, rozsah zoznamu a detailu faktúry, názvy tabuliek a tried, texty v UI.

## Deferred Ideas

- Predkontrola limitu (Phase 10), cudzia mena s kurzom (Phase 8), finálne PDF a výkaz (Phase 6), e-mail a úhrady (Phase 7), dobropisy (Phase 9), PC tabuľky a exporty (Phase 11), plné zálohy (Phase 12).
