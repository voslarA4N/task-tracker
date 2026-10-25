---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: Phase 3 UI-SPEC approved
last_updated: "2026-10-25T15:21:34.221Z"
last_activity: 2026-10-25 -- Phase 3 planning complete
progress:
  total_phases: 12
  completed_phases: 2
  total_plans: 40
  completed_plans: 30
  percent: 17
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-23)

**Core value:** Vystavená faktúra a výkaz sú správne a nemenné: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena už vystavený doklad nezmení.
**Current focus:** Phase 02 — identita-a-skor-nasadenie

## Current Position

Phase: 3
Plan: Not started
Status: Ready to execute
Last activity: 2026-10-25 -- Phase 3 planning complete

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 20
- Average duration: - min
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 02 | 20 | - | - |

**Recent Trend:**

- Last 5 plans: -
- Trend: -

*Updated after each plan completion*
| Phase 01 P01 | 25min | 4 tasks | 40 files |
| Phase 01 P04 | 25min | 3 tasks | 22 files |
| Phase 01 P06 | 15min | 2 tasks | 6 files |
| Phase 01 P10 | 30min | 3 tasks | 3 files |
| Phase 02 P08 | 40min | 3 tasks | 14 files |
| Phase 02 P10 | 35min | 3 tasks | 15 files |
| Phase 02 P11 | 20min | 3 tasks | 16 files |
| Phase 02 P12 | 40min | 3 tasks | 17 files |
| Phase 02 P13 | 25min | 2 tasks | 11 files |
| Phase 02 P15 | 35min | 3 tasks | 19 files |
| Phase 02 P16 | 45min | 3 tasks | 19 files |
| Phase 02 P17 | 30min | 3 tasks | 15 files |
| Phase 02 P18 | 40min | 3 tasks | 17 files |
| Phase 02 P19 | 35min | 3 tasks | 11 files |
| Phase 02 P09 | 60min | 2 tasks | 7 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Init]: PDF faktúry je autoritatívny doklad (uložené so sha256, nikdy sa neregeneruje)
- [Init]: Krajina a mena subjektu sú nemenné, iná krajina alebo mena = nový subjekt
- [Init]: QR platba (INV-11) je vo V1, spätný dátum vystavenia povolený s varovaním
- [Roadmap]: Fakturačné jadro (Phase 5) je izolované bez kurzov, kurzy až Phase 8, limity až po dobropisoch (Phase 10)
- [Roadmap]: Skorý HTTPS staging a test na reálnom iPhone už vo Phase 2
- [Phase 01]: mPDF: DejaVu pinned, substitutions off, 256M memory ample (peak 28MB, 0 deprecations, ~0.3s)
- [Phase 01]: 01-08: feature tests read bodies via response()->getBody(); TestResponse::getBody is DOM-normalised
- [Phase 01]: demo_calculations is insert-only with CHECK amount=round(hours*rate,2); demo controller shares validation between calculate/store
- [Phase 01]: CI .env uses CI_ENVIRONMENT=development; phpstan schema cache in build/phpstan
- [Phase 02-01]: balíky shield/settings/otphp/bacon/psr-clock schválené 2026-10-24 (overil Claude cez Packagist API na pokyn vlastníka; tranzitívne aj dasprid/enum, paragonie/constant_time_encoding, symfony/deprecation-contracts)
- [Phase 02-01]: O-1 = revoke (odhlásenie odvolá aj dôveru aktuálneho zariadenia)
- [Phase 02-01]: O-2 = 10 (záložný kód XXXXX-XXXXX, HMAC-SHA256 s pepperom mimo DB, pokusy obmedzené)
- [Phase 02-01]: O-3 = metadata (staging mail log iba druh, príjemca, predmet, čas; nikdy telo ani reset odkaz)
- [Phase 02-01]: O-4 = letsencrypt (DNS-01 cez Cloudflare token Zone:DNS:Edit, spĺňa NFR-03)
- [Phase 02-01]: O-5 = yes (zapamätanie zariadenia aj pri prvom nastavení 2FA, TOTP bol práve zadaný)
- [Phase 02-01]: O-6 = followup (7- a 30-dňová kontrola iPhone ako sledované úlohy)
- [Phase 02]: 02-03: Shield spike passed; TOTP as Shield login action (totp_gateway), array-only Settings handler

### Pending Todos

- [Phase 2 follow-up] iPhone: po 7 dňoch od zapamätania zariadenia overiť, že PWA nežiada prihlásenie (cieľ: 2026-11-01; zariadenie zapamätané pri UAT 2026-10-25)
- [Phase 2 follow-up] iPhone: po 30 dňoch overiť, že sa vyžaduje heslo aj TOTP (D-17) a že predtým nebolo žiadne prihlásenie (kritérium 5) (cieľ: 2026-11-24)

### Blockers/Concerns

- [Phase 2]: Shield + TOTP je najväčšie technické neznáme (spike s fallbackom)
- [Phase 1]: mPDF pod PHP 8.5 s diakritikou treba overiť spikom
- [Phase 3 -> 6, 8, 10]: Otázky pre účtovníčku (limity SK/PL, zákonné texty, kurz, dobropis, retencia) blokujú finálne znenie textov a limity
- [Phase 7]: Vlastník musí zvoliť SMTP poskytovateľa a nastaviť DNS (SPF/DKIM/DMARC)
- [Phase 2]: vlastník musí pripraviť Cloudflare, firewall a server podľa plánu 02-02

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| *(none)* | | | |

## Session Continuity

Last session: 2026-10-25T14:21:25.128Z
Stopped at: Phase 3 UI-SPEC approved
Resume file: .planning/phases/03-master-d-ta-subjekty-a-klienti/03-UI-SPEC.md
