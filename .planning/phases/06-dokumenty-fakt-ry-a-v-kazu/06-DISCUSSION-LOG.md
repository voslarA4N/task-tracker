# Phase 6: Dokumenty faktúry a výkazu - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-27
**Phase:** 06-dokumenty-fakt-ry-a-v-kazu
**Areas discussed:** žiadna interaktívna diskusia (režim „rozhodnutia zo špecifikácie“)

---

## Režim

Vlastník má trvalé rozhodnutie (pamäť „discuss-phase-from-spec“, Phase 3, 2026-10-25): „Spolieham sa na presnosť špecifikácie a PRD, odtiaľ zober všetko, nepotrebujem to ďalej konzultovať.“ Otázky sa preto nekládli. Rozhodnutia sú odvodené z dokumentov nižšie, medzery sú v Claude's Discretion a právne neistoty v „Otvorené otázky“ s predvolenou hodnotou.

## Odkiaľ sú rozhodnutia

| Oblasť | Rozhodnutia | Zdroj |
|--------|-------------|-------|
| Nemennosť, nová šablóna `inv-2`, snapshot schema 2 | D-01 až D-04 | INV-13, Phase 5 D-16/D-17/D-18, ARCHITECTURE (verzovanie šablón), PITFALLS Pasca 4 |
| Výkaz pri vystavení v tej istej transakcii, obsah | D-05, D-06 | spec §6.6, REP-01, REP-02, INV-13, INV-14, `invoice_documents.kind` z Phase 5 |
| Právne profily, tlačené polia | D-07 až D-09 | spec §6.5 Zákonné náležitosti, FEATURES §Zákonné náležitosti, ARCHITECTURE (`LegalProfile` v kóde, texty v DB), Phase 3 D-14 |
| Forma úhrady, poznámka, VS | D-10, D-11 | INV-12 (M13), Phase 5 D-19 (stĺpce), WR-05 |
| Zákonné texty v nastaveniach, verzie | D-12 až D-16 | SET-01, Phase 3 D-17, Phase 5 D-18, AUD-01, migrácia `CreateLegalProfiles` |
| QR platba | D-17 až D-19 | INV-11 (M8, rozhodnutie vlastníka z inicializácie), STACK (bacon SVG, žiadne externé QR API), ROADMAP poznámka o LZMA |
| Vystavenie, zobrazenie, výkon, staging | D-20 až D-23 | Phase 5 D-15, NFR-01, Phase 1 spike mPDF, ROADMAP poznámka „posielať si vzorové PDF“ |
| Testy | D-24 | NFR-06, PITFALLS Pasca 4 (povinný test nemennosti) |

## Zvážené alternatívy (rozhodol Claude podľa dokumentov)

| Otázka | Možnosti | Zvolené | Dôvod |
|--------|----------|---------|-------|
| Finálne PDF | upraviť `inv-1` / nová verzia `inv-2` | `inv-2` | ARCHITECTURE: zmena šablóny vždy ako nová verzia; staré doklady sa nesmú dotknúť |
| Kedy vzniká výkaz | pri vystavení / pri prvom zobrazení / pri odoslaní | pri vystavení v tej istej transakcii | INV-13 výslovne „PDF faktúry aj výkazu sa vygeneruje raz pri vystavení“ |
| Riadky výkazu | po riadkoch faktúry / zoskupené podľa úlohy | po riadkoch | REP-02 „presne tie časti“, súčty sa nemôžu rozísť; zlúčenie ostáva v Discretion |
| Verziovanie textov | tabuľka verzií s platnosťou / úprava na mieste + audit + kópia v snapshote | úprava + `revision` + audit + kópia | kópia v snapshote už zaručuje „len pre budúce doklady“ (Phase 5 D-18), audit drží históriu |
| Tlačené polia | v šablóne natvrdo / pravidlo profilu v kóde, výsledok v snapshote | pravidlo profilu, `legal.print` v snapshote | spec: „nie natvrdo v šablóne“; zmrazenie výsledku v kópii |
| Výber QR štandardu | podľa krajiny subjektu / podľa krajiny klienta | podľa krajiny klienta a meny | platí klient, rozhoduje jeho banková aplikácia (PL subjekt bude fakturovať SK klientovi) |
| QR payload | počítať pri každom vykreslení / uložiť do snapshotu | uložiť do snapshotu | nemennosť a nezávislosť od knižnice LZMA pri neskoršom čítaní |

## Claude's Discretion

- Vzhľad `inv-2` a `rep-1`, tvar `legal.print`, názvy tried a registry rendererov.
- LZMA riešenie pre PAY by square, bacon SVG vs QR v mPDF, presné polia SPAYD a ZBP (výskum).
- Zobrazenie odkazu na portál, zlúčenie riadkov tej istej úlohy, preklady „16 z 40 h“.
- Voliteľný backfill výkazu pre faktúry `inv-1` (predvolene sa nespúšťa).
- Texty UI a rozloženie obrazovky Zákonné texty.

## Deferred Ideas

- Kurz a druhá suma na PDF (Phase 8), e-mail (Phase 7), PDF dobropisu (Phase 9), ZIP pre účtovníčku (Phase 11).
- Úprava `legal_profiles` v UI, logo, návrhár šablón, podpisový záznam.
- EPC QR pre ostatné krajiny EÚ.
