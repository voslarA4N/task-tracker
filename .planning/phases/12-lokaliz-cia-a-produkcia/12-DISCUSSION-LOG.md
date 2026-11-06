# Phase 12: Lokalizácia a produkcia - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-04
**Phase:** 12-lokaliz-cia-a-produkcia
**Areas discussed:** Úložisko záloh, Kópia mimo servera, Upozornenie na zálohu, Retencia archívu

Lokalizácia, nemazateľnosť, výkon a CLI reset 2FA boli prevzaté zo špecifikácie, PRD a kódu bez diskusie (preferencia vlastníka).

---

## Úložisko záloh

| Option | Description | Selected |
|--------|-------------|----------|
| Objednám Storage Box | Hetzner BX11, okolo 4 €/mes. | (najprv zvolené, potom odvolané) |
| Storage Box už mám | existujúci box | |
| Iné úložisko | S3-kompatibilné | |
| Google Drive (restic cez rclone) | zvážené na otázku vlastníka | |
| Priamo na serveri | bez ďalších nákladov | ✓ |

**User's choice:** „zatiaľ ukladaj zálohy priamo na serveri, nemám budget na ukladanie na ďalší server alebo úložisko“
**Notes:** Vlastník sa pýtal, či sa zálohujú súbory alebo DB. Odpoveď: iba DB, PDF sú v `invoice_documents` (bytea). Potom sa pýtal na Google Drive. Vysvetlené výhody a nevýhody (OAuth token, slabšia ochrana pred zmazaním). Vedomý odklon od NFR-05 „mimo servera“.

## Kópia mimo servera

| Option | Description | Selected |
|--------|-------------|----------|
| Ručný pull do PC | rsync z WSL, šifrovaný restic repozitár | ✓ |
| Nie, iba server | strata servera = strata dát | |

## Upozornenie na zálohu

| Option | Description | Selected |
|--------|-------------|----------|
| healthchecks.io + e-mail | dead man's switch + detail chyby cez Stalwart | ✓ |
| Iba e-mail cez Stalwart | bez tretej strany | |
| Iba healthchecks.io | bez e-mailu | |

## Retencia archívu

| Option | Description | Selected |
|--------|-------------|----------|
| 10 rokov pre všetko | pokryje SK aj PL, nečaká sa na účtovníčku | ✓ |
| Podľa krajiny subjektu | PL podľa účtovníčky | |

## Ochrana záloh pred útočníkom

Otázka (snapshoty Storage Boxu / prune z PC / všetko na serveri) stratila zmysel, keď vlastník zvolil lokálne zálohy. Odložená spolu s off-site úložiskom.

## Claude's Discretion

Cesty a názvy skriptov, cron vs systemd timer, restic na hostiteľovi vs v kontajneri, formát CSV indexu, nástroj na meranie p95, rozdelenie plánov.

## Deferred Ideas

- Off-site úložisko (Storage Box so snapshotmi, alebo Google Drive)
- Off-site záloha mail stacku
- Potvrdenie PL retencie účtovníčkou
