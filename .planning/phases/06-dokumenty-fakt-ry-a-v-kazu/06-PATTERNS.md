# Phase 6: Dokumenty faktúry a výkazu - Pattern Map

**Mapped:** 2026-10-27
**Files analyzed:** 52 nových/upravených súborov
**Analogs found:** 46 / 52 (6 bez analógu alebo len čiastočný)

Zdroje: 06-CONTEXT.md (D-01..D-24), 06-RESEARCH.md, 06-UI-SPEC.md, 05-PATTERNS.md (neopakuje sa: transakčná hranica, zámky, audit, htmx odpovede, insert-only GRANT, FakePdfRenderer kostry platia ďalej). Čísla riadkov sú z čítania 2026-10-27. Fáza rozširuje Phase 5, takže väčšina analógov sú súbory z Phase 5 (nemenia sa tie `inv-1`, kopírujú sa).

**Tvrdé pravidlo D-01/D-02:** `app/Libraries/Invoicing/MpdfInvoiceRenderer.php`, `app/Views/pdf/invoice_inv1.php`, `resources/pdf/invoice-inv1.css` sa NEMENIA (ani formátovaním). Nové renderery sú kópie, nie refaktor.

## File Classification

### Migrácie (pod `app_owner`, predpona po `2026-10-27-000006`)

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `app/Database/Migrations/2026-10-28-000001_AddLegalTextsRevision.php` (`revision`, `GRANT INSERT`, stĺpcový `UPDATE`) | migration | CRUD / GRANT | `2026-10-27-000001_AddLegalProfilesVatCode.php` + `2026-10-25-000005_CreateLegalProfiles.php` (REVOKE) + trait `GrantsPrivileges` | exact |
| `app/Database/Migrations/2026-10-28-000002_RequireReportDocument.php` (`CREATE OR REPLACE FUNCTION invoice_complete_check`) | migration | DB guard | `2026-10-27-000005_GuardInvoiceCounterInsert.php` (CREATE OR REPLACE + down() vráti starú funkciu) | exact |
| (voliteľná) migrácia seed `invoice_note` / úprava `legal_texts` | migration | seed | `2026-10-25-000005_CreateLegalProfiles.php` r. 88 | role-match |

### Doména `app/Libraries/Invoicing/`

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `MpdfFactory.php` (jedna konfigurácia Mpdf pre inv-2 a rep-1) | utility | transform | `MpdfInvoiceRenderer.php` r. 28-44 | exact |
| `MpdfInvoiceInv2Renderer.php` (inv-2) | service | transform | `MpdfInvoiceRenderer.php` | exact |
| `MpdfReportRenderer.php` (rep-1) | service | transform | `MpdfInvoiceRenderer.php` | exact |
| `RendererRegistry.php` | utility | request-response (lookup) | žiadny; vzor `match` + `InvalidArgumentException` z renderera | partial |
| `SnapshotBuilder.php` (úprava: schema 2) | service | transform | sám súbor | — |
| `InvoiceCalculator::previewToken` (úprava: unset `payment.qr`) | utility | transform | sám súbor r. 86-91 | — |
| `InvoiceIssuer.php` (úprava: registry, report, payment_*, schema 2) | service | CRUD v transakcii | sám súbor | — |
| `LegalProfile.php` (+ pravidlá `print`) | utility | transform (čistá funkcia) | `Master/BillingMode.php`, `Master/VatRegime.php` (čisté triedy s konštantami) | role-match |
| `Qr/QrPaymentBuilder.php` | service | transform | `InvoiceCalculator.php` (čistá trieda bez DB) | role-match |
| `Qr/PayBySquare.php`, `Qr/Lzma.php` (`XzBinaryLzma`), `Qr/Spayd.php`, `Qr/PolishZbpQr.php`, `Qr/QrSvg.php` | utility | transform | bez analógu (výskum Pattern 1-4), štýl `PdfFormat.php` (statické čisté funkcie) | no analog |
| `IssueInput` / `PreviewInput` / `IssueCommand` (úprava: `payment_method`, `payment_note`) | DTO/validácia | request-response | `PreviewInput.php`, `IssueCommand.php` | exact |
| `InvoiceQueries.php` (úprava: meta oboch dokumentov) | service (read) | read | sám súbor + `DocumentStore::meta()` | — |

### Doména `app/Libraries/Master/`, príkazy

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `Master/LegalTextService.php` (update, create variant, list) | service | CRUD + audit | `Master/SeriesService.php::update` (Transaction + `recordChange`) | exact |
| `Master/LegalTextRejected.php` (výnimka) | exception | — | `Master/SeriesRejected.php` | exact |
| `Master/LegalProfileResolver.php` (úprava: `revision`, `optionalText`) | service | read | sám súbor | — |
| `app/Commands/DocumentsSamples.php` (`documents:samples`) | command (CLI) | batch (bez DB) | `app/Commands/InvoicesLockHistorical.php` (opt parsing, EXIT kódy) | role-match |

### Controllery, routy

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `app/Controllers/Settings/LegalTexts.php` (index, edit, update, new, create) | controller | request-response + htmx | `app/Controllers/Settings/Series.php` | exact |
| `app/Controllers/Invoices.php` (úprava: `report()`, názov súboru podľa jazyka) | controller | file download | sám súbor `pdf()` r. 53-87 | — |
| `app/Controllers/Invoicing.php` (úprava: validácia a odoslanie `payment_method`/`payment_note`) | controller | request-response + htmx | sám súbor | — |
| `app/Config/Routes/settings.php` (+ `settings/legal-texts*`) | route | — | `Routes/entities.php` r. 7-26 | exact |
| `app/Config/Routes/invoices.php` (+ `invoices/(:num)/report`) | route | — | sám súbor | — |

### Views, jazyk, CSS

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Match |
|---------------------|------|-----------|------------------|-------|
| `app/Views/pdf/invoice_inv2.php` | view (mPDF) | transform | `app/Views/pdf/invoice_inv1.php` | exact |
| `app/Views/pdf/report_rep1.php` | view (mPDF) | transform | `app/Views/pdf/invoice_inv1.php` (items tabuľka) | role-match |
| `resources/pdf/invoice-inv2.css`, `resources/pdf/report-rep1.css` | css | — | `resources/pdf/invoice-inv1.css` | exact |
| `app/Views/settings/legal_texts/index.php`, `edit.php`, `new.php` (+ `_row`/fallback fragment) | view | read / form | `Views/settings/entities/series_form.php`, `entities/index.php`, `clients/index.php` | exact |
| `app/Views/more/index.php` (+ položka) | view | — | sám súbor | — |
| `app/Views/invoices/show.php` (+ tlačidlo Výkaz PDF, informácia pre schema 1) | view | read | sám súbor r. 54-62 | — |
| `app/Views/invoicing/_preview.php`, `_confirm_sheet.php` (forma úhrady, poznámka, upozornenie provisional) | view | htmx fragment | `_confirm_sheet.php` | — |
| `app/Language/{sk,en,pl,cs}/ReportPdf.php` | lang | — | `app/Language/sk/InvoicePdf.php` | exact |
| `app/Language/{sk,en,pl,cs}/InvoicePdf.php` (nové kľúče, dopracovať pl/cs) | lang | — | sám súbor | — |
| `app/Language/{sk,en}/LegalTexts.php`; doplnky `Invoicing.php`, `Invoices.php` | lang | — | `app/Language/sk/Invoices.php` | exact |
| `public/assets/css/app.css` (`.lt-*`, `.inv-*` doplnky) | css | — | existujúce `.master-*`, `.inv-*` | role-match |

### Testy

| Nový/upravený súbor | Role | Najbližší analóg | Match |
|---------------------|------|------------------|-------|
| `tests/integration/Invoicing/MpdfInvoiceInv2RendererTest.php`, `MpdfReportRendererTest.php` | test | `MpdfInvoiceRendererTest.php` | exact |
| `tests/integration/Invoicing/ReportDocumentTest.php`, `ReportTotalsMatchTest.php`, `LegacyInv1UnchangedTest.php`, `LegalTextVersioningTest.php`, `PaymentOptionsTest.php`, `IssueTimingTest.php`, `PdfEscapingTest.php`, `PdfDiacriticsTest.php` | test | `InvoiceDocumentTest.php`, `InvoiceImmutableAfterChangeTest.php`, `IssueRollbackTest.php` | role-match |
| `tests/integration/Master/LegalTextServiceTest.php` | test | `tests/integration/Master/SeriesServiceTest.php` | exact |
| `tests/unit/Invoicing/Qr/PayBySquareTest.php`, `SpaydTest.php`, `PolishZbpQrTest.php`, `QrPaymentBuilderTest.php`, `LegalProfilePrintTest.php`, `ReportRowFormatTest.php` | test | `tests/unit/Invoicing/PdfFormatTest.php` | role-match |
| `tests/feature/Settings/LegalTextsControllerTest.php` | test | `tests/feature/Work/AllocationPanelTest.php` + `tests/feature/Master/*` | exact |
| `tests/integration/Invoicing/DocumentsSamplesCommandTest.php` | test | `tests/integration/MpdfSpikeTest.php` (proc/exec) | partial |
| `tests/_support/PayBySquareDecoder.php`, `PdfText.php` | test support | `MpdfInvoiceRendererTest::text()` (pdftotext) | partial |
| `tests/_support/SnapshotSample.php` (schema 2 vzorky), `FakePdfRenderer.php`, `FaultInjectingRenderer.php`, `IssueFixtures.php` (úprava) | test support | sám súbor | — |
| Úpravy `InvoiceIssuerTest`, `SnapshotBuilderTest`, `IssueRollbackTest`, `PreviewTokenScopeTest`, `InvoiceImmutabilityTest`, `DbRolesTest` | test | sám súbor | — |

### Ops

| Súbor | Role | Najbližší analóg |
|-------|------|------------------|
| `docker/php/Dockerfile` (`apk add --no-cache tzdata xz`) | config | existujúci riadok `install-php-extensions ... && apk add --no-cache tzdata` (výskum, Installation) |
| `docs/ops/staging.md` (vzorové PDF, UAT QR) | doc | sám súbor |

---

## Pattern Assignments

### Migrácia `AddLegalTextsRevision` (migration, GRANT)

**Analóg:** `app/Database/Migrations/2026-10-25-000005_CreateLegalProfiles.php` (REVOKE INSERT r. 49), trait `app/Database/Support/GrantsPrivileges.php`

Pravidlá z traitu (r. 56-79 súboru): `grantUpdateColumns($table, $columns)` dáva **len stĺpcový UPDATE**, nikdy `DELETE`. Hlavička migrácie rovnako ako v `CreateBillingAllocations`: `declare(strict_types=1); namespace App\Database\Migrations; use App\Database\Support\GrantsPrivileges; use CodeIgniter\Database\Migration; final class X extends Migration { use GrantsPrivileges; ...}`.

```php
// vzor z výskumu (Code Examples), overené proti traitu
$this->db->query('ALTER TABLE legal_texts ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1)');
$this->db->query('GRANT INSERT ON legal_texts TO app_rw');
$this->grantUpdateColumns('legal_texts', ['body', 'provisional', 'revision', 'updated_at']);
```
- NIKDY `grantMutable('legal_texts')` (dalo by DELETE), `legal_profiles` ostáva bez grantu (D-16).
- `down()`: `REVOKE INSERT ON legal_texts FROM app_rw`, `REVOKE UPDATE ON legal_texts FROM app_rw`, `ALTER TABLE ... DROP COLUMN revision`.
- Pridať `COMMENT ON TABLE legal_texts` s aktualizovaným znením (pôvodné: "read-only for app_rw (D-17)" už neplatí), štýl z `GuardInvoiceCounterInsert` r. 38 (`COMMENT ON TABLE ... IS '...'`).
- `legal_texts.body` CHECK je 1..1000, `invoices.payment_note` CHECK <= 500: validácia `invoice_note <= 500` patrí do `LegalTextService`, nie do migrácie.

---

### Migrácia `RequireReportDocument` (migration, DB guard)

**Analóg:** `app/Database/Migrations/2026-10-27-000005_GuardInvoiceCounterInsert.php`

**Pattern CREATE OR REPLACE + nowdoc `$$` + down() vráti pôvodné telo** (r. 16-60):
```php
$this->db->query(<<<'SQL'
    CREATE OR REPLACE FUNCTION counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$
    ...
    $$
    SQL);
...
public function down(): void { /* CREATE OR REPLACE FUNCTION s pôvodným telom */ }
```
Skopírovať: **nowdoc `<<<'SQL'`** (aby PHP neinterpoloval `$$`), `down()` obnoví telo z `2026-10-27-000002_CreateInvoices.php` (prečítať jeho `invoice_complete_check` a vložiť doslova). Nový obsah funkcie je v 06-RESEARCH.md "Rozšírenie triggera" (podmienka `v_inv.snapshot_schema >= 2 AND NOT EXISTS (... kind='report')`). Samotný `CREATE TRIGGER` sa nemení (trigger volá funkciu podľa mena). Vlastníctvo funkcie ostáva `app_owner` (migrácia beží pod ním).

---

### `MpdfFactory.php`, `MpdfInvoiceInv2Renderer.php`, `MpdfReportRenderer.php` (service, transform)

**Analóg:** `app/Libraries/Invoicing/MpdfInvoiceRenderer.php` (celý súbor, 61 riadkov)

**Imports** (r. 5-11):
```php
use Composer\InstalledVersions;
use InvalidArgumentException;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
```

**Konfigurácia mPDF, TempDir, DejaVu, bez substitúcií** (r. 28-44), kopírovať do `MpdfFactory::create()`:
```php
$tempDir = WRITEPATH . 'mpdf';
if (! is_dir($tempDir)) { mkdir($tempDir, 0775, true); }
$mpdf = new Mpdf([
    'mode'             => 'utf-8',
    'format'           => 'A4',
    'tempDir'          => $tempDir,
    'fontDir'          => [ROOTPATH . 'resources/fonts'],
    'fontdata'         => ['dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf']],
    'default_font'     => 'dejavusans',
    'useSubstitutions' => false,
    'autoScriptToLang' => false,
    'autoLangToFont'   => false,
]);
```
UI-SPEC A: okraje 15 mm vľavo/vpravo/hore, 18 mm dole (parametre `margin_left`, `margin_right`, `margin_top`, `margin_bottom`), päta strany (`SetHTMLFooter` s `{PAGENO}/{nbpg}`; popisok „Strana“ zo `$snapshot['labels']`).

**Render + metadáta + hash** (r. 46-60), v každom rendereri, s vlastnou verziou:
```php
$mpdf->SetTitle($snapshot['labels']['invoice'] . ' ' . $number);
$mpdf->SetAuthor((string) $snapshot['entity']['trade_name']);
$mpdf->SetCreator('Task Tracker ' . self::TEMPLATE_VERSION);
$mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/invoice-inv1.css'), HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML(view('pdf/invoice_inv1', ['s' => $snapshot]), HTMLParserMode::HTML_BODY);
$bytes = $mpdf->Output('', Destination::STRING_RETURN);
return RenderedPdf::fromBytes($bytes, self::TEMPLATE_VERSION, 'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'));
```
Rozdiely oproti inv-1: `TEMPLATE_VERSION = 'inv-2'` / `'rep-1'`, nový view a CSS, `unset($mpdf)` po `Output` (Pitfall 11, dva objekty v jednej požiadavke), `InvalidArgumentException` pri chýbajúcom čísle ostáva rovnako. Každé renderer-ovanie berie len `$snapshot` (D-04). Rozhranie `PdfRenderer::render(array $snapshot): RenderedPdf` (`PdfRenderer.php` r. 7-13) sa nemení (iba docblock "schema 1" nie je nutné meniť).

---

### `RendererRegistry.php` (utility, lookup)

**Analóg:** žiadny; štruktúra podľa výskumu Pattern 5: `public function for(string $templateVersion): PdfRenderer { return match ($templateVersion) { 'inv-1' => new MpdfInvoiceRenderer(), 'inv-2' => new MpdfInvoiceInv2Renderer(), 'rep-1' => new MpdfReportRenderer(), default => throw new InvalidArgumentException(...) }; }`.
Konštruktor prijme voliteľné prepísania pre testy (analógia: `InvoiceIssuer::__construct(?PdfRenderer $renderer = null, ...)` r. 38-53, všetky závislosti `?T $x = null` s `??`).

---

### `InvoiceIssuer.php` (úprava)

**Analóg:** sám súbor.

**Konštruktor** (r. 29, 41, 48): `?PdfRenderer $renderer` -> `?RendererRegistry $renderers`; `$this->renderer = $renderer ?? new MpdfInvoiceRenderer();` -> `$this->renderers = $renderers ?? new RendererRegistry();`. Rozbije volania `new InvoiceIssuer(` v `tests/` (grep pred zmenou), vrátane `IssueFixtures`, `issue_worker.php`.

**Kroky 8-11** (r. 191-206), nové poradie D-20:
```php
// 8. cislo
$allocated = $this->numbers->next($series, $cmd->issueDate);
$snapshot  = $this->builder->withNumber($snapshot, $allocated->number);   // + payment.qr (vyhodí výnimku => rollback, NECHYTAŤ)
// 9. PDF vnutri transakcie
$pdf    = $this->renderers->for((string) $snapshot['template_version'])->render($snapshot);
$report = $this->renderers->for((string) $snapshot['report_template_version'])->render($snapshot);
...
$this->documents->store($invoiceId, 'invoice', $pdf);
$this->documents->store($invoiceId, 'report', $report);
```
**`insertInvoice`** (r. 247-290): literály `'transfer', NULL` v SQL (r. 257) a `1` pre `snapshot_schema` -> parametre `:pm:`, `:pn:` (`$snapshot['payment']['method']`, `['note']`) a `2`; `'tpl' => $snapshot['template_version']` (r. 285) už je správne. JSON kódovanie `json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)` (r. 284) neprepisovať.
**Audit** (r. 216-225): pridať `'report_sha256' => $report->sha256`.
**Vnútri transakcie nechytať `DatabaseException`** (r. 62-74 už konvertuje vonku, nemeniť).
**Vzor chyby:** `throw new IssueRejected('vat_code_missing')` z `SnapshotBuilder` r. 69; pre nový payment vstup použiť `IssueRejected('invalid_payment')` alebo validáciu už v `IssueInput`, nie v issueri.

---

### `SnapshotBuilder.php` (úprava: schema 2)

**Analóg:** sám súbor. Jediné miesto stavby kópie (D-18 z Phase 5).

**Rozšírenie signatúry** (r. 50): `build(..., ?string $number)` + `string $paymentMethod = 'transfer', ?string $paymentNote = null` (volajúci len `InvoiceIssuer` r. 164 a `InvoiceDraftService` r. 131; obaja sa aktualizujú).

**Labely** (r. 23, 89-93): `LABEL_KEYS` je `const`, rozšíriť; vzor kopírovania do snapshotu:
```php
$labels = ['language' => $docLanguage];
foreach (self::LABEL_KEYS as $key) {
    $labels[$key] = lang('InvoicePdf.' . $key, [], $docLanguage);
}
```
Pre výkaz pridať druhú konštantu `REPORT_LABEL_KEYS` a slučku s `lang('ReportPdf.' . $key, [], $docLanguage)` do kľúča `labels` (kľúče nesmú kolidovať s `InvoicePdf.*`; prefix alebo samostatné pole `report_labels`, rozhodnutie plánovača, ale jedno miesto). Názvy foriem úhrady (`paymentCash`, `paymentCard`, `paymentOther`) ako ďalšie `InvoicePdf` kľúče; vyberie sa jeden podľa `payment.method`.

**Texty** (r. 72, 142-148): `text()` hádže `LegalTextMissing` pre `vat_note`, `invoice_note` je voliteľný (`optionalText`, nová metóda, žiadna výnimka). Tvar `legal.texts.<key>` rozšíriť o `revision`: `['body' => ..., 'language' => ..., 'provisional' => ..., 'revision' => N, 'key' => 'vat_note']`. `detail` view `invoices/show.php` r. 28 číta `legal.texts.vat_note.body`, ostáva kompatibilné.

**Nové kľúče vrchnej úrovne** (r. 95-161): `'schema' => 2`, `'template_version' => 'inv-2'`, `'report_template_version' => 'rep-1'`, `legal.print` z `LegalProfile::print(...)`, `'payment' => ['method' => ..., 'note' => ..., 'qr' => null]`, `'fx' => null` ostáva (miesto pre Phase 8).

**`withNumber`** (r. 169-175): pridať `payment.qr` iba tu:
```php
$snapshot['number']          = $number;
$snapshot['variable_symbol'] = self::variableSymbol($number);
$snapshot['payment']['qr']   = (new QrPaymentBuilder())->build($snapshot);   // null alebo ['standard'=>..., 'payload'=>...]
```
Pozor na WR-05 `variableSymbol()` r. 181-190 (zdieľané, nemeniť).

**`InvoiceCalculator::previewToken`** (r. 86-91): `unset($snapshot['number'], $snapshot['variable_symbol']);` -> doplniť `unset($snapshot['payment']['qr']);` (poistka, výskum Pitfall 1). Rovnaký snapshot v náhľade aj v issueri musí mať rovnaké `payment.method/note`, preto sa obe hodnoty musia posielať v náhľade (inak `preview_stale`): `payment_method` a `payment_note` dať aj do `PreviewInput`-hashu alebo do tokenu cez snapshot (token ide zo snapshotu, takže stačí, že POST vystavenia posiela tie isté hodnoty, ktoré náhľad použil; test v `PreviewTokenScopeTest`).

---

### `LegalProfile.php` (utility, čistá funkcia)

**Analóg:** `app/Libraries/Master/BillingMode.php`, `VatRegime.php`, `Country.php` (čisté triedy s konštantami a statickými metódami; prečítať ich pred písaním pre štýl).
Funkcia `print(string $country, string $vatRegime, ?string $billingMode): array` vráti tvar z výskumu (`supplier`, `customer`, `meta`, `payment_reference`, `line_unit`, `vat_note`). Vstup sú len reťazce, žiadna DB (D-07). Výsledok ide do `legal.print` v snapshote. Šablóna iteruje zoznam a tlačí pole len ak je neprázdne (RESEARCH "Zdroj pravdy pre tlač"), preto v `invoice_inv2.php` sa nahradí pevný zoznam z `invoice_inv1.php` r. 31 a 42 zoznamom z `$s['legal']['print']`.

---

### `Qr/QrPaymentBuilder.php`, `PayBySquare.php`, `Lzma.php`, `Spayd.php`, `PolishZbpQr.php`, `QrSvg.php` (utility, transform)

**Analóg:** bez priameho analógu (nový typ kódu). Štýlovo: čisté triedy bez DB, `final`, `declare(strict_types=1)`, desatinné reťazce cez bcmath (`app/Libraries/Platform/Money.php`), kontroly regexom `/D`.

Použiť presne z `06-RESEARCH.md`:
- Výber štandardu: "Mapovanie štandardu QR" (Code Examples), vráti `?array{standard:string, payload:string}`; **výnimku neprechytávať** (D-19), `null` pre `method !== 'transfer'` a pre kombináciu bez štandardu.
- `PayBySquare` + `XzBinaryLzma`: Pattern 1 (prototyp). `proc_open` s **poľom argumentov** (bez shellu), payload cez stdin.
- `Spayd`: Pattern 2. `PolishZbpQr`: Pattern 3 (vrátane podmienky `PL` NRB IBAN, sumy bcmath v grošoch, nie float).
- `QrSvg::dataUri(string $payload, string $ecc)`: Pattern 4 (bacon `Writer`/`ImageRenderer`/`SvgImageBackEnd`, ECC M, ZBP ECC L, `data:image/svg+xml;base64`). Existujúce použitie `bacon/bacon-qr-code` je pri aktivácii TOTP: nájsť `grep -rn "SvgImageBackEnd" app/` a zosúladiť importy.
- Base32hex: skontrolovať `vendor/paragonie/constant_time_encoding/src/Base32Hex.php` (assumption A7), inak vlastná ~12-riadková funkcia.

Overené `Transliterator::create('Any-Latin; Latin-ASCII')` pre diakritiku (A6, test).

---

### `Master/LegalProfileResolver.php` (úprava)

**Analóg:** sám súbor.
- `text()` r. 69-92: do `SELECT body, language, provisional` pridať `revision` (a tvar vrátenej hodnoty `array{body, language, provisional, revision}`). Poradie `(jazyk, mód) -> (en, mód) -> LegalTextMissing` ostáva (komentár WR-11 r. 62-66 nemeniť).
- `optionalText(...)`: rovnaké telo ako `text()`, ale na konci `return null;` namiesto `throw new LegalTextMissing(...)` (invoice_note, D-12).
- `DbBool::of($row['provisional'])` pre bool z PG (r. 86).

---

### `Master/LegalTextService.php` (service, CRUD + audit)

**Analóg:** `app/Libraries/Master/SeriesService.php::update` (r. 85-143)

**Transakcia + zámok + before/after audit** (r. 99-141):
```php
$now = service('clock')->now()->format('Y-m-d H:i:sP');
Transaction::run($this->db, function () use (...): void {
    $rows = $this->db->query('SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE', [...])->getResultArray();
    ...
    $old = [...]; $new = [...];
    if ($old === $new) { return; }
    $this->db->query('UPDATE invoice_series SET format = :f:, ..., updated_at = :now: WHERE id = :id: AND entity_id = :e:', [...]);
    $this->audit->recordChange('invoice_series', $seriesId, 'update', $old, $new);
});
```
Pre `LegalTextService::update($id, $body, $provisional)`: `SELECT * FROM legal_texts WHERE id = :id: FOR UPDATE`, ak sa `body` a `provisional` nezmenili, return bez zápisu a bez zvýšenia `revision`; inak `UPDATE legal_texts SET body = :b:, provisional = CAST(:p: AS boolean), revision = revision + 1, updated_at = :now: WHERE id = :id:` (len stĺpce zo `GRANT UPDATE (body, provisional, revision, updated_at)`, **nikdy** `country`, `key`, `language`, `vat_regime`, `billing_mode`) a `$this->audit->recordChange('legal_text', $id, 'update', $old, $new)` (entity názov `^[a-z_]{3,40}$`). Bool do SQL cez `DbBool::toDb(...)` ako v `InvoiceIssuer::insertInvoice` r. 272.
Konštruktor: kópia z `AllocationService` (05-PATTERNS): `?BaseConnection $db`, `?AuditLogger $audit`, `$this->audit = $audit ?? new AuditLogger($this->db)`.
Validácia: `body` 1..1000 (CHECK), pre `key = 'invoice_note'` <= 500, nie prázdny po `trim`; `create` (variant): whitelist `language` (`DocLanguage`), `billing_mode` (`BillingMode`), kombinácia musí byť v `legal_profiles`, `UNIQUE NULLS NOT DISTINCT` kolíziu previesť na `LegalTextRejected('exists')` zachytením vonku (nie vo vnútri transakcie, pravidlo z 05-PATTERNS).
Výnimka: `Master/LegalTextRejected.php` ako `Master/SeriesRejected.php` (`final`, `RuntimeException`, `public readonly string $reason`).

---

### `app/Controllers/Settings/LegalTexts.php` (controller, request-response)

**Analóg:** `app/Controllers/Settings/Series.php`

**Imports + kostra** (r. 5-17): `namespace App\Controllers\Settings; use App\Controllers\BaseController; use CodeIgniter\Exceptions\PageNotFoundException; use CodeIgniter\HTTP\RedirectResponse; use CodeIgniter\HTTP\ResponseInterface; final class LegalTexts extends BaseController`.

**Úprava: GET formulár cez `view()`, POST s chybami -> 422 + vlastný view, úspech -> flash + redirect** (r. 19-26, 40-66):
```php
$errors = [];
try { (new SeriesService())->update(...); }
catch (InvalidSeriesFormat $e) { $errors['format'] = lang(...); }
catch (SeriesRejected $e) { $errors += match ($e->reason) { ... default => [...] }; }
if ($errors !== []) {
    return $this->response->setStatusCode(422)->setBody(view('settings/entities/series_form', $this->formData(...)));
}
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Entities.seriesSaved')]);
return redirect()->to('/settings/entities/' . $entityId);
```
**Vstupy** (r. 116-128): `postString()`/`queryString()` (`is_string($raw) ? $raw : ''`), skopírovať.
**404**: `entityOr404()` r. 133-142 -> `textOr404(int $id)` s `PageNotFoundException::forPageNotFound(lang('LegalTexts.notFound'))`.
**Fragment s fallbackom (UI-SPEC B)**: `preview()` r. 28-38 (`->setHeader('Vary', 'HX-Request')->setBody(view(...))`) vzor pre htmx náhľad reálne použitého textu pri fallbacku. Zoznam: `htmx_view($fragment, $data, $page)` (05-PATTERNS, `Tasks::index`).
Routy: `settings/legal-texts` (GET), `settings/legal-texts/new` (GET, **pred** `(:num)`), `settings/legal-texts` (POST), `settings/legal-texts/(:num)/edit` (GET), `settings/legal-texts/(:num)` (POST), podľa `Routes/entities.php` r. 7-17 (statické pred `(:num)`). Súbory v `Routes/*.php` sa načítavajú globom, netreba registrovať.

---

### `app/Controllers/Invoices.php::report()` (controller, file download)

**Analóg:** sám súbor `pdf()` r. 53-87

```php
$row = db_connect()->query('SELECT origin, number FROM invoices WHERE id = :id:', ['id' => $id])->getRowArray();
if ($row === null || $row['origin'] !== 'issued') { throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound')); }
try { $doc = (new DocumentStore())->read($id); }
catch (DocumentIntegrityError $e) { log_message('critical', ...); return $this->response->setStatusCode(500)->setBody(lang('Invoices.errorPdf')); }
if ($doc === null) { log_message('critical', ...); return ...500...; }
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['number']);
return $this->response->setStatusCode(200)
    ->setHeader('Content-Type', 'application/pdf')
    ->setHeader('Content-Disposition', $disposition . '; filename="faktura-' . $name . '.pdf"')
    ->setHeader('Cache-Control', 'private, no-store')
    ->setHeader('ETag', '"' . $doc->sha256 . '"')
    ->setHeader('X-Content-Type-Options', 'nosniff')
    ->setHeader('Content-Length', (string) strlen($doc->bytes))
    ->setBody($doc->bytes);
```
Odporúčanie: zdieľaná private metóda `serve(int $id, string $kind, string $filePrefix)` volaná z `pdf()` a `report()` (zmena správania `pdf()` nulová). `report()` pre faktúru schema 1 (bez dokumentu `report`): `DocumentStore::read($id, 'report') === null` a `snapshot_schema < 2` -> **404** `PageNotFoundException` (nie 500 + critical log, rozdiel oproti chýbajúcemu `invoice` dokumentu; D-21). Názov súboru podľa jazyka dokladu (`faktura-`/`faktura-`/`invoice-`, `vykaz-`/`raport-`/`report-`) cez `SELECT doc_language` (stĺpec `invoices.doc_language` už existuje, r. 274 issuer) alebo lang kľúč; `$name` sanitizované ako dnes.
Route: `app/Config/Routes/invoices.php`: `$routes->get('invoices/(:num)/report', 'Invoices::report/$1');`.

---

### `app/Views/pdf/invoice_inv2.php` (view mPDF, transform)

**Analóg:** `app/Views/pdf/invoice_inv1.php` (71 riadkov)

**Hlavička a premenné** (r. 12-21), skopírovať: `$l = $s['labels']; $lg = (string) $s['doc_language']; $cur = (string) $s['currency']; $e = $s['entity']; $c = $s['client']; $b = $s['bank'];` a pomocník `$ids` (r. 18-20) na filtrovanie prázdnych dvojíc.
**Pravidlá:** každá hodnota cez `esc()`, formát len `PdfFormat::date/hours/amount` (r. 49, 62-64), žiadne `lang()`, `service()`, `<style>`, `style=""`. Bloky parties/meta/items/total/note zo štruktúry inv-1, zoznam polí ale z `$s['legal']['print']` a poznámka/forma úhrady z `$s['payment']`. Poznámku `payment.note` vykresliť `nl2br(esc(...))` (esc najprv, potom nl2br). VS vs. „Tytuł płatności“ podľa `legal.print.payment_reference`. QR: `<img src="data:image/svg+xml;base64,..." width="35mm" height="35mm">` cez `QrSvg::dataUri($s['payment']['qr']['payload'], ...)` volané **z renderera** a predané do view ako premenná (view nesmie volať knižnice), len keď `payment.qr !== null`. Prázdny rezervovaný blok pre `fx` (Phase 8): `<?php if ($s['fx'] !== null) : ?>...<?php endif ?>`.
Pozor na `$s['variable_symbol']`: inv-1 r. 50 používa `array_key_exists('variable_symbol', $s)`; inv-2 číta priamo (schema 2 ho vždy má).

### `app/Views/pdf/report_rep1.php`

Analóg: tabuľka položiek z `invoice_inv1.php` r. 53-69 (`<table class="items">`, `thead`, `foreach ($s['lines'] as $line)`). Odkaz na portál `<a href="<?= esc($line['portal_url'], 'attr') ?>">` iba ak `portal_url` je `http(s)://` (rovnaká kontrola ako `invoices/show.php` r. 12, `preg_match('#^https?://#i', ...)`), text odkazu `task_ref` alebo skrátená URL. „16 z 40 h“: `str_replace(['{0}', '{1}'], [PdfFormat::hours($line['hours'], $lg), PdfFormat::hours($line['estimated_hours'], $lg)], $l['ofHours'])`; ak `estimated_hours === null`, len `PdfFormat::hours`. Súčty zo `$s['totals']` (nie prepočet). Test zhody: `ReportTotalsMatchTest` porovná `pdftotext` oboch PDF.

### CSS `invoice-inv2.css`, `report-rep1.css`

Analóg `resources/pdf/invoice-inv1.css` (12 riadkov): `body { font-family: dejavusans; font-size: 9pt; }`, `table.items { width: 100%; border-collapse: collapse; }`, `table.items th, table.items td { border-bottom: 0.2mm solid #999999; padding: 1mm; }`, `.r { text-align: right; }`. Rozloženie cez tabuľky (nie flex/grid), stupnica z UI-SPEC A, tlač jednou farbou. `page-break-inside: avoid` pre riadky, `thead` opakovaná (UI-SPEC A).

---

### Jazykové súbory `ReportPdf.php`, `InvoicePdf.php`

**Analóg:** `app/Language/sk/InvoicePdf.php` (r. 1-35): `declare(strict_types=1); return ['key' => 'text', ...];` camelCase, docblock vysvetľuje kopírovanie do snapshotu. Nové `InvoicePdf` kľúče (forma úhrady, `paymentReference`, `paymentTitle`, `page`, `deliveryDate` ostáva, `unit`, `note`) vo všetkých štyroch jazykoch (`sk`, `en`, `pl`, `cs`); odstrániť poznámku "Preklady pl a cs su predbezne ... Phase 6" po dopracovaní. `ReportPdf`: `report`, `period`, `invoiceRef`, `task`, `estimated`, `invoiced`, `ofHours` (`{0} z {1} h`, pl `{0} z {1} godz.`, cs `{0} z {1} h`, en `{0} of {1} h`), `rateHeader`, `total`, `page`. `LangKeysTest` stráži zhodu kľúčov medzi jazykmi: **`pl` a `cs` adresáre pre `Language` zatiaľ existujú len pre `InvoicePdf`**, ostatné UI súbory majú len `sk`+`en`; overiť pred tvorbou, ktoré testy kľúčov kontrolujú `pl`/`cs` (`tests/unit/I18n/`).

`SnapshotSample::issued()` (r. 14-23 `$keys` zoznam) obsahuje tvrdo kódovaný zoznam kľúčov: v schema 2 vzorke použiť `SnapshotBuilder::LABEL_KEYS` namiesto kópie.

---

### Views Zákonné texty (`settings/legal_texts/*`)

**Analóg:** `app/Views/settings/entities/series_form.php` (formulár), `entities/index.php`/`clients/index.php` (zoznam)

**Kostra stránky** (series_form r. 20-52):
```php
<?= $this->extend('layouts/app') ?>
<?= $this->section('subtitle') ?><?= esc($title) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="mb-2"><a href="/settings/entities/<?= $eid ?>"><?= esc(lang('Entities.backToDetail')) ?></a></p>
<section class="app-card" aria-labelledby="series-form-title">
    <h1 class="h4 mb-3" id="series-form-title"><?= esc($title) ?></h1>
    <?php if ($errors !== []) : ?><div class="alert alert-danger" role="alert"><?= esc(lang('Entities.errorForm')) ?></div><?php endif ?>
    <form method="post" action="<?= $base ?>" data-submit-once novalidate>
        <?= csrf_field() ?>
```
**Pole s chybou** (r. 55-59): `<input class="form-control<?= isset($errors['format']) ? ' is-invalid' : '' ?>" ...><?php if (isset($errors['format'])) : ?><div class="invalid-feedback"><?= esc($errors['format']) ?></div><?php endif ?>`; hodnoty `esc($v, 'attr')`.
**Pätka akcií** (r. 87-90): `<div class="master-sticky-actions master-actions"><a class="btn btn-outline-secondary btn-touch" ...><button type="submit" class="btn btn-primary btn-touch">`.
**Live náhľad htmx** (r. 58-63): `hx-get=... hx-trigger="keyup changed delay:300ms, change" hx-target="#series-preview" hx-swap="outerHTML" hx-include="closest form"` vzor pre fragment „reálne použitý text pri fallbacku“.
**Menu položka:** `app/Views/more/index.php` r. 6-8: `<a class="list-group-item list-group-item-action more-item" href="/settings/clients"><?= esc(lang('Clients.navTitle')) ?></a>` -> pridať riadok pre `/settings/legal-texts` s `lang('LegalTexts.navTitle')`.
Pravidlá `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest`: bez inline `style`/`onclick`/`<script>`, všetky texty `esc(lang(...))`, kľúče v `sk` aj `en`.

### Úprava `invoices/show.php`, `_confirm_sheet.php`, `_preview.php`

- `show.php` r. 54-62 (blok `master-actions inv-pdf-actions`): za existujúce dva `<a>` pridať tretie `<a class="btn btn-outline-secondary btn-touch" href="/invoices/<?= $id ?>/report" target="_blank" rel="noopener noreferrer">`, podmienka `$snapshotSchema >= 2` (z `$snap['schema']`), inak `<p class="small text-body-secondary">` s informáciou (`Invoices.noReport`). `target="_blank"` kvôli iOS standalone (D-21, Pasca 11).
- `_confirm_sheet.php` r. 24-36: pridať riadky `<dt>/<dd>` pre „Forma úhrady“ a „Poznámka“ v `dl.master-kv`; submit sa robí cez `hx-include="#inv-issue-form"`, preto `payment_method` a `payment_note` musia byť vstupy vo formulári `#inv-issue-form` (v `_preview.php`). Alpine CSP build: `x-bind:disabled` v r. 46 ukazuje vzor povolených výrazov (žiadne inline výrazy s funkciami, len názvy).
- Controller `Invoicing.php` (issue): doplniť `IssueInput::fromPost/validate/normalize` o `payment_method` whitelist (`transfer|cash|card|other`) a `payment_note` <= 500 (`mb_strlen`), chyby 422 s `is-invalid`, vzor `IssueInput` (Phase 5, 05-PATTERNS `AllocationInput`).

---

### `app/Commands/DocumentsSamples.php` (command, batch bez DB)

**Analóg:** `app/Commands/InvoicesLockHistorical.php`

**Hlavička a voľby** (r. 11-40): `final class X extends BaseCommand { protected $group = 'Invoices'; protected $name = 'invoices:lock-historical'; protected $description = '...'; protected $usage = '...'; protected $options = ['--flag' => '...']; }` -> `protected $group = 'Documents'; protected $name = 'documents:samples';`.
**Čítanie volieb a validácia** (r. 42-60): `array_key_exists('commit', $params) || CLI::getOption('commit') !== null`, regexy s `/D`, chyba `CLI::error(...)` + `return EXIT_ERROR`, úspech `CLI::write(...)`/`EXIT_SUCCESS`.
Rozdiel: nepoužíva DB (žiadny `Database::connect()`, žiadny `DocumentStore`), snapshoty zo `tests/_support/SnapshotSample.php` **nie sú v produkčnom obraze**; preto vzorové snapshoty patria do `app/Libraries/Invoicing/Samples/SampleSnapshots.php` (nový súbor v `app/`), z ktorého ťahajú príkaz aj `SnapshotSample` v testoch. Výstup do `WRITEPATH . 'samples/'`, každý PDF označený „VZOR“ v `labels` aj v texte (watermark `SetWatermarkText` alebo riadok v hlavičke, D-23). Príkaz nesmie mať guard na dev-only triedy (rovnaké pravidlo ako v 05-PATTERNS pre `InvoicesLockHistorical`).

---

### Testy: renderery, QR, nemennosť

**Analóg renderer testu:** `tests/integration/Invoicing/MpdfInvoiceRendererTest.php` r. 1-40 (`final class ... extends CIUnitTestCase`, `#[Group('integration')]`, `SnapshotSample::issued()`, kontroly `assertStringStartsWith('%PDF-')`, `assertSame(hash('sha256', $pdf->bytes), $pdf->sha256)`, `assertSame('inv-1', $pdf->templateVersion)`, `assertStringContainsString('mpdf 8.', $pdf->renderer)`, text cez privátnu `text()` s `pdftotext`, `markTestSkipped` ak chýba binárka). Pre inv-2/rep-1 rovnaká kostra s `SnapshotSample::issuedV2()`; testovať text (čísla `1 080,00 €`, `vat_note`, diakritika `Zażółć gęślą jaźń`, `Ľščťžýáíé`, `ěřůďňť`).
**Nemennosť:** `tests/integration/Invoicing/InvoiceImmutableAfterChangeTest.php` a `InvoiceDocumentTest.php` rozšíriť o druhý dokument a zmenu `legal_texts` pod `rw()`. Test sa **neopiera o opakovaný render** (mPDF nie je deterministický, výskum), ale o uložené `sha256`/bajty pred a po zmene.
**Rollback:** `IssueRollbackTest` + upravený `FaultInjectingRenderer` (r. 17-20: `throw new RuntimeException('injected render failure')`); pre render výkazu fault injection podľa `template_version` (`rep-1` zlyhá, `inv-2` prejde); upraviť `FakePdfRenderer` r. 20-24: namiesto `'inv-1'` použiť `(string) ($snapshot['template_version'] ?? 'inv-1')`; na registry cez konštruktor `RendererRegistry` s fake.
**QR testy (unit):** round-trip (base32hex -> odstrániť 4 B -> `xz -dc --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB`, kontrola CRC32 a polí) cez `tests/_support/PayBySquareDecoder.php`; **nehardkódovať presný reťazec** (výskum Pitfall 4). SPAYD a ZBP presné reťazce zo vzoriek výskumu.
**Oprávnenia `legal_texts`:** `tests/integration/DbRolesTest.php` rozšíriť podľa existujúcich assertov oprávnení (`assertPermissionDenied` regex `/permission denied|must be owner/i` v `PgTestCase`): `app_rw` UPDATE `body` OK, UPDATE `key` zlyhá, DELETE zlyhá, INSERT OK.
**Fixtúry:** `resetMasterTables()` netruncuje `legal_*` (nikdy); test, ktorý mení `legal_texts`, musí zmenu vrátiť v `tearDown` (UPDATE ownerom späť `body`, `provisional`, `revision`).

---

## Shared Patterns

(Ostatné cross-cutting vzory: transakcia, zámky, peniaze, čas, audit, htmx odpovede, CSRF, IDOR, insert-only: platia ako v `05-PATTERNS.md` Shared Patterns, neopakujú sa.)

### Šablóna PDF číta iba snapshot
**Zdroj:** `app/Views/pdf/invoice_inv1.php` r. 7-20, `MpdfInvoiceRenderer.php` r. 46-51
**Použiť v:** `invoice_inv2.php`, `report_rep1.php`
`esc()` všetkých hodnôt, formáty cez `PdfFormat` (bez ICU), `lang()`/`service()`/DB zakázané (D-04), CSS len cez `HTMLParserMode::HEADER_CSS` zo súboru, žiadny `<style>`/`style=""`. Pre HTML injection test (výskum Pitfall 9): názov úlohy `<img src=http://x>` sa zobrazí ako text.

### Popisky sa kopírujú do snapshotu
**Zdroj:** `SnapshotBuilder.php` r. 89-93
**Použiť v:** `SnapshotBuilder` (faktúra aj výkaz)
```php
foreach (self::LABEL_KEYS as $key) { $labels[$key] = lang('InvoicePdf.' . $key, [], $docLanguage); }
```

### Nemenné rozšírenie existujúceho
**Zdroj:** `2026-10-27-000005_GuardInvoiceCounterInsert.php`
**Použiť v:** každá nová DB zmena: nová migrácia, `CREATE OR REPLACE`, nikdy úprava starej migrácie; `down()` obnoví predchádzajúce telo; `COMMENT ON TABLE` aktualizovať.

### Registry podľa `template_version` zo snapshotu
**Zdroj:** nový `RendererRegistry` (výskum Pattern 5), `InvoiceIssuer` r. 196 (`$this->renderer->render($snapshot)`)
**Použiť v:** `InvoiceIssuer`, `DocumentsSamples`, testy (fake registry). Nikdy nevolať renderer priamo podľa "aktuálnej" šablóny.

### Validácia vstupu a chýb
**Zdroj:** `Controllers/Settings/Series.php` r. 47-61 (try/catch domény -> `$errors` pole -> 422 + view), `Master/SeriesRejected.php`
**Použiť v:** `Settings\LegalTexts`, `Invoicing::issue` (payment polia).

### Sťahovanie PDF z uložených bajtov
**Zdroj:** `Controllers/Invoices.php` r. 61-86 (`DocumentIntegrityError` -> 500 + `log_message('critical')`, hlavičky `Cache-Control: private, no-store`, `ETag` = sha256, `nosniff`)
**Použiť v:** `Invoices::report`.

---

## No Analog Found

| Súbor | Role | Data Flow | Dôvod |
|-------|------|-----------|-------|
| `Qr/PayBySquare.php`, `Qr/Lzma.php` | utility | transform | Žiadny `proc_open`/LZMA/base32hex v kóde; použiť 06-RESEARCH.md Pattern 1 (prototyp overený round-trip `xz -d`) |
| `Qr/Spayd.php`, `Qr/PolishZbpQr.php` | utility | transform | Žiadny platobný formát v kóde; Pattern 2 a 3 výskumu |
| `Qr/QrSvg.php` | utility | transform | bacon SVG sa dnes používa len pri TOTP (`grep -rn SvgImageBackEnd app/`); Pattern 4 výskumu, data URI do mPDF |
| `RendererRegistry.php` | utility | lookup | Žiadny registry v kóde; `match` z výskumu Pattern 5 |
| `tests/_support/PayBySquareDecoder.php`, `PdfText.php` | test support | — | `pdftotext` helper je dnes len súkromná `text()` v `MpdfInvoiceRendererTest`; vytiahnuť do `PdfText` |
| `app/Libraries/Invoicing/Samples/SampleSnapshots.php` | utility | transform | `SnapshotSample` je v `tests/_support` (nie v prod obraze); zdrojový obsah z neho, ale umiestnenie v `app/` |

## Metadata

**Analog search scope:** `app/Libraries/Invoicing`, `app/Libraries/Master`, `app/Controllers` (+ `Settings`), `app/Views/{pdf,invoices,invoicing,settings,more}`, `app/Config/Routes`, `app/Commands`, `app/Language/sk`, `app/Database/Migrations`, `app/Database/Support`, `resources/pdf`, `tests/_support`, `tests/integration/Invoicing`, `tests/unit/Invoicing`
**Files scanned:** ~120 v zoznamoch, ~22 prečítaných
**Pattern extraction date:** 2026-10-27
**Poznámky plánovačovi:**
- `InvoiceDraftService.php` (r. 131 volanie `build`), `Controllers/Invoicing.php`, `IssueInput.php`, `InvoiceQueries::detail`, `Views/invoicing/_preview.php`, `Views/settings/entities/index.php`, `Master/BillingMode.php` neboli excerptované; pred úpravou ich prečítať celé.
- Čitateľ `invoices/show.php` číta `snapshot['legal']['texts']['vat_note']['body']`; rozšírenie `texts` o `revision`/`key` to nerozbije.
- Pred zmenou konštruktora `InvoiceIssuer` spustiť `grep -rn "new InvoiceIssuer(" app tests` (testy, `issue_worker.php`, `IssueFixtures`).
