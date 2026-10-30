# Phase 8: Meny a kurzy - Pattern Map

**Mapped:** 2026-10-30
**Files analyzed:** 36 (new + modified)
**Analogs found:** 33 / 36 (3 bez analogu: HTTP klient/provider, ECB CSV parser, fixtúry API)

Všetky cesty sú relatívne k `/home/voslar/projects/task-tracker`. Testy a `php` s `bcround` bežia len cez `docker exec task-tracker-php-1 ...` (host PHP 8.3 nemá `bcround`).

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|-------------------|------|-----------|----------------|-------|
| `app/Database/Migrations/2026-10-30-000001_CreateCurrencies.php` | migration | CRUD (read-only seed) | `2026-10-25-000005_CreateLegalProfiles.php` + `2026-10-28-000002_AddLegalTextsRevision.php` | role-match |
| `app/Database/Migrations/2026-10-30-000002_ReplaceCurrencyChecks.php` | migration | batch DDL | `2026-10-28-000002_AddLegalTextsRevision.php` (`replaceBodyCheck`, hľadanie CHECK cez `pg_constraint`) | exact |
| `app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php` | migration | CRUD | `2026-10-28-000002_AddLegalTextsRevision.php` (`grantUpdateColumns`) | role-match |
| `app/Database/Migrations/2026-10-30-000004_CreateExchangeRateCache.php` | migration | CRUD (upsert) | `2026-10-29-000005_CreatePayments.php` (tvar tabuľky) + `GrantsPrivileges::grantMutable` | role-match |
| `app/Libraries/Master/CurrencyRepository.php` | service/repository | CRUD read | `app/Libraries/Master/EntityDirectory.php` (DB read, ctor `?BaseConnection`) | role-match |
| `app/Libraries/Master/Currency.php` (odstrániť) + 9 použití | model | — | `Country::defaultCurrency()` ostáva | — |
| `app/Libraries/Fx/FxConverter.php` | utility | transform | `app/Libraries/Platform/Money.php` (`timesHours`, bcround) | exact |
| `app/Libraries/Fx/RateQuote.php`, `FxQuote.php`, `FxInput.php` | model (readonly VO) | transform | `app/Libraries/Invoicing/Draft.php`, `AllocatedNumber.php`, `Money::of` validácia | role-match |
| `app/Libraries/Fx/RateProvider.php` + `NbpRateProvider`, `EcbRateProvider`, `CnbRateProvider` | service | request-response (HTTP) | ŽIADNY (pozri No Analog) | none |
| `app/Libraries/Fx/RateHttpClient.php`, `CurlRateHttpClient.php` | utility | request-response | ŽIADNY; testovací vzor `tests/_support/CapturingEmail.php` | none |
| `app/Libraries/Fx/FxService.php` | service | request-response + CRUD cache | `app/Libraries/Master/LegalTextService.php` (ctor, `Transaction::run`) | role-match |
| `app/Libraries/Fx/FxSettingsService.php` | service | CRUD + audit | `app/Libraries/Master/LegalTextService.php` | exact |
| `app/Libraries/Fx/RateUnavailable.php`, `RateNotFound.php` | exception | — | `app/Libraries/Invoicing/IssueRejected.php`, `LegalTextRejected` | role-match |
| `app/Controllers/Settings/FxSettings.php` | controller | request-response | `app/Controllers/Settings/LegalTexts.php` | exact |
| `app/Views/settings/fx/index.php` (+ edit) | view | request-response | `app/Views/settings/legal_texts/edit.php`, `index.php` | exact |
| `app/Config/Routes/settings.php` | route | — | riadky 17-21 (legal-texts) | exact |
| `app/Libraries/Invoicing/InvoiceDraftService.php` | service | request-response | sám seba (úprava `draft()`) | modify |
| `app/Libraries/Invoicing/InvoiceIssuer.php` | service | transakčný CRUD | sám seba (úprava krokov 6, 10, 13) | modify |
| `app/Libraries/Invoicing/SnapshotBuilder.php` | service | transform | sám seba (`'fx' => null`, `LABEL_KEYS`, `schema`) | modify |
| `app/Libraries/Invoicing/InvoiceGuards.php` | utility | validácia | sám seba (`assertCurrency` -> `fx_missing`) | modify |
| `app/Libraries/Invoicing/PreviewInput.php`, `IssueInput.php`, `IssueCommand.php`, `Draft.php` | model/DTO | request-response | seba (`FIELDS`, `SCALARS`) | modify |
| `app/Controllers/Invoicing.php` | controller | request-response | seba (`errorMessage` ~209, `draft(` ~370) | modify |
| `app/Views/invoicing/_preview.php` | view | request-response (htmx) | seba (`x-data="invPay"`, `isNotTransfer`, `$dateAttrs`) | modify |
| `app/Views/pdf/invoice_inv2.php` | view (PDF) | transform | seba (riadok 132 blok `fx`) | modify |
| `app/Language/{sk,pl,cs,en}/InvoicePdf.php`, `Invoicing.php`, nový `Fx.php` | config (i18n) | — | `app/Language/*/Invoicing.php:44` | exact |
| `app/Views/invoices/show.php`, `_list.php`, `InvoiceQueries.php` | view/service | request-response | seba | modify |
| `app/Libraries/StagingSeed/*` | utility | batch | existujúci seed | modify |
| `tests/unit/Fx/FxConverterTest.php`, `FxBoundDateTest`, `FxInputTest` | test | unit | `tests/unit/Platform/*Money*` | role-match |
| `tests/unit/Fx/{Nbp,Ecb,Cnb}RateProviderTest.php` | test | unit (fixtúry) | `tests/_support/CapturingEmail.php` (fake namiesto siete) | partial |
| `tests/integration/Fx/*`, `Invoicing/InvoiceIssuerFxTest.php`, `InvoiceFxImmutableTest.php` | test | integration PG | `tests/integration/Invoicing/CurrencyGuardTest.php` | exact |
| `tests/_support/FakeRateHttpClient.php`, `FxFixtures.php`, `MasterFixtures.php` | test support | — | `CapturingEmail.php`, `MasterFixtures.php` | role-match |

## Pattern Assignments

### Migrácie (Currencies, ReplaceCurrencyChecks, FxCountrySettings, ExchangeRateCache)

**Analog:** `app/Database/Migrations/2026-10-28-000002_AddLegalTextsRevision.php` (nájdenie a nahradenie CHECK podľa `pg_constraint`, nikdy nehádať názov) a `2026-10-29-000005_CreatePayments.php` (štýl tabuľky).

**Trait a grants** (AddLegalTextsRevision, riadky 7-22):
```php
final class AddLegalTextsRevision extends Migration
{
    use GrantsPrivileges;
    ...
    $this->db->query('GRANT INSERT ON legal_texts TO app_rw');
    $this->grantUpdateColumns('legal_texts', ['body', 'provisional', 'revision', 'updated_at']);
    $this->db->query("COMMENT ON TABLE legal_texts IS '...'");
```
Default privileges dávajú `app_rw` len SELECT, INSERT. `GrantsPrivileges.php`: `grantUpdateColumns($table, $cols)` je predvolená voľba pre editovateľné tabuľky (`fx_country_settings`: `source, date_basis, day_rule, revision, updated_at`), `grantMutable()` (UPDATE, DELETE) len pre ne-dokladové pomocné tabuľky (`exchange_rate_cache` pre `ON CONFLICT DO UPDATE`; DELETE tam netreba, preferovať `grantUpdateColumns`). `currencies`: žiadny grant navyše, a `REVOKE INSERT ... FROM app_rw` (nová mena = migrácia).

**Nahradenie CHECK -> FK** (zovšeobecniť `replaceBodyCheck`, riadky 47-59):
```php
$rows = $this->db->query(
    "SELECT conname FROM pg_constraint WHERE conrelid = 'legal_texts'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%char_length(body)%'",
)->getResultArray();
foreach ($rows as $row) {
    $this->db->query('ALTER TABLE legal_texts DROP CONSTRAINT "' . str_replace('"', '""', (string) $row['conname']) . '"');
}
```
Pre 5 tabuliek (`entities`, `clients`, `entity_bank_accounts`, `billing_allocations`, `invoices`) filter `pg_get_constraintdef(oid) LIKE '%currency%EUR%'`, potom `ADD CONSTRAINT <t>_currency_fk FOREIGN KEY (currency) REFERENCES currencies(code)`. Rieši A1 z RESEARCH (názov nehádať). Pôvodný CHECK: `currency char(3) NOT NULL CHECK (currency IN ('EUR','PLN','CZK'))` (`CreateEntities.php:21`). `down()` má CHECK vrátiť a zlyhať, ak existujú iné meny (vzor `down()` s `RuntimeException` v AddLegalTextsRevision).

**Tvar novej tabuľky** (CreatePayments 12-24): `id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY`, `created_at timestamptz NOT NULL`, `CHECK (...)`, `COMMENT ON TABLE`. `down()` robí `DROP TABLE IF EXISTS`. Pozn.: `invoices` má guard triggery proti UPDATE/DELETE; `ALTER TABLE ... ADD CONSTRAINT FK` riadky nemení, ale migrácie bežia ako `spark migrate -g owner`. Nová migrácia nikdy edit starej.

---

### `app/Libraries/Fx/FxConverter.php` (utility, transform)

**Analog:** `app/Libraries/Platform/Money.php`

**Validácia + bcround vzor** (Money 19-26, 40-47):
```php
use RoundingMode;
public static function of(string $value): self
{
    if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid money value.');
    }
    ...
}
$product = bcround(bcmul($this->amount, $hours, 4), 2, RoundingMode::HalfAwayFromZero);
return self::checked($product);
```
**Šírka numeric(12,2):** `Money::checked()` (riadky 90-100) hádže `OverflowException` nad `MAX = '9999999999.99'`. Výsledok `FxConverter::convert()` prehnať cez `Money::of()`/rovnakú kontrolu a premietnuť na `IssueRejected('fx_overflow')`. Každé `bc*` s explicitným scale, nikdy float, regexy s `/D`. Vzor funkcie je v RESEARCH.md "Prepočet a zaokrúhlenie" (bcdiv(bcmul(total, rate, 10), amount, 12) -> bcround 2).

---

### `app/Libraries/Fx/FxSettingsService.php` (service, CRUD + audit)

**Analog:** `app/Libraries/Master/LegalTextService.php`

**Ctor + imports** (riadky 5-45):
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
**Core update pattern** (riadky 105-160): validácia -> `Transaction::run($this->db, function () ...)` -> `SELECT ... FOR UPDATE` -> porovnanie `revision` (`stale`) -> no-op ak bez zmeny -> `UPDATE ... revision = revision + 1, updated_at = :now:` s `service('clock')->now()->format('Y-m-d H:i:sP')` -> audit v tej istej transakcii (`$this->audit->recordChange(...)`, podpis `recordChange(string $entityType, int $entityId, string $action, array $before, array $after): bool`). `AuditLogger::record(string, int, ...)` berie int, preto `fx_country_settings` potrebuje `id GENERATED ALWAYS AS IDENTITY` + `UNIQUE(country)` (A6). Chyby cez vlastnú výnimku s `reason` (`LegalTextRejected('stale'|'empty'|...)`).

---

### `app/Controllers/Settings/FxSettings.php` + `app/Views/settings/fx/*` (controller, request-response)

**Analog:** `app/Controllers/Settings/LegalTexts.php`

**Hlavička a PRG vzor** (LegalTexts 1-20, 91-130):
```php
final class LegalTexts extends BaseController
{
    public function index(): string { ... return view('settings/legal_texts/index', [...]); }
    public function update(int $id): RedirectResponse|ResponseInterface
    {
        ...
        try {
            $newRevision = (new LegalTextService())->update($id, $body, $provisional, $revision);
        } catch (LegalTextRejected $e) {
            if ($e->reason === 'not_found') { throw PageNotFoundException::forPageNotFound(lang('LegalTexts.notFound')); }
            $stale = $e->reason === 'stale';
            ...  // 422 + re-render formulára
        }
        session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('LegalTexts.created')]);
        if (is_htmx()) {
            return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/legal-texts')->setBody('');
        }
        return redirect()->to('/settings/legal-texts');
    }
```
Pomocné `postString()` je v `BaseController`. Whitelist hodnôt (`source`, `date_basis`, `day_rule`) overovať `in_array(..., true)`. Trasy pridať do `app/Config/Routes/settings.php` podľa riadkov 17-21:
```php
$routes->get('settings/legal-texts', 'Settings\LegalTexts::index');
$routes->get('settings/legal-texts/(:num)/edit', 'Settings\LegalTexts::edit/$1');
$routes->post('settings/legal-texts/(:num)', 'Settings\LegalTexts::update/$1');
```
Odkaz na "Kurzy" pridať na `app/Views/settings/legal_texts/index.php` alebo do `More::index`. Owner filter + CSRF sa dedí z group filtrov (overiť v `Config/Filters`).

---

### `app/Libraries/Invoicing/InvoiceDraftService.php` (modify)

**Analog:** sám seba. Zmeny:

1. Ctor: pridať `private readonly FxService $fx;` podľa riadkov 36-47 (`new X($this->db)`).
2. Podpis `draft(...)` (riadok 78) dostane posledný voliteľný parameter `?FxInput $fxInput = null` (existujúci štýl: voliteľné parametre na konci s default).
3. Nahradiť volanie (riadok ~139):
```php
if ($entity !== null) {
    // mena sa kontroluje pred vyberom bankoveho uctu, inak by sa ukazal zavadzajuci no_bank_account
    $this->guards->assertCurrency($entity, $this->guards->partsCurrency($parts));
    $snapshot = $this->builder->build($client, $entity, $parts, $month, $issue, $delivery, $due, null);
    $token    = InvoiceCalculator::previewToken($snapshot);
}
```
-> `partsCurrency` ostáva; ak `entity.currency !== currency`, `FxService::resolve(...)` (sieť, len tu) alebo ručný `FxQuote`; `RateUnavailable|RateNotFound` -> `$errors[] = ['reason' => 'fx_unavailable', 'context' => [...]]` v štýle riadku `$errors[] = ['reason' => $e->reason, 'context' => $e->context];` a `Draft` nesie stav pre UI (ručné pole). `canIssue` (podmienka `$count > 0 && $errors === [] && ... && $token !== null`) zostane automaticky false.
4. `SnapshotBuilder::build(..., ?FxQuote $fx = null)` ako posledný parameter, token sa počíta zo snapshotu (kurz je v ňom).

**Error pattern:** `catch (IssueRejected $e)` s `['reason' => ..., 'context' => ...]`; nové reasons `fx_missing`, `fx_unavailable`, `fx_invalid`, `fx_overflow` pridať do zoznamu v `app/Libraries/Invoicing/IssueRejected.php:21` (kde je dnes `'currency_mismatch'`).

---

### `app/Libraries/Invoicing/InvoiceIssuer.php` (modify)

**Analog:** sám seba. **Nikdy sieť v transakcii**, len `FxService::cached()` (SELECT) alebo POST ručný kurz.

**Krok 6** (riadky ~158-168), nahradiť `assertCurrency`:
```php
$this->guards->assertEntityActive($entity, $cmd->issueDate);
$this->guards->assertCurrency($entity, $this->guards->partsCurrency($parts));

$snapshot = $this->builder->build($client, $entity, $parts, $cmd->billingMonth, $cmd->issueDate, $cmd->deliveryDate, $cmd->dueDate, null, $cmd->paymentMethod, $cmd->paymentNote);
$this->guards->assertCurrency($entity, $snapshot['currency']);

if (! hash_equals($cmd->previewToken, InvoiceCalculator::previewToken($snapshot))) {
    throw new IssueRejected('preview_stale');
}
```
`assertCurrency` volania (2x) zmeniť na "kurz potrebný" (`fx_missing`, ak mena líši a `FxQuote` null); `FxQuote` získať pred `build`, aby `fx` bolo v snapshote pred porovnaním tokenu. `build` má už pozičné parametre `$paymentMethod, $paymentNote`, preto nový `?FxQuote` pridať na koniec.

**INSERT** (`insertInvoice`, riadky ~251-258): dnes `... total, total_entity_ccy ... :total:, :total:` (dvakrát ten istý placeholder). Zmeniť na `:total_entity:` a doplniť `fx_rate, fx_source, fx_date, fx_table_no, fx_date_basis` do zoznamu stĺpcov aj VALUES; hodnoty z `$snapshot['fx']` (nullable). `'total_entity' => $snapshot['fx']['total'] ?? $snapshot['totals']['total']`.

**Audit** (krok 13, riadky ~297-307): doplniť do poľa `after` kľúče `fx_source`, `fx_rate`, `fx_manual` (D-11):
```php
$this->audit->record('invoice', $invoiceId, 'issue', null, [
    'number' => $allocated->number, ... 'currency' => $snapshot['currency'], ... 'sha256' => $pdf->sha256, 'report_sha256' => $report->sha256,
]);
```
`IssueCommand` dostane `?FxOverride` (mode, rate, date), `IssueInput::SCALARS` + `PreviewInput::FIELDS` (`['client','month','entity','issue_date','delivery_date','due_date']`) rozšíriť o `fx_mode`, `fx_rate`, `fx_date`. PreviewInput má `pick()` whitelist cez `FIELDS` + validáciu formátu; server nikdy neprijíma zdroj/číslo tabuľky z POST.

---

### `app/Libraries/Invoicing/SnapshotBuilder.php` (modify)

**Analog:** sám seba.

- `schema` 2 -> 3 (riadok 104: `'schema' => 2,`).
- `'fx' => null,` (riadok ~169) -> `$fx?->toSnapshot(...)`.
- `LABEL_KEYS` (riadok 25) doplniť `fxRate`, `fxFrom`, `fxTable`, `fxTotalIn`, `fxManual`, `fxManualFrom` a všetky v `app/Language/{sk,pl,cs,en}/InvoicePdf.php` (labely sa čítajú `lang('InvoicePdf.' . $key, [], $docLanguage)`, riadky 97-99; chýbajúci kľúč = surový kľúč na PDF).
- Predformátované texty: `PdfFormat::amount($total, $ccy, $lg)` a `PdfFormat::date($ymd, $lg)` (PdfFormat.php 18-43, bez NumberFormatter, NBSP, symboly `€`, `zł`, `Kč`), nie `MoneyFormat` (ten závisí od ICU; PDF používa `PdfFormat`). Snapshot musí byť deterministický: žiadny `fetched_at` ani čas.
- `rounding` zostane; dokumentovať nové pravidlo prepočtu v `fx`.
- Tvar `fx` pozri RESEARCH.md "Pattern 4".

---

### `app/Views/pdf/invoice_inv2.php` (modify)

**Analog:** sám seba, riadky 129-135:
```php
<table class="totals">
    <tr><td><?= esc($l['total']) ?></td><td class="r"><?= esc(PdfFormat::amount($s['totals']['total'], $cur, $lg)) ?></td></tr>
</table>
<?php if (! empty($s['fx'])) : ?>
    <p class="small"><?= esc((string) ($s['fx']['text'] ?? '')) ?></p>
<?php endif; ?>
```
Rozšíriť o riadky `rate_line`, `date_line` a riadok v tabuľke `totals` "Spolu v PLN" z predformátovaných reťazcov snapshotu, všetko cez `esc()`. Šablóna nič nepočíta, nepreklada a nečíta živé nastavenia. Staré faktúry: `fx = null` musí ostať platné (`! empty(...)`).

---

### `app/Libraries/Invoicing/InvoiceGuards.php` (modify)

Ponechať `partsCurrency()` (riadky 31-50, D-02). `assertCurrency` (riadky 55-63) nahradiť `assertFx(array $entity, string $invoiceCurrency, ?array $fx)`:
```php
if ($entityCurrency !== $invoiceCurrency) {
    throw new IssueRejected('currency_mismatch', ['currency' => $invoiceCurrency, 'entity_currency' => $entityCurrency]);
}
```
Tvar `IssueRejected(reason, context)` zachovať; nový reason `fx_missing`. Jazykové správy: `app/Controllers/Invoicing.php:209` mapa `'currency_mismatch' => lang('Invoicing.errorCurrencyMismatch', [$text('currency'), $text('entity_currency')])` nahradiť kľúčmi `fx_missing`, `fx_unavailable`, `fx_invalid`, `fx_overflow` a v `app/Language/{sk,en}/Invoicing.php:44` nahradiť `errorCurrencyMismatch` (SK+EN existujú; PL/CS doplniť, ak majú Invoicing.php, test úplnosti prekladov v `tests/unit/I18n`).

---

### `app/Views/invoicing/_preview.php` (modify, htmx + Alpine CSP)

**Analog:** sám seba. Vzor htmx re-renderu (riadok 26):
```php
$dateAttrs = static fn (string $include): string => 'hx-get="/fragments/invoice-preview" hx-trigger="change" hx-target="#inv-preview" hx-swap="outerHTML" hx-push-url="true" hx-include="' . $include . ', #inv-payment-method, #inv-payment-note, #inv-payment-note-dirty"';
```
`#inv-payment-*` zoznam `hx-include` rozšíriť o `#inv-fx-mode, #inv-fx-rate, #inv-fx-date` (všetky preview/issue formuláre musia nosiť rovnaké polia, inak `preview_stale`). Alpine CSP: komponent `x-data="invPay"` (riadok 110) s gettermi, napr. `x-bind:hidden="isNotTransfer"` (riadok 160). Žiadne inline výrazy typu `!manual`, žiadny inline `style`; nový komponent `fxManual` s getterom `isAuto` a metódou `enable()`. Alpine komponenty sú registrované v `public/assets/js/` (nájsť `invPay`), pridať tam, nový JS s verziou v názve podľa existujúceho zvyku.

---

### Testy

**Analog (integration):** `tests/integration/Invoicing/CurrencyGuardTest.php` (musí sa prepísať na `fx_missing`)
```php
#[Group('integration')]
final class CurrencyGuardTest extends PgTestCase
{
    use InvoiceFixtures; use IssueFixtures; use MasterFixtures;
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-27T10:00:00+01:00'));
        $this->resetMasterTables();
    }
    protected function tearDown(): void { $this->resetMasterTables(); Services::reset(); parent::tearDown(); }
    private function rejectedReason(callable $fn): IssueRejected { try { $fn(); } catch (IssueRejected $e) { return $e; } $this->fail('Expected IssueRejected.'); }
    private function written(): int { /* count invoices + invoice_counters cez $this->owner() */ }
```
Test vytvára kombináciu "klient PLN pri subjekte EUR" cez `$this->owner()->query("UPDATE clients SET currency = 'PLN' ...")`. Nové testy fx vychádzajú z toho: `FakeRateHttpClient` s počítadlom volaní, assert 0 HTTP volaní pri vystavení.

**Fake namiesto siete:** `tests/_support/CapturingEmail.php` (podtrieda prepisuje `spoolEmail()` a zachytáva výstup). `FakeRateHttpClient implements RateHttpClient` s mapou URL -> `HttpResult(status, body z fixtúry)` a poľom `$calls`.

**`MasterFixtures::resetMasterTables`** (riadok 22) zoznam tabuliek rozšíriť o `exchange_rate_cache` (a nie o `currencies`/`fx_country_settings`: komentár "NIKDY nesmie obsahovať seed tabuľky z migrácií"; fx nastavenia sa po teste vrátia UPDATE-om na defaulty). Podobne neresetovať `currencies`.

---

## Shared Patterns

### bcmath/string-only
**Zdroj:** `app/Libraries/Platform/Money.php`. **Platí pre:** `FxConverter`, providery (kurz string, nikdy float; pre JSON najprv `preg_replace('/"(mid|rate)":\s*([0-9.]+)/', '"$1":"$2"', $body)` pred `json_decode`), snapshot `fx`.

### Transakcia + audit v jednej tx
**Zdroj:** `LegalTextService::update` (`Transaction::run`, `FOR UPDATE`, `revision`, audit). **Platí pre:** `FxSettingsService`.

### Nová DB tabuľka = nová migrácia + grants
**Zdroj:** `AddLegalTextsRevision.php`, `GrantsPrivileges.php`. Default `app_rw` = SELECT, INSERT; UPDATE len stĺpcovo (`grantUpdateColumns`), `grantMutable` len pomocné tabuľky.

### Odmietnutie so `reason`
**Zdroj:** `IssueRejected` (`reason`, `context`); controller mapuje na `lang()` v `Invoicing::errorMessage`. **Platí pre:** všetky nové `fx_*` odmietnutia; texty SK+EN (a PL/CS ak existujú).

### Zdroj času
`service('clock')->today()`, `->parseDate()`, `->now()` (nikdy `new DateTime()`/`date()`); testy `Services::injectMock('clock', new FrozenClock('...'))`.

### Formátovanie na PDF
**Zdroj:** `PdfFormat::amount/date` (SK/PL/CS: NBSP + čiarka, EN: čiarka + bodka). Predformátovať pri build snapshotu, šablóna iba `esc()`.

### CSP frontend
Žiadny inline `style`/`onclick`; Alpine CSP build (getery, nie výrazy); htmx `hx-get` + `hx-include`.

## No Analog Found

| File | Role | Data Flow | Reason / odporúčanie |
|------|------|-----------|----------------------|
| `app/Libraries/Fx/{Nbp,Ecb,Cnb}RateProvider.php`, `RateHttpClient`, `CurlRateHttpClient` | service | HTTP request-response | Kód nikde nevolá externé HTTP API (overené: žiadny `curlrequest` v `app/`). Použiť RESEARCH.md "Code Examples" a "Pattern 1" (`service('curlrequest', ['timeout'=>3,'connect_timeout'=>2,'http_errors'=>false])`, zachytiť `Throwable` -> `RateUnavailable`). |
| ECB CSV parser | utility | transform | `str_getcsv` + mapovanie podľa hlavičky (`TIME_PERIOD`, `OBS_VALUE`), RESEARCH tabuľka API. |
| `tests/_support/fixtures/fx/*` | fixtúry | — | Nové skutočné odpovede (NBP rozsah, NBP 400, ČNB daily, ECB csv, ECB prázdne telo). |
| `app/Libraries/Fx/FxService` negatívna cache 60 s | service | cache | Žiadny doterajší `cache()`; použiť CI4 `cache()` podľa RESEARCH Pitfall 7. |

## Metadata

**Analog search scope:** `app/Libraries/{Invoicing,Master,Platform,Audit}`, `app/Controllers/Settings`, `app/Database/Migrations`, `app/Database/Support`, `app/Views/{invoicing,pdf,settings}`, `app/Config/Routes`, `tests/_support`, `tests/integration/Invoicing`.
**Files scanned:** ~40 (priame čítanie: InvoiceDraftService, InvoiceIssuer 150-349, SnapshotBuilder 100-226, InvoiceGuards, Money, LegalTextService, LegalTexts, GrantsPrivileges, AddLegalTextsRevision, CreatePayments, invoice_inv2 120-170, CurrencyGuardTest, MasterFixtures).
**Pattern extraction date:** 2026-10-30
**Pozn.:** `Currency` enum sa používa v `ClientInput:213`, `BankAccountInput:89`, `EntityInput:269`, `Country:16-18` (ostáva `defaultCurrency`), `Views/settings/entities/_regime_fields.php:12`, `Views/settings/clients/form.php:131`, `Controllers/Settings/BankAccounts.php:195`; všetky `tryFrom`/`cases()` -> `CurrencyRepository::isActive()`/`codes()`.
