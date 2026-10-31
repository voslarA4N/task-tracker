# Phase 9: Dobropisy - Pattern Map

**Mapped:** 2026-10-31
**Files analyzed:** 34 (new + modified)
**Analogs found:** 34 / 34 (cela faza je rozsirenie existujuceho vzoru Phase 5-8, ziadny "No analog" subor)

Cesty su relativne ku koreni `/home/voslar/projects/task-tracker`. Cisla riadkov plati k stavu 2026-10-31.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php` (NEW) | migration | batch/DDL | `2026-10-27-000003_LockAllocationsToInvoices.php` + `2026-10-28-000001_RequireReportDocument.php` + `2026-10-29-000006_ExtendInvoiceStatusViewPayments.php` + `...CreateEmailTemplates.php` | exact (kombinacia 4) |
| `app/Libraries/Invoicing/CreditNoteIssuer.php` (NEW) | service (jediny zapisovatel) | CRUD v 1 transakcii | `app/Libraries/Invoicing/InvoiceIssuer.php` | exact |
| `app/Libraries/Invoicing/CreditNoteCommand.php` (NEW) | model (validovany vstup) | request-response | `app/Libraries/Invoicing/IssueCommand.php` | exact |
| `app/Libraries/Invoicing/CreditNoteRejected.php` (NEW) | model (exception) | n/a | `app/Libraries/Invoicing/IssueRejected.php` | exact |
| `app/Libraries/Invoicing/CorrectionType.php` (NEW) | model (enum) | n/a | `IssueCommand::PAYMENT_METHODS` (konstanta); PHP backed enum nema v repo analog | role-match |
| `app/Libraries/Invoicing/CreditNoteSnapshotBuilder.php` (NEW) | service | transform | `app/Libraries/Invoicing/SnapshotBuilder.php` (`fxBlock`, `LABEL_KEYS`, `withNumber`) | role-match |
| `app/Libraries/Invoicing/MpdfCreditNoteCn1Renderer.php` (NEW) | service (renderer) | transform | `app/Libraries/Invoicing/MpdfInvoiceInv2Renderer.php` | exact |
| `app/Views/pdf/credit_note_cn1.php` + `resources/pdf/credit-note-cn1.css` (NEW) | view (PDF) | transform | `app/Views/pdf/invoice_inv2.php` + `resources/pdf/invoice-inv2.css` | exact |
| `app/Libraries/Invoicing/RendererRegistry.php` (MOD) | service | request-response | sam seba (`match`) | exact |
| `app/Libraries/Invoicing/DocumentFilename.php` (MOD) | utility | transform | sam seba (`PREFIXES`) | exact |
| `app/Libraries/Invoicing/InvoiceQueries.php` (MOD) | service (read) | CRUD read | sam seba | exact |
| `app/Libraries/Receivables/InvoiceStatusQueries.php` (MOD) | service (read) | CRUD read | sam seba | exact |
| `app/Libraries/Receivables/PaymentService.php` (MOD) | service | CRUD | sam seba (`not_payable` guard) | exact |
| `app/Libraries/Delivery/InvoiceMailer.php`, `ManualSendService.php`, `EmailTemplateService.php`, `TemplateContext/TemplateRenderer` (MOD) | service | event-driven (SMTP) | samy seba | exact |
| `app/Controllers/CreditNotes.php` (NEW) | controller | request-response (preview -> confirm -> POST, PRG) | `app/Controllers/Invoicing.php` (`issue`, `rejected`, `issuedRedirect`) + `app/Controllers/Payments.php` (`form` / sheet / page fallback) | exact |
| `app/Controllers/Invoices.php` (MOD) | controller | request-response | sam seba | exact |
| `app/Config/Routes/invoices.php` (MOD) alebo `Routes/credit_notes.php` (NEW) | route | n/a | `app/Config/Routes/invoices.php` | exact |
| `app/Config/Services.php` (MOD: `creditNoteIssuer`) | config | n/a | `Services::invoiceIssuer()` (r. 45-53) | exact |
| `app/Views/credit_notes/*` (NEW: `_preview`, `_confirm_sheet`, `new_page`) | view | request-response | `app/Views/invoicing/_confirm_sheet.php`, `app/Views/invoices/payment_page.php`, `_payment_sheet.php` | role-match |
| `app/Views/invoices/show.php`, `_list.php` (MOD) | view | read | sam seba | exact |
| `app/Language/{sk,pl,cs,en}/CreditNotes.php` (NEW) + doplnky `InvoicePdf/Invoices/Deliveries/EmailTemplates.php` | config (lang) | n/a | `app/Language/sk/Invoicing.php`, `InvoicePdf.php` | exact |
| `tests/_support/CreditNoteFixtures.php` (NEW) | test fixture | n/a | `tests/_support/IssueFixtures.php` | exact |
| `tests/_support/credit_note_worker.php` (NEW) | test worker | batch (2 procesy) | `tests/_support/issue_worker.php` (`--command` vetva) | exact |
| `tests/integration/Invoicing/CreditNote*Test.php` (NEW) | test | integration | `InvoiceIssuerTest.php`, `IssueConcurrencyTest.php` | exact |
| `tests/integration/Work/CreditNoteAllocationReleaseTest.php` (NEW) | test | integration | `tests/integration/Work/AllocationGuardTest.php` | exact |
| `tests/integration/Delivery/InvoiceStatusViewColumnsTest.php`, `EmailTemplatesSeedTest.php`, `tests/unit/Invoicing/RendererRegistryTest.php` (MOD) | test | integration/unit | samy seba | exact |
| `app/Libraries/StagingSeed/Modules/Invoices.php` (MOD, volitelne) | seed | batch | sam seba | exact |

## Pattern Assignments

### `app/Libraries/Invoicing/CreditNoteIssuer.php` (service, 1 transakcia)

**Analog:** `app/Libraries/Invoicing/InvoiceIssuer.php` (381 r.). Samostatna trieda, nezdielat kod; kopirovat kostru (konstruktor, `issue()` wrapper, `isUniqueViolation`, `assertNotIssued`, `insertLine`).

**Konstruktor + DI** (r. 23-60): `final class`, `private readonly BaseConnection $db`, vsetky zavislosti `?Type $x = null` s `?? new ...($this->db)`. Pre dobropis: `AuditLogger`, `RendererRegistry`, `NumberAllocator`, `DocumentStore`, `InvoiceGuards`, `CreditNoteSnapshotBuilder`. **NEvytvarat** `SnapshotBuilder` ani `FxService`.

**Wrapper s transakciou a mapovanim DB chyb** (r. 62-98):
```php
public function issue(IssueCommand $cmd): IssuedInvoice
{
    try {
        return Transaction::run($this->db, fn (): IssuedInvoice => $this->issueInTransaction($cmd));
    } catch (DatabaseException $e) {
        if (str_contains($e->getMessage(), 'is locked')) { throw new IssueRejected('parts_changed'); }
        if (self::isUniqueViolation($e, self::REQUEST_KEY_CONSTRAINT)) { $this->assertNotIssued($cmd->requestKey); }
        throw $e;
    }
}
public static function isUniqueViolation(DatabaseException $e, string $constraint): bool { /* r. 87-98, prechadza getPrevious() a hlada 'duplicate key value violates unique constraint "<name>"' */ }
```
Pre dobropis pridat vetvy: `isUniqueViolation($e, 'invoices_one_credit_note_uq')` -> `CreditNoteRejected('already_credited')`, `invoices_entity_id_number_key` -> `number_collision` (RESEARCH Pitfall 6). Znovupouzit `InvoiceIssuer::isUniqueViolation` (je `public static`) a `InvoiceIssuer::REQUEST_KEY_CONSTRAINT`.

**Idempotencia** (r. 289-296), skopirovat 1:1 (`SELECT id, number FROM invoices WHERE request_key = CAST(:k: AS uuid)` -> `already_issued`).

**ODCHYLKA od analogu (kritické): zamok.** `InvoiceIssuer` pouziva `FOR UPDATE` (r. 116, 120, 132, 195). `app_rw` nema UPDATE na `invoices`, preto `SELECT ... FOR UPDATE` na `invoices` zlyha `permission denied`. Pouzit:
```php
$this->db->query("SELECT pg_advisory_xact_lock(hashtext('credit_note'), :id:)", ['id' => $cmd->originalId]);
$orig = $this->db->query('SELECT * FROM invoices WHERE id = :id:', ['id' => $cmd->originalId])->getRowArray();
// guard: origin='issued' AND type='invoice', NOT EXISTS credit_note s corrected_invoice_id = id
```
Poradie zamkov: advisory(original) -> `tasks ... ORDER BY id FOR UPDATE` (r. 109-117 vzor; ids z `invoice_lines.source_task_id` povodnej, ignorovat NULL) -> `SELECT * FROM invoice_series WHERE entity_id = :e: ORDER BY id FOR UPDATE` (r. 195, vyberat `kind === 'credit_note'`, nie `invoice`) -> `NumberAllocator::next`.

**Rad, backdate, cislo** (r. 195-215): skopirovat. Plus nova kontrola `issue_date >= original.issue_date` (`date_before_original`). **Nevolat** `assertEntityActive` (r. 168) - ENT-04 storno aj pre ukonceny subjekt.
```php
$backdate = $this->guards->backdate((int) $series['id'], $cmd->issueDate);   // InvoiceGuards r. 100-105, filtruje podla series_id
if ($backdate !== null && ! $cmd->confirmBackdate) { throw new ...Rejected('backdate_unconfirmed', ['last_number' => ..., 'last_date' => ...]); }
$allocated = $this->numbers->next($series, $cmd->issueDate);   // NumberAllocator r. 36-63; obdobie z issueDate dobropisu
```
**Nevolat** `$this->builder->withNumber()` (r. 218): vola QR builder. Cislo a VS nastavit v `CreditNoteSnapshotBuilder` (`SnapshotBuilder::variableSymbol($number)` je `public static`, r. 255), `payment.qr = null`.

**PDF vnutri transakcie + kontrola verzie** (r. 225-230) skopirovat; **report cast (r. 232-245, 256) VYNECHAT**. `$this->documents->store($creditNoteId, 'invoice', $pdf)` (DocumentStore r. 33).

**Uvolnenie casti** namiesto r. 258-263:
```php
$released = (int) $this->db->query('SELECT release_allocations(:cn:) AS n', ['cn' => $creditNoteId])->getRow()->n;
// zhoda s COUNT(*) FROM billing_allocations WHERE invoice_id = original pred uvolnenim; inak RuntimeException
```

**Audit** (r. 266-281): `$this->audit->record('invoice', $creditNoteId, 'credit_note_issue', null, [...])` s kluci `number, corrected_invoice_id, corrected_number, reason, correction_type, total, currency, released_parts, sha256, fx_*`.

**Final** (r. 284): `$this->db->query('SET CONSTRAINTS ALL IMMEDIATE');` nutne, aby `invoice_complete_check` padol vnutri transakcie.

**INSERT** (r. 303-355): rovnaky zoznam stlpcov a `CAST(:x: AS boolean)`/`jsonb`/`uuid`. Zmeny: `'issued', 'credit_note'` v VALUES, pridat `corrected_invoice_id, correction_reason, correction_type`, hodnoty stran z **riadka povodnej** (nie zo snapshot-u klienta), `turnover_date` = `orig['turnover_date']`, `due_date` = `issue_date`, `total`/`total_entity_ccy` cez `Money::of(...)->negate()->toDecimalString()` (normalizuje `-0.00`), `counts` = `DbBool::toDb(DbBool::of($orig['counts_toward_limit']))` (vzor r. 337; boolean z PG moze prist ako `'t'`).

**Riadky** (r. 360-380 `insertLine`) skopirovat; zaporne hodiny, `amount` negovane, `source_allocation_id = NULL`, `source_task_id` kopia (RESEARCH Pattern 1, A1). Zdrojom su `SELECT ... FROM invoice_lines WHERE invoice_id = :orig: ORDER BY position`, NIE JSONB.

---

### `app/Libraries/Invoicing/CreditNoteCommand.php` (model, validovany vstup)

**Analog:** `app/Libraries/Invoicing/IssueCommand.php` r. 14-81 (`final readonly class`, validacia v konstruktore, `InvalidArgumentException`).

Skopirovat regexy tokenu a UUID a kontrolu datumu:
```php
if (preg_match('/^[0-9a-f]{64}$/D', $previewToken) !== 1) { throw new InvalidArgumentException('Invalid preview token.'); }
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestKey) !== 1) { ... }
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { ... }
// poznamka (r. 77) - vzor pre dovod: mb_strlen 1..NOTE_MAX(500) a zakaz riadiacich znakov
if ($paymentNote !== null && (mb_strlen($paymentNote) < 1 || mb_strlen($paymentNote) > self::NOTE_MAX || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $paymentNote) === 1)) { ... }
```
Polia: `originalId, issueDate, reason, correctionType (CorrectionType), previewToken, requestKey, confirmBackdate=false, userId=null`. Dovod je povinny (nie nullable). Server neprijima sumy, riadky ani kurz.

### `app/Libraries/Invoicing/CreditNoteRejected.php`

**Analog:** `IssueRejected.php` r. 12-49. Rovnaka struktura (`REASONS` konstanta, `reason/context/invoiceId/invoiceNumber`). Dovody: `not_found, not_creditable, already_credited, already_issued, preview_stale, date_before_original, backdate_unconfirmed, invalid_series_format, sequence_overflow, number_collision, pdf_failed`. Alternativa: rozsirit `IssueRejected::REASONS` - vtedy upravit mapovanie v `Invoicing::errorMessage()` (r. 205-226); odporucanie RESEARCH: samostatna trieda.

### `app/Libraries/Invoicing/CorrectionType.php`

V repo je len konstantne pole (`IssueCommand::PAYMENT_METHODS`, r. 16). Plan: `enum CorrectionType: string` (`data_error, amount_error, fx_error, order_cancelled, other`) s `label()` cez `lang('CreditNotes.type_' . $this->value)`; hodnoty vyhovuju CHECK `^[a-z_]{3,30}$`. Alternativa: `public const TYPES = [...]` presne ako `PAYMENT_METHODS` a `in_array(..., true)`.

---

### `app/Libraries/Invoicing/CreditNoteSnapshotBuilder.php` (transform)

**Analog:** `SnapshotBuilder.php`, ale **NEVOLAT** `SnapshotBuilder::build()` (r. 58-181): cita `versions->atDate`, legal profil a banku zo zivych tabuliek, zlyha pre ukonceny subjekt.

Co skopirovat/znovupouzit:
- `LABEL_KEYS` + `lang('InvoicePdf.' . $key, [], $docLanguage)` (r. 29, 99-103): nova `CreditNoteSnapshotBuilder::LABEL_KEYS` s kluci `cn*` (nepridavat do `SnapshotBuilder::LABEL_KEYS`, zmenilo by token novych faktur - RESEARCH Open Q2).
- Tvar snapshotu (r. 108-180): povodny `invoices.snapshot` dekodovat, zmenit `schema` => 4, `type` => `'credit_note'`, `template_version` => `'cn-1'`, `unset(report_template_version, report)`, `unset($labels['report'])`, `number`, `variable_symbol` (`SnapshotBuilder::variableSymbol`), `payment.qr = null`, `issue_date`, `due_date = issue_date`, `lines` (zaporne), `totals` (`total`, `hours`, `line_count`), `fx.total` + `fx.text.total_value`, novy blok `correction`.
- `fxBlock` (r. 192-233) NEVOLAT (prepocitava cez `FxConverter::convert`). Z povodneho bloku len negovat `total` cez `Money::negate()` a prerendrovat `'total_value' => PdfFormat::amount($negated, $entityCcy, $lang)` (r. 230).
- Hodiny: `Hours::plus` zaporne nepodporuje (regex `^\d{1,5}`). `totals.hours` = `'-' . original totals.hours` (povodny je kladny a nenulovy cez CHECK).
- Preview token: `InvoiceCalculator::previewToken($snapshot)` (genericky, bez `number`, `variable_symbol`, `payment`); riadky `ORDER BY position`.

Blok `correction`: `{original_id, original_number, original_issue_date, reason, type, scope: 'full'}`.

---

### `app/Libraries/Invoicing/MpdfCreditNoteCn1Renderer.php` + `app/Views/pdf/credit_note_cn1.php`

**Analog:** `MpdfInvoiceInv2Renderer.php` r. 17-65 (cele; 65 r.) a `app/Views/pdf/invoice_inv2.php`.

Skopirovat kostru; zmeny: `TEMPLATE_VERSION = 'cn-1'`, bez `$qr` vetvy (r. 31-42), titul `$l['cnTitle'] . ' ' . $number`, CSS `resources/pdf/credit-note-cn1.css`, view `pdf/credit_note_cn1`.
```php
$mpdf = MpdfFactory::create();
$mpdf->SetHTMLFooter('<div class="footer">' . esc((string) $snapshot['entity']['trade_name']) . ' · ' . esc($number) . ' · ' . esc((string) $l['page']) . ' {PAGENO}/{nbpg}</div>');
$mpdf->WriteHTML((string) file_get_contents(ROOTPATH . 'resources/pdf/invoice-inv2.css'), HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML(view('pdf/invoice_inv2', ['s' => $snapshot, 'qr' => $qr]), HTMLParserMode::HTML_BODY);
$bytes = $mpdf->Output('', Destination::STRING_RETURN);
unset($mpdf);
return RenderedPdf::fromBytes($bytes, self::TEMPLATE_VERSION, 'mpdf ' . ltrim((string) InstalledVersions::getPrettyVersion('mpdf/mpdf'), 'v'));
```
PDF view: dostava iba `$s` (snapshot), vsetky texty z `$s['labels']`, ziadne `lang()`, `esc()` na `correction.reason` (XSS). Znovupouzit `$addr`/`$partyRows` closures z `invoice_inv2.php` r. 21-50 (kopia alebo spolocny partial). Formatovanie zapornych cisel: `PdfFormat::hours/amount`.

**Registracia:** `RendererRegistry::for()` r. 23-28: pridat `'cn-1' => new MpdfCreditNoteCn1Renderer(),` a doplnit `tests/unit/Invoicing/RendererRegistryTest.php`.

**Nazov suboru:** `DocumentFilename::PREFIXES` r. 15-18: pridat kind `'credit_note' => ['sk' => 'Dobropis_', 'cs' => 'Opravny_doklad_', 'pl' => 'Faktura_korygujaca_', 'en' => 'Credit_note_']` (iba ASCII, UI-SPEC G); `Invoices::serve()` rozhodne kind podla `type`.

---

### `app/Database/Migrations/2026-10-31-000001_AddCreditNotes.php` (migration, jeden subor)

Konvencia: `final class X extends Migration`, `$this->db->query(<<<'SQL' ... SQL)`, docblok s odkazom na D-xx, plny `down()`.

**(a) `alloc_guard` vynimka + `release_allocations`** - analog `2026-10-27-000003_LockAllocationsToInvoices.php` r. 18-62. Existujuce telo (r. 20-41) pouziva `IS DISTINCT FROM`; zachovat styl. Pridat vynimku pred `RAISE EXCEPTION 'allocation % is locked'`:
```sql
IF OLD.invoice_id IS NOT NULL THEN
    IF TG_OP = 'UPDATE' AND NEW.invoice_id IS NULL
       AND NEW.task_id IS NOT DISTINCT FROM OLD.task_id AND NEW.hours IS NOT DISTINCT FROM OLD.hours
       AND NEW.billing_month IS NOT DISTINCT FROM OLD.billing_month AND NEW.rate IS NOT DISTINCT FROM OLD.rate
       AND NEW.amount IS NOT DISTINCT FROM OLD.amount AND NEW.currency IS NOT DISTINCT FROM OLD.currency
       AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at AND NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
       AND EXISTS (SELECT 1 FROM invoices cn WHERE cn.type = 'credit_note' AND cn.corrected_invoice_id = OLD.invoice_id) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'allocation % is locked', OLD.id USING ERRCODE = 'integrity_constraint_violation';
END IF;
```
Funkcia (vzor `lock_allocations` r. 44-65): `LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp`, `GET DIAGNOSTICS v_count = ROW_COUNT`, potom `REVOKE ALL ON FUNCTION release_allocations(bigint) FROM PUBLIC; GRANT EXECUTE ... TO app_rw`. NIKDY `GRANT UPDATE (invoice_id)` (strazi `AllocationGuardTest`). `down()`: obnovit telo `alloc_guard` z r. 72-81 vo vzore (to je telo z TEJTO migracie r. 20-41, nie povodne z CreateBillingAllocations - pri `down()` obnovit verziu s kontrolou `NEW.invoice_id IS NOT NULL`), `DROP FUNCTION release_allocations(bigint)`.

**(b) `invoice_complete_check`** - analog `2026-10-28-000001_RequireReportDocument.php` r. 14-45. Zmena je jediny riadok v podmienke:
```sql
OR (v_inv.type = 'invoice' AND v_inv.snapshot_schema >= 2 AND NOT EXISTS (SELECT 1 FROM invoice_documents WHERE invoice_id = v_id AND kind = 'report')) THEN
```
`down()` obnovi telo z r. 49-79 tohto suboru (verzia s `snapshot_schema >= 2` bez `type`) a `COMMENT ON FUNCTION`.

**(c) `invoice_status_v`** - analog `2026-10-29-000006_ExtendInvoiceStatusViewPayments.php` r. 15-52. `CREATE OR REPLACE VIEW` musi zachovat 22 stlpcov v poradi (`id ... last_paid_on`, r. 17-30), nove na koniec. `status` CASE (r. 20-24) nove poradie: `external` > `cancelled` > `paid` > `partially_paid` > `sent` > `issued`. Pridat LEFT JOIN `invoices cn ON cn.corrected_invoice_id = i.id AND cn.type = 'credit_note'` a `invoices o ON o.id = i.corrected_invoice_id`; nove stlpce `credit_note_id, credit_note_number, credit_note_issue_date, corrected_invoice_id, corrected_number, corrected_issue_date`. `balance` pre cancelled = `0.00` (A2, vyzaduje potvrdenie). `GRANT SELECT ON invoice_status_v TO app_rw` zachovat (r. 31). `down()` ma tvar r. 33-52 (DROP VIEW + vytvorit v2 verziu; pozor, ze `down` tohto suboru obnovuje v1, treba obnovit v2 definiciu z r. 17-40).

**(d) `email_templates` CHECK + seed** - analog `...CreateEmailTemplates.php` r. 23 (`CHECK (key IN ('invoice','reminder'))`), r. 37-42 (seed loop) a `seed()` (r. 51+, tvar `[key, lang, subject, body]`, placeholdery `{{number}}`, `{{entity_name}}`):
```php
$this->db->query('ALTER TABLE email_templates DROP CONSTRAINT email_templates_key_check');
$this->db->query("ALTER TABLE email_templates ADD CONSTRAINT email_templates_key_check CHECK (key IN ('invoice','reminder','credit_note'))");
foreach ($this->seed() as [$key, $language, $subject, $body]) {
    $this->db->query('INSERT INTO email_templates (key, language, subject, body, revision, updated_at) VALUES (:key:, :language:, :subject:, :body:, 1, now())', [...]);
}
```
`down()`: `DELETE FROM email_templates WHERE key = 'credit_note'` pred navratom CHECK-u. Pozn.: `app_rw` nema DELETE na `email_templates`, migracia bezi ako owner.

**(e) index + CHECK-y** - analog `2026-10-30-000005_AddInvoiceFxChecks.php` r. 14-24 (`ALTER TABLE invoices ADD CONSTRAINT ... CHECK (...)` v `up()`, `DROP CONSTRAINT IF EXISTS` v `down()`):
```sql
CREATE UNIQUE INDEX invoices_one_credit_note_uq ON invoices (corrected_invoice_id) WHERE type = 'credit_note';
```
Volitelne CHECK `type <> 'credit_note' OR total <= 0`. Nazov indexu je presne ten, ktory sluzba mapuje na `already_credited`.

---

### `app/Controllers/CreditNotes.php` (controller)

**Analog:** `app/Controllers/Invoicing.php` (issue + rejected + redirect) a `app/Controllers/Payments.php` (sheet/page dvojica `form`, `record`, `panel`, `done`).

**Vystavenie + mapovanie odmietnuti** (`Invoicing::issue`, r. 98-143):
```php
try {
    $issued = service('invoiceIssuer')->issue(IssueInput::toCommand($input, auth()->id() === null ? null : (int) auth()->id()));
} catch (IssueRejected $e) {
    if ($e->reason === 'already_issued' && $e->invoiceId !== null) { return $this->issuedRedirect($e->invoiceId, $e->invoiceNumber); }
    return $this->rejected($e, $raw);
} catch (Throwable $e) {
    log_message('error', 'Invoice issue failed: {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage()]);
    return $this->renderPreview(false, $raw, 500, [['message' => lang('Invoicing.errorFailed')]]);
}
```
Pre dobropis: `service('creditNoteIssuer')`, `CreditNoteRejected`, `already_credited` -> 409 (alebo redirect na existujuci dobropis).

**PRG po vystaveni** (r. 145-158): flash `app_notice` + `HX-Location` pre htmx, inak `redirect()->to('/invoices/' . $id)`. Znovupouzit tvar, flash text s poctom uvolnenych casti.

**404 pre neplatny typ povodnej:** `throw PageNotFoundException::forPageNotFound(lang('Invoices.notFound'));` (Invoices.php r. 41; Payments r. 54).

**htmx sheet + plna stranka fallback:** `htmx_view('invoicing/_preview', $data, 'invoicing/index')` (Invoicing r. 420) a `Payments::panel`/`payment_page.php` (`extend('layouts/app')`, `section('content')`, `<div id="sheet-body">`). Hlavicky: `->setHeader('Vary', 'HX-Request')`.

**Request key:** `private static function uuid4()` v `Invoicing` r. 309-317 (alebo `Uuid::v4()` v Payments, r. 28) - pouzit `Uuid::v4()`.

**Validacia vstupu z POST:** `Payments::record` r. 40-47 (regex UUID + `post()` helper), nevyuzivat `IssueInput::fromPost` (tvar pre faktury).

**Routes** (`app/Config/Routes/invoices.php`): pridat v rovnakom style
```php
$routes->get('invoices/(:num)/credit-note', 'CreditNotes::new/$1');
$routes->post('invoices/(:num)/credit-note/preview', 'CreditNotes::preview/$1');
$routes->post('invoices/(:num)/credit-note', 'CreditNotes::issue/$1');
```
(staticke `invoices/new` su v `invoicing.php`, `(:num)` ich nezachyti.)

**Services:** `app/Config/Services.php` r. 45-53 - skopirovat `invoiceIssuer()` ako `creditNoteIssuer()` (shared instance cez `getSharedInstance`).

---

### Views `app/Views/credit_notes/*` a `app/Views/invoices/show.php`

**Analog:** `_confirm_sheet.php` (offcanvas-bottom `work-sheet`, `<dl class="master-kv">`, `esc(lang(...))`), `payment_page.php`/`_payment_sheet.php` (sheet + page rovnaky partial cez `view(..., get_defined_vars())`). CSP: ziadne inline `style`/`onclick`; text iba cez `lang()` (strazia `ViewsCspTest`, `ViewsHardcodedTextTest`).

**show.php** stavova matica r. 49-57:
```php
[$statusKey, $statusClass] = match (true) {
    $external          => ['Invoices.badgeExternal', 'text-bg-secondary'],
    $status === 'sent' => ['Invoices.statusSent', 'text-bg-light border'],
    ...
```
Pridat `$status === 'cancelled' => ['Invoices.statusCancelled', ...]` pred `paid`, `$isCreditNote = $invoice['type'] === 'credit_note'`. Gating tlacidiel (r. 58-67): `$canPay`, `$canSend`, `$canResend`, `$canRemind` - doplnit `$status !== 'cancelled'` a pre dobropis `$canPay = false`, `$canRemind = false`; novy `$canCredit = ! $external && ! $isCreditNote && $status !== 'cancelled'`. Riadky `Hours::plus` (r. 41-43) so zapornymi hodinami PADNU (regex) - pre dobropis scitovat inak (zaporny retazec z `$snap['totals']['hours']`). `MoneyFormat::format` zaporne zvlada. Detail dat: `InvoiceQueries::detail` (r. 63-95) rozsirit SELECT o `corrected_invoice_id, correction_reason, correction_type` a z `invoice_status_v` o stlpce `credit_note_*`/`corrected_*`; `report_document` je pre dobropis `null`.

---

### Zmeny existujucich spotrebitelov stavu `cancelled`

| Subor | Miesto | Zmena |
|---|---|---|
| `InvoiceStatusQueries.php` r. 36-38, 82, 116 | `origin='issued' AND type='invoice' AND balance > 0 AND due_date < :today:` | pridat `AND status <> 'cancelled'` (obrana do hlbky, aj ked `balance=0`) |
| `InvoiceQueries.php` r. 33-45 | `$cond` + `in_array($status, ['issued','sent','partially_paid','paid'])` | pridat `cancelled` (a pripadne `credit_note`); zosuladit `Invoices::index` whitelist (r. 21) |
| `PaymentService.php` r. 220-222 | `if ($row['origin'] !== 'issued' \|\| $row['type'] !== 'invoice') throw new PaymentRejected('not_payable');` | SELECT doplnit o `v.status` a odmietnut `cancelled`; to iste v `void()` |
| `InvoiceMailer.php` r. 353-376 `loadInvoice` | `if ($row['type'] !== 'invoice') throw new SendRejected('not_sendable');` | povolit `credit_note` pre `invoice`/`resend`, `reminder` odmietnut pre `credit_note` aj `cancelled`; `draft()`/`loadAttachments`: sablona `credit_note`, bez `report` prilohy |
| `ManualSendService.php` r. ~178, `InvoiceDelivery.php` r. ~145 | rovnaky `type !== 'invoice'` guard | rovnaka uprava |
| `Invoices::serve` r. 79-119 | `report` pre dobropis | 404 (nie 500 `critical`); `DocumentFilename::for($kind...)` kind podla typu |
| `TemplateContext` / `TemplateRenderer::PLACEHOLDERS` / `EmailTemplateService::KEYS` | zoznam klucov a placeholderov | pridat `credit_note`, `original_number`, `original_date` |

---

### Testy

**`tests/_support/CreditNoteFixtures.php`** - analog `tests/_support/IssueFixtures.php` (trait `@mixin PgTestCase`): `issueSetup()` (r. 28-70), `issueCommand()` (r. 76-103), `issuer()` s `RendererRegistry(['inv-2' => new FakePdfRenderer('inv-2'), 'rep-1' => new FakePdfRenderer('rep-1')])` (r. 105-110), `uuid4()` (r. 112+). Dobropis: `creditNoteIssuer(?PdfRenderer $cn = null)` s `new RendererRegistry(['cn-1' => $cn ?? new FakePdfRenderer('cn-1')])`, `creditNoteCommand(int $originalId, array $overrides)` s tokenom pocitanym cez `CreditNoteSnapshotBuilder` + `InvoiceCalculator::previewToken` (vzor `previewTokenFor` v IssueFixtures), pomocna assercia `assertSumsToZero($originalId, $cnId)`.

**Setup/teardown vzor** (InvoiceIssuerTest r. 20-39): `use InvoiceFixtures; use IssueFixtures; use MasterFixtures;`, `#[Group('integration')]`, `Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'))`, `resetMasterTables()` v `setUp/tearDown`, `Services::reset()`. Extend `PgTestCase`, `$this->owner()` (cital/asserty), `$this->rw()` (app_rw, tak ako aplikacia).

**Concurrency** - `tests/integration/Invoicing/IssueConcurrencyTest.php` r. 52-80 (zamok 777 `pg_advisory_lock`, `proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/issue_worker.php', ...])`, `releaseStartLock()`, `tearDown` `pg_advisory_unlock_all`) + worker `tests/_support/issue_worker.php` r. 28-52 (`chdir`, `CI_ENVIRONMENT=testing`, `bootstrap.php`, `Services::injectMock('clock', ...)`, `Database::connect('tests', false)`, `pg_advisory_lock_shared(777)`, vetva `--command <json>` vracia `{"worker","numbers","errors","rejected":[{"reason","invoice_id"}]}`). `credit_note_worker.php`: ta ista vetva `--command`, ale s `CreditNoteIssuer` a `RendererRegistry(['cn-1' => FakePdfRenderer])`. Ocakavanie: 1 uspech, 1 `already_credited`, rad `credit_note` bez medzery.

**Allocation release** - `tests/integration/Work/AllocationGuardTest.php` (helper `assertDbError(string $regex, callable)`, r. 36+): overit `release_allocations` len s existujucim dobropisom, priamy `UPDATE invoice_id` cez `$this->rw()` = `permission denied`, zamknute casti dalej padaju na DELETE/UPDATE.

**Aktualizovat existujuce:** `InvoiceStatusViewColumnsTest` (zoznam stlpcov), `EmailTemplatesSeedTest` (8 -> 12 riadkov), `RendererRegistryTest`, `DocumentFilenameTest`, `LangKeysTest` (vsetky `cn*` v 4 jazykoch).

## Shared Patterns

### Neobchadzatelna nemennost (insert-only)
**Zdroj:** `invoices*` insert-only triggery + `app_rw` bez UPDATE; `SECURITY DEFINER` funkcie (`lock_allocations`, `release_allocations`) s `SET search_path = public, pg_temp`, `REVOKE ALL ... FROM PUBLIC`, `GRANT EXECUTE ... TO app_rw`.
**Apply:** migracia, `CreditNoteIssuer`. Stav `cancelled` len vo view, nikdy UPDATE povodnej faktury.

### Transakcia + audit + SET CONSTRAINTS
**Zdroj:** `InvoiceIssuer.php` r. 68, 266, 284: `Transaction::run($this->db, fn () => ...)`, `$this->audit->record('invoice', $id, '<action>', null, [...])`, `SET CONSTRAINTS ALL IMMEDIATE`.
**Apply:** `CreditNoteIssuer`.

### Peniaze a hodiny (bcmath, nikdy float)
**Zdroj:** `app/Libraries/Platform/Money.php` (`of`, `negate`, `sum`, `timesHours`, `compare`, `equals`; `negate` normalizuje `-0.00`), `Hours.php` (zaporne NEPODPORUJE), `PdfFormat::amount/hours`, `MoneyFormat::format`.
**Apply:** `CreditNoteIssuer`, `CreditNoteSnapshotBuilder`, views. Kontrola riadku: `Money::of($rate)->timesHours($negHours)->equals(Money::of($negAmount))` (HalfAwayFromZero == PG `round`), inak throw. Invarianty: `total = -orig.total`, `total_entity_ccy = -orig.total_entity_ccy` (kopia, nie prepocet).

### Nikdy zive tabulky pre dobropis
**Zdroj:** RESEARCH Pattern 2. Vsetko (strany, jazyk, rezim, banka, pravne texty, `vat_code`, fx) z riadka a snapshotu povodnej. Nevolat `SnapshotBuilder::build`, `assertEntityActive`, `FxService`, `BankAccountSelector`, `EntityVersionResolver`.

### Idempotencia a preview token
**Zdroj:** `request_key uuid UNIQUE` (`InvoiceIssuer::assertNotIssued` r. 289-296; `REQUEST_KEY_CONSTRAINT`), `hash_equals($cmd->previewToken, InvoiceCalculator::previewToken($snapshot))` (r. 190-192). **Apply:** `CreditNoteIssuer`, `CreditNoteCommand`, kontroler.

### CSRF / htmx / CSP / lang
**Zdroj:** `htmx_view()`, `is_htmx()`, `HX-Location`, `Vary: HX-Request` (Invoicing r. 153-157, 420); views bez inline JS/CSS, vsetky texty cez `lang()` (`ViewsCspTest`, `ViewsHardcodedTextTest`); `esc()` na vsetky premenne. **Apply:** vsetky nove controllery a views.

### Audit logger / DbBool
`DbBool::toDb(DbBool::of($row['counts_toward_limit']))` (InvoiceIssuer r. 337) pre boolean stlpce; `AuditLogger::record(entity, id, action, null, array)` bez snapshotu a bajtov.

## No Analog Found

Ziadny novy subor nezostal bez analogu. Ciastocne bez priameho analogu:

| Cast | Dovod |
|---|---|
| PHP `enum CorrectionType` | v `app/` nie je backed enum; pouzit konstantu v style `IssueCommand::PAYMENT_METHODS` alebo novy enum (planner rozhodne) |
| `pg_advisory_xact_lock` zamok | `InvoiceIssuer` pouziva len `FOR UPDATE`; advisory zamok je nove (RESEARCH Pitfall 1, overene na test DB) |
| Partial unique index + `release_allocations()` | analog len pre funkciu (`lock_allocations`); index je nova vec |

## Metadata

**Analog search scope:** `app/Libraries/Invoicing`, `app/Libraries/Delivery`, `app/Libraries/Receivables`, `app/Controllers`, `app/Config`, `app/Database/Migrations`, `app/Views/{invoices,invoicing,pdf}`, `tests/_support`, `tests/integration/{Invoicing,Work,Delivery}`
**Files scanned:** ~45 (precitanych v celku alebo cielene: InvoiceIssuer, IssueCommand, IssueRejected, SnapshotBuilder, InvoiceGuards, NumberAllocator, MpdfInvoiceInv2Renderer, RendererRegistry, DocumentFilename, Invoicing, Invoices, Payments, 4 migracie, InvoiceQueries, InvoiceStatusQueries, PaymentService, InvoiceMailer, show.php, IssueFixtures, issue_worker, IssueConcurrencyTest, InvoiceIssuerTest, AllocationGuardTest)
**Pattern extraction date:** 2026-10-31
