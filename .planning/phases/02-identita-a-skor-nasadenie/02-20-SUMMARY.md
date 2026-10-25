---
phase: 02-identita-a-skor-nasadenie
plan: 20
subsystem: ops
tags: [staging, smoke, uat, iphone, pwa]
requires: [02-09, 02-13, 02-19]
provides: [phase-2-on-staging, iphone-uat-passed]
affects: []
key-files:
  created: [docs/ops/iphone-uat.md]
  modified: [docs/ops/staging.md, scripts/smoke-staging.sh, .planning/STATE.md]
key-decisions:
  - "Push v 02-20 vynechaný: main (ba9b92f) už obsahoval celú fázu a bol nasadený zeleným CI behom 40132767378"
  - "Dlhodobé kontroly O-6: 2026-11-01 (7 dní) a 2026-11-24 (30 dní)"
duration: ~30min + UAT vlastníka
completed: 2026-10-25
---

# Phase 02 Plan 20: Celá fáza na stagingu a UAT na iPhone Summary

**Fáza 2 beží na https://work-tracker.vostack.dev (tag sha-ba9b92f…), staging smoke s dočasným účtom je OK vrátane výmeny session cookie po hesle, pamäť v pokoji 58 MiB a vlastník prešiel všetkých 12 krokov UAT na iPhone a PC bez chyby.**

## Tasks

| Task | Názov | Commit |
|------|-------|--------|
| 1 | Nasadenie fázy a staging smoke s dočasným účtom | e2f2416 (fix), bc045f0 (docs), 4171daa (docs) |
| 2 | Kontrolný zoznam UAT pre iPhone | 5a7f283 |
| 3 | Admin účet a UAT vlastníka | tento commit (výsledky) |

## Task 1 výsledky

- `make check` 335 testov, `node --test tests/js` 26/26.
- CI beh 40132767378 na ba9b92f: check + deploy success; `.last-good-tag` = `sha-ba9b92f689e80dff486c4d57865e3b48860d5dcf`.
- `smoke-staging.sh`: `SMOKE OK`, riadok „po hesle 302/303 /2fa a nová session cookie“ = OK (Pitfall 3 / T-02-16-07 overené na stagingu).
- Pamäť po ≥ 5 min nečinnosti: nginx 4,5 + php 22,3 + db 31,6 = ~58 MiB (< 512 MiB, NFR-02).
- Dočasné účty `smoke+20261024[a-d]@work-tracker.invalid` (accountant, náhodné heslo iba v premennej shellu) zmazané, `count(*) ... LIKE 'smoke+%'` = 0.

## Task 3: UAT (2026-10-25)

Vlastník nahlásil „UAT OK“: kroky 1–12 v `docs/ops/iphone-uat.md` sú OK (inštalačný panel, aktivácia 2FA a záložné kódy, standalone PWA, Zabezpečenie so správnou IP, prežitie vynúteného zatvorenia a reštartu, offline stránka, odmietnutie opakovaného TOTP, odvolanie zariadenia z PC, prihlásenie záložným kódom, metadata-only mail log). Admin účet vytvoril vlastník interaktívne.

Dlhodobé kontroly (O-6 = followup) sú sledované úlohy v STATE.md: 2026-11-01 a 2026-11-24.

## Deviations from Plan

- **[Rule 1] smoke-staging.sh:** po hesle server vracia 303, skript čakal iba 302 → prijíma 302 aj 303 (e2f2416). Aplikácia bola správna, cookie sa menila.
- **[Rule 3] IMAGE_TAG:** `docker compose -f compose.staging.yaml` na serveri vyžaduje `IMAGE_TAG`; príkazy v staging.md (sekcie 10, 12) a iphone-uat.md používajú `IMAGE_TAG=$(cat .last-good-tag)` (4171daa).
- Push vynechaný (main už nasadený); nová sekcia staging.md má číslo 14 (13 = prvé nasadenie z 02-09).
- Krok 1: vlastník spustil príkaz z PC priamo na serveri ako root; náhrada `sudo -u deploy sh -c '...'`.

## Self-Check: PASSED
