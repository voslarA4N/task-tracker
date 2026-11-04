# Phase 11: Prehľady, exporty a audit - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-04
**Phase:** 11-Prehľady, exporty a audit
**Areas discussed:** Dátum pre „vyfakturované“, obsah ZIP balíka, obrazovka Používatelia, PC tabuľky

Ostatné rozhodnutia sú prevzaté zo špecifikácie, PRD a REQUIREMENTS (preferencia vlastníka, bez interaktívnej diskusie).

---

## Dátum pre „vyfakturované“ (RPT-01)

| Option | Description | Selected |
|--------|-------------|----------|
| Dátum dodania | Rovnaký základ ako limity obratu, súčty sedia s dashboardom | ✓ |
| Dátum vystavenia | Zhoda s obdobím číslovania a dátumom dokladu | |

**User's choice:** Dátum dodania

---

## Obsah ZIP balíka (RPT-03)

| Option | Description | Selected |
|--------|-------------|----------|
| Faktúry + dobropisy | Len účtovné doklady a CSV zoznam | ✓ |
| Aj výkazy práce | Pridá PDF výkazu ku každej faktúre | |

**User's choice:** Faktúry + dobropisy

---

## Nastavenia → Používatelia (UI-03)

| Option | Description | Selected |
|--------|-------------|----------|
| Zoznam len na čítanie | Rola, stav 2FA, posledné prihlásenie; nový účet cez `spark` | ✓ |
| Zoznam + vytvoriť/deaktivovať | Správa účtovníčky z UI, zasahuje do v2 | |

**User's choice:** Zoznam len na čítanie

---

## PC tabuľky (UI-03)

| Option | Description | Selected |
|--------|-------------|----------|
| Tabuľka na ≥lg, karty na mobile | Jeden htmx fragment, triedenie a filtre, export nad tabuľkou | ✓ |
| Samostatné desktopové stránky | Vlastné URL, duplicitné dotazy a šablóny | |

**User's choice:** Tabuľka na ≥lg, karty na mobile

---

## Claude's Discretion

- Rozloženie tabuliek, triedenie, stránkovanie
- Spoločná služba na výber dokladov za obdobie
- Externé faktúry v súčtoch prehľadu
- Limit obdobia a času pri ZIP
- Preklady nových textov (PL/CZ/EN vo fáze 12)

## Deferred Ideas

- Plné UI pre rolu `accountant` (v2)
- Správa používateľov v UI (v2)
- Výkazy práce v balíku pre účtovníčku
- Export KSeF FA(3)
