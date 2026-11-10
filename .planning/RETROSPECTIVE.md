# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v1.0 — MVP

**Shipped:** 2026-11-10
**Phases:** 14 (12 plánovaných + vložené 11.1 a 12.1) | **Plans:** 155 | **Sessions:** nesledované

### What Was Built
- Nemenné faktúry, výkazy a dobropisy v PDF so sha256 pre SK, CZ a PL subjekt, s kópiou údajov v JSONB, číslovaním bez medzier a ochranou na úrovni DB rolí a triggerov
- Doručenie e-mailom cez vlastný SMTP, úhrady, upomienky, kurzy NBP/ECB/ČNB, limity obratu, prehľady, exporty CSV/XLSX a ZIP pre účtovníčku
- Prevádzka: 2FA, PWA na iPhone, štyri jazyky, staging a produkcia cez GitHub Actions, restic zálohy s testom obnovy a ročným archívom

### What Worked
- Skorý HTTPS staging už vo Phase 2 a UAT na reálnom iPhone po každej fáze: problémy s PWA, dotykovými cieľmi a layoutom sa chytali hneď, nie na konci.
- Rozhodnutia brané zo špecifikácie a PRD namiesto interaktívnych diskusií udržali tempo (14 fáz za 19 dní).
- Invarianty nemennosti vynútené v DB (roly `app_owner`/`app_rw`, insert-only tabuľky, triggery), nie len v aplikácii, s testami pod pripojením `app_rw`.
- Code review a security verifikácia po každej fáze odhalili skutočné chyby (súbeh úhrady a dobropisu, živé meno klienta v exportoch, zvyšky ZIP súborov).
- Audit milestone pred uzavretím našiel medzery v core value (meno klienta zo živých dát), ktoré sa dali cielene uzavrieť vloženou fázou 12.1.

### What Was Inefficient
- Meno klienta zo živej tabuľky `clients` sa šírilo do viacerých čítacích ciest (zoznam, export, prehľady, dashboard, úhrady) a opravovalo sa až po audite. Pravidlo „čítaj z kópie údajov“ malo byť od Phase 5 pomocník (`ClientNameSql`) a test, nie konvencia.
- Review nálezy (Phase 9 WR-02/WR-04, Phase 10 CR/WR) sa odkladali „na koniec fáz“ a pri uzavretí milestone blokovali. Lacnejšie je opraviť ich hneď v quick tasku.
- Bookkeeping: `VERIFICATION.md` ostávali na `human_needed` aj po dokončenom HUMAN-UAT a quick SUMMARY nemali `status`, takže audit pri uzavretí hlásil falošné otvorené položky.
- Repo na `/mnt/c` (9p) rozbíjal výpis migrácií a spomaľoval Composer; presun na ext4 (`~/projects/task-tracker`) prišiel neskoro.

### Patterns Established
- Každý vystavený doklad sa číta a renderuje iba z JSONB kópie údajov; živé master dáta len pre nové doklady.
- Peniaze ako string + bcmath, PHPStan pravidlo proti float a času mimo `Clock`/`Money`.
- Migrácie výhradne ako owner (`make migrate`, `spark migrate -g owner --all`); obyčajný `spark migrate` zlyhá na právach.
- Súbehy: advisory zámky s jednoznačným poradím (`invoice_sends_rk` → `payments` → `credit_note`) plus DB trigger ako posledná poistka.
- `develop` → staging (reseed), `main` → produkcia so schválením; testuje sa iba na stagingu.

### Key Lessons
1. Pri invariante z core value pridať hneď na začiatku automatický test „po zmene master dát sa vystavený doklad nikde nezmení“, ktorý prechádza všetky čítacie cesty.
2. Review nálezy typu WR/CR riešiť v tej istej fáze alebo v quick tasku do pár dní; neodkladať ich na koniec milestone.
3. Po dokončení HUMAN-UAT prepnúť aj `VERIFICATION.md` na `passed`, aby audit pri uzavretí nehlásil falošné otvorené položky.

### Cost Observations
- Model mix: profil `balanced` (planner opus, executor/checker/verifier sonnet), presné percentá nesledované
- Sessions: nesledované
- Notable: plný PHPUnit beh trvá približne 9 minút lokálne a 13,5 minúty v CI; cielené testy počas vývoja, plná sada raz na konci plánu

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | — | 14 | Skorý staging, UAT na iPhone po každej fáze, audit milestone pred uzavretím |

### Cumulative Quality

| Milestone | Tests | Coverage | Zero-Dep Additions |
|-----------|-------|----------|-------------------|
| v1.0 | 2701 | nemeraná | — |

### Top Lessons (Verified Across Milestones)

1. (Doplní sa po v1.1, keď sa lekcie z v1.0 potvrdia alebo vyvrátia.)
