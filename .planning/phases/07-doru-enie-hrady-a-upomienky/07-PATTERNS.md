# Phase 7: Doručenie, úhrady a upomienky - Pattern Map

**Mapped:** 2026-10-29
**Files analyzed:** 27 (new/modified)
**Analogs found:** 25 / 27 (2 bez analógu: CI4 Email podtrieda pre Message-ID, compose sieť `mail`)

Všetky cesty sú relatívne k `/mnt/c/personal-projects/task-tracker`. Čísla riadkov sú z čítania dňa 2026-10-29.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|-------------------|------|-----------|----------------|-------|
| `app/Libraries/Mail/AppMailer.php` (mod: `sendMessage`) | service | request-response | sám (`AppMailer::send`) | exact |
| `app/Libraries/Mail/AppEmail.php` (nové, podtrieda CI4 Email) | utility | request-response | žiadny | none |
| `app/Libraries/Delivery/InvoiceMailer.php` | service | request-response + CRUD (insert po SMTP) | `app/Libraries/Invoicing/InvoiceIssuer.php` (idempotencia, audit) + `AppMailer` | role-match |
| `app/Libraries/Delivery/SendCommand.php`, `SendResult.php`, `SendRejected.php` | model/DTO | request-response | `Invoicing/IssueCommand.php`, `IssuedInvoice.php`, `IssueRejected.php` | exact |
| `app/Libraries/Delivery/SenderIdentity.php` | utility | transform | `Master/CcEmails.php` (validácia adries) | role-match |
| `app/Libraries/Delivery/EmailTemplateService.php` | service | CRUD | `Master/LegalTextService.php` | exact |
| `app/Libraries/Delivery/TemplateRenderer.php` | utility | transform | `Invoicing/PdfFormat.php` + `strtr` (nový) | partial |
| `app/Libraries/Receivables/PaymentService.php` | service | CRUD | `Master/LegalTextService.php` (Transaction + audit) + `InvoiceIssuer::assertNotIssued` | role-match |
| `app/Libraries/Receivables/InvoiceStatusQueries.php` | service (queries) | request-response | `Invoicing/InvoiceQueries.php`, `Work/TaskQueries.php` | exact |
| `app/Controllers/InvoiceDelivery.php` (send/resend/remind/mark-sent) | controller | request-response (htmx) | `app/Controllers/Invoicing.php` (`moveSheet`, potvrdzovací panel) + `Settings/LegalTexts.php` | role-match |
| `app/Controllers/Payments.php` (record/void) | controller | request-response (htmx) | `Settings/LegalTexts.php::update` | role-match |
| `app/Controllers/Settings/EmailTemplates.php` | controller | CRUD | `Settings/LegalTexts.php` | exact |
| `app/Controllers/Invoices.php` (mod: filenames, show data) | controller | request-response | sám (`serve`, `show`) | exact |
| `app/Controllers/Home.php` (mod: overdue) | controller | request-response | sám (`data()`) | exact |
| `app/Config/Routes/invoices.php`, `settings.php` (mod) | route | - | sám | exact |
| `app/Config/Email.php` (mod: `allowedFromDomains`, `SMTPTimeout`) | config | - | sám | exact |
| `app/Views/invoices/show.php` (mod) + `_delivery_sheet.php`, `_history.php`, `_payments.php`, `_payment_sheet.php` | view | request-response | `invoices/show.php` + `invoicing/_confirm_sheet.php` | role-match |
| `app/Views/invoices/_list.php` (mod: status badge/filter) | view | request-response | sám | exact |
| `app/Views/home/_overdue.php` + `_body.php` (mod) | view | request-response | `home/_body.php` | exact |
| `app/Views/settings/email_templates/index.php`, `edit.php` | view | CRUD | `app/Views/settings/legal_texts/index.php`, `edit.php` | exact |
| `app/Views/more/index.php` (mod) | view | - | sám (riadok 7) | exact |
| `app/Language/{sk,en}/Delivery.php`, `Receivables.php`, `EmailTemplates.php` | config (lang) | - | `app/Language/sk/LegalTexts.php` | exact |
| Migrácia `email_templates` (+ seed) | migration | CRUD | `2026-10-28-000002_AddLegalTextsRevision.php` + migrácia `legal_texts` | role-match |
| Migrácia `invoice_sends` (+ `invoice_send_voids`), `payments` (+ `payment_voids`) | migration | insert-only | `2026-10-27-000002_CreateInvoices.php` + `000005/6` guard | exact |
| Migrácia `entities` sender stĺpce (D-27) | migration | CRUD | `2026-10-25-000002_CreateEntities.php`, `2026-10-27-000001_AddLegalProfilesVatCode.php` | role-match |
| Migrácia rozšírenie `invoice_status_v` | migration | view | `2026-10-27-000004_CreateInvoiceStatusView.php` | exact |
| `app/Libraries/StagingSeed/Modules/Deliveries.php` + zápis do `StagingSeeder.php` | seed module | batch | `StagingSeed/Modules/Invoices.php` | exact |
| `compose.server.yaml` (mod: sieť `mail`), `app.env` | config | - | žiadny v tomto prieskume | none |
| Testy `tests/integration/Mail/*`, `Receivables/*` | test | - | `tests/integration/Mail/SecurityNotifierTest.php`, `tests/integration/Invoicing/IssueIdempotencyTest.php` | role-match |

## Pattern Assignments

### `app/Libraries/Mail/AppMailer.php` (mod) a `InvoiceMailer` (service, request-response)

**Analog:** `app/Libraries/Mail/AppMailer.php` (celý súbor, 46 riadkov). `SecurityNotifier` ostáva na `send()`; pridať paralelnú metódu `sendMessage()` s vlastnou NOVOU `Email` inštanciou (RESEARCH A3).

**Log transport vetva a clean()** (AppMailer riadky 14-33, 41-44) — skopírovať do `sendMessage`, logovať len `kind`, `to`, `subject`, nikdy telo:
```php
/** @var EmailConfig $config */
$config = config('Email');

if ($config->transport === 'log') {
    log_message('info', sprintf('mail.suppressed kind=%s to=%s subject=%s', $kind, $this->clean($to), $this->clean($subject)));
    return true;
}
$email = service('email');   // POZOR: v novej ceste NEPOUZIT zdielanu instanciu; `new AppEmail($config)` alebo service('email', $config, false)
...
if ($email->send() === false) {
    log_message('error', sprintf('mail.failed kind=%s to=%s', $kind, $this->clean($to)));
    return false;
}
private function clean(string $value): string { return trim((string) preg_replace('/[\r\n]+/', ' ', $value)); }
```
Rozdiel proti dnešku (D-02): pri `log` transporte `InvoiceMailer` vráti úspech a zapíše `invoice_sends` s `transport='log'`.

**Idempotencia a poradie krokov** — analog `app/Libraries/Invoicing/InvoiceIssuer.php`:
- riadky 59-77 (zachytenie unique violation po súbehu):
```php
} catch (DatabaseException $e) {
    // WR-08: subezne vystavenie s rovnakym request_key ...
    if (self::isUniqueViolation($e, self::REQUEST_KEY_CONSTRAINT)) {
        $this->assertNotIssued($cmd->requestKey);
    }
    throw $e;
}
```
- riadky 82-94 `public static function isUniqueViolation(DatabaseException $e, string $constraint): bool` — znovupoužiť priamo (`InvoiceIssuer::isUniqueViolation($e, 'invoice_sends_request_key_key')`), pozri aj `LegalTextService::create` riadky ~190-197 pre rovnaké použitie.
- riadky 262-270 `assertNotIssued`: `SELECT ... WHERE request_key = CAST(:k: AS uuid)`; v `InvoiceMailer` na začiatku vrátiť predchádzajúci výsledok.

**Zápis po SMTP úspechu** (D-10, RESEARCH Pattern 3) — `Transaction::run($this->db, fn () => { INSERT invoice_sends; $this->audit->record('invoice_send', $id, 'create', null, [...]) })` (vzor `Transaction` v `app/Libraries/Platform/Transaction.php` 27-65, `AuditLogger::record` signatúra `record(string $entityType, int $entityId, string $action, ?array $before, ?array $after)`; `entityType` musí spĺňať `/^[a-z_]{3,40}$/`, preto `invoice_send`, `payment`, `payment_void`, `email_template`). Do audit dát nikdy telo ani prílohy; `AuditLogger::scrub` filtruje kľúče password/secret/token.

**Prílohy** — analog `app/Libraries/Invoicing/DocumentStore.php` 46-65:
```php
public function read(int $invoiceId, string $kind = 'invoice'): ?StoredDocument   // overuje sha256, hádže DocumentIntegrityError
```
Chyby spracovať ako v `Invoices::serve` (riadky 63-78): `catch (DocumentIntegrityError $e) { log_message('critical', 'Invoice {id} {kind} PDF failed integrity check: {message}', [...]); ... }`. `$doc->bytes` ide do `attach($bytes, 'attachment', $name, 'application/pdf')`.

**Názvy príloh** — vzor v `Invoices::serve` riadky 80-84 (prefixy `faktura-`/`wykaz-`/`invoice-`; sanitizácia `preg_replace('/[^A-Za-z0-9._-]/', '_', $number)`). Vytiahnuť do jednej pomocnej metódy (napr. `DocumentFilename::for($kind, $docLanguage, $number)`) a použiť v `Invoices::serve` aj v `InvoiceMailer` (CONTEXT D-07 uvádza `Faktura_<číslo>.pdf`, existuje rozpor so súčasným `faktura-`: plán musí zvoliť jednu a zosúladiť).

**Test vzor:** `tests/integration/Mail/SecurityNotifierTest.php` riadky 17-50: `#[Group('integration')]`, `config('Email')->transport = 'smtp'` v `setUp`, `$this->mockEmail()` (CI4 `MockEmail`), `TestLogger`, `FrozenClock`.

---

### `app/Libraries/Mail/AppEmail.php` (utility, request-response) — bez analógu

Nič podobné v kóde (nie je podtrieda frameworku). Použiť RESEARCH Pattern 2 (override `getMessageID()`), najprv spike (Wave 0). `vendor/` nie je v checkoute (je v kontajneri), preto čítať `vendor/codeigniter4/framework/system/Email/Email.php` v kontajneri.

---

### `app/Libraries/Delivery/EmailTemplateService.php` (service, CRUD)

**Analog:** `app/Libraries/Master/LegalTextService.php` (exact: DB text s `revision`, audit, stĺpcový UPDATE).

**Konštruktor a závislosti** (riadky 20-47):
```php
use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
...
public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
{
    $this->db    = $db ?? Database::connect();
    $this->audit = $audit ?? new AuditLogger($this->db);
}
```
**Update s optimistickým zámkom revízie** (riadky 99-146): `Transaction::run` → `SELECT ... FOR UPDATE` → porovnať `revision` (`stale`) → no-op ak bez zmeny → `UPDATE ... revision = revision + 1, updated_at = :now:` → `$this->audit->recordChange('legal_text', $id, 'update', $old, $new)`. Čas len `service('clock')->now()->format('Y-m-d H:i:sP')`. Výnimky cez malú triedu s `reason` (ako `LegalTextRejected`; kódy `empty|too_long|stale|not_found|invalid|unknown_placeholder`). Konštanty `MAX_BODY`, `KEYS`, `LANGUAGE_ORDER` ako riadky 20-26; v `email_templates` pridať `MAX_SUBJECT`.

**Fallback jazyka** (D-14): vzor `LegalTextService::effective` (riadky ~232-255, `fallback` príznak); pre e-mail: hľadať `doc_language`, inak `en`, nikdy prázdne.

**Migrácia (grant):** `app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php` riadky 18-25:
```php
use GrantsPrivileges;
$this->db->query('GRANT INSERT ON legal_texts TO app_rw');
$this->grantUpdateColumns('legal_texts', ['body', 'provisional', 'revision', 'updated_at']);
```
Pre `email_templates`: `GRANT INSERT`, `grantUpdateColumns('email_templates', ['subject','body','revision','updated_at'])`, `COMMENT ON TABLE`. `down()` s `REVOKE UPDATE (...)` a `REVOKE INSERT` (riadky 38-39). `UNIQUE (key, language)` a `CHECK (key IN ('invoice','reminder'))`, `CHECK (language IN ('sk','pl','cs','en'))`, `CHECK (char_length(body) BETWEEN 1 AND N)`.

---

### `app/Controllers/Settings/EmailTemplates.php` + views `settings/email_templates/{index,edit}.php` (controller, CRUD)

**Analog:** `app/Controllers/Settings/LegalTexts.php` + `app/Views/settings/legal_texts/edit.php`.

**Imports/štruktúra** (LegalTexts riadky 1-17): `final class ... extends BaseController`, `PageNotFoundException`, `RedirectResponse|ResponseInterface`.

**Update + stale + 422 vzor** (riadky 105-135):
```php
try {
    $newRevision = (new LegalTextService())->update($id, $body, $provisional, $revision);
} catch (LegalTextRejected $e) {
    if ($e->reason === 'not_found') { throw PageNotFoundException::forPageNotFound(lang('LegalTexts.notFound')); }
    $stale  = $e->reason === 'stale';
    $status = $stale ? 409 : 422;
    if ($stale) { $text = (new LegalTextService())->find($id) ?? $text; $revision = (int) $text['revision']; }
    return $this->response->setStatusCode($status)->setBody(view('settings/legal_texts/edit', $this->formData(...)));
}
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('LegalTexts.saved', [$newRevision])]);
if (is_htmx()) { return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/legal-texts')->setBody(''); }
return redirect()->to('/settings/legal-texts');
```
`postString()` helper (riadky 261-266) a `textOr404()` (riadky 272-282) skopírovať.

**View vzor:** `legal_texts/edit.php` riadky 17-70: `$this->extend('layouts/app')`, `<form method="post" action="..." data-submit-once novalidate>`, `csrf_field()`, skrytý `revision`, textarea s `is-invalid`/`invalid-feedback`, `master-sticky-actions master-actions`, všetky texty `esc(lang('...'))`. Zoznam placeholderov zobraziť ako `form-text`. Náhľad na vzorovej faktúre: `TemplateRenderer` + vzorové dáta (analógia `app/Libraries/Invoicing/Samples`).

**Routes** (`app/Config/Routes/settings.php` 17-21):
```php
$routes->get('settings/legal-texts', 'Settings\LegalTexts::index');
$routes->get('settings/legal-texts/(:num)/edit', 'Settings\LegalTexts::edit/$1');
$routes->post('settings/legal-texts/(:num)', 'Settings\LegalTexts::update/$1');
```
Pridať `settings/email-templates` rovnako. Položka v `app/Views/more/index.php` riadok 7:
```php
<a class="list-group-item list-group-item-action more-item" href="/settings/legal-texts"><?= esc(lang('LegalTexts.navTitle')) ?></a>
```

---

### `app/Libraries/Delivery/TemplateRenderer.php` (utility, transform)

**Analóg:** `app/Libraries/Invoicing/PdfFormat.php` (formát súm a dátumov bez ICU; hodnoty placeholderov `{{total}}`, `{{due_date}}` v jazyku dokladu) a `app/Libraries/Platform/MoneyFormat.php` (len pre jazyk UI, preto pre doklad `PdfFormat`). Samotné `strtr` + whitelist nemá analóg (RESEARCH Pattern 4): konštanta kľúčov, `preg_match_all('/\{\{([a-z_]+)\}\}/')` pri uložení, `strtr` pri renderi, `nl2br(esc())` pre HTML, CR/LF preč z predmetu.

---

### `app/Libraries/Delivery/SenderIdentity.php` (utility, transform)

**Analóg:** `app/Libraries/Master/CcEmails.php` — `parse()` (split na `[,;\r\n]+`, lowercase, dedup) a `validate()` (riadky 41-52):
```php
if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1 || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    return lang('Clients.errorCcInvalid', [trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $email))]);
}
```
Znovupoužiť pre To/CC/BCC/Reply-To; `CcEmails::MAX = 5` je pre klienta, pre adresátov odoslania zaviesť vlastný limit (CONTEXT: ~10). Allowlist domén: RESEARCH „Code Examples → Sender allowlist“.

---

### `app/Libraries/Receivables/PaymentService.php` (service, CRUD)

**Analóg:** `LegalTextService::update`/`create` pre štruktúru (`Transaction::run` + `AuditLogger::record`) a `InvoiceIssuer` pre idempotenciu (`request_key`, `isUniqueViolation`). Peniaze: `app/Libraries/Platform/Money.php` (string + bcmath; `Money::of($x)->toDecimalString()` použité v `MoneyFormat.php` riadok 17); použiť `bccomp/bcsub` s mierkou 2, nikdy float. Dátum „dnes“ z `service('clock')->today()` (`Clock::today()` existuje, `Platform/Clock.php` riadok 29), nie `date()`. Súbeh: `pg_advisory_xact_lock(:invoice_id)` (RESEARCH Pattern 5, `FOR UPDATE` na `invoices` by pod `app_rw` zlyhalo — nepotvrdené, test pod `rw()`).

**Insert vzor** (LegalTextService::create riadky 183-194): `$this->db->table('legal_texts')->insert([...]); $id = (int) $this->db->insertID(); $this->audit->record(...)`.

---

### `app/Libraries/Receivables/InvoiceStatusQueries.php` (service/queries, request-response)

**Analóg:** `app/Libraries/Invoicing/InvoiceQueries.php` — konštruktor s `?BaseConnection $db` (riadky 17-25), `list()` číta z `invoice_status_v` (riadky 31-46), výsledok upratať `trim()` pre `char(3)` mena:
```php
$rows = $this->db->query(
    'SELECT i.id, ... FROM invoice_status_v i JOIN clients c ON c.id = i.client_id
     ORDER BY i.issue_date DESC NULLS LAST, i.number DESC NULLS LAST, i.id DESC LIMIT :n:',
    ['n' => $limit + 1],
)->getResultArray();
$truncated = count($rows) > $limit;
```
`overdue(string $today, ?int $clientId, int $limit)`: `due_date < CAST(:today: AS date) AND balance > 0 AND origin='issued' AND type='invoice'` (použiť `CAST(... AS date)`, nie `::date`, lebo CI4 `:name:` binding; vzor `CAST(:k: AS uuid)` v InvoiceIssuer riadok 265). Dátum z `Clock`. `InvoiceQueries::list/detail` rozšíriť o stĺpce `status`, `balance`, `paid_amount`, `sent_at` (detail číta z `invoices`, nie z view; pridať join alebo druhý dotaz na `invoice_status_v`).

---

### Migrácia: rozšírenie `invoice_status_v`

**Analóg:** `app/Database/Migrations/2026-10-27-000004_CreateInvoiceStatusView.php` riadky 17-26:
```php
CREATE VIEW invoice_status_v AS
SELECT id, origin, type, number, external_ref, client_id, entity_id, billing_month, issue_date, due_date,
       currency, total, counts_toward_limit, line_count, created_at,
       CASE WHEN origin = 'external' THEN 'external' ELSE 'issued' END AS status
FROM invoices
...
GRANT SELECT ON invoice_status_v TO app_rw
```
Pôvodných 16 stĺpcov (15 + `status` na poz. 16; RESEARCH hovorí 17, overiť počtom) zachovať v poradí a typoch, nové stĺpce len na koniec cez `CREATE OR REPLACE VIEW`. Komentár v hlavičke migrácie hovorí presne toto (Pitfall 6). `down()` nesmie `DROP VIEW`, obnoviť pôvodnú definíciu.

### Migrácie: `invoice_sends`, `payments`, voids (insert-only)

**Analóg:** `2026-10-27-000002_CreateInvoices.php`:
- `use GrantsPrivileges;` a štýl `CREATE TABLE` s `bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY`, `request_key uuid NULL UNIQUE` (riadok 36), `CHECK (char_length(...) <= N)`, `created_at timestamptz NOT NULL`, `created_by integer NULL REFERENCES users(id)`, `REFERENCES invoices(id) ON DELETE RESTRICT`.
- Trigger: `CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION immutable_guard()` (cyklus nad tabuľkami; `immutable_guard()` už existuje, nevytvárať znovu).
- TRUNCATE: `2026-10-27-000006_GuardInvoiceTruncate.php` riadky 38-40 `CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION truncate_guard()` (funkcia existuje; použiť len `CREATE TRIGGER`, `down()` iba `DROP TRIGGER`, nie `DROP FUNCTION`).
- Default privileges: `GrantsPrivileges` docblock hovorí, že tabuľky sú pre `app_rw` insert-only predvolene (default privileges v `provision.sql`), takže explicitný GRANT netreba; testovať pod `rw()`.
- `COMMENT ON TABLE ...` na každej tabuľke (riadky 160-163).
- `invoice_sends`: `UNIQUE request_key`, `kind CHECK IN ('invoice','resend','reminder','manual')`, `counts_as_sent boolean` (RESEARCH Pattern 1), `recipients jsonb`, `from_email`, `reply_to`, `bcc jsonb`, `transport CHECK IN ('smtp','log')`, `message_id`, `body_sha256`. Constraint názov pre `isUniqueViolation` je `invoice_sends_request_key_key` (automaticky názov, ako `invoices_request_key_key` v `InvoiceIssuer::REQUEST_KEY_CONSTRAINT`).

### Migrácia: sender polia na `entities` (D-27)

**Analóg:** `2026-10-27-000001_AddLegalProfilesVatCode.php` (jednoduchý ALTER TABLE ADD COLUMN) a `2026-10-25-000002_CreateEntities.php` (štruktúra, grant UPDATE stĺpcov). `entities` je mutovateľná; nové stĺpce pridať do stĺpcového `grantUpdateColumns`. NULL = default (RESEARCH Runtime State: riešiť v `SenderIdentity`). Zmena cez `EntityService` s auditom (`app/Libraries/Master/EntityService.php`, nečítané v tomto prieskume, plán ho musí prečítať).

---

### `app/Controllers/InvoiceDelivery.php` a `Payments.php` (controller, request-response htmx)

**Analógy:** `app/Controllers/Invoicing.php` (bottom sheet cez `moveSheet`, `is_htmx_fragment_request()` + `HX-Target === 'sheet-body'`, riadky 45, 291-293) a `Settings/LegalTexts.php` (422/409 odpovede, `HX-Location`, flashdata `app_notice`).

**Bottom sheet view vzor:** `app/Views/invoicing/_confirm_sheet.php` riadky 20-50: `<div class="offcanvas offcanvas-bottom work-sheet" tabindex="-1" id="..." aria-labelledby="...">`, `offcanvas-header`/`btn-close data-bs-dismiss="offcanvas"`, `offcanvas-body`, tlačidlá `btn btn-touch` (44 px), všetky texty `esc(lang(...))`, žiadny inline štýl. Formulár s `data-submit-once`, `csrf_field()` a skrytým `request_key` (uuid).

**Zdrojové dáta/404:** `Invoices::show` riadky 29-44: `PageNotFoundException::forPageNotFound(lang('Invoices.notFound'))`; externé faktúry (`origin='external'`) odmietnuť (rovnaká podmienka ako `Invoices::serve` riadok 55).

**Fragmenty:** `htmx_view('home/_body', $data, 'home/index')` + `->setHeader('Vary', 'HX-Request')` (Home::body riadky 21-26, Invoices::index riadky 21-25).

**Routes** (`app/Config/Routes/invoices.php`, všetky GET dnes; pridať POST):
```php
$routes->get('invoices/(:num)/pdf', 'Invoices::pdf/$1');
// pridať: $routes->post('invoices/(:num)/send', 'InvoiceDelivery::send/$1'); /resend /remind /mark-sent /payments; payments/(:num)/void
```
CSRF testovať ako `tests/feature/HtmxCsrfTest.php`.

---

### `app/Views/home/_overdue.php` + `Home.php` (mod)

**Analóg:** `app/Controllers/Home.php::data()` riadky 36-70 (zloženie poľa pre view, rešpektuje `DashboardFilter` s `clientId`) a `app/Views/home/_body.php` riadky 1-30 (docblock `@var`, `$locale = service('request')->getLocale()`, helper closures, `MoneyFormat`). Pridať do `data()` kľúč `'overdue' => (new InvoiceStatusQueries())->overdue(service('clock')->today(), $filter->clientId, 20)`; sekcia sa nezobrazí pri prázdnom zozname (D-23). Fragment sa obnovuje trasou `fragments/dashboard` (`Routes/dashboard.php`).

---

### `app/Libraries/StagingSeed/Modules/Deliveries.php` (seed, batch)

**Analóg:** `app/Libraries/StagingSeed/Modules/Invoices.php` (exact). Kontrakt `SeedModule` (`name(): string`, `run(SeedContext $ctx): void`). Vzor riadky 21-45: `$ctx->clock->at($inv['at'])` pred každou udalosťou, `$ctx->id('user','admin')`, `$ctx->id('client', ...)`, služby vytvárať s `$ctx->db` (`new InvoiceIssuer($ctx->db)`), chyby ako `throw new RuntimeException('Seed ... rejected: ' . $e->reason, 0, $e)`, `$ctx->notes[...]` pre výstup. Zápis modulu: `app/Libraries/StagingSeed/StagingSeeder.php` riadky 24-27 (pole tried: `Modules\AdminUser::class, MasterData, Tasks, Invoices`) → pridať `Modules\Deliveries::class` za `Invoices`. Rozšíriť `HistoryPlan`/`HistoryPlanner` o plán odoslaní a úhrad. Služby musia pracovať s injektovaným `$db` (konštruktor `?BaseConnection $db`), a `InvoiceMailer` s `log` transportom (`config('Email')->transport = 'log'` pre seed). Test: `tests/integration/StagingSeed/StagingSeederTest.php`.

---

### Testy

- `tests/integration/Mail/SecurityNotifierTest.php` (viď vyššie) pre `InvoiceMailer*Test` (MockEmail, `TestLogger`).
- Idempotencia: `tests/integration/Invoicing/IssueIdempotencyTest.php` (nečítané, existuje; plán ho má prečítať) pre `InvoiceMailerIdempotencyTest`.
- Insert-only pod `app_rw`: `tests/integration/Invoicing/InvoiceImmutabilityTest.php`, `tests/integration/DbRolesTest.php`; helpery `tests/_support/PgTestCase.php` (`owner()`/`rw()`).
- Hranica „po splatnosti“: `tests/integration/TimezoneConsistencyTest.php`, `FrozenClock` (`app/Libraries/Platform/FrozenClock.php`).
- Pohľad: stĺpce pôvodného view nezmenené (nový `InvoiceStatusViewColumnsTest`, bez analógu, porovnať `information_schema.columns` poradie).

## Shared Patterns

### Transakcia + audit v jednom kroku
**Source:** `app/Libraries/Platform/Transaction.php` 27-65, `app/Libraries/Audit/AuditLogger.php` 38-62
**Apply to:** `InvoiceMailer` (len zápis po SMTP), `PaymentService`, `EmailTemplateService`, ručné „odoslaná“, storná
```php
Transaction::run($this->db, function () use (...): int { ...; $this->audit->record('payment', $id, 'create', null, [...]); return $id; });
```
SMTP volanie nikdy vnútri `Transaction::run` (D-04).

### Idempotencia `request_key` (uuid UNIQUE)
**Source:** `app/Libraries/Invoicing/InvoiceIssuer.php` 59-94, 262-270; `app/Database/Migrations/2026-10-27-000002_CreateInvoices.php` riadok 36
**Apply to:** `invoice_sends`, `payments` (a stornové tabuľky ak majú vlastný POST)
Predkontrola SELECT + zachytenie `isUniqueViolation` mimo transakcie; formulár nesie `request_key`, UI `data-submit-once`.

### Insert-only + immutable triggery
**Source:** `2026-10-27-000002_CreateInvoices.php` (immutable_guard cyklus), `2026-10-27-000006_GuardInvoiceTruncate.php` (truncate_guard), `app/Database/Support/GrantsPrivileges.php`
**Apply to:** `invoice_sends`, `payments`, `payment_voids`, `invoice_send_voids`; `email_templates` výnimka: stĺpcový UPDATE (grantUpdateColumns), bez DELETE.

### Peniaze a čas
**Source:** `app/Libraries/Platform/Money.php`, `MoneyFormat.php`, `Clock.php` (`today()`), `Hours.php`
**Apply to:** `PaymentService`, `InvoiceStatusQueries`, `TemplateRenderer`
String + bcmath, žiadny float; „dnes“ len `service('clock')`; NIKDY `CURRENT_DATE`/`NOW()` v biznis logike ani vo view. Sumy do e-mailu cez `PdfFormat` (jazyk dokladu).

### Validácia adries
**Source:** `app/Libraries/Master/CcEmails.php` 15-52
**Apply to:** `SenderIdentity`, `InvoiceMailer`, `InvoiceDelivery` (To/CC/BCC/Reply-To)

### htmx/Controllers/UI
**Source:** `app/Controllers/Settings/LegalTexts.php` 82-135; `app/Views/settings/legal_texts/edit.php`; `app/Views/invoicing/_confirm_sheet.php`
**Apply to:** všetky nové controllery a views: `HX-Location` + `app_notice` flash pri úspechu, 422 s vykresleným formulárom pri chybe, `data-submit-once`, `csrf_field()`, `esc(lang(...))` (sk + en súbory; pl/cs len pre doklady), CSP bez inline skriptov a štýlov, `btn-touch` 44 px.

### Čítanie PDF z DB
**Source:** `app/Libraries/Invoicing/DocumentStore.php` 46-65 a `Invoices::serve` 63-78
**Apply to:** `InvoiceMailer` — bajty výlučne z `DocumentStore::read`, `DocumentIntegrityError` → `log critical` + chyba, nikdy regenerácia.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Libraries/Mail/AppEmail.php` | utility | request-response | Žiadna podtrieda frameworku v kóde; riešiť podľa RESEARCH Pattern 2 po spike (override `getMessageID()`) |
| `compose.server.yaml` (sieť `mail`), `app.env` (`email.*`), `docker/mail/stalwart/plan.ndjson` (alias pre nové From) | config/infra | - | Súbory neboli v rozsahu tohto prieskumu; plán ich musí prečítať. `docs/ops/mail.md` §6 je zdroj |
| `TemplateRenderer` jadro (`strtr` whitelist) | utility | transform | Nič podobné; použiť RESEARCH Pattern 4 |
| `invoice_status_v` test stĺpcov | test | - | Žiadny existujúci test štruktúry view |

## Metadata

**Analog search scope:** `app/Libraries/{Mail,Invoicing,Master,Platform,Audit,StagingSeed,Work}`, `app/Controllers`, `app/Controllers/Settings`, `app/Config/Routes`, `app/Database/Migrations`, `app/Views/{invoices,home,invoicing,settings/legal_texts,more}`, `tests/integration`
**Files read:** ~22
**Poznámky pre plánovač:**
- `app/Views/settings/legal_texts/index.php`, `Invoicing.php`, `EntityService.php`, `PdfFormat.php`, `IssueIdempotencyTest.php`, `HistoryPlan(ner).php` a `app/Language/sk/LegalTexts.php` sú len pomenované, nie obsahovo extrahované; pred implementáciou ich prečítať.
- Rozpor v názvoch príloh: CONTEXT D-07 `Faktura_202610001.pdf`, existujúci `Invoices::serve` `faktura-<číslo>.pdf`; zjednotiť.
- Počet stĺpcov pôvodného `invoice_status_v`: v migrácii 15 dátových + `status` = 16 (RESEARCH píše 17); overiť `\d invoice_status_v` pred `CREATE OR REPLACE VIEW`.
**Pattern extraction date:** 2026-10-29
