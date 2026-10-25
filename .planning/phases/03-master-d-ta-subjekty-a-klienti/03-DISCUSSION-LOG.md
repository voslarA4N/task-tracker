# Phase 3: Master dáta (subjekty a klienti) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-25
**Phase:** 03-master-d-ta-subjekty-a-klienti
**Areas discussed:** žiadne (vlastník diskusiu odmietol)

---

## Výber oblastí

| Option | Description | Selected |
|--------|-------------|----------|
| Verzie subjektu | Kedy vzniká verzia, oprava preklepu, budúci dátum „platné od“, verziovanie účtov | |
| Číselný rad a formát | Formát a počiatočné číslo na subjekte, zmena formátu, rad pre dobropisy | |
| Klient: formulár a polia | Povinné polia, typ identifikátora, e-maily v kópii, vzor odkazu, archivácia | |
| Právny profil a kód oslobodenia | Umiestnenie kódu dôvodu, obsah seedu `legal_texts` | |

**User's choice:** „Spolieham sa na presnosť špecifikácie a PRD, odtiaľ zober všetko, nepotrebujem to ďalej konzultovať.“
**Notes:** Rozhodnutia v CONTEXT.md sú prevzaté zo špecifikácie, PRD, REQUIREMENTS.md a výskumu. Body, ktoré tieto dokumenty neriešia, sú v Claude's Discretion.

## Claude's Discretion

- UX vzniku verzie a oprava preklepu (bez úpravy verzie na mieste)
- Bankové účty ako súčasť verzie vs samostatná tabuľka
- Syntax vzoru odkazu na portál, validácia, povinné polia, zobrazenie archivovaných klientov
- Rozloženie obrazoviek nastavení
- Dev seeder (SK a PL subjekt, klient All4Net)
- Príprava schémy `turnover_limits`

## Deferred Ideas

Žiadne.
