---
phase: 09-dobropisy
verified: 2026-11-01T12:00:00Z
status: human_needed
score: 4/4 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Na staging (work-tracker-stage.vostack.dev) vystav dobropis zo seedovanej faktúry (aj v cudzej mene) a otvor PDF"
    expected: "Záporné položky, odkaz na číslo a dátum pôvodnej faktúry, dôvod, typ opravy, vlastné číslo, kurz a záporný súčet v mene subjektu; rozloženie PDF je čitateľné"
    why_human: "Vizuálna kontrola PDF"
  - test: "Na iPhone (PWA) prejdi tok faktúra, dobropis, náhľad, potvrdenie a vystavenie"
    expected: "Ovládateľné jednou rukou, ciele aspoň 44 px, bez horizontálneho posúvania. Pri písaní dôvodu sa nestrácajú znaky (WR-05)"
    why_human: "Mobilné UX a správanie htmx pri živom náhľade"
  - test: "Zobraz stornovanú faktúru a stiahni jej PDF"
    expected: "Banner a odznak 'stornovaná', odkaz na dobropis, PDF faktúry je bajt po bajte rovnaké ako pred stornom"
    why_human: "Vizuálna kontrola a overenie na reálnom prostredí"
  - test: "Odošli dobropis e-mailom na staging"
    expected: "E-mail s PDF dobropisu v prílohe, šablóna dobropisu, správny názov súboru"
    why_human: "Externá služba (SMTP) a vzhľad e-mailu"
  - test: "Po storne prefakturuj uvoľnené časti do iného mesiaca"
    expected: "Časti sa ponúkajú na fakturáciu, flash odkaz na uvoľnené časti funguje"
    why_human: "Celý tok v UI"
  - test: "Rozhodnutie vlastníka k WR-01 (odoslanie stornovanej faktúry klientovi), WR-02 a WR-04"
    expected: "Rozhodnúť, či sa opravia pred nasadením"
    why_human: "Ide o obchodné rozhodnutie, nie o nesplnenie kritérií"
---

# Phase 9: Dobropisy, verifikačná správa

**Cieľ fázy:** Vlastník opraví chybnú faktúru vystavením dobropisu (celé storno), ktorý je rovnako nemenný, a časti z nej sa uvoľnia na opätovnú fakturáciu.
**Overené:** 2026-11-01
**Stav:** human_needed (automatické kontroly prešli, chýba staging UAT)
**Opakovaná verifikácia:** Nie, prvá verifikácia

## Overenie cieľa

| # | Kritérium | Stav | Dôkaz |
|---|-----------|------|-------|
| 1 | Dobropis s povinným dôvodom a typom opravy, vlastný rad alebo prefix, vlastný dátum, odkaz na pôvodnú faktúru | VERIFIED | `CreditNoteIssuer` ukladá `corrected_invoice_id`, `correction_reason`, `correction_type` (enum `CorrectionType`) a `issue_date` z príkazu. Číslo cez `NumberAllocator` z vlastného radu so zámkom. Migrácia obsahuje CHECK `invoices_credit_note_fields_check`, takže dôvod a typ sú povinné aj v DB. Cieľový trigger a unikátny index dovolia jeden dobropis na faktúru. |
| 2 | Záporné položky celej faktúry (aj cudzia mena s kurzom), pôvodná faktúra stornovaná a PDF nezmenené | VERIFIED | `CreditNoteSnapshotBuilder` neguje hodiny a sumy, kontroluje vzorec sumy a súčet voči `-original.total`, kopíruje `fx` a neguje `total_entity_ccy`. `invoice_status_v` má stav `cancelled`, `show.php` zobrazuje banner. Testy `CreditNoteFxTest`, `CreditNoteImmutabilityTest::testOriginalInvoiceIsUnchangedByCreditNote`. |
| 3 | Časti sa uvoľnia a dajú sa fakturovať znova do iného mesiaca, storno aj pre ukončený subjekt | VERIFIED | `release_allocations()` je SECURITY DEFINER a `alloc_guard` povolí len prechod na NULL pri existujúcom dobropise. Issuer porovnáva počet uvoľnených častí s počtom pred uvoľnením. Testy `CreditNoteReissueTest` (iný mesiac aj ten istý) a `CreditNoteGuardsTest::testEndedEntityStillGetsCreditNote`. |
| 4 | Vlastné uložené PDF so sha256 a odoslanie e-mailom ako pri faktúre | VERIFIED | Issuer renderuje cez `MpdfCreditNoteCn1Renderer`, ukladá `$pdf->sha256` do dokumentu aj do auditu. `InvoiceMailer` a `ManualSendService` podporujú dobropis, testy `CreditNoteMailerTest`, `CreditNoteTemplateTest`, `CreditNotePdfEndpointTest`. |

**Skóre:** 4/4

### Automatické kontroly

- `docker compose exec -T php composer test -- --filter CreditNote`: OK (149 testov, 883 assertions).
- Predtým celá sada `composer check`: 1952 testov prešlo (podľa zadania, nespúšťané znova).

### Pokrytie požiadaviek

| ID | Plány | Stav |
|----|-------|------|
| INV-07 | 09-01 až 09-11 | SATISFIED (záporné položky, vlastný rad, uvoľnenie častí) |
| INV-10 | 09-01 a ďalšie | SATISFIED (dôvod, odkaz na pôvodnú faktúru, vlastný dátum, typ opravy) |

REQUIREMENTS.md priraďuje obe ID fáze 9 a obe sú v plánoch. Osirotené požiadavky nie sú.

### Anti-patterns

Čítaný súbor code review, v ktorom sú 0 kritických nálezov a 5 varovaní. V zmenených súboroch som nehľadal markery TBD/FIXME/XXX.

### Vplyv varovaní z code review na kritériá

- **WR-01 (potvrdené v kóde):** `InvoiceMailer.php:386` blokuje len typ `reminder`. `show.php` nezohľadňuje `$cancelled` pri `canSend` a `canResend`. Stornovanú faktúru, ktorá nebola odoslaná, možno ešte poslať klientovi. Kritériá 1 až 4 to nenarúša, ale je to reálna obchodná chyba a dôrazne odporúčam opraviť pred nasadením. Nie je to BLOCKER pre cieľ fázy.
- **WR-03:** nezhoda súm sa zobrazí ako 404. Ide o interný stav, ktorý by nastal len pri poškodení dát. Kritériá nenarúša, ide o zlú diagnostiku.
- **WR-02, WR-04, WR-05:** hranové prípady (nemožnosť zrušiť omylom zadanú platbu po storne, súbeh platby so stornom, strata znakov pri živom náhľade). Kritériá ostávajú splnené. WR-05 treba overiť na iPhone.

## Súhrn

Všetky štyri kritériá sú podložené kódom, migráciou aj testami. Fáza nemá BLOCKER. Stav je `human_needed`, pretože staging UAT sa ešte nerobil (položky vo frontmatteri). Päť varovaní z code review je odporúčaných na opravu, hlavne WR-01.

_Verifier: Claude (gsd-verifier)_
