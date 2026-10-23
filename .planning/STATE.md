---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Completed 01-01-PLAN.md
last_updated: "2026-10-23T20:50:21.387Z"
last_activity: 2026-10-23
progress:
  total_phases: 12
  completed_phases: 0
  total_plans: 10
  completed_plans: 5
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-23)

**Core value:** Vystavená faktúra a výkaz sú správne a nemenné: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena už vystavený doklad nezmení.
**Current focus:** Phase 01 — Základ a infraštruktúra

## Current Position

Phase: 01 (Základ a infraštruktúra) — EXECUTING
Plan: 6 of 10
Status: Ready to execute
Last activity: 2026-10-23

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: - min
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:**

- Last 5 plans: -
- Trend: -

*Updated after each plan completion*
| Phase 01 P01 | 25min | 4 tasks | 40 files |
| Phase 01 P04 | 25min | 3 tasks | 22 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Init]: PDF faktúry je autoritatívny doklad (uložené so sha256, nikdy sa neregeneruje)
- [Init]: Krajina a mena subjektu sú nemenné, iná krajina alebo mena = nový subjekt
- [Init]: QR platba (INV-11) je vo V1, spätný dátum vystavenia povolený s varovaním
- [Roadmap]: Fakturačné jadro (Phase 5) je izolované bez kurzov, kurzy až Phase 8, limity až po dobropisoch (Phase 10)
- [Roadmap]: Skorý HTTPS staging a test na reálnom iPhone už vo Phase 2

### Pending Todos

None yet.

### Blockers/Concerns

- [Phase 2]: Shield + TOTP je najväčšie technické neznáme (spike s fallbackom)
- [Phase 1]: mPDF pod PHP 8.5 s diakritikou treba overiť spikom
- [Phase 3 -> 6, 8, 10]: Otázky pre účtovníčku (limity SK/PL, zákonné texty, kurz, dobropis, retencia) blokujú finálne znenie textov a limity
- [Phase 7]: Vlastník musí zvoliť SMTP poskytovateľa a nastaviť DNS (SPF/DKIM/DMARC)
- [Phase 2]: Vlastník objedná Hetzner VPS a Storage Box (overiť podmienky)

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| *(none)* | | | |

## Session Continuity

Last session: 2026-10-23T20:50:21.364Z
Stopped at: Completed 01-01-PLAN.md
Resume file: None
