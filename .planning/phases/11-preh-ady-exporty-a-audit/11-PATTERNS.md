# Phase 11: Prehľady, exporty a audit - Pattern Map

**Mapped:** 2026-11-04
**Files analyzed:** 27 (new/modified)
**Analogs found:** 24 / 27 (3 sú čisto nové: OpenSpout wrapper, ZIP builder, FormulaGuard)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Libraries/Reporting/ReportCalculator.php` | service | CRUD (read agregácia) | `app/Libraries/Reporting/TurnoverCalculator.php` | exact |
| `app/Libraries/Reporting/ReportPeriod.php` | utility (VO) | transform | `app/Libraries/Reporting/PeriodResolver.php` | role-match |
| `app/Libraries/Reporting/ReportResult.php` | model (readonly VO) | transform | `app/Libraries/Reporting/EntityLimits.php` | role-match |
| `app/Libraries/Reporting/BundleSelector.php` | service | CRUD (read) | `TurnoverCalculator` + `InvoiceQueries::list` | role-match |
| `app/Libraries/Export/CsvWriter.php`, `FormulaGuard.php`, `XlsxWriter.php`, `XlsxNumber.php`, `ExportFilename.php` | utility | file-I/O / transform | `DocumentFilename.php` (štýl), `Money.php` (VO) | partial |
| `app/Libraries/Export/AccountantBundle.php` | service | file-I/O | `Invoices::serve` + `DocumentStore::read` | partial |
| `app/Libraries/Audit/AuditQueries.php` | service | CRUD (read) | `InvoiceQueries::list` (LIMIT+1) + `TaskQueries::list` (filtre) | role-match |
| `app/Libraries/Auth/UserDirectory.php` | service | CRUD (read) | `InvoiceQueries` (štruktúra) | role-match |
| `app/Libraries/Invoicing/InvoiceQueries.php` (mod) | service | CRUD | seba | exact |
| `app/Libraries/Work/TaskQueries.php` + `TaskFilter` (mod) | service | CRUD | seba | exact |
| `app/Controllers/Reports.php` | controller | request-response + file download | `app/Controllers/Invoices.php` | exact |
| `app/Controllers/Settings/Audit.php`, `Settings/Users.php` | controller | request-response | `app/Controllers/Settings/Limits.php` | exact |
| `app/Config/Routes/reports.php`, `settings.php` (mod), `invoices.php` (mod) | route | - | `app/Config/Routes/invoices.php`, `settings.php` | exact |
| `app/Views/reports/*`, `settings/audit/*`, `settings/users/*` | view | request-response | `app/Views/invoices/index.php`, `settings/limits/index` | role-match |
| `app/Views/invoices/_list.php`, `tasks/_list.php` (mod) | view (fragment) | request-response | `app/Views/invoices/_list.php` | exact |
| `app/Views/more/index.php` (mod) | view | - | seba | exact |
| `app/Language/{sk,en}/{Reports,Export,AuditLog,Users}.php` | config (i18n) | - | `app/Language/sk/Limits.php` | exact |
| `tests/_support/PhpStan/ForbiddenCallsRule.php` + selftest (mod) | test/config | - | seba | exact |
| `tests/integration/Reporting/Report*Test.php` | test | - | `tests/integration/Reporting/TurnoverCalculatorTest.php` | exact |
| `tests/feature/Reports/*`, `Settings/AuditViewerTest.php`, `UsersListTest.php` | test | - | `tests/feature/Settings/LimitsControllerTest.php` | exact |
| `tests/unit/Export/*` | test | - | `tests/unit/Reporting/*` | role-match |
| `tests/_support/ReportFixtures.php`, `XlsxReader.php` | test support | - | `tests/_support/TurnoverFixtures.php` | role-match |

## Pattern Assignments

### `app/Libraries/Reporting/ReportCalculator.php` (service, read agregácia)

**Analog:** `app/Libraries/Reporting/TurnoverCalculator.php`

**Štruktúra, DI a imports** (lines 1-33):
```php
declare(strict_types=1);
namespace App\Libraries\Reporting;
use App\Libraries\Platform\Money;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class TurnoverCalculator
{
    /** @var BaseConnection<mixed, mixed> */
    private readonly BaseConnection $db;
    /** @param BaseConnection<mixed, mixed>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
```

**Core SQL + Money normalizácia** (lines 38-55) – rovnaké stĺpce (`total_entity_ccy`, `turnover_date`, `origin='issued'`), bind `:e:`, výstup cez `Money::of(...)->toDecimalString()`:
```php
$row = $this->db->query(
    "SELECT COALESCE(SUM(total_entity_ccy), 0) AS total, ...
     FROM invoices
     WHERE entity_id = :e: AND counts_toward_limit AND origin = 'issued'
       AND turnover_date BETWEEN :from: AND :to:",
    ['e' => $entityId, 'from' => $from, 'to' => $to],
)->getResultArray();
return ['total' => Money::of((string) ($row['total'] ?? '0'))->toDecimalString(), ...];
```
Súčty cez `Money::sum(...$amounts)->toDecimalString()` (line 81). Pridať `FILTER (WHERE counts_toward_limit)` stĺpec `counted` (RESEARCH Pitfall 2). Delenie/násobenie podielu úhrady NESMIE byť v tomto súbore (ForbiddenCallsRule), dať do `Money::proportion` alebo `ReportMath.php` pridaného do `BC_ALLOWED_FILES`. Hranice mesiaca bez `date()`/`DateTimeImmutable`: SQL `interval` alebo reťazce (Pitfall 10).

---

### `app/Libraries/Audit/AuditQueries.php` (service, read + stránkovanie)

**Analog:** `app/Libraries/Invoicing/InvoiceQueries.php::list` (LIMIT+1) a `TaskQueries::list` (dynamické WHERE)

**LIMIT+1 / truncated pattern** (InvoiceQueries lines 46-74):
```php
$binds = ['today' => $today, 'n' => $limit + 1];
... ORDER BY ... LIMIT :n:
$truncated = count($rows) > $limit;
return ['rows' => array_slice($rows, 0, $limit), 'truncated' => $truncated];
```
**Dynamické WHERE s bindmi** (TaskQueries lines 55-82): `$where[]` + `$params[...]`, `implode(' AND ', $where)`, LIKE escape cez `self::escapeLike`. Pre sort/dir len whitelist mapa (nikdy interpolácia vstupu).
**Zápis denníka** (AuditLogger lines 38-62): stĺpce `occurred_at, user_id, entity_type, entity_id, action, before_data, after_data, ip` (jsonb stringy, `ip='cli'` pre CLI, `user_id` môže byť NULL). `recordChange` ukladá iba zmenené kľúče (diff), takže detail musí zvládnuť kľúč len na jednej strane. `scrub` už odstránil citlivé kľúče.

---

### `app/Controllers/Reports.php` (controller, stránky + download)

**Analog:** `app/Controllers/Invoices.php`

**Imports + final class + htmx_view** (lines 5-34):
```php
final class Invoices extends BaseController
{
    public function index(): ResponseInterface
    {
        ...
        $body = htmx_view('invoices/_list', [...], 'invoices/index');
        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }
```
**Download odpoveď cez `setBody` + pevné hlavičky** (lines 114-125) – použiť pre CSV/XLSX/ZIP (nie `DownloadResponse`, nie `openToBrowser`):
```php
return $this->response
    ->setStatusCode(200)
    ->setHeader('Content-Type', 'application/pdf')
    ->setHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"')
    ->setHeader('Cache-Control', 'private, no-store')
    ->setHeader('X-Content-Type-Options', 'nosniff')
    ->setHeader('Content-Length', (string) strlen($doc->bytes))
    ->setBody($doc->bytes);
```
**Chyba integrity PDF** (lines 96-102): `catch (DocumentIntegrityError $e)` -> `log_message('critical', ...)` -> 500; v balíku nevytvoriť čiastočný ZIP. 404: `throw PageNotFoundException::forPageNotFound(lang('...'))`.
**Dátumy a „dnes“:** `service('clock')->today()`; `dateFormatter()` (lines 128-131) s `IntlDateFormatter`, pre mesiac pattern `LLLL yyyy`.
**Čítanie PDF do ZIP:** `(new DocumentStore())->read($id, 'invoice')` (line 97) vracia `StoredDocument` (`->bytes`, `->sha256`); názov `DocumentFilename::for($isCreditNote ? 'credit_note' : 'invoice', trim((string) $row['doc_language']), (string) $row['number'])` (line 114). `meta()` vracia `byte_size` bez načítania bajtov (kontrola stropu pred stavbou).

---

### `app/Controllers/Settings/Audit.php`, `Settings/Users.php` (controller, read-only)

**Analog:** `app/Controllers/Settings/Limits.php` (lines 1-45)
```php
namespace App\Controllers\Settings;
use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;
final class Limits extends BaseController
{
    public function index(): string
    {
        return view('settings/limits/index', [ ..., 'locale' => $this->request->getLocale(), 'today' => $today ]);
    }
```
Komentárový štýl: slovenský docblock s odkazom na požiadavku. Prístup: `AuthGate` už vyžaduje `admin` pre všetko mimo whitelistu (AuthGate line 61), preto nová trasa NESMIE byť v `ALLOW_EXACT`/`ALLOW_PREFIX`; žiadny extra filter.

---

### Routes (`reports.php` nový, `settings.php`, `invoices.php`)

**Analog:** `app/Config/Routes/settings.php` a `invoices.php`
```php
use CodeIgniter\Router\RouteCollection;
/** @var RouteCollection $routes */
$routes->get('settings/limits', 'Settings\Limits::index');
$routes->get('invoices/(:num)', 'Invoices::show/$1');
```
Statické trasy pred `(:num)` (napr. `invoices.csv`/`invoices/export` pred `invoices/(:num)`). Overiť, ako sa `Routes/*.php` načítavajú v `app/Config/Routes.php`. Len GET (denník aj prehľady read-only).

---

### `app/Views/invoices/_list.php` (úprava: tabuľka + karty v jednom fragmente, D-14)

**Analog:** seba (79 riadkov). Zachovať `<div id="invoice-list">`, pomocné premenné (`$rows`, `$truncated`, `$status`), empty states (lines 15-24) a karty `inv-row` (lines 26-74). Karty zabaliť do `<div class="dt-only-sm">`, pridať `<div class="dt-only-lg"><table class="dt-table">` nad tým istým `$rows`. Texty cez `esc(lang('...'))`, peniaze `MoneyFormat::format((string) $row['total'], (string) $row['currency'])` (line 65), stav-badge `match` (lines 35-42) znovupoužiť pre bunku tabuľky. Žiadny `style=`/`on*=` (`ViewsCspTest`).

**Filter formulár (htmx)** – `app/Views/invoices/index.php` lines 18-29:
```php
<form class="d-flex align-items-end gap-2 mb-3" method="get" action="/invoices" hx-get="/invoices" hx-trigger="change, submit" hx-target="#invoice-list" hx-swap="outerHTML" hx-push-url="true">
    <select class="form-select btn-touch" id="inv-status" name="status"> ...
<noscript><button ...>
```
Triediteľné hlavičky: odkazy `hx-get` + `hx-push-url` s rovnakým `hx-target`. Rozšírenie `Invoices::index` o `sort/dir/client/q/from/to` (whitelist) a o export (`InvoiceQueries::list` s `limit` null/veľký, rovnaký filter).

---

### `app/Views/more/index.php` (mod)

**Analog:** seba (lines 5-11): položka je
```php
<a class="list-group-item list-group-item-action more-item" href="/settings/limits"><?= esc(lang('Limits.navTitle')) ?></a>
```
Pridať 4 položky (Prehľady, Balík pre účtovníčku, Denník zmien, Používatelia) pred formulár odhlásenia. Doplniť aj `app/Views/layouts/_nav_items.php` (nie čítané; overiť pri plánovaní).

---

### `app/Language/sk/{Reports,Export,AuditLog,Users}.php`

**Analog:** `app/Language/sk/Limits.php` – `return ['navTitle' => '...', 'title' => '...', 'backToMore' => 'Späť na Viac', ...]`, parametre `{0}`, `{1}`. Kľúče aj pre `en` (a pripraviť PL/CZ), hlídané `LangKeysTest`, `ViewsHardcodedTextTest`. Popisky akcií audit logu dynamicky z `DISTINCT action` s fallbackom na surový kľúč.

---

### PHPStan `ForbiddenCallsRule` (mod)

**Analog:** `tests/_support/PhpStan/ForbiddenCallsRule.php` lines 60-64, 79-87, 111
```php
private const BC_ALLOWED_FILES = [
    'app/Libraries/Platform/Money.php', 'app/Libraries/Platform/Hours.php',
    'app/Libraries/Fx/FxConverter.php', 'app/Libraries/Fx/FxQuote.php',
    'app/Libraries/Reporting/LimitMath.php', 'app/Libraries/Reporting/PeriodResolver.php',
];
```
Pridať `app/Libraries/Reporting/ReportMath.php` do `BC_ALLOWED_FILES`; nový `FLOAT_CAST_ALLOWED_FILES = ['app/Libraries/Export/XlsxNumber.php']` pre `(float)` cast; aktualizovať `phpstan.selftest.neon` / `tests/_support/PhpStan/selftest.php`.

---

### Testy

**Integračný vzor** – `tests/integration/Reporting/TurnoverCalculatorTest.php` (lines 1-50): `#[Group('integration')]`, `final class ... extends PgTestCase`, traity `MasterFixtures, InvoiceFixtures, CreditNoteFixtures, IssueFixtures, TurnoverFixtures`, `setUp` -> `resetMasterTables()`, `createEntity('SK', ['active_from' => '2025-01-01'])`, `createClient()`, `new TurnoverCalculator($this->rw())`. Fixtures: `turnoverInvoice(entityId, clientId, turnoverDate, amount, overrides)`, `turnoverCreditNote(originalId, issueDate)`.

**Feature vzor** – `tests/feature/Settings/LimitsControllerTest.php` (lines 1-60): `extends AuthTestCase`, `Services::injectMock('clock', new FrozenClock('2026-11-02T10:00:00+01:00'))`, `session()->set('last_activity', ...)`, `login()` = `createUser` + `enrollTotp` + `loginAs`; `tearDown` -> `Services::reset()`. Použiť pre 403 (ne-admin/anonym), hlavičky exportov (`getBody()` funguje len pri `setBody`).

## Shared Patterns

### Peniaze (NFR-04)
**Source:** `app/Libraries/Platform/Money.php`
`Money::of('1080.5')` (regex `^-?\d{1,10}(\.\d{1,2})?$`), `Money::sum(...)`, `plus/minus/negate/compare/equals`, `toDecimalString()`. Žiadny float, žiadny `round`/`number_format`/`/`; bcmath len v povolených súboroch. Zobrazenie `MoneyFormat::format((string) $amount, $currency)`.

### Autorizácia
**Source:** `app/Filters/AuthGate.php` line 61 (`$user->inGroup('admin')`), pre iných 403 view `errors/forbidden_403`. Nové trasy mimo whitelistu = admin only.

### htmx fragment + Vary
`htmx_view($fragment, $data, $fullPage)` + `->setHeader('Vary', 'HX-Request')` (Invoices::index lines 31-33).

### Download hlavičky
Pozri `Invoices::serve` lines 117-125; Content-Type: `text/csv; charset=UTF-8`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `application/zip`; názov súboru slug `[a-z0-9-]` cez Transliterator, nikdy surové meno.

### Čas
Nikdy `date()`/`DateTimeImmutable` mimo `Clock`; `service('clock')->today()`, v testoch `FrozenClock`.

### CSV (PHP 8.5)
Vždy `fputcsv($h, $row, ';', '"', '')` (inak Deprecated), BOM `"\xEF\xBB\xBF"`, desatinná čiarka cez `str_replace('.', ',', $money)`, `FormulaGuard` len na textové stĺpce.

### XLSX (OpenSpout)
Len `StringCell`/`NumericCell` (nikdy `Cell::fromValue`/`Row::fromValues`), `openToFile` do `writable/`, `(new Style())->withFormat('#,##0.00')`.

### CSP / i18n
Žiadne inline `style=`/`<style>`/`<script>`/`on*=`; všetky texty cez `lang()`; `esc()` na všetkom výstupe.

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `app/Libraries/Export/XlsxWriter.php` | utility | file-I/O | V repo nie je žiadny XLSX writer (OpenSpout je nová závislosť); použiť RESEARCH.md „Code Examples“ |
| `app/Libraries/Export/AccountantBundle.php` | service | file-I/O | Žiadne ZIP generovanie; `ZipArchive` + `tempnam(WRITEPATH.'exports')` + `try/finally unlink` (RESEARCH Pitfall 9); čítanie PDF cez `DocumentStore::read` |
| `app/Libraries/Export/FormulaGuard.php`, `CsvWriter.php` | utility | transform | Žiadny CSV export; kód v RESEARCH.md „CSV s BOM“ |

## Poznámky pre plánovač (z RESEARCH, odchýlky od CONTEXT)

- Externé faktúry (`origin='external'`) nemajú `entity_id`, `turnover_date`, `total_entity_ccy`, `number`: vylúčiť z prehľadu aj balíka (default), pokiaľ vlastník nerozhodne inak.
- Test D-05: porovnávať `counted` (filter `counts_toward_limit`) s `TurnoverCalculator::turnover()['total']`, `invoiced` so SUM všetkých `issued`.
- Zoznam akcií audit logu je dynamický (`DISTINCT action`), nie pevný zoznam z UI-SPEC.
- `app/Libraries/Auth/AuditLog.php` zapisuje `auth_events`, nie `audit_log`; nezamieňať s `Libraries/Audit/AuditLogger`.
- Úhrady: kumulatívne zaokrúhlenie podielu, clamp `cum <= total`, guard `total = 0`.

## Metadata

**Analog search scope:** `app/Controllers`, `app/Controllers/Settings`, `app/Libraries/{Invoicing,Reporting,Audit,Platform,Work}`, `app/Views/{invoices,more}`, `app/Config/Routes`, `app/Language/sk`, `app/Filters/AuthGate.php`, `tests/{integration,feature,_support}`
**Files scanned:** ~25 (čítané priamo: Invoices.php, InvoiceQueries.php, TurnoverCalculator.php (1-110), _list.php, index.php, more/index.php, AuditLogger.php, AuthGate.php (40-75), TaskQueries (1-110), Money.php (1-50), DocumentFilename.php, ForbiddenCallsRule (výňatky), testy)
**Pattern extraction date:** 2026-11-04
