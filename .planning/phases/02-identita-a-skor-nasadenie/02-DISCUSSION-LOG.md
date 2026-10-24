# Phase 2: Identita a skoré nasadenie - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-24
**Phase:** 02-identita-a-skoré-nasadenie
**Areas discussed:** Staging a nasadenie, Prvý účet a obnova 2FA, Relácie a zariadenia, PWA identita

---

## Staging a nasadenie

| Otázka | Možnosti | Voľba |
|--------|----------|-------|
| Adresa stagingu | subdoména all4net.sk / iná doména / zatiaľ nevieme; potom sslip.io / počkať / subdoména hneď | `work-tracker.vostack.dev` (Cloudflare, registrovaná dnes) |
| Staging = produkcia? | jeden server / dva servery | ✓ jeden server |
| VPS | x86 CX / ARM CAX / už objednaný | už objednaný: cx23, 2 vCPU, 4 GB, IP 2.31.74.48 |
| Spôsob nasadenia | make deploy cez SSH / GHA + GHCR / rozhodni ty | ✓ GHA + GHCR |
| Spúšťač | ručne (workflow_dispatch) / push do main / git tag | ✓ push do main |
| 512 MB pri 4 GB | držať / uvoľniť | ✓ držať 512 MB |
| Cloudflare | len DNS / proxy / rozhodni ty | ✓ proxy |
| Migrácie pri deployi | áno so zálohou / ručne | ✓ áno, pg_dump pred nimi |

**Notes:** Výpadok elektriny počas diskusie, pokračovalo sa po obnove.

---

## Prvý účet a obnova 2FA

| Otázka | Možnosti | Voľba |
|--------|----------|-------|
| Prvý admin | spark príkaz / úvodná stránka | ✓ spark príkaz |
| Záložné kódy | raz + .txt / len raz / aj tlač | ✓ raz + .txt |
| Strata telefónu aj kódov | spark reset / aj e-mail s oneskorením 24 h | ✓ len spark reset |
| Upozornenia e-mailom | áno pri citlivých zmenách / len denník | ✓ áno |
| SMTP na stagingu | vybrať teraz / do Phase 7 bez e-mailu | ✓ do Phase 7 bez e-mailu (log) |

---

## Relácie a zariadenia

| Otázka | Možnosti | Voľba |
|--------|----------|-------|
| Čo preskočí zapamätanie | heslo aj TOTP / len TOTP | ✓ heslo aj TOTP |
| Predvolené začiarknutie | vypnuté / zapnuté | ✓ zapnuté (odporúčané bolo vypnuté) |
| Nečinnosť (nedôveryhodné) | 2 h / 30 min / do zatvorenia | ✓ 2 h |
| Limit pokusov | spomalenie + zámok IP aj účet / len IP / rozhodni ty | ✓ 5 pokusov, zámok 15 min, IP aj účet |
| 30 dní | pevne / posuvne | ✓ pevne |
| Umiestnenie | Nastavenia → Zabezpečenie / dve stránky | ✓ jedna stránka |

---

## PWA identita

| Otázka | Možnosti | Voľba |
|--------|----------|-------|
| Názov | Work Tracker / Faktúry / Task Tracker | ✓ Work Tracker |
| Ikona | jednoduchá SVG / vlastné logo | ✓ jednoduchá SVG |
| Jazyk loginu | podľa prehliadača, inak SK / vždy SK | ✓ podľa prehliadača |
| Návod na inštaláciu | panel v Safari / len v Nastaveniach | ✓ panel v Safari |

---

## Claude's Discretion

- Shield akcia vs vlastný filter pre TOTP (podľa spiku)
- Mechanizmus remember-me + dôveryhodné zariadenie, session handler a expirácie
- Názvy spark príkazov, štruktúra GHA workflow, prístup servera ku GHCR, umiestnenie tajomstiev
- Offline stránka a dizajn ikony
- Rola accountant iba v modeli

## Deferred Ideas

- Produkčný SMTP + SPF/DKIM/DMARC → Phase 7
- UI pre accountant → neskôr
- restic zálohy a finálny prod compose → Phase 12
