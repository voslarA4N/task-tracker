# Phase 6: Dokumenty faktúry a výkazu - Research

**Researched:** 2026-10-27
**Domain:** PDF doklady (mPDF 8.3 na PHP 8.5 Alpine), QR platby (PAY by square, SPAYD, ZBP), právne profily a editovateľné zákonné texty, rozšírenie nemenného vystavenia (snapshot schema 2)
**Confidence:** MEDIUM-HIGH (technika a integrácia HIGH, overené v kóde a živým spikeom. Formáty QR MEDIUM: bez oficiálneho testovacieho vektora a bez overenia v reálnej bankovej aplikácii. Právny obsah LOW až MEDIUM, predbežný do potvrdenia účtovníčkou)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
(Skrátené doslovne, plné znenie D-01 až D-24 je v `06-CONTEXT.md`, plánovač ich MUSÍ dodržať.)

- **D-01** Finálna faktúra = nová verzia šablóny `inv-2` (nový view a CSS). `invoice_inv1.php`, `invoice-inv1.css`, `MpdfInvoiceRenderer` (inv-1) sa nemenia ani nemažú. Renderer sa vyberá podľa `template_version` zo snapshotu (registry `inv-1` / `inv-2` / `rep-1`).
- **D-02** Uložené PDF sa nikdy neregeneruje. Staré faktúry `inv-1` majú po nasadení rovnaký `sha256` a bajty.
- **D-03** Snapshot `schema = 2`: `template_version='inv-2'`, `report_template_version='rep-1'`, `legal.texts` (všetky vyriešené kľúče), `legal.print`, `payment` (`method`, `note`, `qr`), rozšírené `labels`. Schema 1 sa len číta. Preview token je z celej kópie.
- **D-04** Všetko, čo PDF zobrazí, je v snapshote ako hotová hodnota. Šablóna nečíta `lang()`, DB, `service()`, nastavenia ani čas.
- **D-05** Výkaz vzniká pri vystavení v tej istej transakcii, `invoice_documents.kind='report'`. Deferred trigger `invoice_complete_check` sa novou migráciou rozšíri: vlastná faktúra so `snapshot_schema >= 2` musí mať dokument `report`. Schema 1 a externé faktúry výkaz nevyžadujú.
- **D-06** Obsah výkazu: hlavička (subjekt, klient, obdobie, odkaz na číslo faktúry), tabuľka po riadkoch faktúry (odkaz na portál, názov, nacenené hodiny, „16 z 40 h“, suma), súčet hodín a súm, sadzba a `vat_note`. Súčty zo snapshotu `totals`. Bez nacenených hodín len fakturované. Rovnaká sadzba všade = v hlavičke súčtov, inak v stĺpci.
- **D-07** Tlačené polia určuje pravidlo profilu v kóde (`LegalProfile`), texty sú v DB. Výsledok sa zapíše do snapshotu ako `legal.print`. Predbežné pravidlá SK neplatiteľ / PL art. 113 / EÚ B2B / ostatné (fallback `billing_mode NULL`).
- **D-08** Nadpis „Faktúra“ / „Faktura“ / „Faktura“ / „Invoice“, nie „daňový doklad“ ani „Faktura VAT“. Jazyk dokladu = jazyk klienta, inak jazyk verzie subjektu.
- **D-09** Preklady `InvoicePdf` pl/cs sa dopracujú, pribudne jazykový súbor výkazu (`ReportPdf`) vo 4 jazykoch. Popisky sa kopírujú do snapshotu.
- **D-10** Forma úhrady sa vyberá v náhľade (predvolene `transfer`; `transfer|cash|card|other`). Poznámka (<= 500 znakov) sa predvyplní z `invoice_note` a je editovateľná. Server validuje. Ukladá sa do `payment_method`, `payment_note` aj snapshotu.
- **D-11** VS podľa WR-05 `SnapshotBuilder::variableSymbol()`. Na PL doklade „Tytuł płatności“ s plným číslom faktúry.
- **D-12** Kľúče `legal_texts`: `vat_note` (povinný) a `invoice_note` (voliteľný). Údaj o zápise je súčasť verzie subjektu.
- **D-13** Obrazovka Nastavenia → Zákonné texty (zoznam podľa profilu a jazyka, úprava, pridanie variantu, príznak `provisional`, ukáže reálny fallback). Bez mazania. Vzory UI ako ostatné nastavenia.
- **D-14** Znenie sa pri vystavení kopíruje do snapshotu s `key`, `language`, `provisional`, `revision` (nový stĺpec, ++ pri úprave). Jedna služba (`LegalTextService`) v transakcii s `AuditLogger`. `app_rw` dostane len `INSERT` a stĺpcový `UPDATE (body, provisional, revision, updated_at)`, bez `DELETE`.
- **D-15** Pri `provisional=true` náhľad zobrazí nevýrazné upozornenie. Neblokuje.
- **D-16** `legal_profiles` sa v UI len zobrazujú, oprava migráciou.
- **D-17** QR payload sa vypočíta pri vystavení a uloží do snapshotu (`payment.qr = {standard, payload}`), PDF ho len vykreslí. Žiadna externá služba. Vykreslenie `bacon/bacon-qr-code` SVG alebo mPDF QR (rozhoduje výskum).
- **D-18** Štandard podľa krajiny klienta a meny: SK = PAY by square, CZ = SPAYD, PL + PLN = ZBP, inak bez QR.
- **D-19** QR len pri forme `transfer`. Payload sa v náhľade nevytvára. Zlyhanie výpočtu pri existujúcom štandarde = chyba a rollback (nikdy ticho bez QR). PAY by square potrebuje LZMA bez externej služby.
- **D-20** Poradie v `InvoiceIssuer`: snapshot (schema 2, QR po čísle) → render faktúry → render výkazu → zápis faktúry, riadkov a oboch dokumentov → zamknutie častí → audit, jedna transakcia. Test rollbacku.
- **D-21** Detail ukáže „Faktúra PDF“ a „Výkaz PDF“ (`target="_blank"`), nová trasa `invoices/(:num)/report` cez `DocumentStore::read($id,'report')`. Schema 1 ukáže informáciu namiesto tlačidla.
- **D-22** Každý PDF < 2 s, jeden font DejaVu, bez substitúcií, dva samostatné objekty `Mpdf`.
- **D-23** `spark` príkaz vygeneruje vzorové PDF každého profilu a jazyka z fixtúrnych snapshotov do `writable/` (označené „VZOR“, nič do DB).
- **D-24** Test pokrytie (výber polí, schema 2, zhoda súčtov, oba dokumenty pod `app_rw`, rollback, nemennosť po zmene textu/prekladu/subjektu/klienta/sadzby, nezmenené bajty `inv-1`, QR vektory, oprávnenia a audit `legal_texts`).

### Claude's Discretion
Vzhľad `inv-2` a `rep-1`; tvar `legal.print`; názvy tried (`LegalProfile*`, `ReportRenderer`, `QrPaymentBuilder`, `LegalTextService`), registry rendererov, jazykový súbor výkazu; knižnica/spôsob LZMA, SVG z bacon vs QR v mPDF, presná špecifikácia polí SPAYD a ZBP; zobrazenie odkazu na portál; zlúčenie riadkov tej istej úlohy (predvolene nie) a formát „16 z 40 h“ v jazykoch; voliteľný jednorazový `spark` príkaz na doplnenie výkazov pre staré vlastné faktúry (predvolene nespúšťať); texty upozornení a rozloženie obrazovky Zákonné texty.

### Deferred Ideas (OUT OF SCOPE)
- Druhá suma v mene subjektu a kurz na PDF: Phase 8 (šablóna má miesto, `fx` je `null`).
- Odoslanie e-mailom a šablóny e-mailov: Phase 7.
- PDF dobropisu: Phase 9.
- ZIP balík pre účtovníčku: Phase 11.
- Úprava `legal_profiles` v UI: len migráciou.
- Logo, návrhár šablón, podpisový záznam: mimo V1.
- EPC QR (SEPA) pre klientov mimo SK/CZ/PL: len ak to výskum odporučí ako lacné rozšírenie (odporúčanie nižšie: NEPRIDÁVAŤ).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INV-05 | PDF faktúry so zákonnými náležitosťami podľa krajiny, režimu a jazyka | Sekcia Právny obsah, `LegalProfile` pravidlá, šablóna `inv-2`, mPDF + DejaVu |
| INV-11 | QR kód na platbu (SK PAY by square, CZ SPAYD, PL ZBP) | Sekcie PAY by square, SPAYD, ZBP, `QrPaymentBuilder`, bacon SVG v mPDF |
| INV-12 | Forma úhrady a poznámka na faktúre, VS | `payment` v snapshote, `IssueInput`/`PreviewInput` rozšírenie, preview token |
| REP-01 | Nemenný PDF výkaz práce pri vystavení | `rep-1` renderer, `invoice_documents.kind='report'`, rozšírený trigger |
| REP-02 | Výkaz a faktúra majú rovnaké súčty | Oba renderery čítajú `totals`/`lines` toho istého snapshotu, test cez `pdftotext` |
| SET-01 | Úprava zákonných textov bez zásahu do kódu | `LegalTextService`, stĺpec `revision`, GRANT migrácia, UI |
</phase_requirements>

## Summary

Fáza je v podstate rozšírenie hotového a dobre navrhnutého jadra z Phase 5, nie nová technológia. Všetky potrebné knižnice (mPDF 8.3.x, `bacon/bacon-qr-code`, ext-intl, bcmath) už sú v projekte. **Jediná nová runtime závislosť je binárka `xz`** pre LZMA1 v PAY by square. Overil som, že `php:8.5-fpm-alpine` ju už obsahuje (`/usr/bin/xz` 5.8.4, balíky `xz` a `xz-libs` sú v základnom obraze) a že dev obraz `task-tracker-php:dev` ju má tiež. Raw LZMA1 s parametrami `lc=3,lp=0,pb=2,dict=128KiB` som vyskúšal a dekóduje sa späť (xz 5.4.5 aj 5.8.4). Odporúčanie: volať `xz` cez `proc_open` s poľom argumentov (bez shellu) a explicitne ju pripnúť v Dockerfile (`apk add --no-cache xz`), aby spoliehanie na základný obraz nebolo tiché. Čisto-PHP knižnice na Packagist existujú (`hrmpfz/pay-by-square`, `paybysquare/php`), no sú to malé neznáme balíky bez histórie, preto ich neodporúčam ako závislosť (podľa pravidla o pôvode balíkov by boli `[ASSUMED]`).

SVG QR z `bacon/bacon-qr-code` sa v mPDF vykreslí ako vektorový Form XObject cez `<img src="data:image/svg+xml;base64,...">` (overené spikeom: PDF 29 kB, 0,20 s, peak 22 MB, 0 rastrových obrázkov), takže nie je potrebný GD ani PNG a výstup je ostrý. mPDF nie je bajtovo deterministický (čas v `CreationDate`/`ModDate`/`/ID`), čo je podľa Phase 5 v poriadku, lebo sa nikdy neregeneruje. Testy preto porovnávajú **uložené** bajty a `sha256`, nie opakované vykreslenie.

Najväčšie riziká fázy nie sú technické, ale integračné: (1) preview token je hash celého snapshotu bez `number` a `variable_symbol`, takže `payment.qr` (závislé od čísla) sa musí do tokenu nedostať, inak vystavenie vždy spadne na `preview_stale`. (2) Rozšírenie `InvoiceIssuer` na dva renderery rozbije konštruktor a testovacie fake rendery. (3) Migrácia triggera a GRANT-ov pod `app_owner`. (4) Formáty QR sa nedajú plne overiť bez reálnej bankovej aplikácie, preto je v pláne nutný ručný UAT krok.

**Primary recommendation:** Zaviesť `QrPaymentBuilder` (PAY by square cez `xz` + vlastný ~60-riadkový serializér, SPAYD, ZBP) volaný z `SnapshotBuilder::withNumber()`, vylúčiť `payment.qr` z `previewToken`, pridať `RendererRegistry` (`inv-1`/`inv-2`/`rep-1`) a dopĺňať schema 2 snapshot v tom istom `SnapshotBuilder`, nič z `inv-1` nemeniť.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Zostavenie snapshotu schema 2 (texty, print, payment, labels) | API / Backend (`SnapshotBuilder`) | Database (čítanie `legal_texts`) | jediné miesto stavby kópie pre náhľad aj vystavenie |
| Pravidlá tlačených polí (`LegalProfile`) | API / Backend (kód, verzované so šablónou) | — | rozhodnutie D-07: pravidlá v kóde, texty v DB |
| Výpočet QR payloadu | API / Backend (`QrPaymentBuilder`) | OS binárka `xz` | pri vystavení po pridelení čísla, v transakcii |
| Render PDF faktúry a výkazu | API / Backend (mPDF renderery) | — | render vnútri transakcie, z kópie |
| Uloženie dokumentov a kontrola úplnosti | Database (`invoice_documents`, deferred trigger) | API (`DocumentStore`) | nemennosť a úplnosť vynucuje DB, nie len PHP |
| Úprava zákonných textov | API / Backend (`LegalTextService`) | Database (stĺpcový UPDATE GRANT) | audit v tej istej transakcii |
| Obrazovka Zákonné texty, forma úhrady v náhľade | Frontend Server (CI4 views + htmx) | — | serverom renderované, žiadna SPA |
| Sťahovanie PDF | API / Backend (`Invoices::pdf/report`) | — | uložené bajty, `no-store`, overenie `sha256` |
| Vzorové PDF na staging | CLI (`spark`) | — | z fixtúrnych snapshotov, bez DB zápisu |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `mpdf/mpdf` | 8.3.1 (už v `composer.json` `^8.3.1`) | PDF faktúry a výkazy | rozhodnutý stack, spike Phase 1 a 5 hotový [VERIFIED: codebase `MpdfInvoiceRenderer`] |
| `bacon/bacon-qr-code` | ^3.1 (už v projekte) | QR ako SVG pre mPDF | už v projekte pre TOTP, SVG bez GD [VERIFIED: composer.json, spike 2026-10-27] |
| `xz` binárka (XZ Utils) | 5.8.4 v `php:8.5-fpm-alpine` | LZMA1 raw pre PAY by square | v základnom obraze už je, overené `docker run php:8.5-fpm-alpine` [VERIFIED: docker run 2026-10-27] |
| ext-intl `Transliterator` | z PHP | odstránenie diakritiky v QR poliach | už povinné rozšírenie [ASSUMED: `Any-Latin; Latin-ASCII` ID, overiť v teste] |
| bcmath | z PHP | halier/grosz konverzia sumy pre ZBP a formát sumy | pravidlo projektu, nikdy float |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `poppler-utils` (`pdftotext`) | v dev obraze už je | testy: extrakcia textu z PDF (REP-02, diakritika) | len dev/testy, nie prod |
| PHPUnit | `^11.5` (z `composer.json`, pozn.: CLAUDE.md spomína pin 10.5, repo používa 11.5) | testy | existujúca infraštruktúra |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `xz` cez `proc_open` | čisto-PHP LZMA (`hrmpfz/pay-by-square`, `paybysquare/php`) | bez procesu, ale neznáme malé balíky, literal-only kompresia, bez histórie. Zvážiť len ako núdzový fallback |
| `xz` cez `proc_open` | vlastný literal-only LZMA range encoder (~100 riadkov) | žiadna závislosť od binárky, ale vlastná krehká kryptografia-podobná logika a nutnosť overiť dekodérom |
| `xz` cez `proc_open` | PECL `lzma2` | rozšírenie nie je v obraze, a podľa dostupných zdrojov nevie raw LZMA1 s týmito parametrami [ASSUMED] |
| bacon SVG v mPDF | vstavaný `<barcode type="QR">` v mPDF | mPDF barcode je tiež vektorový, ale ECC/verzia sa ovláda horšie, a bacon je už v projekte. Zostať pri bacon |
| bacon SVG data URI | PNG cez GD | rastrový, rozmazaný pri tlači, zbytočné |
| EPC QR (SEPA) pre ostatné | — | NEPRIDÁVAŤ: D-18 hovorí „bez QR“, rozšírenie je mimo rozsah |

**Installation:**
```bash
# žiadne nové composer balíky
# Dockerfile (base stage), explicitné pripnutie xz (v základnom obraze už je, apk add je idempotentné):
#   RUN install-php-extensions intl pdo_pgsql pgsql gd bcmath zip \
#       && apk add --no-cache tzdata xz
```

**Version verification:** `docker run --rm php:8.5-fpm-alpine xz --version` → `xz (XZ Utils) 5.8.4`. `docker run --rm task-tracker-php:dev xz --version` → 5.8.4. Nové composer balíky sa neinštalujú, takže `slopcheck` sa nevyžaduje.

## Package Legitimacy Audit

Fáza neinštaluje žiadne nové composer ani npm balíky. Jediná nová závislosť je systémový balík `xz` z oficiálnych Alpine repozitárov (už prítomný v `php:8.5-fpm-alpine`).

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `xz` (apk) | Alpine main | desaťročia | — | tukaani.org / alpine | n/a (nie composer/npm) | Approved (v základnom obraze, overené) |
| `hrmpfz/pay-by-square`, `paybysquare/php`, `feldsam-inc/paybysquare-php`, `peterbodnar.com/bsqr`, `engazan/pay-by-square`, `hubleto/pay-by-square-encoder`, `easydoklad/bysqr` | Packagist | neoverené | neoverené | neoverené | nespustené | NEPOUŽIŤ (nenainštalované, len porovnanie; `[ASSUMED]` existencia a funkčnosť, objavené cez WebSearch) |

**Packages removed due to slopcheck [SLOP] verdict:** žiadne (slopcheck nespúšťaný, nič sa neinštaluje)
**Packages flagged as suspicious [SUS]:** žiadne

## Architecture Patterns

### System Architecture Diagram

```
POST issue (IssueCommand + payment_method + payment_note + previewToken)
        |
        v
InvoiceIssuer::issueInTransaction  (jedna DB transakcia)
  1-5  zamky, klient, časti, subjekt
  6    SnapshotBuilder::build(number=null)  --> schema 2, payment.qr = null
        |   LegalProfile::print(profile) --> legal.print
        |   LegalProfileResolver::text(vat_note) + optionalText(invoice_note) --> legal.texts (s revision)
        |   labels (InvoicePdf + ReportPdf, kopírované do snapshotu)
        v
       previewToken(snapshot)  [bez number, variable_symbol, payment.qr]  == cmd.previewToken ?  --no--> preview_stale
  7-8  rad, zámok čítača, NumberAllocator::next --> number
        v
       SnapshotBuilder::withNumber(number)  --> + variable_symbol, + payment.qr  (QrPaymentBuilder)
                                                      |--> SK: PayBySquare (tab payload, CRC32, xz raw LZMA1, base32hex)
                                                      |--> CZ: SPAYD
                                                      |--> PL+PLN: ZBP (pipe)
                                                      |--> inak: null (bez QR)   ; výpočet zlyhá --> výnimka --> rollback
        v
  9    RendererRegistry::for(snapshot.template_version) -> InvoiceInv2Renderer::render  --> RenderedPdf
       RendererRegistry::for(snapshot.report_template_version) -> ReportRep1Renderer::render --> RenderedPdf
        v
  10-11 INSERT invoices (payment_method, payment_note, snapshot_schema=2, template_version='inv-2')
        INSERT invoice_lines, DocumentStore::store(invoice), DocumentStore::store(report)
  12   lock_allocations(...)
  13   AuditLogger::record(issue, + report_sha256)
  14   SET CONSTRAINTS ALL IMMEDIATE  --> invoice_complete_check (vyžaduje 'report' pre schema >= 2)
        v
      COMMIT  (alebo rollback: číslo aj časti sa vrátia)

GET invoices/{id}/pdf     --> DocumentStore::read(id,'invoice')  --> uložené bajty
GET invoices/{id}/report  --> DocumentStore::read(id,'report')   --> uložené bajty (schema 1: 404/info)

Settings -> Zákonné texty -> LegalTextService::update (transakcia: UPDATE legal_texts revision+1 + AuditLogger before/after)
spark documents:samples --> fixtúrne snapshoty -> renderery -> writable/samples/*.pdf (bez DB)
```

### Recommended Project Structure
```
app/Libraries/Invoicing/
├── RendererRegistry.php          # template_version -> PdfRenderer (inv-1, inv-2, rep-1)
├── MpdfInvoiceRenderer.php       # inv-1, NEMENIŤ
├── MpdfInvoiceInv2Renderer.php   # inv-2 (zdieľa MpdfFactory)
├── MpdfReportRenderer.php        # rep-1
├── MpdfFactory.php               # jedna konfigurácia Mpdf (tempDir, DejaVu), nová trieda, inv-1 ostáva so svojou kópiou
├── SnapshotBuilder.php           # schema 2 (rozšírený)
├── LegalProfile.php (+ pravidlá) # print pravidlá podľa profilu
└── Qr/
    ├── QrPaymentBuilder.php      # výber štandardu a payloadu
    ├── PayBySquare.php           # serializér + LZMA cez xz + base32hex
    ├── Lzma.php                  # rozhranie + XzBinaryLzma
    ├── Spayd.php
    ├── PolishZbpQr.php
    └── QrSvg.php                 # bacon SVG -> data URI
app/Libraries/Master/
├── LegalProfileResolver.php      # + revision, + optionalText()
└── LegalTextService.php          # úprava + audit
app/Views/pdf/invoice_inv2.php, report_rep1.php
resources/pdf/invoice-inv2.css, report-rep1.css
app/Language/{sk,en,pl,cs}/ReportPdf.php   # + dopracovať InvoicePdf pl/cs
app/Controllers/Settings/LegalTexts.php, app/Views/settings/legal_texts/*
app/Commands/DocumentsSamples.php
app/Database/Migrations/2026-10-2x-00000N_*.php   # trigger, legal_texts revision+GRANT
```
Pozn.: `MpdfFactory` je nová trieda. `MpdfInvoiceRenderer` (inv-1) zostane bajt-po-bajte nezmenený (D-01), aj keď sa tým duplikuje 10 riadkov konfigurácie. To je zámerné (nemennosť inv-1).

### Pattern 1: PAY by square encoder (overený prototyp)
**What:** Formát: `base32hex( header(2) || len(2, LE) || lzma_raw( crc32_le(4) || tab_payload ) )`, bez paddingu `=`. [CITED: zdrojový kód `bysquare` 4.1.0 (`src/pay/encode.ts`, `header.ts`, `lzma.ts`, `base32hex.ts`, npm tarball), overené vlastným prototypom s `xz`]
- **Header (2 B):** `(bySquareType<<4 | version)`, `(documentType<<4 | reserved)`. Pre platbu `0x?0 0x00`: bajt0 = **verzia formátu** (nibble), teda 1.0.0 = `0x00`, 1.1.0 = `0x01`, 1.2.0 = `0x02`.
- **Length (2 B, uint16 LE):** dĺžka nekomprimovaných dát **vrátane CRC** (CRC 4 B + tab-reťazec v UTF-8). Musí byť < 131072.
- **CRC32:** štandardný CRC-32 (IEEE, rovnaký ako PHP `crc32()`) z UTF-8 bajtov tab-reťazca, uložený ako 4 B little-endian (`pack('V', crc32($tab))`).
- **LZMA:** raw LZMA1, `lc=3, lp=0, pb=2`, dict 2^17 (128 KiB). Z LZMA „alone“ streamu sa vynechá 13-bajtová hlavička (props `0x5D`, dict 4 B, veľkosť 8 B), dekodér ju pri čítaní zrekonštruuje s pevným dict 2^17. [CITED: bysquare `lzma.ts`]
- **Base32hex:** abeceda `0123456789ABCDEFGHIJKLMNOPQRSTUV`, **bez paddingu** (`encode(output, false)`).
- **Tab-reťazec (verzia 1.1.0/1.2.0), polia v poradí:** `invoiceId`, `paymentsCount`, potom pre každú platbu: `type`(1=prevod), `amount`, `currencyCode`, `paymentDueDate`(YYYYMMDD), `variableSymbol`, `constantSymbol`, `specificSymbol`, `originatorsReferenceInformation`, `paymentNote`, `bankAccountsCount`, pre každý účet `iban`, `bic`, potom `standingOrderExt` (`0`), `directDebitExt` (`0`); po všetkých platbách pre každú `beneficiaryName`, `beneficiaryStreet`, `beneficiaryCity`. Prázdne polia ostávajú prázdne (dva tabulátory za sebou). Tab v hodnote sa nahradí medzerou.
- **Suma:** referenčná implementácia tlačí číslo bez koncových núl (`1080.5`, nie `1080.50`). Odporúčam rovnako (decimálna bodka, max 8 des. miest), z reťazca `totals.total` bcmath-orezaním núl, nie cez float.
- **Verzia:** v1.2.0 (od 2025-04-01) robí meno prijímateľa povinným. Pre čo najširšiu podporu starších bankových aplikácií odporúčam **zapísať verziu 1.1.0 (`0x01`) s vyplneným menom a adresou prijímateľa**, [ASSUMED: kompatibilita starších aplikácií, overiť UAT]. Konštanta verzie v jednom mieste.
- **Diakritika:** banky ju v QR často nezvládajú, referenčná knižnica ju štandardne odstraňuje (`deburr: true`). Použiť `Transliterator::create('Any-Latin; Latin-ASCII')` na `paymentNote`, meno, ulicu a mesto.
- **Dĺžky (spec):** poznámka (paymentNote) do 140 znakov, VS do 10 číslic, IBAN bez medzier. [ASSUMED: z tréningu, overiť pri implementácii proti špecifikácii „PAY by square 1.1.0“ od SBA]

**Example (overený prototyp, vykonaný s xz 5.4.5; PHP 8.3, kód je platný aj pre 8.5):**
```php
// Zdroj: vlastný prototyp overený dekódovaním cez `xz -dc --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB`
final class XzBinaryLzma
{
    public function compress(string $raw): string
    {
        $cmd  = ['xz', '--format=raw', '--lzma1=preset=9,lc=3,lp=0,pb=2,dict=128KiB', '-c'];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); // pole = bez shellu
        if (! is_resource($proc)) { throw new RuntimeException('xz not runnable'); }
        fwrite($pipes[0], $raw); fclose($pipes[0]);           // payload < 1 kB, pipe sa nezaplní
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($proc) !== 0 || $out === '') { throw new RuntimeException('xz failed: ' . $err); }
        return $out;
    }
}

$tab = implode("\t", $fields);
$raw = pack('V', crc32($tab)) . $tab;
$bin = "\x01\x00" . pack('v', strlen($raw)) . (new XzBinaryLzma())->compress($raw); // verzia 1.1.0
$qr  = base32hex_encode_no_padding($bin);   // abeceda 0-9A-V, 5-bitové skupiny, bez '='
```
**Pozn. o EOPM:** raw `xz` zapisuje značku konca (EOPM), referenčný encoder (lzma1 JS) ju nemusí zapisovať. Dekodéry bánk čítajú podľa poľa dĺžky a rovnaký prístup (`xz` binárka) používa široko nasadená knižnica `rikudou/skqrpayment` [CITED: WebSearch výsledok o `rikudou/skqrpayment` README], preto je to akceptované. Napriek tomu to **musí overiť reálna bankovská aplikácia v UAT** (viď Open Questions).

**Testovací vektor:** Oficiálny verejný testovací vektor SBA som nenašiel. Vlastný regresný vektor (nie autoritatívny): vstup (`2610001`, `1080.50 EUR`, splatnosť `20261110`, VS `2610001`, SK3112000000198742637541, TATRSKBX, „Jan Novak“) dal pri verzii 1.1.0 reťazec začínajúci `0408K0003UAGL8B1…`. **Presný reťazec neassertovať** (výstup `xz` sa môže líšiť medzi verziami 5.4/5.8). Testovať round-trip: base32hex dekód → odstrániť 4 B → `xz -dc --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB` → overiť že prvé 4 B sú `crc32` zvyšku, dĺžka sedí s poľom a tab-polia zodpovedajú vstupu.

### Pattern 2: SPAYD 1.0 (CZ)
[CITED: qr-platba.cz/pro-vyvojare/specifikace-formatu]
- Tvar: `SPD*1.0*KEY:VALUE*KEY:VALUE*` (kľúče veľkými písmenami, hodnota končí `*`).
- Polia: `ACC` (povinné, IBAN, voliteľne `+BIC`, max 46), `AM` (max 10 znakov, max 2 des., bodka), `CC` (3, ISO 4217), `DT` (YYYYMMDD), `MSG` (max 60), `X-VS` (max 10 číslic), `RN` (max 35), `PT`, `CRC32` (voliteľné, 8 hex veľkými, z kanonického reťazca bez tohto poľa), `NT`/`NTA`.
- `*` vo hodnote = `%2A`. Hodnoty nesmú začínať ani končiť medzerou. Presiahnutá dĺžka sa orezáva sprava (po limit).
- Znaková sada: ISO-8859-1 povolené, ale odporúča sa len `0-9 A-Z` medzera a `$ % * + - . / :` (menšie QR). **Odporúčanie:** transliterovať na ASCII, veľké písmená, vynechať CRC32 (voliteľné) alebo ho pridať (deterministické, lacné). Pre tlač ECC úroveň **M**.
- Poradie polí: `ACC`, `AM`, `CC`, `DT`, `X-VS`, `MSG`, `RN` (abecedné poradie nie je požadované, ale kanonické pre CRC32).
- **Príklad:** `SPD*1.0*ACC:SK3112000000198742637541+TATRSKBX*AM:1080.50*CC:EUR*DT:20261110*X-VS:2610001*MSG:FAKTURA 2610001*RN:JAN NOVAK*`
- Pozor: ACC s nečeskou IBAN a EUR sumou pre českého klienta, česká banka môže zobraziť upozornenie o zahraničnej platbe. [ASSUMED] Treba UAT.

### Pattern 3: Poľský QR podľa Rekomendacji ZBP
Zdroj formátu: [CITED: github.com/IonBazan/pl-qr-payment `QrPayment.php` (zdrojový kód getQrString), github.com/MarcinOrlowski/bank-qrcode-formatter README]. Samotný dokument ZBP som nenašiel (len odkaz), preto **MEDIUM**.
- Oddeľovač `|`, 9 polí: `NIP príjemcu` (max 10) `|` `kraj` (`PL`) `|` `číslo účtu` (26 číslic NRB, bez „PL“ a bez medzier) `|` `suma v grošoch` (zero-pad na min. 6 číslic, `sprintf('%06d')`) `|` `názov príjemcu` (max 20) `|` `názov platby` (max 32) `|` `rezerva1` (max 20) `|` `rezerva2` (max 12) `|` `rezerva3` (max 24). Prázdne polia zostávajú ako koncové `|`. Celková dĺžka ≤ 160 znakov.
- Mapovanie: NIP = `entity.tax_id` (NIP predajcu, 10 číslic bez pomlčiek), účet z `bank.iban` bez prefixu `PL`, suma `totals.total` × 100 cez bcmath (string, nie float), názov príjemcu = orezaný `trade_name` (20 znakov), názov platby = číslo faktúry (D-11: „Tytuł płatności“ je plné číslo; ak > 32 znakov, QR sa vynechá alebo sa orezáva, viď Pitfall 7).
- Z textu vyhodiť `|` a riadiace znaky, orezať podľa `mb_substr`, kódovanie UTF-8, ECC **L** (odporúčanie knižnice), min. veľkosť 1,8 cm.
- Pre `bank.iban` ktorý nie je PL IBAN (napr. SK IBAN na PL klienta s PLN) QR **nevytvárať** (ZBP vyžaduje polský NRB), t.j. podmienka štandardu: klient PL **a** mena PLN **a** IBAN začína `PL` a má 28 znakov. Inak bez QR (D-18 „iná kombinácia“).
- **Suma a limit 6 číslic:** knižnica povoľuje až 10 číslic, ale pôvodná špecifikácia môže predpisovať 6. Pre faktúru nad 9 999,99 PLN nie je isté, že ju aplikácie prijmú. [ASSUMED], viď Open Questions.

### Pattern 4: QR v mPDF ako SVG data URI
[VERIFIED: spike 2026-10-27 v `task-tracker-php:dev`]
```php
// Zdroj: spike, bacon/bacon-qr-code 3.1 + mPDF 8.3
$writer = new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd())); // 2 moduly quiet zone
$svg    = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());
$html   = '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="35mm" height="35mm">';
```
- Výsledok: vektorový Form XObject, 0 rastrových obrázkov, PDF ~29 kB, 0,20 s, peak 22 MB.
- Zakódovať QR SVG **pri vykreslení**, nie ukladať SVG do snapshotu (snapshot nesie len payload, D-17). Rovnaký payload + bacon verzia = rovnaký SVG, ale bajty PDF sa aj tak neregenerujú.
- Alfanumerický režim: PAY by square (base32hex veľké písmená + číslice) je čisto alfanumerický, takže QR je malý. Použiť kódovanie `ISO-8859-1`/`UTF-8` parametrom podľa štandardu, pre PL `UTF-8`.
- Veľkosť: 35 mm je rozumná pre PAY by square (QR verzia ~9 pri ~250 znakoch).

### Pattern 5: Registry rendererov a snapshot schema 2
- `RendererRegistry::for(string $templateVersion): PdfRenderer` s `match` na `inv-1|inv-2|rep-1`, neznáma verzia = výnimka. `InvoiceIssuer` dostane `invoice` aj `report` renderer cez registry, nie dva ručne injektované (jednoduchšie testy: fake registry).
- `InvoiceIssuer` konštruktor dnes berie `?PdfRenderer $renderer` (default `MpdfInvoiceRenderer`). Zmeniť na `?RendererRegistry $renderers` a **aktualizovať všetky testy**, ktoré dnes dávajú `FakePdfRenderer`/`FaultInjectingRenderer` (grep `new InvoiceIssuer(` v `tests/`).
- `FakePdfRenderer` hard-kóduje `'inv-1'`: upraviť na `$snapshot['template_version']`.
- Mpdf objekty: `unset($mpdf)` po `Output()` v každom rendereri, aby sa nezdvojnásobil peak (dva renderery v jednej požiadavke, `memory_limit` 256M je dosť).

### Anti-Patterns to Avoid
- **Meniť `MpdfInvoiceRenderer`/`invoice_inv1.php`/CSS inv-1** (rozbije D-01/D-02). Aj „drobná oprava“ znamená, že neskoršie nové vystavenie `inv-1` už nie je možné, a nie je to zdôvodnené.
- **Čítať `lang()`, `service('clock')`, DB, `esc()` s nastavením z ENV v šablóne** (D-04). `esc()` je v poriadku (čistá funkcia), `lang()` nie.
- **Vkladať `payment.qr` do hashu preview tokenu** (viď Pitfall 1).
- **`float` pre grosze/sumy QR** (použiť bcmath/reťazce).
- **Chytať `DatabaseException` vnútri transakcie a pokračovať** (Phase 5 pravidlo, PG označí transakciu za aborted).
- **Ticho vynechať QR pri zlyhaní výpočtu** (D-19: výnimka → rollback).
- **Generovať dokument z živého `legal_texts`/`lang()`** pri sťahovaní (vždy z uložených bajtov).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| LZMA kompresia | vlastný range encoder | binárka `xz` cez `proc_open` | overený encoder, parametre presné, dekodér bánk očakáva štandardný LZMA1 |
| QR matica a SVG | vlastný QR generátor | `bacon/bacon-qr-code` (už v projekte) | Reed-Solomon, masky, verzie |
| CRC32 | vlastná tabuľka | PHP `crc32()` + `pack('V')` | štandardný IEEE CRC-32 |
| Base32hex | vlastný s paddingom | malá funkcia 5-bitových skupín bez `=` (PHP nemá base32) | alebo `paragonie/constant_time_encoding` `Base32::encodeUnpadded` je abeceda **RFC 4648 base32 (A-Z2-7)**, nie hex! `Base32Hex` existuje v `ParagonIE\ConstantTime\Base32Hex` (je tranzitívna závislosť `spomky-labs/otphp`) [ASSUMED: názov triedy, overiť v `vendor/`] |
| Diakritika v QR | vlastná tabuľka znakov | `Transliterator` (ext-intl) | pokrýva SK/PL/CZ vrátane `Ł`, `ß` |
| Formát sumy a dátumu v PDF | `NumberFormatter`/`IntlDateFormatter` | existujúci `PdfFormat` | nezávislé od ICU (D-04, Phase 5) |
| PDF z HTML | vlastný PDF writer | mPDF | už rozhodnuté |
| Prepis „16 z 40 h“ cez `lang()` v šablóne | | popisky v snapshote (`ofHours` s `{0}`/`{1}`) a `str_replace` v šablóne | nemennosť (Pasca 4) |

**Key insight:** Doménová hodnota fázy je v **správnom obsahu a nemennosti**, nie v technike. Nič, čo sa týka kompresie, QR alebo PDF, sa nemá písať ručne. Vlastný kód je len skladanie polí (serializér tabuliek, SPAYD, ZBP) a ten sa dá úplne pokryť unit testami.

## Právny obsah podľa profilu (PREDBEŽNÉ, potvrdí účtovníčka)

Všetko v tejto sekcii je `[ASSUMED]`/LOW až MEDIUM: zdrojom je `.planning/research/FEATURES.md` (tréningové znalosti, neoverené na texte zákona) a CONTEXT.md D-07. Plánovač to implementuje ako **predvolené pravidlá v kóde** a texty ako konfiguráciu, nič nie je zamknuté pred účtovníčkou.

| Profil | Tlačené polia (pravidlo `LegalProfile`) | Text zo `legal_texts` | Poznámka |
|--------|------------------------------------------|-----------------------|----------|
| SK neplatiteľ (`SK`/`sk_non_vat`, domestic) | obchodné meno, sídlo, IČO, DIČ, **údaj o zápise** (`registry_note`), odberateľ (názov, adresa, identifikátor, DIČ ak je), číslo, dátumy vystavenia/dodania/splatnosti, položky, súčet, platobné údaje (IBAN, SWIFT, VS), forma úhrady, **IČ DPH sa netlačí** | `vat_note` „Nie som platiteľ DPH.“ | § 3a ObZ, 431/2002 Z. z. Bez podpisového záznamu (otvorený bod 5). Kód DPH na riadku `np` (predbežný) |
| CZ neplatiteľ (`cz_non_vat`) | analógia SK (IČO, DIČ, sídlo, zápis, odberateľ, dátumy, položky), VS | `vat_note` „Nejsem plátce DPH.“ | Fáza nemá reálny CZ subjekt, ale profil v DB existuje (musí sa renderovať) |
| PL art. 113 (`pl_exempt_113_1/9`, domestic aj non_eu) | NIP predajcu, NIP kupujúceho (ak ho má), adresy, číslo, dátumy vystavenia a **dodania (vždy)**, položky s jednotkou (`godz.`/`h`), suma, **podklad oslobodenia** (`vat_note` s `exemption_code`), forma úhrady, číslo účtu, „Tytuł płatności“ = plné číslo faktúry | `vat_note` „Sprzedawca zwolniony podmiotowo… art. 113 ust. 1/9 ustawy o VAT.“ | art. 106e ust. 1 (pkt 1-4, 7-9, 15, 19). REGON a CEIDG sa netlačia |
| EÚ B2B (`billing_mode='eu_b2b'`, PL subjekt → SK klient) | to isté ako PL art. 113 + **VAT ID klienta s prefixom krajiny**, **VAT-UE subjektu** (ak vyplnené, `PL`+NIP) | `vat_note` „Odwrotne obciążenie.“ (kód `ART_28B_RC`) | art. 28b, pkt 18. Neisté či pri zwolnieniu podmiotowym (otvorená otázka 2). Predvolené podľa CONTEXT |
| Mimo EÚ / ostatné | pravidlo profilu s `billing_mode NULL` (fallback v `LegalProfileResolver`) | všeobecný text profilu, prepísateľný variantom v UI | otvorená otázka 3 |

- **Nadpis:** „Faktúra“ / „Faktura“ / „Faktura“ / „Invoice“ podľa jazyka dokladu (D-08), nie „daňový doklad“ ani „Faktura VAT“.
- **Zdroj pravdy pre tlač:** `legal.print` v snapshote. Navrhovaný tvar (Claude's Discretion): `{"supplier":["trade_name","address","company_id","tax_id","registry_note"],"customer":["name","address","identifier","tax_id","vat_id"],"meta":["issue_date","delivery_date","due_date","payment_method"],"payment_reference":"variable_symbol"|"invoice_number","line_unit":true|false,"vat_note":true}`. Pole sa tlačí práve vtedy, keď je v zozname **a** hodnota je neprázdna. Šablóna iteruje zoznam, nehľadá profil.
- Šablóna `inv-2` má rezervované miesto pre budúcu druhú sumu v mene subjektu (Phase 8): prázdny blok pri `fx === null`, bez zmeny výstupu.
- Všetky texty sú `provisional = true`, kým ich vlastník v UI neoznačí ako potvrdené (D-15, upozornenie v náhľade).

## Integrácia s existujúcim kódom: presné dotykové body

| Súbor | Zmena | Pasca |
|-------|-------|-------|
| `SnapshotBuilder::build()` | `schema=2`, `template_version='inv-2'`, `report_template_version='rep-1'`, `legal.texts` s `revision`, `legal.print`, `payment={method,note,qr:null}`, `labels` (+ výkaz + názvy foriem úhrady) | jediné miesto stavby (nepísať druhý builder). `LABEL_KEYS` je `const` so zoznamom kľúčov, rozšíriť a pridať kľúče výkazu (`ReportPdf.*`), `lang('ReportPdf.x', [], $docLanguage)` |
| `SnapshotBuilder::build()` signatúra | pribudnú `payment_method`, `payment_note` (z `IssueInput`/`PreviewInput`) | volá ju `InvoiceDraftService` (náhľad) aj `InvoiceIssuer`, aktualizovať obe volania a `SnapshotBuilderTest` |
| `SnapshotBuilder::withNumber()` | + `payment.qr` cez `QrPaymentBuilder` | volať **len po** pridelení čísla. Pri náhľade `number=null` → `payment.qr=null` |
| `InvoiceCalculator::previewToken()` | **`unset($snapshot['payment']['qr'])`** popri `number` a `variable_symbol` | bez toho `hash_equals` v kroku 6 nikdy nesedí (náhľad má `qr=null`, vystavenie by malo QR, ale token sa počíta pred `withNumber`, takže technicky sedí; chyba hrozí len ak niekto token neskôr počíta zo snapshotu po `withNumber`, čo robí test `PreviewTokenScopeTest`). Preto: unset je poistka a test musí overiť, že token pred a po `withNumber` je rovnaký |
| `InvoiceIssuer` | konštruktor: registry; kroky 9-11 pridajú render výkazu a `store($id,'report')`; `insertInvoice` zapíše `payment_method`/`payment_note` zo snapshotu (dnes hard-kódované `'transfer'`, `NULL`), `snapshot_schema=2`; audit pridá `report_sha256` | `payment_method` v INSERT je dnes literal `'transfer'`, `payment_note` literal `NULL` — zmeniť na parametre. `snapshot_schema` literal `1` → `2` |
| `invoice_complete_check()` (migrácia) | `CREATE OR REPLACE FUNCTION` s ďalšou podmienkou: `v_inv.snapshot_schema >= 2 AND NOT EXISTS (… kind='report')` | funkcia sa spúšťa len **AFTER INSERT na `invoices` a `invoice_lines`** (nie `invoice_documents`), ale vykoná sa pri `SET CONSTRAINTS ALL IMMEDIATE` na konci, keď už sú dokumenty vložené, takže poradie vkladania nevadí. Migrácia pod `app_owner`. Skontrolovať, že `FUNCTION` ostane vlastnená `app_owner` a testy `InvoiceSchemaTest`/fixtúry (`InvoiceFixtures`) vkladajúce schema 1 stále prechádzajú |
| `DocumentStore` | bez zmeny | už podporuje `kind` a `UNIQUE (invoice_id, kind)` |
| `LegalProfileResolver::text()` | vrátiť aj `revision` (`SELECT … revision`), pridať `optionalText()` (žiadna výnimka pri chýbaní `invoice_note`) | `text()` pri `vat_note` ostáva s výnimkou (blokuje vystavenie, Phase 3 D-17) |
| `legal_texts` (migrácia) | `ALTER TABLE ADD COLUMN revision integer NOT NULL DEFAULT 1`; `GRANT INSERT ON legal_texts TO app_rw`; `GRANT UPDATE (body, provisional, revision, updated_at) ON legal_texts TO app_rw` | `CreateLegalProfiles` urobil `REVOKE INSERT`. Použiť pomocnú `grantUpdateColumns()` z existujúcej `Migration` základne. Žiadny `DELETE`. `legal_profiles` ostáva len na čítanie |
| `invoices.payment_note` CHECK ≤ 500 | validácia na serveri + `legal_texts.body` CHECK je 1..1000 | predvyplnená `invoice_note` môže mať až 1000 znakov, ale `payment_note` len 500: obmedziť `invoice_note` na 500 pri ukladaní v `LegalTextService` (validácia) alebo orezať. Rozhodnúť, doporučené: validovať `invoice_note` ≤ 500 |
| `Invoices::pdf()` | rovnaký vzor pre `report()`: `DocumentStore::read($id,'report')`, 404 ak schema 1, názov súboru `faktura-`/`vykaz-` + sanitizované číslo (dnes pevne `faktura-`; D-21 chce názov podľa jazyka dokladu) | `Content-Disposition` filename sanitizovať (už robí `preg_replace`). `target="_blank"` v šablóne detailu (iOS standalone, Pasca 11) |
| `app/Config/Routes/invoices.php` | `invoices/(:num)/report` | rovnaké filtre (auth) |
| `app/Views/more/index.php` | položka „Zákonné texty“ | |
| Testy | `FakePdfRenderer`, `FaultInjectingRenderer`, fixtúry vystavovania | upraviť na registry. `IssueFixtures` bude vyrábať schema 2 faktúry cez skutočný `InvoiceIssuer` |

**Staré faktúry (`inv-1`, schema 1):** Nemajú `report`. Detail ukáže informáciu (D-21). Test D-02: po nasadení má každá skôr vystavená faktúra rovnaký `sha256` (stačí že žiadny kód nezapisuje do `invoice_documents` pre existujúce ID, a že trigger nevyžaduje `report` pre schema 1, overiť priamym SELECT-om pred a po migrácii).

## Runtime State Inventory

Nie je rename/refactor fáza, ale migrácia schémy existujúcich dát. Stručne:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Existujúce riadky `legal_texts` (seed `vat_note`, `provisional=true`) dostanú `revision = 1` cez `DEFAULT`. Existujúce faktúry (aj produkčná externá FA 208-2026) nie sú dotknuté | migrácia `ADD COLUMN … DEFAULT 1`, žiadna migrácia dát faktúr |
| Live service config | Žiadne | None — overené: texty sú len v DB |
| OS-registered state | Žiadne | None |
| Secrets/env vars | Žiadne nové | None |
| Build artifacts | Dockerfile: ak sa pridá `apk add xz`, treba prebuildovať obraz `task-tracker-php` (dev aj prod) | rebuild obrazov po zmene Dockerfile |

## Common Pitfalls

### Pitfall 1: Preview token a `payment.qr`
**What goes wrong:** Token sa porovnáva v kroku 6 (pred číslom). Ak by token zahŕňal `payment.qr`, alebo ak by sa `withNumber` mutoval pred porovnaním, vystavenie padne na `preview_stale`.
**Why it happens:** `previewToken()` vynecháva len `number` a `variable_symbol`.
**How to avoid:** `payment.qr` v `build()` vždy `null`, plnené len vo `withNumber()`; v `previewToken()` aj tak `unset($snapshot['payment']['qr'])`. Test: token zo snapshotu pred a po `withNumber` je identický.
**Warning signs:** všetky vystavenia končia `preview_stale`.

### Pitfall 2: `payment_method` ≠ `transfer` a QR
**What goes wrong:** Pri `cash`/`card`/`other` sa QR nemá tlačiť (D-19), no VS a „Forma úhrady“ áno. Platobné údaje (IBAN) pri hotovosti dáva zmysel skryť v `legal.print`.
**How to avoid:** `QrPaymentBuilder::build()` vráti `null` pre `method !== 'transfer'`. Test pre každú formu.

### Pitfall 3: LZMA neúmyselne nedeterministické / `xz` chýba v prod
**What goes wrong:** `xz` nie je v obraze, alebo `proc_open` je v `disable_functions`. Vystavenie SK faktúry zlyhá s rollbackom (správne podľa D-19, ale pre užívateľa je to blokujúca chyba).
**How to avoid:** Pripnúť `apk add xz` v Dockerfile, pridať **smoke test pri štarte** (`spark` príkaz alebo healthcheck, ktorý spustí `xz --version`), test v CI, že `proc_open` + `xz` fungujú (unit test s kompresiou a dekompresiou). `app.ini` nemá `disable_functions` (overené), nepridávať.
**Warning signs:** výnimka `xz not runnable`.

### Pitfall 4: Výstup `xz` sa líši medzi verziami
**What goes wrong:** Test s pevným QR reťazcom padne po upgrade obrazu.
**How to avoid:** testovať round-trip (dekomprimovať a porovnať polia), nie pevný reťazec.

### Pitfall 5: ZBP a SPAYD pre nesedia IBAN/mena
**What goes wrong:** PL klient + PLN, ale `bank.iban` je nepoľský (napr. SK IBAN); ZBP QR by bol neplatný. CZ klient + EUR, SPAYD by mal zmysel, ale česká banka to nemusí prijať.
**How to avoid:** podmienky štandardu v `QrPaymentBuilder` vrátane IBAN prefixu (ZBP vyžaduje `PL`, 28 znakov). Výsledok pre „nezrovnalosť“ = bez QR (nie chyba), chyba len ak štandard platí a výpočet zlyhá (D-19).

### Pitfall 6: Diakritika a font
**What goes wrong:** DejaVu Sans pokrýva Latin Extended-A (SK/PL/CZ vrátane `ł`, `ě`, `ř`, `ů`) a znaky `€`, `zł`, `Kč`, ale PDF nemusí obsahovať všetky glyfy, ak font nie je subsetovaný korektne.
**How to avoid:** test s `pdftotext` na vete s diakritikou všetkých troch jazykov (`Zażółć gęślą jaźń`, `Ľščťžýáíé`, `ěřůďňť`). Spike výstup potvrdil text, `MpdfSpikeTest` už existuje. [VERIFIED: spike 2026-10-27 renderoval tieto vety bez chyby, nebolo overené dekódovaním glyfov] — pridať `pdftotext` kontrolu.

### Pitfall 7: Orezanie v QR poliach
**What goes wrong:** ZBP názov platby max 32 znakov, SPAYD MSG 60, PAY by square note 140. Číslo faktúry je krátke (`RRRRMM###`), ale vlastný formát číslovania môže byť dlhší.
**How to avoid:** pre číslo > limit ZBP: orezať sprava? (stratí sa jednoznačnosť) → radšej **bez QR** a nie ticho; zdokumentovať. Rozhodnutie plánovača: pri presiahnutí limitu pre povinné pole `title` vrátiť `null` (bez QR), nie výnimku. Zaznamenať do Open Questions.

### Pitfall 8: Výkaz a „16 z 40 h“ v inom jazyku/ICU
**What goes wrong:** použitie `NumberFormatter` v šablóne → výstup závisí od ICU.
**How to avoid:** `PdfFormat::hours` + popisok `ofHours` v snapshote („{0} z {1} h“ sk, „{0} z {1} godz.“ pl, „{0} z {1} h“ cs, „{0} of {1} h“ en), `str_replace`.

### Pitfall 9: mPDF a HTML injection / SSRF
**What goes wrong:** názvy úloh, texty klienta a zákonné texty idú do HTML. Neescapovaný text môže vložiť `<img src="http://…">` alebo `<img src="file://…">` (mPDF číta zdroje).
**How to avoid:** **každá** hodnota zo snapshotu cez `esc()`; `nl2br` len po `esc()`. V `MpdfFactory` nenastavovať `allow_url_fopen` zmeny, nepovoliť vzdialené obrázky. Test: úloha s názvom `<img src=http://x>` sa v PDF zobrazí ako text.

### Pitfall 11: Dva Mpdf objekty a pamäť
**How to avoid:** `unset()` po `Output`, nepoužívať spoločný objekt (D-22). Zmerať peak vystavenia s oboma PDF (cieľ < 2 s spolu a peak < 128 MB). Spike: 0,2 s a 22 MB na jedno PDF.

### Pitfall 12: Zámok čítača sa drží dlhšie
**What goes wrong:** Zámok číselného radu sa drží počas oboch renderov (2×0,2-0,4 s). Pri jednom užívateľovi OK.
**How to avoid:** zmerať, nezrýchľovať predčasne. Možnosť: render výkazu pred pridelením čísla nie je možná (číslo je v hlavičke výkazu).

## Code Examples

### Rozšírenie triggera (migrácia, `app_owner`)
```php
// Zdroj: existujúca invoice_complete_check() z CreateInvoices, rozšírená o report pre schema >= 2
$this->db->query(<<<'SQL'
    CREATE OR REPLACE FUNCTION invoice_complete_check() RETURNS trigger LANGUAGE plpgsql AS $$
    DECLARE v_id bigint; v_inv invoices%ROWTYPE; v_count bigint; v_sum numeric;
    BEGIN
        IF TG_TABLE_NAME = 'invoices' THEN v_id := NEW.id; ELSE v_id := NEW.invoice_id; END IF;
        SELECT * INTO v_inv FROM invoices WHERE id = v_id;
        SELECT count(*), coalesce(sum(amount), 0) INTO v_count, v_sum FROM invoice_lines WHERE invoice_id = v_id;
        IF v_count <> v_inv.line_count OR v_sum <> v_inv.total THEN
            RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        IF v_inv.origin = 'issued' THEN
            IF EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id = v_id AND vat_code IS NULL)
               OR NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'invoice')
               OR (v_inv.snapshot_schema >= 2 AND NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'report')) THEN
                RAISE EXCEPTION 'invoice % is incomplete', v_id USING ERRCODE = 'integrity_constraint_violation';
            END IF;
        END IF;
        RETURN NULL;
    END; $$
    SQL);
```
`down()`: vrátiť pôvodné telo funkcie.

### `legal_texts` migrácia
```php
// Zdroj: vzor z CreateLegalProfiles/Entities migrácií (grantUpdateColumns)
$this->db->query('ALTER TABLE legal_texts ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1)');
$this->db->query('GRANT INSERT ON legal_texts TO app_rw');
$this->grantUpdateColumns('legal_texts', ['body', 'provisional', 'revision', 'updated_at']);
```

### Mapovanie štandardu QR
```php
// Zdroj: D-18 + zistenia tohto výskumu
public function build(array $s): ?array
{
    if ($s['payment']['method'] !== 'transfer') { return null; }
    $country = $s['client']['country']; $cur = $s['currency'];
    return match (true) {
        $country === 'SK'                         => ['standard' => 'pay_by_square', 'payload' => $this->pbs->encode($s)],
        $country === 'CZ'                         => ['standard' => 'spayd',         'payload' => $this->spayd->encode($s)],
        $country === 'PL' && $cur === 'PLN' && $this->isPlNrb($s['bank']['iban']) => ['standard' => 'zbp', 'payload' => $this->zbp->encode($s)],
        default                                   => null,
    };
}
```
Výnimka pri zlyhaní enkódera sa NECHYTÁ (D-19).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| PAY by square 1.0/1.1 (meno prijímateľa nepovinné) | 1.2.0 s povinným menom prijímateľa | 2025-04-01 [CITED: bysquare types.ts] | používať vyplnené meno vždy, verziu byte držať konfigurovateľnú |
| PAY by square cez externú službu/PNG | lokálny encoder (xz + serializér) | — | splnené D-17 (žiadna externá služba) |

**Deprecated/outdated:** žiadne v tejto fáze. `Config\Encryption`/Shield nie sú dotknuté.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Verzia PAY by square 1.1.0 (`0x01`) má najširšiu podporu v bankových aplikáciách (vs 1.2.0) | Pattern 1 | QR sa v niektorej aplikácii nenaskenuje. Mitigácia: UAT s 2+ SK bankami, verzia v jednej konštante |
| A2 | `xz` raw encoder s EOPM je akceptovaný dekodérmi SK bánk (rovnaký prístup používa `rikudou/skqrpayment`) | Pattern 1 | PAY by square nefunguje. Mitigácia: UAT, fallback literal-only encoder bez EOPM |
| A3 | Limity PAY by square (poznámka 140, VS 10) | Pattern 1 | orezanie/odmietnutie. Overiť špecifikáciou SBA |
| A4 | ZBP: amount min 6 číslic zero-pad, ďalšie obmedzenia (max suma) | Pattern 3 | faktúry nad ~10 000 PLN nemusia fungovať. UAT s PL bankou |
| A5 | Všetky právne náležitosti a znenia (SK § 3a ObZ, PL art. 106e/113/28b, VAT-UE) | Právny obsah | nesprávna faktúra, preto predbežné (R1) |
| A6 | `Transliterator` ID `Any-Latin; Latin-ASCII` správne spracuje SK/PL/CZ znaky vrátane `Ł` | Standard Stack | zlá diakritika v QR. Pokryť unit testom |
| A7 | `ParagonIE\ConstantTime\Base32Hex` je v `vendor/` a je to abeceda 0-9A-V | Don't Hand-Roll | použiť vlastnú ~12-riadkovú funkciu (funkčne rovnaká) |
| A8 | PL IBAN musí byť polský NRB pre ZBP QR | Pattern 3 | zbytočné vynechanie QR pre iný IBAN |
| A9 | Česká banka akceptuje SPAYD s nečeskou IBAN a EUR | Pattern 2 | QR použiteľný len čiastočne |

## Open Questions

1. **Overenie QR v reálnych aplikáciách**
   - What we know: formáty sú overené proti zdrojom implementácií a vlastným round-trip testom.
   - What's unclear: či ich prijmú SK banky (Tatra, SLSP, VÚB, ČSOB…), CZ banka a PL banka.
   - Recommendation: **ručný UAT krok** v pláne (`human_verify_mode: end-of-phase`): vzorové PDF zo `spark documents:samples` naskenovať 2 SK, 1 CZ a 1 PL aplikáciou, a zdokumentovať výsledok.
2. **ZBP: veľké sumy a dĺžka názvu platby**
   - Recommendation: predvolené správanie „bez QR“ pre suma > 9 999,99 PLN? Navrhujem **nechať QR a upozorniť v UAT**, v prípade problému obmedziť v `PolishZbpQr`.
3. **PAY by square: verzia a EOPM** (A1, A2). Recommendation: implementovať s konštantou, UAT rozhodne.
4. **Právne texty** (otvorené otázky 1-7 z CONTEXT.md): predvolené hodnoty zostávajú, žiadny blokátor.
5. **Rozsah `invoice_note` (1000 vs 500 znakov)**. Recommendation: validovať ≤ 500 v `LegalTextService`.
6. **Jednorazový príkaz na doplnenie výkazu pre staré vlastné faktúry `inv-1`**: predvolene sa nespúšťa (Claude's Discretion). Recommendation: nerobiť, kým o to vlastník nepožiada. Existuje len jedna externá faktúra (bez PDF), vlastné faktúry `inv-1` môžu byť len testovacie.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `xz` (v php-fpm obraze) | PAY by square | ✓ | 5.8.4 (`php:8.5-fpm-alpine`, `task-tracker-php:dev`) | literal-only LZMA encoder v PHP |
| Docker / compose | testy, spike | ✓ | 29.9.2 | — |
| `pdftotext` (poppler-utils) | testy PDF textu | ✓ v dev obraze | — | — |
| ext-intl (`Transliterator`) | QR diakritika | ✓ v obraze | — | — |
| PHP 8.5 | runtime | ✓ v obraze (8.5.12), host má 8.3.6 | — | spúšťať cez `docker compose` |
| `proc_open` | `xz` | ✓ (bez `disable_functions`) | — | — |
| Reálna bankovská aplikácia | UAT QR | ✗ (ľudský krok) | — | nie je (nutné vlastníkom) |

**Missing dependencies with no fallback:** reálna bankovská aplikácia na UAT (nie je blokujúce pre kód, blokuje len finálne potvrdenie QR).
**Missing dependencies with fallback:** žiadne.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 + CI4 `CIUnitTestCase` (`failOnDeprecation/Notice/Warning/Risky`), suites `unit`, `integration`, `feature` |
| Config file | `phpunit.dist.xml` |
| Quick run command | `docker compose exec -T -u www-data php vendor/bin/phpunit --filter <Trieda>` |
| Full suite command | `make test` (potom `composer stan`, `composer cs`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INV-05 | Výber tlačených polí pre každý profil (SK, CZ, PL 113/1, 113/9, EÚ B2B, fallback) | unit | `phpunit --filter LegalProfilePrintTest` | ❌ Wave 0 |
| INV-05 | Snapshot schema 2 má `legal.texts` (s `revision`, `provisional`), `legal.print`, `payment`, `labels` | integration | `phpunit --filter SnapshotBuilderTest` | ✅ rozšíriť |
| INV-05 | PDF `inv-2` obsahuje čísla, súčty, `vat_note`, údaje odberateľa (text cez `pdftotext`), v 4 jazykoch | integration | `phpunit --filter MpdfInvoiceInv2RendererTest` | ❌ Wave 0 |
| INV-05 | Nemennosť: zmena textu/prekladu/subjektu/klienta/sadzby nezmení uložené bajty a `sha256` oboch PDF | integration | `phpunit --filter InvoiceImmutableAfterChangeTest` | ✅ rozšíriť |
| INV-05 | Staré faktúry `inv-1`: rovnaké bajty a `sha256` | integration | `phpunit --filter LegacyInv1UnchangedTest` | ❌ Wave 0 |
| INV-11 | PAY by square: round-trip dekódovanie (`xz -d`), CRC32, dĺžka, polia | unit/integration | `phpunit --filter PayBySquareTest` | ❌ Wave 0 |
| INV-11 | SPAYD a ZBP presné reťazce, escapovanie `*` a `|`, limity dĺžok | unit | `phpunit --filter SpaydTest`, `PolishZbpQrTest` | ❌ Wave 0 |
| INV-11 | Výber štandardu podľa krajiny klienta a meny, `null` mimo, žiadny QR pri `cash` | unit | `phpunit --filter QrPaymentBuilderTest` | ❌ Wave 0 |
| INV-11 | Zlyhanie QR enkodéra = rollback (číslo aj časti sa vrátia) | integration | `phpunit --filter IssueRollbackTest` | ✅ rozšíriť |
| INV-11 | Preview token je rovnaký pred a po `withNumber` (QR/VS nezasahujú) | integration | `phpunit --filter PreviewTokenScopeTest` | ✅ rozšíriť |
| INV-12 | Forma úhrady a poznámka: validácia, uloženie do stĺpcov aj snapshotu, predvyplnenie `invoice_note` | integration/feature | `phpunit --filter PaymentOptionsTest` | ❌ Wave 0 |
| REP-01 | Výkaz vzniká v transakcii, `kind='report'`, `sha256`, `rep-1`; deferred trigger vyžaduje `report` pre schema >= 2 pod `app_rw` | integration | `phpunit --filter ReportDocumentTest` | ❌ Wave 0 |
| REP-01 | Výkaz sa nedá zmeniť (UPDATE/DELETE zlyhá) | integration | `phpunit --filter InvoiceImmutabilityTest` | ✅ rozšíriť |
| REP-02 | Súčty faktúra = výkaz = snapshot = riadky (text z `pdftotext` oboch PDF porovnaný so `totals`) | integration | `phpunit --filter ReportTotalsMatchTest` | ❌ Wave 0 |
| REP-02 | Formát „16 z 40 h“ vo všetkých jazykoch, bez nacenených hodín len fakturované | unit | `phpunit --filter ReportRowFormatTest` | ❌ Wave 0 |
| SET-01 | `LegalTextService`: úprava zvyšuje `revision`, audit pred/po v tej istej transakcii | integration | `phpunit --filter LegalTextServiceTest` | ❌ Wave 0 |
| SET-01 | Oprávnenia `legal_texts` pod `app_rw` (UPDATE len povolené stĺpce, bez DELETE) | integration | `phpunit --filter DbRolesTest` | ✅ rozšíriť |
| SET-01 | Nové znenie platí len pre nové doklady (vystav, zmeň, vystav, porovnaj) | integration | `phpunit --filter LegalTextVersioningTest` | ❌ Wave 0 |
| SET-01 | UI Zákonné texty: zoznam, úprava, fallback zobrazenie, CSRF, validácia | feature | `phpunit --filter LegalTextsControllerTest` | ❌ Wave 0 |
| NFR-01 | Vystavenie s oboma PDF < 2 s (peak pamäte) | integration | `phpunit --filter IssueTimingTest` | ❌ Wave 0 |
| NFR-01/INV-05 | Diakritika SK/PL/CZ a `€ zł Kč` v PDF | integration | `phpunit --filter PdfDiacriticsTest` | ❌ Wave 0 (rozšíriť `MpdfSpikeTest`) |
| D-23 | `spark documents:samples` vyrobí PDF pre každý profil a jazyk, nič nezapíše do DB | integration | `phpunit --filter DocumentsSamplesCommandTest` | ❌ Wave 0 |
| Security | HTML injection v názve úlohy sa v PDF zobrazí ako text | integration | `phpunit --filter PdfEscapingTest` | ❌ Wave 0 |

Manuálne: sken QR reálnymi aplikáciami (SK, CZ, PL), vizuálna kontrola vzorových PDF účtovníčkou, tlač na A4 a čitateľnosť na iPhone (`target="_blank"`).

### Sampling Rate
- **Per task commit:** `phpunit --filter <dotknutá trieda>` + `composer stan`
- **Per wave merge:** `make test`
- **Phase gate:** plná sada zelená + `composer stan` + `composer cs` pred `/gsd-verify-work`, plus manuálny UAT QR.

### Wave 0 Gaps
- [ ] testy uvedené ako ❌ vyššie (QR enkódery, `LegalProfilePrintTest`, renderery, `LegalTextServiceTest`, `ReportDocumentTest`, `ReportTotalsMatchTest`, `LegacyInv1UnchangedTest`, `PdfDiacriticsTest`, `PdfEscapingTest`, `DocumentsSamplesCommandTest`)
- [ ] `tests/_support/PayBySquareDecoder.php` — test helper (base32hex → header → `xz -d` → polia)
- [ ] `tests/_support/PdfText.php` — wrapper `pdftotext` pre aserty textu
- [ ] upraviť `FakePdfRenderer`/`FaultInjectingRenderer`/`IssueFixtures` na registry a schema 2
- [ ] `tests/_support/SnapshotSample.php` — doplniť schema 2 vzorky pre každý profil a jazyk (použije ich aj `spark documents:samples`)
- Framework install: žiadny (existujúci)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (beze zmeny) | Shield, existujúce filtre |
| V3 Session Management | no | existujúce |
| V4 Access Control | yes | trasy `invoices/(:num)/report` a `settings/legal-texts` za rovnakým auth filtrom ako ostatné |
| V5 Input Validation | yes | validácia `body` (1..1000, ak `invoice_note` ≤ 500), `provisional`, forma úhrady (whitelist), poznámka ≤ 500 na serveri, `esc()` všetkých hodnôt v PDF |
| V6 Cryptography | no | `sha256` integrity, bez nových kryptografických operácií |
| V8 Data Protection / V10 Malicious Code | yes | `proc_open` s poľom argumentov (bez shellu, bez vstupu z užívateľa v argumentoch) |
| V13 API / CSRF | yes | CSRF na POST úpravy textov (existujúci filter), htmx hlavičky podľa Phase 1 |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| HTML/URL injection do mPDF (SSRF, čítanie lokálnych súborov cez `<img src>`) | Tampering / Info disclosure | `esc()` všetkého, žiadne surové HTML zo snapshotu, `legal_texts.body` len ako text s `esc()` + `nl2br` |
| Command injection cez `xz` | Tampering | argumenty sú pevné pole, payload cez stdin, žiadny shell |
| Zmena vystaveného dokladu cez úpravu zákonného textu | Tampering | snapshot kópia, stĺpcový UPDATE len na `legal_texts`, `invoice_documents` insert-only + `immutable_guard` |
| Neoprávnená úprava textov | Elevation | auth + CSRF + audit (AUD-01) pred a po |
| Únik PDF cez cache | Info disclosure | `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff` (existujúce v `pdf()`) |
| Podvrhnuté `Content-Disposition` filename | Tampering | sanitizácia (`preg_replace`) čísla faktúry |

## Sources

### Primary (HIGH confidence)
- Kódová základňa projektu (čítané): `MpdfInvoiceRenderer`, `SnapshotBuilder`, `InvoiceIssuer`, `LegalProfileResolver`, `PdfFormat`, `DocumentStore`, migrácie `CreateInvoices`, `CreateLegalProfiles`, `Invoices::pdf`, `composer.json`, `Dockerfile`, `docker/php/conf.d/app.ini`
- Živé overenie 2026-10-27: `docker run php:8.5-fpm-alpine` (xz 5.8.4 prítomný, `proc_open` dostupný), spike bacon SVG data URI v mPDF (vektorový XObject, 0,20 s, 22 MB), prototyp PAY by square s `xz` raw LZMA1 (round-trip dekódovanie OK)
- npm tarball `bysquare` 4.1.0 (zdrojový kód `src/pay/encode.ts`, `header.ts`, `lzma.ts`, `base32hex.ts`, `field.ts`, `types.ts`) čítaný, nie vykonávaný — formát PAY by square (hlavička, CRC32 LE, dĺžka LE, LZMA bez 13 B hlavičky, base32hex bez paddingu, poradie polí)
- [qr-platba.cz špecifikácia SPAYD](https://qr-platba.cz/pro-vyvojare/specifikace-formatu/) — polia, limity, escapovanie

### Secondary (MEDIUM confidence)
- [IonBazan/pl-qr-payment](https://github.com/IonBazan/pl-qr-payment) (zdroj `QrPayment.php::getQrString`) a [MarcinOrlowski/bank-qrcode-formatter](https://github.com/MarcinOrlowski/bank-qrcode-formatter) — formát ZBP QR (oddeľovač `|`, 9 polí, limity)
- [Packagist: rikudou/skqrpayment](https://root.packagist.org/packages/rikudou/skqrpayment), [hrmpfz/pay-by-square](https://packagist.org/packages/hrmpfz/pay-by-square), [paybysquare/php](https://packagist.org/packages/paybysquare/php) — prehľad prístupov k LZMA
- `.planning/research/FEATURES.md`, `STACK.md`, `PITFALLS.md` (predchádzajúci výskum projektu)

### Tertiary (LOW confidence)
- Právne náležitosti (tréningové znalosti, neoverené na texte zákona), oficiálna špecifikácia SBA PAY by square a samotný dokument ZBP nenájdené priamo, čítané len cez implementácie
- Pokus o spustenie referenčného dekodéra `bysquare` (npm) bol zamietnutý nástrojom (nespustený cudzí kód), preto referenčné dekódovanie nezávislou implementáciou chýba a nahrádza ho UAT

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — žiadne nové balíky, `xz` overený v obraze, mPDF a bacon overené spikeom
- Architecture/integrácia: HIGH — dotykové body čítané priamo v kóde
- QR formáty: MEDIUM — formát PAY by square z referenčného zdrojáku, SPAYD z oficiálnej špecifikácie, ZBP len cez implementácie, bez reálneho skenu
- Právny obsah: LOW-MEDIUM — predbežné, závisí od účtovníčky
- Pitfalls: HIGH pre integračné, MEDIUM pre QR

**Research date:** 2026-10-27
**Valid until:** 2026-11-26 (stabilné; PAY by square 1.2.0 je nová, sledovať podporu v aplikáciách)
