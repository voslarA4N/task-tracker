# Phase 8: Meny a kurzy - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-30
**Phase:** 08-meny-a-kurzy
**Areas discussed:** Deň kurzu ECB/ČNB, Ručný prepis kurzu, Kurz na PDF

Ostatné rozhodnutia sú prevzaté zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/` bez diskusie (preferencia vlastníka „discuss-phase-from-spec“).

---

## Deň kurzu ECB (SK) a ČNB (CZ)

| Option | Description | Selected |
|--------|-------------|----------|
| SK deň pred, CZ v deň | SK: kurz ECB z predchádzajúceho dňa (SK ZoÚ); CZ: kurz ČNB platný v deň, inak posledný pred ním (CZ ZoÚ); posun je nastavenie profilu | ✓ |
| Všade deň pred | ECB aj ČNB ako NBP: posledný publikovaný kurz prísne pred dátumom | |
| Všade v daný deň | ECB aj ČNB: kurz platný v dátum alebo posledný dostupný pred ním | |

**User's choice:** SK deň pred, CZ v deň (odporúčané)

---

## Ručný prepis kurzu

| Option | Description | Selected |
|--------|-------------|----------|
| Bez dôvodu, uloží aj stiahnutý | Zdroj = manual, do kópie údajov aj pôvodne stiahnutý kurz, zmena do denníka | ✓ |
| Povinný krátky dôvod | Pri prepise treba vyplniť dôvod, uloží sa do kópie a denníka | |

**User's choice:** Bez dôvodu, uloží aj stiahnutý (odporúčané)

---

## Kurz na PDF

| Option | Description | Selected |
|--------|-------------|----------|
| Riadok pod súčtom | Kurz, zdroj, dátum, tabuľka a „Spolu v PLN“ pod súčtom, riadky len v mene faktúry | ✓ |
| Aj stĺpec pri riadkoch | Informatívna suma v mene subjektu pri každom riadku | |

**User's choice:** Riadok pod súčtom (odporúčané)

---

## Claude's Discretion

- Štruktúra tabuliek (`currencies`, cache kurzov, nastavenie kurzu v právnom profile)
- `RateProvider` a HTTP klient, testy na fixtúrach
- UX náhľadu na mobile, druhá suma na detaile a v zozname
- Staging seed s faktúrou v cudzej mene
- Sťahovanie kurzov len na požiadanie

## Deferred Ideas

- „Uhradené v mene subjektu“ a kurzové rozdiely (Phase 11 / mimo V1)
- CUR-04 prehľady a limity (Phase 10/11)
- Dobropis v cudzej mene (Phase 9)
- UI na pridávanie mien
- KSeF `KursWalutyZ` (mimo V1)
