---
phase: 06-dokumenty-fakt-ry-a-v-kazu
plan: 01
subsystem: invoicing-pdf
tags: [mpdf, snapshot-schema-2, legal-profile, renderer-registry, inv-2]
requires: []
provides:
  - LegalProfile::print (pravidla tlacenych poli podla profilu)
  - snapshot schema 2 (template_version inv-2, legal.print, legal.texts.vat_note.key, payment.qr)
  - RendererRegistry, MpdfFactory, MpdfInvoiceInv2Renderer, invoice_inv2 sablona
  - SampleSnapshots (fixturne snapshoty pre 5 profilov)
affects: [06-02, 06-04, 06-05, 06-09]
tech-stack:
  added: []
  patterns: [renderer vybrany podla template_version zo snapshotu, sablona iteruje legal.print]
key-files:
  created:
    - app/Libraries/Invoicing/LegalProfile.php
    - app/Libraries/Invoicing/MpdfFactory.php
    - app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php
    - app/Libraries/Invoicing/RendererRegistry.php
    - app/Libraries/Invoicing/Samples/SampleSnapshots.php
    - app/Views/pdf/invoice_inv2.php
    - resources/pdf/invoice-inv2.css
    - tests/_support/PdfText.php
    - tests/integration/Invoicing/LegacyInv1UnchangedTest.php
  modified:
    - app/Libraries/Invoicing/SnapshotBuilder.php
    - app/Libraries/Invoicing/InvoiceCalculator.php
    - app/Libraries/Invoicing/InvoiceIssuer.php
    - app/Language/{sk,en,pl,cs}/InvoicePdf.php
key-decisions:
  - "inv-1 subory ostali bajtovo nezmenene; sha256 zapisane v LegacyInv1UnchangedTest"
  - "InvoiceIssuer odmietne renderer, ktoreho templateVersion nesedi so snapshotom (rollback)"
  - "previewToken ignoruje payment.qr (QR vznika az s cislom)"
requirements-completed: [INV-05]
duration: ~50 min
completed: 2026-10-27
---

# Phase 6 Plan 01: Faktura inv-2 podla pravneho profilu Summary

Nove faktury sa vystavuju so snapshotom schema 2 a PDF `inv-2`, ktore tlaci iba polia z `legal.print` (SK/CZ neplatitel, PL art. 113 s "Tytul platnosti", EU B2B s VAT ID klienta), renderer sa vybera cez `RendererRegistry` podla `template_version`.

## Tasks

1. **LegalProfile a snapshot schema 2** - commit d54284a. `LegalProfile::print`, rozsirene `SnapshotBuilder` (schema 2, print, key textu, payment.qr), plne preklady `InvoicePdf` vo 4 jazykoch (nove kluce paymentCash/Card/Other, paymentTitle, unitHours, vatNoteHeading, note, page, nip, bankName), `previewToken` bez `payment.qr`.
2. **Renderer inv-2** - commit 2d45793. `MpdfFactory`, `MpdfInvoiceInv2Renderer`, `RendererRegistry`, sablona a CSS, `SampleSnapshots` (sk, cz, pl, pl_eu_b2b, pl_non_eu), `PdfText`, testy cez pdftotext (diakritika, escapovanie, hotovost bez IBAN).
3. **Vystavenie cez registry** - commit 479a771. `InvoiceIssuer` s `RendererRegistry`, kontrola verzie renderera, `snapshot_schema` a `payment_method/note` zo snapshotu, `FakePdfRenderer(templateVersion)`, `IssueFixtures` a worker na registry, `LegacyInv1UnchangedTest`.

## Verification

- Plna suita `make test`: **OK, 1122 tests, 7562 assertions** (0 zlyhani).
- `composer stan`: No errors. `composer cs`: cisto.
- `git diff` na `MpdfInvoiceRenderer.php`, `invoice_inv1.php`, `invoice-inv1.css` je prazdny.
- Grep gate sablony inv-2 (`lang(`, `service(`, `db_connect`, `<style`, `style=`): 0 vyskytov.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Test `IssueRollbackTest` pouzival renderer s verziou 'inv-1'**
- Novy check verzie renderera by ho odmietol z ineho dovodu; zmenene na 'inv-2'.

**2. [Rule 1 - Bug] PHPStan chyba v SampleSnapshots (`??` na vzdy existujucom kluci)**
- Vyriesene pomocnou metodou `notes()` s `array<string, string>`.

**3. Poznamka k labelom:** `paymentTransfer` sa zmenil na "Prevod" (sk) / "Převod" (cs) podla UI-SPEC A3; `pl` `deliveryDate`, `paymentMethod`, `bankAccount` upravene podla UI-SPEC. Stare snapshoty inv-1 maju labely skopirovane, nie su dotknute.

**4. Test `LegacyInv1UnchangedTest`** vklada doklad cez `insertIssuedInvoiceRaw` (template_version `test-1` v DB riadku), bajty PDF su vsak realny vystup `MpdfInvoiceRenderer`; endpoint test je v `InvoicePdfEndpointTest`.

## Known Stubs

Blok `fx` v sablone sa vykresli len pri neprazdnom `fx` (rezerva pre Phase 8). `payment.qr` je `null` do 06-05; `payment.method/note` su fixne transfer/null do 06-04. Pravidla `LegalProfile` su predbezne (R1, otvorene otazky 2, 3, 6 v 06-CONTEXT.md).

## Self-Check: PASSED

Subory a commity (d54284a, 2d45793, 479a771) existuju; plna suita zelena.
