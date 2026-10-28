# Phase 6: Dokumenty faktúry a výkazu - Context

**Gathered:** 2026-10-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Každá novo vystavená faktúra dostane pri vystavení dva autoritatívne PDF doklady: **finálnu faktúru** (šablóna `inv-2`) so zákonnými náležitosťami podľa právneho profilu (krajina, DPH režim, režim fakturácie) v jazyku dokladu, s formou úhrady, voliteľnou poznámkou, variabilným symbolom a QR kódom na platbu, a **výkaz práce** (šablóna `rep-1`) s presne tými istými časťami. Oba vznikajú len z kópie údajov (snapshot schema 2) v tej istej transakcii ako vystavenie a ukladajú sa nemenne so `sha256`. Vlastník v nastaveniach upraví zákonné texty a poznámky pre každú krajinu, DPH režim, režim fakturácie a jazyk; nové znenie platí len pre doklady vystavené po zmene.

V tejto fáze: INV-05, INV-11, INV-12, REP-01, REP-02, SET-01.

Mimo tejto fázy: odoslanie e-mailom a prílohy (Phase 7, použije uložené bajty z `invoice_documents`), kurz a druhá suma v mene subjektu na PDF (Phase 8, šablóna musí mať pre ňu miesto, ale `fx` je zatiaľ vždy `null`), dobropis a jeho PDF (Phase 9), ZIP balík pre účtovníčku (Phase 11), finálne potvrdenie znení účtovníčkou (mimo kódu, texty sú konfigurácia v DB).

Už vystavené faktúry (šablóna `inv-1`, snapshot schema 1, aj na produkcii) sa nemenia: ich uložené PDF ostáva jediným autoritatívnym dokladom.

</domain>

<decisions>
## Implementation Decisions

Vlastník rozhodol: rozhodnutia sa berú zo špecifikácie, PRD, REQUIREMENTS.md a `.planning/research/`, bez ďalšej diskusie (pamäť „discuss-phase-from-spec“). Medzery sú v „Claude's Discretion“, právne neisté veci v „Otvorené otázky“ s predvolenou hodnotou.

### Nemennosť a verzie šablón (INV-05, INV-13 z Phase 5)
- **D-01:** Finálna faktúra je **nová verzia šablóny `inv-2`** (nový view a CSS), nie úprava `inv-1`. Súbory `app/Views/pdf/invoice_inv1.php`, `resources/pdf/invoice-inv1.css` a `MpdfInvoiceRenderer` (inv-1) sa **nemenia ani nemažú**. Renderer sa vyberá podľa `template_version` zo snapshotu (registry `inv-1` / `inv-2` / `rep-1`), nie podľa „aktuálnej“ šablóny.
- **D-02:** Uložené PDF sa nikdy neregeneruje (INV-13). Existujúce faktúry `inv-1` (vrátane produkcie) si nechajú svoje uložené bajty. Test: po nasadení Phase 6 má každá skôr vystavená faktúra rovnaký `sha256` a rovnaké bajty ako pred ním.
- **D-03:** Snapshot dostane **`schema = 2`**. Nové a zmenené časti: `template_version = 'inv-2'`, `report_template_version = 'rep-1'`, `legal.texts` (všetky vyriešené kľúče, pozri D-12), `legal.print` (zoznam polí, ktoré šablóna vytlačí, pozri D-07), `payment` (`method`, `note`, `qr`), rozšírené `labels` (popisky faktúry aj výkazu, názvy foriem úhrady). Snapshot schema 1 sa ďalej len číta (detail, zoznam), nikdy sa nevytvára. Preview token (quick 261027-jjx) je z celej kópie údajov, takže nové polia sú pokryté automaticky.
- **D-04:** Všetko, čo PDF zobrazí, je v snapshote ako hotová hodnota: preložené popisky, vyriešené texty, zoznam tlačených polí, QR payload, variabilný symbol, sumy ako reťazce. Šablóna nečíta `lang()`, DB, `service()`, nastavenia ani čas (vzor `inv-1`, PdfFormat bez ICU).

### Výkaz práce (REP-01, REP-02)
- **D-05:** Výkaz vzniká **pri vystavení, v tej istej transakcii** ako faktúra a jej PDF (INV-13/INV-14: „PDF faktúry aj výkazu sa vygeneruje raz pri vystavení“). Ukladá sa do `invoice_documents` s `kind = 'report'`, `sha256`, `template_version = 'rep-1'` a `renderer`. Deferred trigger `invoice_complete_check` sa novou migráciou rozšíri: vlastná faktúra so `snapshot_schema >= 2` musí mať aj dokument `report`. Faktúry schema 1 a externé faktúry výkaz nevyžadujú.
- **D-06:** Obsah výkazu (spec 6.6): hlavička (subjekt, klient, obdobie = fakturačný mesiac, odkaz na číslo faktúry), tabuľka po **riadkoch faktúry** v ich poradí (odkaz na portál ako klikateľný odkaz s `task_ref` alebo skrátenou URL, názov úlohy, nacenené hodiny, fakturované hodiny vo formáte „16 z 40 h“, suma), súčet hodín a súm, sadzba a poznámka o DPH (ten istý vyriešený `vat_note` ako na faktúre). Súčty sa berú zo snapshotu (`totals`), takže výkaz a faktúra sa nemôžu rozísť (REP-02). Ak úloha nemá nacenené hodiny, zobrazí sa len fakturovaný počet hodín. Ak majú všetky riadky rovnakú sadzbu, sadzba je v hlavičke súčtov, inak v stĺpci.

### Právne profily a tlačené náležitosti (INV-05)
- **D-07:** Ktoré polia sa tlačia, určuje **pravidlo profilu v kóde** (`LegalProfile` pre SK neplatiteľa, PL art. 113, EÚ B2B, výskum ARCHITECTURE), **texty sú v DB**. Výsledok pravidla sa pri vystavení zapíše do snapshotu ako `legal.print`, takže neskoršia zmena pravidla nezmení ani prípadné núdzové vykreslenie starého dokladu. Predbežné pravidlá (R1, potvrdí účtovníčka):
  - **SK neplatiteľ** (spec 6.5, § 3a ObZ, 431/2002): obchodné meno, sídlo, IČO, DIČ, údaj o zápise (`registry_note` z verzie subjektu), údaje odberateľa (názov, adresa, identifikátor, DIČ ak je), číslo, dátum vystavenia, dodania a splatnosti, položky a sumy, platobné údaje, text `vat_note` („Nie som platiteľ DPH.“). IČ DPH subjektu sa netlačí.
  - **PL art. 113** (art. 106e): NIP predajcu, NIP kupujúceho (ak ho má), adresy, číslo, dátum vystavenia a dodania, položky s jednotkou, suma, podklad oslobodenia (`vat_note` s `exemption_code`), platobné údaje. REGON a údaj CEIDG sa netlačia (nie sú v art. 106e).
  - **EÚ B2B** (spec 6.5, ENT-05, Phase 3 D-14): navyše IČ DPH / VAT ID klienta s prefixom krajiny a VAT-UE subjektu, ak je vyplnené; zákonná poznámka z profilu s `billing_mode = 'eu_b2b'`.
  - **mimo EÚ** a ostatné kombinácie bez vlastného riadku: pravidlo a text profilu s `billing_mode NULL` (existujúci fallback v `LegalProfileResolver`).
- **D-08:** Nadpis dokladu je „Faktúra“ / „Faktura“ / „Faktura“ / „Invoice“ podľa jazyka dokladu, nie „daňový doklad“ ani „Faktura VAT“ (neplatiteľ). Jazyk dokladu = jazyk klienta, inak jazyk verzie subjektu (už v Phase 5).
- **D-09:** Popisky `InvoicePdf` pre `pl` a `cs` (dnes predbežné) sa v tejto fáze dopracujú a pribudne jazykový súbor pre výkaz (napr. `ReportPdf`) vo všetkých štyroch jazykoch. Popisky sa kopírujú do snapshotu (D-04), takže neskoršia úprava prekladu nezmení vystavené doklady.

### Forma úhrady, poznámka a variabilný symbol (INV-12)
- **D-10:** Forma úhrady sa vyberá v náhľade (predvolene **prevod**; `transfer | cash | card | other` podľa existujúceho `CHECK` v `invoices.payment_method`). Voliteľná poznámka (napr. o úrokoch z omeškania, do 500 znakov podľa `CHECK`) sa predvyplní z textu `invoice_note` v jazyku dokladu (D-12), ak existuje, a dá sa pre konkrétnu faktúru upraviť alebo vymazať. Výsledok sa uloží do stĺpcov `payment_method`, `payment_note` aj do snapshotu `payment`. Server validuje oba vstupy.
- **D-11:** Variabilný symbol zostáva podľa WR-05 (`SnapshotBuilder::variableSymbol()`: číslice čísla faktúry, najviac 10), pre predvolený formát `RRRRMM###` sa rovná číslu faktúry. Na PL doklade sa namiesto „variabilného symbolu“ tlačí „Tytuł płatności“ s plným číslom faktúry (pravidlo profilu, D-07).

### Zákonné texty v nastaveniach a ich verzie (SET-01)
- **D-12:** Kľúče textov v `legal_texts`: **`vat_note`** (povinný, chýbajúci blokuje vystavenie, Phase 3 D-17) a **`invoice_note`** (voliteľný, predvolená poznámka faktúry, D-10). Ďalšie kľúče pridá kód len s novou verziou šablóny. Údaj o zápise nie je zákonný text, ale súčasť verzie subjektu (`registry_note`).
- **D-13:** Obrazovka **Nastavenia → Zákonné texty**: zoznam podľa profilu (krajina, DPH režim, režim fakturácie) a jazyka, úprava znenia, pridanie variantu pre chýbajúci jazyk alebo režim fakturácie (napr. `cs` pre SK profil, vlastné znenie pre `non_eu`) a príznak **„predbežné / potvrdené účtovníčkou“** (`provisional`). Zobrazí aj, ktorý text sa reálne použije pri fallbacku (režim `NULL`, jazyk `en`). Mazanie textov sa vo V1 neponúka (oprava = úprava). Vzory UI ako ostatné nastavenia (htmx, Bootstrap, 44 px ciele, CSP bez inline).
- **D-14:** Verziovanie a zmrazenie: znenie sa pri vystavení **kopíruje do snapshotu ako vyriešený text** (už Phase 5 D-18) spolu s `key`, `language`, `provisional` a **`revision`** (nový stĺpec, zvýši sa pri každej úprave). Úprava je jedna služba (napr. `LegalTextService`) v transakcii s `AuditLogger` (pred a po, AUD-01). `app_rw` dostane len `INSERT` a stĺpcový `UPDATE (body, provisional, revision, updated_at)` na `legal_texts`, bez `DELETE`. História znení je v `audit_log`, vystavené doklady majú svoju kópiu, preto samostatná tabuľka verzií nie je potrebná. Kritérium „nové znenie ovplyvní len doklady vystavené po zmene“ overí test (vystav, zmeň text, vystav znova, porovnaj obe kópie a bajty prvého PDF).
- **D-15:** Ak je použitý text `provisional = true`, náhľad zobrazí nevýrazné upozornenie „znenie ešte nepotvrdila účtovníčka“. Vystavenie neblokuje.
- **D-16:** `legal_profiles` (`exemption_code`, `vat_code`) sa v UI len zobrazujú. Ich oprava po potvrdení účtovníčkou ide novou migráciou a platí len pre budúce doklady (Phase 5 rozhodnutie, R1).

### QR platba (INV-11)
- **D-17:** QR payload sa **vypočíta pri vystavení a uloží do snapshotu** (`payment.qr = {standard, payload}`), PDF ho len vykreslí. QR sa negeneruje cez žiadnu externú službu. Vykreslenie cez existujúci `bacon/bacon-qr-code` (SVG) alebo vstavaný QR v mPDF (rozhodne výskum podľa kvality v mPDF).
- **D-18:** Štandard sa vyberá podľa **krajiny klienta** (platí klient, rozhoduje jeho banková aplikácia) a meny faktúry:
  - klient SK: **PAY by square** (akákoľvek mena účtu, typicky EUR),
  - klient CZ: **QR Platba / SPAYD**,
  - klient PL a mena PLN: **QR kód ZBP** („Rekomendacja ZBP dotycząca kodów QR“),
  - iná kombinácia: **bez QR** (QR nie je zákonná náležitosť, faktúra ostáva platná). Pre EUR mimo SK zváži výskum EPC QR (SEPA) ako rozšírenie.
  Obsah: IBAN a SWIFT zo snapshotu `bank`, suma `totals.total`, mena, variabilný symbol (SK/CZ) alebo číslo faktúry v správe pre príjemcu, dátum splatnosti, meno príjemcu.
- **D-19:** QR sa tlačí len pri forme úhrady **prevod**. Payload sa v náhľade nevytvára (číslo vzniká až pri vystavení), vytvorí sa po pridelení čísla. Ak pre danú kombináciu štandard existuje a výpočet payloadu zlyhá, vystavenie zlyhá s chybou a rollbackom. Faktúra nikdy ticho nevznikne bez QR, ktorý mala mať. PAY by square potrebuje LZMA (výskum vyberie riešenie bez externej služby, napr. binárka `xz` v obraze alebo knižnica v čistom PHP, a overí ho dekódovaním).

### Vystavenie, zobrazenie, výkon
- **D-20:** Poradie v `InvoiceIssuer` sa rozšíri: snapshot (schema 2, s QR payloadom po pridelení čísla) → render faktúry → render výkazu → zápis faktúry, riadkov a **oboch** dokumentov → zamknutie častí → audit, všetko v jednej transakcii. Zlyhanie ktoréhokoľvek renderu vráti číslo aj časti (test rollbacku ako v Phase 5).
- **D-21:** Detail faktúry ukáže dve tlačidlá: „Faktúra PDF“ a „Výkaz PDF“ (uložené bajty, `target="_blank"` kvôli iOS standalone). Nová trasa pre výkaz (napr. `invoices/(:num)/report`) cez `DocumentStore::read($id, 'report')`. Faktúra bez výkazu (schema 1) ukáže informáciu namiesto tlačidla. Názov súboru podľa jazyka dokladu a sanitizovaného čísla.
- **D-22:** Výkon (NFR-01): každý PDF pod 2 s, vystavenie s oboma PDF zmerať (spike Phase 1: jedno PDF ~0,3 s, peak 22 MB). Jeden font DejaVu (už pripnutý), bez substitúcií, dva samostatné objekty `Mpdf`.
- **D-23:** Staging (poznámka ROADMAP): `spark` príkaz vygeneruje **vzorové PDF** faktúry a výkazu pre každý profil a jazyk z fixtúrnych snapshotov do `writable/` (nič nezapisuje do DB, viditeľne označené „VZOR“), aby ich vlastník mohol poslať účtovníčke. E-mail je až Phase 7.

### Testy (NFR-06)
- **D-24:** PHPUnit pokryje: výber tlačených polí pre každý profil, snapshot schema 2 (texty, revision, print, payment, QR), zhodu súčtov faktúra = výkaz = snapshot = riadky, existenciu oboch dokumentov pod `app_rw` (deferred trigger), rollback pri zlyhaní renderu výkazu alebo QR, nemennosť po zmene zákonného textu, prekladu, subjektu, klienta a sadzby (bajty a `sha256` oboch PDF), nezmenené bajty starých faktúr `inv-1`, QR payloady proti referenčným vektorom (PAY by square dekódovaním späť, SPAYD a ZBP reťazcom), oprávnenia `legal_texts` pod `app_rw` a audit úprav.

### Claude's Discretion
Plánovač a výskum môžu spresniť, nie obrátiť:
- Vzhľad `inv-2` a `rep-1` (jednoduchý, čistý, len zákonné údaje, A4, spec §10): rozloženie hlavičky, poradie blokov, miesto pre QR a pre budúcu druhú sumu v mene subjektu (Phase 8).
- Presný tvar `legal.print` (zoznam kľúčov alebo booleovská mapa), názvy tried (`LegalProfile*`, `ReportRenderer`, `QrPaymentBuilder`, `LegalTextService`), registry rendererov a jazykový súbor výkazu.
- Knižnica alebo spôsob LZMA pre PAY by square, voľba SVG z bacon vs QR v mPDF, presná špecifikácia polí SPAYD a ZBP (výskum overí voči dokumentácii štandardov).
- Ako sa v PDF zobrazí odkaz na portál (`task_ref` ako text odkazu, inak skrátená URL).
- Či sa riadky výkazu tej istej úlohy v jednom mesiaci zlúčia (predvolene nie, D-06), a formát „16 z 40 h“ v ostatných jazykoch („16 z 40 godz.“, „16 of 40 h“).
- Ak na produkcii existujú vlastné faktúry `inv-1` bez výkazu, voliteľný jednorazový idempotentný `spark` príkaz môže pre ne vytvoriť výkaz `rep-1` zo snapshotu schema 1 (obsahuje `portal_url`, `estimated_hours`, riadky). PDF faktúry sa tým nemení. Predvolene sa nespúšťa.
- Text upozornení, tlačidiel a toastov, rozloženie obrazovky Zákonné texty na mobile a PC.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Rozsah a požiadavky
- `.planning/ROADMAP.md` §Phase 6 — cieľ, kritériá úspechu, poznámky (výskum: právne profily, mPDF fonty a pamäť, PAY by square a LZMA; závislosť od účtovníčky; staging a vzorové PDF)
- `.planning/REQUIREMENTS.md` — INV-05, INV-11, INV-12, REP-01, REP-02, SET-01; súvisiace INV-03, INV-08, INV-09, INV-13, INV-14, SET-02, ENT-05, CLI-05; §Otvorené otázky pre účtovníčku
- `docs/superpowers/specs/2026-10-23-task-tracker-design.md` §6.1 (subjekt, údaj o zápise), §6.2 (jazyk dokladov), §6.5 (Faktúra, Zákonné náležitosti, cezhraničná fakturácia), §6.6 (Výkaz práce), §7 (jazyky a formát čísel), §10 (vzhľad PDF na implementátorovi)
- `docs/PRD.md` §6 (INV-05, REP-01, REP-02, SET-01, CLI-05), §7 NFR-01, §8 riziko R1, G2, G3, G4

### Výskum
- `.planning/research/FEATURES.md` §Zákonné náležitosti faktúry (SK neplatiteľ, PL art. 106e/113, EÚ B2B art. 28b), M5, M8 (QR), M13, M14, FA(3) kontrolný zoznam
- `.planning/research/ARCHITECTURE.md` — Documents (`InvoicePdf`, `WorkReportPdf`, `DocumentStore`), obsah snapshotu, `LegalProfile` v kóde a texty v DB, verzovanie šablón (`inv-2`), editovateľné texty v DB
- `.planning/research/PITFALLS.md` Pasca 4 (únik nemennosti: texty, popisky, „16 z 40 h“, ICU), Pasca 11 (iOS PDF a `target="_blank"`, zámok relácie pri PDF)
- `.planning/research/SUMMARY.md` §Otvorené otázky (body 3, 4, 9 pre účtovníčku)
- `.planning/research/STACK.md` — mPDF nastavenie (fonty, tempDir, bez substitúcií), `bacon/bacon-qr-code` SVG

### Predchádzajúce fázy
- `.planning/phases/05-faktura-n-jadro/05-CONTEXT.md` — D-16 (autoritatívne PDF), D-17 (`inv-1` je minimum, finálne PDF je Phase 6), D-18 (snapshot samostatne postačujúci, vyriešené texty), D-19 (stĺpce `payment_method`, `payment_note`)
- `.planning/phases/05-faktura-n-jadro/05-RESEARCH.md` — schéma `invoices`, `invoice_documents` (`kind invoice|report`), deferred trigger, predbežné `vat_code` (A1)
- `.planning/phases/05-faktura-n-jadro/05-REVIEW-FIX.md` — WR-05 (variabilný symbol), WR-02 (hranice dátumov)
- `.planning/phases/05-faktura-n-jadro/05-02-SUMMARY.md` — PdfFormat, PdfRenderer, inv-1, predbežné preklady pl/cs
- `.planning/phases/05-faktura-n-jadro/05-01-SUMMARY.md` — predbežné `vat_code` (zw/np)
- `.planning/phases/03-master-d-ta-subjekty-a-klienti/03-CONTEXT.md` — D-14 (režim fakturácie, VAT ID klienta pri EÚ B2B), D-16 (kód oslobodenia), D-17 (`legal_texts`, fallback, nikdy prázdny text, UI až Phase 6)
- `.planning/phases/01-z-klad-a-infra-trukt-ra/01-06-SUMMARY.md` — spike mPDF pod PHP 8.5 (čas, pamäť, diakritika)

### Prevádzka
- `docs/ops/staging.md` — nasadenie na staging (vzorové PDF)
- `docs/ops/backup.md` — PDF dokladov sú v `bytea`, záloha cez `pg_dump`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Libraries/Invoicing/SnapshotBuilder.php`: jediné miesto stavby kópie (náhľad aj vystavenie), `LABEL_KEYS`, `variableSymbol()`, `withNumber()`. Rozšíri sa na schema 2 (texty, print, payment, QR).
- `app/Libraries/Invoicing/PdfRenderer.php`, `RenderedPdf.php`, `MpdfInvoiceRenderer.php` (inv-1, nemeniť): vzor pre `inv-2` a `rep-1` rendery (mPDF konfigurácia, `SetCreator`, `tempDir`, DejaVu z `resources/fonts`).
- `app/Libraries/Invoicing/PdfFormat.php`: formát súm, hodín a dátumov bez ICU, použiť aj vo výkaze.
- `app/Libraries/Invoicing/DocumentStore.php`: `store($id, $kind, $pdf)`, `read($id, $kind)`, `meta()`, už podporuje `kind`.
- `app/Libraries/Invoicing/InvoiceIssuer.php`: kroky 8 až 13 (číslo, render, zápis, dokumenty, zámok, audit) sa rozšíria o výkaz a QR.
- `app/Libraries/Master/LegalProfileResolver.php`: `profile()` a `text()` s fallbackom režimu a jazyka `en`. Rozšíri sa o `revision` a voliteľný kľúč (`invoice_note` bez výnimky pri chýbaní).
- `app/Libraries/Audit/AuditLogger.php`: audit úprav textov v tej istej transakcii.
- `bacon/bacon-qr-code` (už v `composer.json`, použité pri TOTP): SVG QR bez GD.
- `app/Views/invoicing/_preview.php`, `_confirm_sheet.php`: miesto pre formu úhrady, poznámku a upozornenie na predbežný text.
- `app/Views/invoices/show.php`, `app/Controllers/Invoices.php::pdf()`: vzor pre tlačidlo a trasu výkazu.

### Established Patterns
- Insert-only tabuľky cez GRANT, `immutable_guard` aj pre ownera, deferred constraint trigger `invoice_complete_check` (rozšíriť o výkaz pre schema >= 2).
- `legal_texts` a `legal_profiles` sú dnes pre `app_rw` len na čítanie (`REVOKE INSERT`); Phase 6 povolí stĺpcový UPDATE a INSERT len na `legal_texts`.
- Migrácie pod `app_owner`, testy pod `app_rw` (`PgTestCase` `owner()`/`rw()`).
- Šablóna PDF dostáva len `$s` (snapshot), žiadne `lang()`, DB, `<style>` ani inline `style=` (CSS cez `HEADER_CSS`).
- Všetky texty UI cez `lang()`, doménové služby v `app/Libraries/<Kontext>/`, htmx `HX-Location`/`HX-Trigger`, CSP bez inline skriptov.

### Integration Points
- `invoices.payment_method`, `payment_note` (stĺpce už existujú, dnes vždy `transfer` a `NULL`).
- `invoice_documents.kind = 'report'` (CHECK už povoľuje).
- Nastavenia: nová položka „Zákonné texty“ v `app/Views/more/` a trasy v `app/Config/Routes/settings.php`.
- Phase 7 (e-mail) bude prikladať oba uložené dokumenty, Phase 8 doplní `fx` a druhú sumu do `inv-2` (alebo `inv-3`), Phase 9 pridá šablónu dobropisu.

</code_context>

<specifics>
## Specific Ideas

- Core Value: rovnaký súčet na obrazovke, vo faktúre, vo výkaze a v snapshote; žiadna neskoršia zmena (text, preklad, subjekt, klient, sadzba) nezmení vystavený doklad.
- Výkaz nahrádza ručne skladaný mesačný PDF z Excelu: odkaz do Taskmanu, názov, nacenené hodiny, „16 z 40 h“, suma.
- Prvé reálne použitie: SK subjekt (neplatiteľ, EUR) → All4Net (SK, domáci režim), teda PAY by square a „Nie som platiteľ DPH.“. Neskôr PL subjekt (PLN, art. 113) → All4Net (SK, EÚ B2B).
- Na produkcii existuje historická externá faktúra FA 208-2026 (09/2026) bez PDF; Phase 6 sa jej netýka.

</specifics>

<deferred>
## Deferred Ideas

- Druhá suma v mene subjektu a kurz na PDF: Phase 8.
- Odoslanie faktúry a výkazu e-mailom, šablóny e-mailov: Phase 7.
- PDF dobropisu (opravnej faktúry): Phase 9.
- ZIP balík PDF pre účtovníčku: Phase 11.
- Úprava `legal_profiles` (`vat_code`, `exemption_code`) v UI: zatiaľ len migráciou.
- Logo, návrhár šablón, podpisový záznam: mimo V1 (návrhár je anti-feature; podpis závisí od účtovníčky, pozri otvorené otázky).
- EPC QR (SEPA) pre klientov mimo SK/CZ/PL: len ak to výskum odporučí ako lacné rozšírenie.

</deferred>

## Otvorené otázky / závislosť od účtovníčky

Neblokujú plánovanie ani implementáciu (texty sú konfigurácia v DB, pravidlá profilu sú verzované so šablónou), blokujú len **finálne znenie** pred ostrými dokladmi PL subjektu. Každá má predvolenú hodnotu, s ktorou sa implementuje:

1. **Znenie `vat_note` pre každý profil a jazyk** (§ 3a ObZ, art. 106e, art. 113 ust. 1 vs 9). Predvolené: existujúci predbežný seed (`provisional = true`), vlastník ho upraví v UI po potvrdení.
2. **PL subjekt → SK B2B klient (EÚ B2B):** reverse charge („Odwrotne obciążenie“, art. 28b) alebo art. 113, povinnosť VAT-UE a `PL`+NIP na faktúre. Predvolené: profil `eu_b2b` s textom „Odwrotne obciążenie.“, kódom `ART_28B_RC`, tlač VAT ID klienta a VAT-UE subjektu, ak je vyplnené.
3. **PL subjekt, klient mimo EÚ (`non_eu`):** iná poznámka (napr. „np“, miesto plnenia mimo PL)? Predvolené: fallback na všeobecný text profilu (art. 113), dá sa prepísať variantom v UI (D-13).
4. **Kódy DPH na položke** (`np` pre SK/CZ neplatiteľa a reverse charge, `zw` pre art. 113). Predvolené: predbežné hodnoty z Phase 5, oprava migráciou.
5. **Podpisový záznam** (§ 10 zákona 431/2002) na SK doklade. Predvolené: bez podpisu (elektronická faktúra).
6. **REGON, údaj CEIDG a dátum dodania na PL faktúre** (tlačiť, aj keď nie je povinný?). Predvolené: REGON a CEIDG nie, dátum dodania vždy.
7. **Predvolený text poznámky o úrokoch z omeškania** (`invoice_note`). Predvolené: žiadny seed, vlastník ho voliteľne doplní v UI.

---

*Phase: 06-dokumenty-fakt-ry-a-v-kazu*
*Context gathered: 2026-10-27*
