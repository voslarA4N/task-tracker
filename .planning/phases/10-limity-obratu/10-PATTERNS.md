# Phase 10: Limity obratu - Pattern Map

**Mapped:** 2026-11-02
**Files analyzed:** 33 (new/modified)
**Analogs found:** 31 / 33 (2 bez priameho analogu: `PeriodResolver`, `TurnoverCalculator` ako SUM nad `invoices`)

Pozn.: cesty relativne k `/home/voslar/projects/task-tracker`. Cisla riadkov su z aktualneho stavu `develop`.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php` | migration | CRUD (settings + seed) | `app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php` | exact |
| `app/Libraries/Reporting/LimitService.php` | service | CRUD + audit + revision | `app/Libraries/Fx/FxSettingsService.php` | exact (create/delete pridat z `BankAccountService`) |
| `app/Libraries/Reporting/OpeningTurnoverService.php` | service | CRUD upsert/delete + audit | `app/Libraries/Fx/FxSettingsService.php` | role-match |
| `app/Libraries/Reporting/LimitRejected.php` | exception | request-response | `app/Libraries/Fx/FxSettingsRejected.php` | exact |
| `app/Libraries/Reporting/LimitInput.php` | utility (validacia) | transform | `app/Libraries/Master/BankAccountInput.php` | role-match |
| `app/Libraries/Reporting/LimitSelector.php` | service | request-response (read) | `app/Libraries/Fx/FxSettingsService::forCountry/all` (cisty SELECT cez `$db->query` s `:p:`) | partial |
| `app/Libraries/Reporting/PeriodResolver.php` | utility (cista funkcia) | transform | `Clock::parseDate` (`app/Libraries/Platform/Clock.php:36`) + `Money::timesHours` (bcround) | partial, bez analogu |
| `app/Libraries/Reporting/TurnoverCalculator.php` | service | batch/aggregate (SUM) | `app/Libraries/Receivables/InvoiceStatusQueries.php` (SQL read nad `invoices`) | role-match |
| `app/Libraries/Reporting/LimitStatus.php` | value object | transform | `app/Libraries/Invoicing/Draft.php` (readonly VO) | role-match |
| `app/Controllers/Settings/Limits.php` | controller | request-response, CRUD, sheet | `app/Controllers/Settings/FxSettings.php` + `Settings/BankAccounts.php` | exact |
| `app/Config/Routes/settings.php` (mod) | route | - | tento subor, riadky 7-27 | exact |
| `app/Views/settings/limits/index.php` | view | request-response | `app/Views/settings/fx/index.php` | exact |
| `app/Views/settings/limits/_card.php` | view partial | request-response | `app/Views/home/_overdue.php` (partial s guardom) + `invoicing/_fx_card.php` | role-match |
| `app/Views/settings/limits/form.php` | view (sheet + standalone) | request-response | `app/Views/settings/entities/bank_account_form.php` + `app/Views/invoices/_send_void_sheet.php` | exact |
| `app/Views/settings/limits/delete.php`, `opening.php` | view (sheet) | request-response | `app/Views/invoices/_send_void_sheet.php` | role-match |
| `app/Views/more/index.php` (mod) | view | - | tento subor, riadok 8 | exact |
| `app/Controllers/Home.php` (mod) | controller | request-response | tento subor `data()` r. 56-66 | exact |
| `app/Views/home/_limits.php` (new), `_body.php` (mod) | view partial | request-response | `app/Views/home/_overdue.php`, `_body.php` r. 39 | exact |
| `app/Controllers/Invoicing.php::renderPreview()` (mod) | controller | request-response | tento subor r. 340-420 | exact |
| `app/Views/invoicing/_limit_warning.php` (new), `_preview.php` (mod) | view partial | request-response | `app/Views/invoicing/_fx_card.php`, `_preview.php` r. 107-109 | exact |
| `app/Language/sk/Limits.php`, `en/Limits.php`; doplnky `Dashboard.php`, `Invoicing.php` | config (lang) | - | `app/Language/sk/FxSettings.php` | exact |
| `public/assets/css/app.css` (mod) + `asset_url` verzia | config (css) | - | `.ov-row` r. 495, `.inv-warn` r. 411 | role-match |
| `tests/_support/TurnoverFixtures.php` | test fixture trait | - | `tests/_support/IssueFixtures.php` + `CreditNoteFixtures.php` | role-match |
| `tests/_support/MasterFixtures.php` (mod) | test fixture | - | tento subor r. 26 (zoznam tabuliek) | exact |
| `tests/unit/Reporting/{PeriodResolver,LimitInput,Threshold}Test.php` | test | transform | `tests/unit/Fx/FxInputTest.php`, `FxBoundDateTest.php` | role-match |
| `tests/integration/Reporting/LimitSchemaTest.php` | test | DB schema/grants | `tests/integration/Fx/FxSchemaTest.php` | exact |
| `tests/integration/Reporting/LimitServiceTest.php` | test | CRUD | `tests/integration/Fx/FxSettingsServiceTest.php` | exact |
| `tests/integration/Reporting/{TurnoverCalculator,LimitSelector}Test.php` | test | aggregate | `tests/integration/Fx/FxServiceCacheTest.php` + `IssueFixtures` | role-match |
| `tests/feature/Settings/LimitsControllerTest.php` | test | request-response | `tests/feature/Settings/FxSettingsTest.php` | exact |
| `tests/feature/DashboardLimitsTest.php`, `tests/feature/Invoicing/InvoicingLimitWarningTest.php` | test | request-response | `tests/feature/Settings/FxSettingsTest.php` (login/AuthTestCase) + existujuce `tests/feature/Invoicing/*` | role-match |

## Pattern Assignments

### `app/Database/Migrations/2026-11-02-000001_CreateTurnoverLimits.php` (migration, CRUD)

**Analog:** `app/Database/Migrations/2026-10-30-000003_CreateFxCountrySettings.php` (posledna existujuca migracia je `2026-10-31-000001_AddCreditNotes.php`, novy nazov musi byt lexikograficky vyssi).

**Header + trait** (riadky 1-18):
```php
declare(strict_types=1);
namespace App\Database\Migrations;
use App\Database\Support\GrantsPrivileges;
use CodeIgniter\Database\Migration;
final class CreateFxCountrySettings extends Migration
{
    use GrantsPrivileges;
    public function up(): void
```

**Core: CREATE TABLE + seed INSERT + granty + COMMENT** (riadky 20-39):
```php
$this->db->query("CREATE TABLE fx_country_settings (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    country char(2) NOT NULL UNIQUE CHECK (country IN ('SK','PL','CZ')),
    ...
    revision integer NOT NULL DEFAULT 1 CHECK (revision >= 1),
    updated_at timestamptz NOT NULL
)");
$this->db->query("INSERT INTO fx_country_settings (...) VALUES (... now())");
$this->db->query('REVOKE INSERT, DELETE ON fx_country_settings FROM app_rw');
$this->grantUpdateColumns('fx_country_settings', ['source', 'date_basis', 'day_rule', 'revision', 'updated_at']);
$this->db->query("COMMENT ON TABLE fx_country_settings IS '...'");
```
**Rozdiel pre Phase 10:** NEvolat `REVOKE INSERT` (limity aj opening sa vytvaraju z UI; default privileges z `docker/postgres/provision.sql` davaju `app_rw` SELECT, INSERT). Pridat `grantUpdateColumns(...)` + `$this->grantDelete('turnover_limits')` a rovnako pre `entity_opening_turnover` (D-06, mazanie povolene). Seed: `INSERT ... SELECT ... WHERE NOT EXISTS (SELECT 1 FROM turnover_limits)` s `is_seed = true`. `down()` = `DROP TABLE IF EXISTS` (r. 42-45). Index `invoices_turnover_idx ON invoices (entity_id, turnover_date) WHERE counts_toward_limit AND origin = 'issued'` v tej istej migracii (owner smie). Tabulky: FK `currency char(3) REFERENCES currencies(code)` (vzor `2026-10-30-000001_CreateCurrencies.php`), `entity_opening_turnover.entity_id REFERENCES entities(id)`.

---

### `app/Libraries/Reporting/LimitService.php` (service, CRUD + audit + revision)

**Analog:** `app/Libraries/Fx/FxSettingsService.php`

**Imports + ctor DI** (riadky 7-39):
```php
use App\Libraries\Audit\AuditLogger;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

protected readonly BaseConnection $db;
protected readonly AuditLogger $audit;
public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
{
    $this->db    = $db ?? Database::connect();
    $this->audit = $audit ?? new AuditLogger($this->db);
}
```

**Read s parametrizovanym dotazom a mapovanim** (r. 50-68, 158-168): `$this->db->query('SELECT * FROM x WHERE country = :c:', ['c' => $country])->getRowArray()`; privatne `map(array $r)` pretypuje `(int) $r['id']`, `(string) ...`.

**Transakcia + FOR UPDATE + revision + audit** (r. 105-150):
```php
$now = service('clock')->now()->format('Y-m-d H:i:sP');
return Transaction::run($this->db, function () use ($rows, $now): bool {
    $current = $this->db->query('SELECT * FROM fx_country_settings ORDER BY id FOR UPDATE')->getResultArray();
    ...
    if ((int) $rows[$country]['revision'] !== $byCountry[$country]['revision']) {
        throw new FxSettingsRejected('stale');
    }
    $this->db->query('UPDATE fx_country_settings SET source = :s:, ..., revision = revision + 1, updated_at = :now: WHERE id = :id:', [...]);
    $this->audit->recordChange('fx_settings', $old['id'], 'update', [... before ...], [... after, 'revision' => $old['revision'] + 1]);
```
**Pre create/delete:** `AuditLogger::record($entityType, $id, $action, $before|null, $after|null)` (`app/Libraries/Audit/AuditLogger.php:38`), `entity_type` regex `^[a-z_]{3,40}$`: pouzit `turnover_limit`, `entity_opening_turnover`. Create: `before=null`; delete: `after=null`. `recordChange` vracia false ak sa nic nezmenilo. `scrub()` vyhadzuje kluce `password|secret|token`, tu netreba.
**Pozor `thresholds int[]`:** zapis ako literal `'{80,100}'` s `::integer[]`; citanie `explode(',', trim($s, '{}'))` (A2 - overit v prvom integracnom teste). Duplicitu `(country, year, name)` chytat `DatabaseException` na UNIQUE ako v `BankAccounts::duplicateOrRethrow` (`str_contains($e->getMessage(), '<constraint>')`).

---

### `app/Libraries/Reporting/OpeningTurnoverService.php` (service, upsert/delete)

**Analog:** `app/Libraries/Fx/FxSettingsService.php` (rovnaky vzor `Transaction::run` + `FOR UPDATE` + `revision`), logika upsertu: `SELECT ... WHERE entity_id=:e: AND year=:y: FOR UPDATE`; ak chyba -> INSERT + `audit->record(..., null, $after)`; ak prazdna suma -> DELETE + `audit->record(..., $before, null)`; inak UPDATE s kontrolou `revision`. Mena sa NEPRIJIMA z formulara (berie sa z `entities.currency`).

---

### `app/Libraries/Reporting/LimitRejected.php` (exception)

**Analog:** `app/Libraries/Fx/FxSettingsRejected.php` (cely subor, 21 riadkov):
```php
final class FxSettingsRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $fields = [])
    {
        parent::__construct('FX settings change rejected: ' . $reason);
    }
}
```
Reasons: `invalid`, `stale`, `not_found`, `duplicate`.

---

### `app/Libraries/Reporting/LimitInput.php` (utility, validacia/normalizacia)

**Analog:** `app/Libraries/Master/BankAccountInput.php` (staticke `fromPost(IncomingRequest)`, `normalize(array)`, `validate(array): array<string,string>` vracia mapu `pole => text chyby`; pouziva ho `Settings\BankAccounts` r. 31-34). Peniaze validovat rovnakym regexom ako `Money::of` (`/^-?\d{1,10}(\.\d{1,2})?$/D`, `app/Libraries/Platform/Money.php:26`) a nasledne `Money::of()`; prahy "80, 100" -> `int[]` (1-3 hodnoty, 1-200) v PHP (CHECK s subquery v PG nejde, A1).

---

### `app/Libraries/Reporting/TurnoverCalculator.php` + `LimitSelector.php` + `PeriodResolver.php` + `LimitStatus.php` (core domain, bez priameho analogu)

**Analog pre SQL read + `Clock` + bcmath:** `app/Libraries/Receivables/InvoiceStatusQueries.php` (volanie `overdue(service('clock')->today(), ...)` z `Home::data()`); `Money` (`app/Libraries/Platform/Money.php`), `Clock` (`app/Libraries/Platform/Clock.php`).

**Clock strict date** (`Clock.php:36-41`):
```php
public function parseDate(string $iso): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d') === $iso ? $date : null;
}
```
**Zaokruhlenie** (`Money.php:49-56`): `bcround(bcmul($this->amount, $hours, 4), 2, RoundingMode::HalfAwayFromZero)`; `Money::of`, `plus`, `minus`, `negate`, `compare`, `toDecimalString`.

**Kľúčové stĺpce, ktoré SUM číta** (zapisuje `InvoiceIssuer.php:280` `'total_entity_ccy' => $snapshot['fx']['total'] ?? $snapshot['totals']['total']`; dobropis `CreditNoteIssuer.php:223,272-281` negovaný `total_entity_ccy`, `turnover_date` a `counts_toward_limit` originálu). Dotaz (z RESEARCH Pattern 2):
```php
$row = $db->query(
    "SELECT COALESCE(SUM(total_entity_ccy), 0) AS total,
            COUNT(*) FILTER (WHERE type = 'invoice') AS invoices,
            COUNT(*) FILTER (WHERE type = 'credit_note') AS credit_notes
     FROM invoices
     WHERE entity_id = :e: AND counts_toward_limit AND origin = 'issued'
       AND turnover_date BETWEEN :from: AND :to:", [...])->getRowArray();
```
Nefiltrovat `status = 'cancelled'`, nikdy nečítať `snapshot`/`exchange_rate_cache`. Ak sa niekde číta boolean zo stĺpca do PHP, použiť `DbBool::of()` (`SnapshotBuilder.php:154`).

**Subjekty:** `EntityDirectory::listAll($today)` (`app/Libraries/Master/EntityDirectory.php:34`) vracia `id, name_internal, country, currency, active_from, active_to, vat_regime, status`; filtrovat `status === 'active'`; `trim()` na `country`/`currency` (char stlpce).

**Presne porovnanie prahov** (RESEARCH Pattern 5): `bccomp(bcmul($total,'100',4), bcmul((string)$threshold,$limit,4), 4) >= 0`, percento na zobrazenie `bcround(bcdiv(bcmul($total,'100',4),$limit,6),1,RoundingMode::HalfAwayFromZero)`.

**LimitStatus tvar:** `final readonly class` s `public` ctor parametrami podla `Draft.php:21-47` (VO s verejnymi readonly polami) a navrh v RESEARCH "Code Examples".

---

### `app/Controllers/Settings/Limits.php` (controller, CRUD + sheet)

**Analogy:** `app/Controllers/Settings/FxSettings.php` (409/422/flash/HX-Location) a `app/Controllers/Settings/BankAccounts.php` (sheet formulare, `entityOr404`, `duplicateOrRethrow`).

**Imports** (FxSettings r. 7-11): `App\Controllers\BaseController`, `CodeIgniter\HTTP\RedirectResponse`, `CodeIgniter\HTTP\ResponseInterface`; z BankAccounts: `CodeIgniter\Exceptions\PageNotFoundException`, `CodeIgniter\HTTP\IncomingRequest`, `CodeIgniter\Database\Exceptions\DatabaseException`.

**Validacia 422 / 409 / uspech** (FxSettings r. 36-56):
```php
try {
    $service->updateAll($rows);
} catch (FxSettingsRejected $e) {
    if ($e->reason === 'stale') {
        return $this->response->setStatusCode(409)->setBody(view('settings/fx/index', $this->data(null, true, [], null)));
    }
    return $this->response->setStatusCode(422)->setBody(view(..., $this->data($rows, false, $e->fields, lang('FxSettings.errorInvalid'))));
}
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('FxSettings.saved')]);
if (is_htmx()) {
    return $this->response->setStatusCode(200)->setHeader('HX-Location', '/settings/fx')->setBody('');
}
return redirect()->to('/settings/fx');
```

**Sheet vs stranka (new/edit)** (`BankAccounts::newForm/create`, r. 20-47): `$request = $this->request; assert($request instanceof IncomingRequest);` potom `Input::normalize(Input::fromPost($request,...))`, `$errors = Input::validate($values)`; pri chybach `setStatusCode(422)->setBody(view(form, formData(...)))`. Sheet fragment: `is_htmx_fragment_request()` + `htmx_view($fragment, $data, $fullPageView)` (vzor `InvoiceDelivery.php:204`: `$sheet && is_htmx_fragment_request() ? view('..._sheet', $data) : htmx_view('..._form', $data, '..._page')`).
Nenajdene: `PageNotFoundException` pre neexistujuce id (IDOR/404, RESEARCH Security).

---

### `app/Config/Routes/settings.php` (route, mod)

**Analog:** rovnaky subor, r. 20-27:
```php
$routes->get('settings/legal-texts/new', 'Settings\LegalTexts::new');
$routes->post('settings/legal-texts', 'Settings\LegalTexts::create');
$routes->get('settings/legal-texts/(:num)/edit', 'Settings\LegalTexts::edit/$1');
$routes->post('settings/legal-texts/(:num)', 'Settings\LegalTexts::update/$1');
$routes->get('settings/fx', 'Settings\FxSettings::index');
$routes->post('settings/fx', 'Settings\FxSettings::update');
```
Pridat za `settings/fx`: `settings/limits`, `/new`, `/opening` (GET+POST) **pred** `(:num)` trasami (`/(:num)/edit`, `/(:num)` POST, `/(:num)/delete` GET+POST).

---

### `app/Views/settings/limits/index.php` (view, SSR)

**Analog:** `app/Views/settings/fx/index.php`

**Hlavicka, layout, sekcie** (r. 1-30):
```php
<?= $this->extend('layouts/app') ?>
<?= $this->section('subtitle') ?><?= esc(lang('FxSettings.title')) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<p class="mb-2"><a href="/more"><?= esc(lang('FxSettings.backToMore')) ?></a></p>
<h1 class="h4 mb-1"><?= esc(lang('FxSettings.title')) ?></h1>
<p class="small text-body-secondary"><?= esc(lang('FxSettings.intro')) ?></p>
<div class="alert alert-secondary small" role="status"><?= esc(lang('FxSettings.provisional')) ?></div>
<?php if ($stale) : ?><div class="alert alert-warning" role="alert">...</div><?php endif ?>
```
Dokumentacny `@var` blok na zaciatku, `esc(lang(...))` vsade, `csrf_field()`, `data-submit-once novalidate` na formulari. Odkaz "Spat na Viac" `<p class="mb-2"><a href="/more">`.

---

### `app/Views/settings/limits/form.php`, `delete.php`, `opening.php` (sheet formulare)

**Analogy:** pole-vzor `app/Views/settings/entities/bank_account_form.php`, sheet obal `app/Views/invoices/_send_void_sheet.php`.

**Pole s validaciou** (bank_account_form.php r. 30-36):
```php
$field = static fn (string $k): string => esc((string) ($values[$k] ?? ''), 'attr');
<div class="mb-3">
    <label class="form-label" for="f-label"><?= esc(lang('Entities.bankLabel')) ?></label>
    <input class="form-control<?= isset($errors['label']) ? ' is-invalid' : '' ?>" type="text" id="f-label" name="label" value="<?= $field('label') ?>" autocomplete="off">
    <?php if (isset($errors['label'])) : ?><div class="invalid-feedback"><?= esc($errors['label']) ?></div><?php endif ?>
</div>
```
Select: `<select class="form-select..."><option value="<?= esc($c,'attr') ?>"<?= $sel ? ' selected' : '' ?>>`. Pata: `<div class="master-sticky-actions master-actions"><a class="btn btn-outline-secondary btn-touch" ...>Zrusit</a><button type="submit" class="btn btn-primary btn-touch">...`.

**Offcanvas sheet obal** (`_send_void_sheet.php` r. 9-17):
```php
<div class="offcanvas offcanvas-bottom work-sheet dl-sheet" tabindex="-1" id="work-sheet" aria-labelledby="work-sheet-title">
    <div class="offcanvas-header px-0 pt-0">
        <h2 class="offcanvas-title h5 mb-1" id="work-sheet-title">...</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="..."></button>
    </div>
    <div class="offcanvas-body px-0 pb-0" id="sheet-body"><?= view('invoices/_send_void_form', get_defined_vars()) ?></div>
</div>
```
Cielovy kontajner pre htmx: `hx-get=... hx-target="#sheet" hx-swap="innerHTML"` (vzor `home/_overdue.php` r. 38). Pri ukladani `data-submit-once`, hidden `revision`.

---

### `app/Views/more/index.php` (mod)

Pridat po riadku 8 (`FxSettings.navTitle`), rovnaky vzor:
```php
<a class="list-group-item list-group-item-action more-item" href="/settings/fx"><?= esc(lang('FxSettings.navTitle')) ?></a>
<a class="list-group-item list-group-item-action more-item" href="/settings/limits"><?= esc(lang('Limits.navTitle')) ?></a>
```

---

### `app/Controllers/Home.php` (mod) + `app/Views/home/_limits.php` + `_body.php` (mod)

**Analog:** `Home::data()` r. 56-66 a `home/_overdue.php`.

**Pridat do `data()` pola** (Home.php r. 59-65): `'limits' => (new TurnoverCalculator())->alertsForActiveEntities(service('clock')->today()),` (limity su za subjekt, NEpouzivat `$filter->clientId`).

**Guard partial + trieda** (`_overdue.php` r. 11-14, 17-19):
```php
if ($overdue['rows'] === []) { return; }
...
<section class="work-group" aria-labelledby="overdue-title">
    <h2 id="overdue-title"><?= esc(lang('Dashboard.overdueTitle')) ?> <span class="badge text-bg-danger"><?= (int) $overdue['total'] ?></span></h2>
    <div class="app-card p-0 overflow-hidden">
        ... <a class="ov-row" href="/invoices/<?= $id ?>"> ...
```
**Vlozenie do `_body.php`** (r. 38-40): pridat `@var array<string,mixed> $limits` do docblocku a pred `view('home/_overdue', ...)` riadok `<?= view('home/_limits', ['limits' => $limits]) ?>`. Peniaze `MoneyFormat::format((string)$amount, (string)$currency)` (`use App\Libraries\Platform\MoneyFormat;`).

---

### `app/Controllers/Invoicing.php::renderPreview()` (mod) + `app/Views/invoicing/_limit_warning.php` + `_preview.php` (mod)

**Analog:** `renderPreview()` r. 340-420, `_fx_card.php`, `_preview.php` r. 107-109.

**Miesto vlozenia v controlleri** (po `$draft = $service->draft(...)`, r. ~385-397, pred `$data['draft'] = $draft;`):
```php
if (($draft->fx['state'] ?? null) === 'invalid') { $forcedStatus = 422; }
$data['draft']      = $draft;
```
Pridat `$data['limitWarning'] = $this->limitWarning($draft);` (inicializovat `'limitWarning' => null` v poli `$data` r. 350-364). Privatna metoda: `try { ... } catch (Throwable) { return ['error' => 'failed']; }` (nikdy neblokovat). Suma v mene subjektu rovnako ako `InvoiceIssuer.php:280`: `$draft->snapshot['fx']['total'] ?? $draft->snapshot['totals']['total']`; priznak klienta `$draft->snapshot['client']['counts_toward_limit']` (`SnapshotBuilder.php:154`). `Draft` polia: `entityId`, `deliveryDate`, `snapshot` (nullable), `canIssue`, `fx` (r. 21-47). Overit A6: `snapshot` pri `canIssue=false`.

**View vlozenie** (`_preview.php` r. 107-109):
```php
$fxState = $draft->fx['state'] ?? 'ok';
echo view('invoicing/_fx_card', ['draft' => $draft, 'locale' => $locale]);
```
Pridat za to `echo view('invoicing/_limit_warning', ['limitWarning' => $limitWarning ?? null, ...]);` a `@var` v docblocku. `_limit_warning.php` zacina guardom `if ($limitWarning === null) { return; }` (vzor `_fx_card.php` r. 21-23: `if ($fx === null) { return; }`).

---

### `app/Language/sk/Limits.php` (+ `en/Limits.php`, doplnky `Dashboard.php`, `Invoicing.php`)

**Analog:** `app/Language/sk/FxSettings.php`: `declare(strict_types=1); return ['navTitle' => ..., 'title' => ..., 'intro' => ..., 'saved' => ..., 'errorStale' => ..., 'countrySK' => 'Slovensko (SK)' ...];`. Kluce musia byt v `sk` aj `en` (`tests/unit/I18n/LangKeysTest.php`). Znovupouzit `FxSettings.countrySK|PL|CZ` pre krajiny. Texty podla "Copywriting Contract" v 10-UI-SPEC.md.

---

### `public/assets/css/app.css` (mod)

**Analog:** `.ov-row` r. 495 (`display:block; min-height:64px; padding:16px; overflow-wrap:anywhere`), `.inv-warn` r. 411. Pridat `.lim-card/.lim-card--warn/--crit`, `.lim-bar`, `.lim-formula`, `.lim-draft`, `.lim-row` podla UI-SPEC; zvysit verziu v `asset_url` (ako v Phase 9). Ziadny inline `style` (`ViewsCspTest`), sirku progress nesie `value`.

---

### Testy

**`tests/integration/Reporting/LimitSchemaTest.php`** - analog `tests/integration/Fx/FxSchemaTest.php`:
```php
#[Group('integration')]
final class FxSchemaTest extends PgTestCase
{
    use MasterFixtures;
    protected function setUp(): void { parent::setUp(); $this->resetMasterTables(); }
    ...
    $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE fx_country_settings SET country = 'XX' WHERE country = 'PL'"));
```
Seed kontrola cez `$this->owner()->query(...)->getResultArray()`; granty cez `$this->rw()` (BEGIN/ROLLBACK okolo povoleneho UPDATE).

**`TurnoverFixtures` trait** - analog `tests/_support/IssueFixtures.php` (`issueSetup(['country'=>..., 'parts'=>..., 'client'=>[...]])` r. 31, `issueCommand(...)` r. 75 s override `deliveryDate`, `issuer()` r. 102) a `CreditNoteFixtures::issueCreditNote($originalId)` (r. 138) / `insertRawCreditNote` (r. 28, raw INSERT cez `Transaction::run($db,...)` a `SET CONSTRAINTS ALL IMMEDIATE`). Pre hranicne datumy je rychlejsi raw `INSERT INTO invoices` pod ownerom (splnit CHECK `invoices_origin_shape` + deferred `invoice_complete_check`, vyzaduje `invoice_lines` a `invoice_documents`).

**`MasterFixtures::resetMasterTables`** (mod): pridat `'entity_opening_turnover'` do zoznamu tabuliek (r. 26, pred `'entities'`). `turnover_limits` tam NEPRIDAVAT (zmazal by seed, na ktory sa mozu spoliehat ine testy); testy limitov si robia `DELETE FROM turnover_limits` cez `$this->owner()` v setUp/tearDown.

**`tests/feature/Settings/LimitsControllerTest.php`** - analog `tests/feature/Settings/FxSettingsTest.php`: `extends AuthTestCase`, `use MasterFixtures`, `#[Group('feature')]`, helper `login()` = `createUser(); enrollTotp($user); loginAs($user);`, `body(TestResponse $r)`.

**Unit testy** (`PeriodResolverTest`, `ThresholdTest`, `LimitInputTest`): bez DB, vzor `tests/unit/Fx/FxInputTest.php`; `FrozenClock` (`app/Libraries/Platform/FrozenClock.php`) pre fixny dnes.

---

## Shared Patterns

### Audit (D-06)
**Source:** `app/Libraries/Audit/AuditLogger.php:38,68`
**Apply to:** `LimitService`, `OpeningTurnoverService`
`$this->audit->record('turnover_limit', $id, 'create'|'delete', $before|null, $after|null)`; `recordChange(...)` pri update (vracia false pri nezmene). `entity_type` ^[a-z_]{3,40}$.

### Transakcie a optimisticky zamok
**Source:** `app/Libraries/Fx/FxSettingsService.php:107-150`, `Transaction::run($db, fn)` + `SELECT ... FOR UPDATE` + `revision` -> `LimitRejected('stale')` -> HTTP 409.

### Peniaze a datumy bez float
**Source:** `app/Libraries/Platform/Money.php`, `Clock.php`, `MoneyFormat::format($amount, $ccy)`
**Apply to:** `TurnoverCalculator`, `PeriodResolver`, vsetky views. Nikdy `date('Y')`, `CURRENT_DATE`, `(float)`; vzdy `service('clock')`. Test `TimezoneConsistencyTest` to vynucuje.

### Parametrizovane dotazy a char(n) trim
**Source:** `FxSettingsService.php:50` (`:c:` binding); `trim((string) $entity['currency'])`.
**Apply to:** vsetky `$db->query` v `Reporting/*`.

### Flash + PRG + htmx redirect
**Source:** `app/Controllers/Settings/FxSettings.php:50-56` (`session()->setFlashdata('app_notice', [...])`, `is_htmx()` -> `HX-Location`, inak `redirect()->to`).
**Apply to:** `Settings\Limits` (create/update/delete/opening).

### Views: CSP, texty, escape
**Source:** `esc(lang('X.key'))` vsade, ziadny inline `style`/`onclick`, `csrf_field()`, `data-submit-once`, `novalidate`. Testy `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest` pokryju nove views automaticky.
**Apply to:** vsetky nove views.

### Partial s guardom pri prazdnom stave
**Source:** `app/Views/home/_overdue.php:11-14`, `app/Views/invoicing/_fx_card.php:21-23`
**Apply to:** `home/_limits.php`, `invoicing/_limit_warning.php`.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Libraries/Reporting/PeriodResolver.php` | utility | transform (datumove okna, pomerny limit) | Ziadna existujuca cista datumova logika okien; pouzit RESEARCH Pattern 3 (first day of this month pred `-11 months`, `bcround` half-up, cap dni na 365 [A3]) |
| `app/Libraries/Reporting/TurnoverCalculator.php` (SUM agregacia) | service | aggregate | Existujuce `InvoiceStatusQueries` je len blizky role-match; agregaciu `SUM total_entity_ccy` nad `invoices` nikto zatial nerobi, dotaz je v RESEARCH Pattern 2 |

Pole `integer[]` (`thresholds`) nema v projekte existujuci analog (zatial ziadna `int[]` kolona); rozhodnutie o zapise/citani riesit prvym integracnym testom (A2).

## Metadata

**Analog search scope:** `app/Libraries/{Fx,Master,Audit,Platform,Invoicing,Receivables}`, `app/Controllers/{Settings,Home,Invoicing}`, `app/Views/{settings,home,invoicing,invoices,more}`, `app/Database/{Migrations,Support}`, `app/Config/Routes`, `app/Language/sk`, `tests/{_support,unit,integration,feature}`, `public/assets/css/app.css`
**Files scanned:** ~35 (precitanych priamo ~25)
**Pattern extraction date:** 2026-11-02
