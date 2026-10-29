# Phase 7: Doručenie, úhrady a upomienky - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-29
**Phase:** 07-doru-enie-hrady-a-upomienky
**Mode:** spec-first (pamäť „discuss-phase-from-spec“): 26 rozhodnutí odvodených zo spec, PRD, REQUIREMENTS a výskumu (D-01 až D-26); vlastníkovi predložené iba 3 otvorené otázky.

---

## O-1: Identita odosielateľa (From meno, Reply-To, BCC)

| Option | Description | Selected |
|--------|-------------|----------|
| Podľa subjektu | From meno = obchodné meno subjektu, Reply-To a BCC = e-mail subjektu | |
| Podľa subjektu, BCC na info@vostack.dev | jeden archív | |
| Pevne z .env | jednotné pre všetky subjekty | |
| Iné (vlastník) | „Nech je to pre každý subjekt nastaviteľné, kto bude odosielateľ, reply-to a bcc“ | ✓ |

**Notes:** Adresa odosielateľa obmedzená na domény podpisované vlastným mailovým serverom (DMARC) → D-27.

## O-2: Oprava omylom zadanej úhrady

| Option | Description | Selected |
|--------|-------------|----------|
| Storno úhrady s auditom | insert-only, dôvod, audit | ✓ |
| Zmazať s auditom | DELETE + audit | |
| Bez opravy vo V1 | | |

## O-3: Kedy je faktúra „odoslaná“

| Option | Description | Selected |
|--------|-------------|----------|
| Iba ak bol medzi príjemcami klient | | |
| Akékoľvek odoslanie | | |
| Iné (vlastník) | „Bude, keď odošlem klientovi alebo ručne cez tlačidlo označím FA ako odoslanú“ | ✓ |

**Notes:** → D-29 (automaticky pri adrese klienta v To/CC, alebo ručné tlačidlo; odoslanie len sebe stav nemení).

## Claude's Discretion
Viď CONTEXT.md, sekcia „Claude's Discretion“.

## Deferred Ideas
Viď CONTEXT.md, sekcia `<deferred>` (automatické upomienky, párovanie s bankou, storno faktúry v Phase 9, balík pre účtovníčku v Phase 11).
