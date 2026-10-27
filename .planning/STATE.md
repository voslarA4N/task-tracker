---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: verifying
stopped_at: Completed 05-09-PLAN.md
last_updated: "2026-10-27T01:39:26.641Z"
last_activity: 2026-10-27
progress:
  total_phases: 12
  completed_phases: 5
  total_plans: 62
  completed_plans: 62
  percent: 42
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-23)

**Core value:** Vystavená faktúra a výkaz sú správne a nemenné: súčty sa vždy zhodujú na obrazovke, vo výkaze, na faktúre aj v exportoch a žiadna neskoršia zmena už vystavený doklad nezmení.
**Current focus:** Phase 5 — faktura-n-jadro

## Current Position

Phase: 5 (faktura-n-jadro) — EXECUTING
Plan: 9 of 9
Status: Phase complete — ready for verification
Last activity: 2026-10-27 - Completed quick task 261027-jjx: WR-01 + WR-04 (preview token, line order)

Progress: [░░░░░░░░░░] 0%

## Performance Metrics

**Velocity:**

- Total plans completed: 43
- Average duration: - min
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 02 | 20 | - | - |
| 3 | 10 | - | - |
| 04 | 13 | - | - |

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
| Phase 03 P01 | 20min | 2 tasks | 9 files |
| Phase 03 P02 | 45min | 3 tasks | 24 files |
| Phase 03 P03 | 20min | 2 tasks | 9 files |
| Phase 03 P04 | 25min | 3 tasks | 17 files |
| Phase 03 P05 | 35min | 3 tasks | 21 files |
| Phase 03 P06 | 30min | 2 tasks | 15 files |
| Phase 03 P07 | 25min | 2 tasks | 13 files |
| Phase 03 P08 | 40min | 3 tasks | 17 files |
| Phase 03 P09 | 45min | 3 tasks | 17 files |
| Phase 03 P10 | 40min | 3 tasks | 12 files |
| Phase 04 P04 | 30min | 3 tasks | 16 files |
| Phase 04 P09 | n/a | 3 tasks | 7 files |
| Phase 5 P02 | 35min | 3 tasks | 18 files |
| Phase 05 P08 | 50min | 3 tasks | 16 files |
| Phase 05 P09 | 45min | 2 tasks | 11 files |

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
- [Phase 03]: country/currency excluded from app_rw column grants (DB-enforced immutability); Clock::parseDate for strict dates
- [Phase 03]: 03-05: ClientInput validates raw post then normalizes; rate display formatted without float
- [Phase 03]: 03-07: portal URL template accepts only http/https with host and {id} once; preview is text only
- [Phase 04]: 04-04: client currency lock applies to any billing allocation (locked or unlocked), stricter than Discretion text; RESEARCH Pitfall 9
- [Phase 04]: WR-07 (vlastník 2026-10-26, voľba C): importované riadky „Fakturované = ÁNO“ ostávajú zatiaľ nezamknuté časti; vo Phase 5 sa zamknú na historickú (externú) faktúru, aby sa nedali znova fakturovať. Historická faktúra nesmie spotrebovať číslo z radu `RRRRMM###`.

### Pending Todos

- [Phase 5] WR-07 (voľba C): zamknúť importované časti z riadkov „Fakturované = ÁNO“ (dev DB: 09/2026, 11 častí, 89 h, 2 403,00 €) na historickú externú faktúru; bez čísla z vlastného radu; pred prvým ostrým vystavením a pred importom reálnych dát na produkciu
- [Phase 2 follow-up] iPhone: po 7 dňoch od zapamätania zariadenia overiť, že PWA nežiada prihlásenie (cieľ: 2026-11-01; zariadenie zapamätané pri UAT 2026-10-25)
- [Phase 2 follow-up] iPhone: po 30 dňoch overiť, že sa vyžaduje heslo aj TOTP (D-17) a že predtým nebolo žiadne prihlásenie (kritérium 5) (cieľ: 2026-11-24)

### Blockers/Concerns

- [Phase 2]: Shield + TOTP je najväčšie technické neznáme (spike s fallbackom)
- [Phase 1]: mPDF pod PHP 8.5 s diakritikou treba overiť spikom
- [Phase 3 -> 6, 8, 10]: Otázky pre účtovníčku (limity SK/PL, zákonné texty, kurz, dobropis, retencia) blokujú finálne znenie textov a limity
- [Phase 7]: Vlastník musí zvoliť SMTP poskytovateľa a nastaviť DNS (SPF/DKIM/DMARC)
- [Phase 2]: vlastník musí pripraviť Cloudflare, firewall a server podľa plánu 02-02

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 261027-elv | import:excel --month RRRR-MM (import len jedného mesiaca) | 2026-10-27 | 7d884a4 | [261027-elv-import-excel-month-filter](./quick/261027-elv-import-excel-month-filter/) |
| 261027-jjx | WR-01 + WR-04: preview token z celej kópie údajov, poradie položiek len z DB, blokovanie Vystaviť počas obnovy náhľadu | 2026-10-27 | cef426f | [261027-jjx-preview-token-full-snapshot-and-line-ord](./quick/261027-jjx-preview-token-full-snapshot-and-line-ord/) |

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| *(none)* | | | |

## Session Continuity

Last session: 2026-10-27T01:39:26.605Z
Stopped at: Completed 05-09-PLAN.md
Resume file: None
