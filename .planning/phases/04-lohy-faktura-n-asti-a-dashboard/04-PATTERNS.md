# Phase 4: Úlohy, fakturačné časti a dashboard - Pattern Map

**Mapped:** 2026-10-25
**Files analyzed:** 41 (nové alebo upravené)
**Analogs found:** 36 / 41 (5 bez analógu: trigger, pohľad, offcanvas panel, ExcelReader/Import, BigInt JS)

Zdroje súborov: 04-CONTEXT.md (D-01..D-20), 04-RESEARCH.md (Recommended Project Structure, Odporúčané rezy). Všetky cesty sú relatívne ku koreňu repozitára.

## File Classification

| Nový / upravený súbor | Role | Data Flow | Najbližší analóg | Kvalita |
|-----------------------|------|-----------|------------------|---------|
| `app/Database/Migrations/2026-10-25-00000N_CreateTasks.php` | migration | CRUD (schéma) | `app/Database/Migrations/2026-10-25-000003_CreateClients.php` | exact |
| `app/Database/Migrations/..._CreateBillingAllocations.php` (+ trigger `alloc_guard`) | migration | CRUD + guard | `..._CreateClients.php` (tabuľka, granty); trigger bez analógu | role-match |
| `app/Database/Migrations/..._CreateTaskBillingView.php` | migration | transform (read model) | `..._CreateClients.php` (štruktúra migrácie) | partial |
| `app/Database/Support/GrantsPrivileges.php` (+ `grantDelete()`) | utility (trait) | config | sám sebou, metóda `grantMutable()` | exact |
| `app/Libraries/Platform/Hours.php` | utility | transform | `app/Libraries/Platform/Money.php` | exact |
| `app/Libraries/Platform/MoneyFormat.php` | utility | transform | `Settings\Clients::formatRate()` (extrakcia) | exact |
| `app/Libraries/Work/TaskInput.php`, `AllocationInput.php` | utility (validácia) | request-response | `app/Libraries/Master/ClientInput.php` | exact |
| `app/Libraries/Work/TaskService.php` | service | CRUD + audit | `app/Libraries/Master/ClientService.php` | exact |
| `app/Libraries/Work/AllocationService.php` | service | CRUD + atómový zámok | `ClientService.php` (`update`, `setArchived`) | role-match |
| `app/Libraries/Work/AllocationLocked.php` | model (výnimka) | n/a | `app/Libraries/Master/SeriesRejected.php` / `VersionRejected.php` | role-match |
| `app/Libraries/Work/TaskQueries.php` | service (read) | request-response (agregácia) | `ClientService::listAll()` + `find()` | role-match |
| `app/Libraries/Work/BillingMonth.php`, `LastClient.php` | utility | transform | `app/Libraries/Platform/Clock.php`, `Master/BillingMode.php` | partial |
| `app/Controllers/Tasks.php` | controller | CRUD + htmx fragmenty | `app/Controllers/Settings/Clients.php` + `Demo.php` (htmx_view) | exact |
| `app/Controllers/Allocations.php` | controller | CRUD + htmx (409/422) | `Settings\Clients.php`, `Demo::invalid()` | role-match |
| `app/Controllers/Home.php` (dashboard) | controller | request-response | `Home.php` + `Demo::index()` | role-match |
| `app/Config/Routes/tasks.php` | route | config | `app/Config/Routes/clients.php` | exact |
| `app/Commands/ImportExcel.php` | command | batch / file-I/O | `app/Commands/UserCreate.php` | role-match |
| `app/Libraries/Work/Import/{ExcelReader,ImportPlanner,ImportRunner,ImportRow}.php` | service | batch / file-I/O | žiadny | no analog |
| `app/Views/tasks/{index,form,show}.php` | view | request-response | `app/Views/settings/clients/{index,form}.php` | exact |
| `app/Views/tasks/_list.php`, `_parts.php`, `_tiles.php` | view (fragment) | htmx | `app/Views/demo/_list.php`, `settings/clients/_portal_preview.php` | role-match |
| `app/Views/tasks/_done_panel.php`, `_delete_confirm.php` (offcanvas) | view (fragment) | htmx | modal v `settings/clients/form.php` r. 214-232 | partial |
| `app/Views/home/index.php` (dashboard B) | view | request-response | `app/Views/home/index.php` | exact |
| `app/Views/layouts/_nav_items.php` (úprava) | view | n/a | sám sebou, riadok `<a href="/more">` | exact |
| `app/Language/{sk,en}/Tasks.php` | config (i18n) | n/a | `app/Language/sk/Clients.php` | exact |
| `public/assets/js/app.js` (`taskForm`, `donePanel`, offcanvas hook) | component (Alpine CSP) | event-driven | `Alpine.data('clientForm')` r. 70-83 | exact |
| `public/assets/css/app.css` (progress, tile, fab) | config (CSS) | n/a | `.app-card`, `.master-row`, `.btn-touch` | role-match |
| `tests/_support/MasterFixtures.php` (úprava) | test support | n/a | sám sebou | exact |
| `tests/unit/Work/{TaskInputTest,HoursTest}.php` | test | unit | `tests/unit/Master/ClientInputTest.php`, `tests/unit/Platform/MoneyTest.php` | exact |
| `tests/integration/Work/*.php` | test | integration (app_rw + owner) | `tests/integration/Master/ClientServiceTest.php`, `MasterRolesTest.php` | exact |
| `tests/feature/Work/*.php` | test | feature (HTTP) | `tests/feature/Master/ClientsTest.php` | exact |
| `tests/js/task-form.test.js` | test | unit (node) | `tests/js/client-form.test.js` | exact |
| `tests/integration/Work/ImportExcelTest.php` + fixture generátor v `tests/_support/` | test | file-I/O | `tests/integration/Master/ClientServiceTest.php` (štruktúra) | partial |
| `app/Libraries/Master/ClientService.php` (úprava: kontrola zmeny meny) | service | CRUD | sám sebou, `update()` | exact |

## Pattern Assignments

### Migrácie `CreateTasks` a `CreateBillingAllocations` (migration, CRUD)

**Analog:** `app/Database/Migrations/2026-10-25-000003_CreateClients.php`

**Štruktúra a granty** (r. 1-60): trieda `final class ... extends Migration`, `use GrantsPrivileges`, DDL cez `$this->db->query(<<<'SQL' ... SQL)`, `COMMENT ON TABLE`, stĺpcový grant, `down()` s `DROP TABLE IF EXISTS`.
```php
final class CreateClients extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE clients (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                ...
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CHECK ((identifier_type = 'none') = (identifier_value IS NULL))
            )
            SQL);
        $this->db->query('CREATE INDEX clients_name_idx ON clients (name)');
        $this->db->query("COMMENT ON TABLE clients IS '...'");
        $this->grantUpdateColumns('clients', ['name', ..., 'updated_at']);
    }
    public function down(): void { $this->db->query('DROP TABLE IF EXISTS clients'); }
}
```
**Čo použiť pre Phase 4:** DDL z 04-RESEARCH.md "Migrácia tabuliek" (tasks, billing_allocations, partial index). Poradie: `tasks` pred `billing_allocations` (FK RESTRICT). Mena `char(3) CHECK (currency IN ('EUR','PLN','CZK'))` rovnako ako `clients.currency`. Pozor: `clients.hourly_rate` je po migrácii 000006 `numeric(12,2)`, `tasks.rate` tiež `numeric(12,2)`.
**Granty:** `tasks`: `grantUpdateColumns('tasks', ['client_id','url','title','hours','rate','note','status','done_at','updated_at'])` + nové `grantDelete('tasks')`. `billing_allocations`: `grantUpdateColumns(..., ['hours','billing_month','rate','amount','updated_at'])` + `grantDelete`. NIKDY `invoice_id`, `task_id`, `currency` (Phase 5 pridá `GRANT UPDATE (invoice_id)`).
**Migračné príkazy:** `spark migrate -g owner|tests_owner` (migrácie bežia ako vlastník).

### `app/Database/Support/GrantsPrivileges.php` (úprava, utility)

**Analog:** ten istý súbor, `grantMutable()` r. 19-27. Pridať `grantDelete()` s rovnakou validáciou názvu a `escapeIdentifiers`, aby sa nepoužíval `grantMutable` (ktorý dáva UPDATE na celú tabuľku):
```php
protected function grantMutable(string $table): void
{
    if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
        throw new InvalidArgumentException('Invalid table name: ' . $table);
    }
    assert($this->db instanceof BaseConnection);
    $this->db->query('GRANT UPDATE, DELETE ON TABLE ' . $this->db->escapeIdentifiers($table) . ' TO app_rw');
}
```
Aktualizovať aj docblock traitu (r. 10-16 tvrdí "DELETE sa nedáva nikdy"; pre `tasks` a `billing_allocations` to už neplatí, chránené triggerom).

### Trigger `alloc_guard` a pohľad `task_billing_v` (migration)

Žiadna existujúca migrácia nemá `CREATE TRIGGER`/`CREATE FUNCTION` (overené grepom v `app/Database/Migrations/`). Použiť SQL z 04-RESEARCH.md Pattern 2 a Pattern 3 doslovne, v `$this->db->query(<<<'SQL' ... SQL)` (nowdoc kvôli `$$`). `down()`: `DROP TRIGGER`, `DROP FUNCTION`, `DROP VIEW IF EXISTS` pred `DROP TABLE`. Pohľad nesmie byť `SECURITY DEFINER`; overiť SELECT pod `app_rw` testom (A2).

### `app/Libraries/Platform/Hours.php` (utility, transform)

**Analog:** `app/Libraries/Platform/Money.php`

**Vzor** (r. 16-31, 49-58): `final class`, `declare(strict_types=1)`, regex vstupu s modifikátorom `D`, explicitný `scale` pri každom `bc*`, `InvalidArgumentException`.
```php
public static function of(string $value): self
{
    if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid money value.');
    }
    return new self(self::norm(bcadd($value, '0', 2)));
}
public function timesHours(string $hours): self
{
    if (preg_match('/^-?\d{1,5}(\.\d{1,2})?$/D', $hours) !== 1) { throw new InvalidArgumentException('Invalid hours value.'); }
    $product = bcround(bcmul($this->amount, $hours, 4), 2, RoundingMode::HalfAwayFromZero);
    return self::checked($product);
}
```
Hours môže byť statická pomocná trieda (04-RESEARCH "Hours (bcmath)") s `of`, `minus`, `remaining`, `format` (znamienko `+` a U+2212). Sumu časti vždy `Money::of($rate)->timesHours($hours)->toDecimalString()`.

### `app/Libraries/Platform/MoneyFormat.php` (extrakcia)

**Analog:** `app/Controllers/Settings/Clients.php` r. 205-225 (`formatRate`). Presunúť telo do `MoneyFormat::format(string $amount, string $currency): string` bez zmeny logiky (NumberFormatter + string, bez float) a `Settings\Clients` prepnúť na volanie (existujúci test `ClientsTest` musí ostať zelený):
```php
$money              = Money::of($rate)->toDecimalString();
[$whole, $fraction] = explode('.', $money) + [1 => '00'];
$decimal = new NumberFormatter($locale, NumberFormatter::DECIMAL);
$decimal->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
...
return str_starts_with($formatter->getPattern(), '¤') ? $symbol . $number : $number . "\u{00A0}" . $symbol;
```

### `app/Libraries/Work/TaskInput.php`, `AllocationInput.php` (utility, request-response)

**Analog:** `app/Libraries/Master/ClientInput.php`

**Triedny tvar:** `FIELDS` whitelist (`const`), `fromPost(IncomingRequest): array<string,string>` (trim, nič mimo whitelistu), `normalize(array)`, `validate(array): array<string,string>` s chybami cez `lang('...')`.
```php
public static function fromPost(IncomingRequest $request): array
{
    $out = [];
    foreach (self::FIELDS as $field) {
        $value       = $request->getPost($field);
        $out[$field] = is_string($value) ? trim($value) : '';
    }
    return $out;
}
// normalize: sadzba
$out[$field] = Money::of(str_replace(',', '.', (string) preg_replace('/\s+/u', '', $value)))->__toString();
// validate: peniaze regex so stropom
if (preg_match('/^\d{1,8}([.,]\d{1,2})?$/D', (string) preg_replace('/\s+/u', '', $get('hourly_rate'))) !== 1) {
    $errors['hourly_rate'] = lang('Clients.errorRate');
}
```
**Pre Phase 4:** `FIELDS` = `client_id, url, title, hours, rate, note` (NIKDY `amount`, `status`, `invoice_id`, `done_at` z POST: mass-assignment, 04-RESEARCH Security). Hodiny regex `^\d{1,5}([.,]\d{1,2})?$` a `> 0`. URL: len `http`/`https` s hostom, max 2000 znakov; vzor kontroly schémy `PortalUrlTemplate::validate()` (`app/Libraries/Master/PortalUrlTemplate.php` r. 18). Mesiac `^\d{4}-(0[1-9]|1[0-2])$`. Chyby cez `lang('Tasks.error*')`.

### `app/Libraries/Work/TaskService.php` (service, CRUD + audit)

**Analog:** `app/Libraries/Master/ClientService.php`

**Konštruktor s DI pre testy** (r. 22-33):
```php
private readonly BaseConnection $db;
private readonly AuditLogger $audit;
public function __construct(?BaseConnection $db = null, ?AuditLogger $audit = null)
{
    $this->db    = $db ?? Database::connect();
    $this->audit = $audit ?? new AuditLogger($this->db);
}
```
**Create** (r. 38-54): čas z `service('clock')->now()->format('Y-m-d H:i:sP')`, `Transaction::run($this->db, function () ...)`, `INSERT ... RETURNING id`, `$this->audit->record('task', $id, 'create', null, [...])`.
**Update/lock** (r. 66-86): `SELECT * FROM clients WHERE id = :id: FOR UPDATE`, `InvalidArgumentException` ak chýba, porovnať before/after a `recordChange` (vráti `false` ak bez zmeny).
**Stavové prechody** (r. 99-130, `setArchived`): `FOR UPDATE`, kontrola predchádzajúceho stavu, `UPDATE ... WHERE id = :id: AND archived_at IS NULL`, audit s `before`/`after`. Rovnaký tvar pre `markDone` (nastaví `status='done'`, `done_at=:now:`, vytvorí časť, audit) a `reopen` (vymaže `done_at`, audit).
**Pre Phase 4 (Pitfall 10):** prvý krok každej operácie nad úlohou alebo jej časťami je `SELECT ... FROM tasks WHERE id = :id: FOR UPDATE`. Zmazanie úlohy: `DELETE FROM tasks WHERE id = :id: AND NOT EXISTS (SELECT 1 FROM billing_allocations WHERE task_id = tasks.id AND invoice_id IS NOT NULL)` + `affectedRows()`, nezamknuté časti zmazať v tej istej transakcii pred tým, s auditom.
**Peniaze:** `hydrate()` vracia `(string)` pre `hours`, `rate` (r. 222), nikdy float; bool cez `DbBool::of`.

### `app/Libraries/Work/AllocationService.php` (service, atómový zámok)

**Analog:** `ClientService::setArchived()` r. 99-130 (podmienka `AND archived_at IS NULL` v UPDATE + audit), rozšírené podľa 04-RESEARCH Pattern 1:
```php
$this->db->query(
    'UPDATE billing_allocations SET hours = :hours:, billing_month = :month:, rate = :rate:, amount = :amount:, updated_at = :now:
     WHERE id = :id: AND invoice_id IS NULL', [...]);
if ($this->db->affectedRows() !== 1) { throw new AllocationLocked($id); } // -> 409
```
Rovnako `DELETE ... WHERE id = :id: AND invoice_id IS NULL`. `currency` a `rate` kopírovať z úlohy/klienta pri `add()`, `amount` vždy `Money::of($rate)->timesHours($hours)`. Audit entita `billing_allocation` (regex `^[a-z_]{3,40}$` v `audit_log`).
**Výnimka `AllocationLocked`:** vzor jednoduchých doménových výnimiek v `app/Libraries/Master/SeriesRejected.php` / `VersionRejected.php` (pozri tie súbory pre tvar konštruktora).

### `app/Libraries/Work/TaskQueries.php` (service, agregácia)

**Analog:** `ClientService::listAll()` r. 142-151 a `find()` r. 132-140: priame `$this->db->query('SELECT ...')->getResultArray()` s bindovaním `:name:`, výsledok cez `hydrate`. Zoznam čítať z pohľadu `task_billing_v` jedným dotazom (bez N+1). Filter textu: escapovať `\`, `%`, `_` a `ILIKE :q: ESCAPE '\'` (Pitfall 12). Dashboard súčty `GROUP BY currency` (nikdy nesčítať meny).

### `app/Controllers/Tasks.php` (controller, CRUD + htmx)

**Analog:** `app/Controllers/Settings/Clients.php`

**Imports + tvar** (r. 1-18): `final class ... extends BaseController`, `CodeIgniter\Exceptions\PageNotFoundException`, `IncomingRequest`, `ResponseInterface`.
**Create s 422** (r. 42-58):
```php
$request = $this->request;
assert($request instanceof IncomingRequest);
$values = ClientInput::fromPost($request);
$errors = ClientInput::validate($values);
if ($errors !== []) {
    return $this->response->setStatusCode(422)->setBody(view('settings/clients/form', $this->formData(null, $values, $errors)));
}
(new ClientService())->create(ClientInput::normalize($values));
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Clients.saved')]);
return redirect()->to('/settings/clients');
```
**404:** `throw PageNotFoundException::forPageNotFound(lang('Clients.notFound'));` (r. 65, 76).
**htmx fragment s Vary** (r. 105-112):
```php
return $this->response->setHeader('Vary', 'HX-Request')
    ->setBody(htmx_view('settings/clients/_portal_preview', $data, 'settings/clients/form'));
```
**Filtre v URL (TSK-06):** `index()` číta `$this->request->getGet(...)` (whitelist, int/regex validácia, vzor `getGet('archived') === '1'` r. 22) a vráti `htmx_view('tasks/_list', $data, 'tasks/index')`; formulár `hx-get ... hx-push-url="true"` (vzor `hx-get`/`hx-trigger`/`hx-target` v `settings/clients/form.php` r. 166-167).
**Formuláre tvorby úlohy:** obyčajný POST + `redirect()->to()` + flashdata (ako Clients). Po htmx POST (panel, časti): `HX-Redirect`/`HX-Location` hlavička (existujúci vzor len v `app/Filters/AuthGate.php` r. 135: `->setHeader('HX-Redirect', $target)`), nie 302 (D-15).
**Autorizácia:** globálny `AuthGate` (admin) platí automaticky pre všetky nové routy; v kontroleroch sa nerobí nič navyše.

### `app/Controllers/Allocations.php` (controller, 409/422)

**Analog:** `Clients.php` (422) a `Demo::invalid()` (`app/Controllers/Demo.php`, volá `invalid($values, $errors)`; pozri r. 60+ pre tvar). 409: `catch (AllocationLocked|DatabaseException)` (text `locked`, A3) → `$this->response->setStatusCode(409)->setBody(view('tasks/_parts', ...))`. Po zmene časti pridať `->setHeader('HX-Trigger', 'allocationChanged')` (D-15). V kóde zatiaľ žiadny `HX-Trigger` neexistuje (grep v `app/`), je to nový vzor.

### `app/Controllers/Home.php` + `app/Views/home/index.php` (dashboard)

**Analog:** súčasný `Home::index()` (`return view('home/index', ['title' => lang('App.title')]);`) a `Demo::index()` (`htmx_view('demo/_calculator', ..., 'demo/index')`). Dashboard: `htmx_view('home/_tiles', $data, 'home/index')` pre obnovu dlaždíc po `allocationChanged` (`hx-trigger="allocationChanged from:body"`). Layout štruktúra view: `$this->extend('layouts/app')`, `$this->section('subtitle')`, `$this->section('content')` (`app/Views/home/index.php` celý súbor).

### `app/Views/tasks/form.php`, `index.php`, `show.php` (view)

**Analog:** `app/Views/settings/clients/form.php`, `index.php`

**Pomocné closures pre chyby** (form.php r. 13-20): `$v`, `$describe`, `$feedback`, `$invalid`:
```php
$v      = static fn (string $k): string => (string) ($values[$k] ?? '');
$describe = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="f-' . esc($name, 'attr') . '-error"' : '';
$feedback = static fn (string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback d-block" id="f-' . esc($name, 'attr') . '-error">' . esc($errors[$name]) . '</div>' : '';
```
**Formulár** (r. 39-41): `<form method="post" action="..." data-submit-once novalidate x-data="clientForm" data-...>` + `<?= csrf_field() ?>`; pole: `class="form-control<?= $invalid('x') ?>" ... inputmode="decimal"`. Tlačidlo: `btn btn-primary btn-touch w-100` + spinner `d-none`, sticky `master-sticky-actions` (r. 191-197). Pre `taskForm` doplniť `data-currency`, `data-hours`, `data-rate` a `x-text="sumText"` (žiadne výrazy v atribútoch, len gettery).
**Zoznam** (index.php r. 20-31): `app-card`, `master-row` odkazy, prázdny stav cez `lang(...Heading/Body)`, filter `nav` s `btn btn-outline-secondary btn-touch flex-fill` + `aria-current="page"` (r. 15-18).
**Potvrdenie zmazania časti (D-10):** modal v form.php r. 212-232 (`data-bs-toggle="modal"`, `modal-dialog-centered`); pre mobil zmeniť na `offcanvas offcanvas-bottom`.
**Pravidlá:** všetky texty `esc(lang(...))` (`ViewsHardcodedTextTest`), žiadne `style=""`/`onclick`/inline `<script>` (`ViewsCspTest`). Priebeh `<progress max value>` namiesto Bootstrap `.progress-bar` so štýlom.

### `app/Views/layouts/_nav_items.php` (úprava)

**Analog:** riadky 7 a 10 toho istého súboru. Nahradiť `<span aria-disabled="true"><?= esc(lang('App.navTasks')) ?></span>` za:
```php
<a href="/tasks"<?= str_starts_with($path, '/tasks') ? ' aria-current="page"' : '' ?>><?= esc(lang('App.navTasks')) ?></a>
```
Pozor: `/` je aktívne len pri `$path === '/'` (r. 7), `/more` pri `/settings*` (r. 10). `tests/feature/LayoutTest.php` treba upraviť (očakáva `aria-disabled` na Úlohy).

### `app/Config/Routes/tasks.php` (route)

**Analog:** `app/Config/Routes/clients.php` (celé):
```php
// Statické cesty pred (:num).
$routes->get('settings/clients/new', 'Settings\Clients::new');
$routes->post('settings/clients', 'Settings\Clients::create');
$routes->get('settings/clients/(:num)/edit', 'Settings\Clients::edit/$1');
$routes->post('settings/clients/(:num)', 'Settings\Clients::update/$1');
```
Súbor sa načíta globom `app/Config/Routes.php` r. 12 (`glob(APPPATH . 'Config/Routes/*.php')`), netreba registrovať. Pre Phase 4: statické (`tasks/new`) pred `(:num)`; zmazanie úlohy a časti ako POST (`tasks/(:num)/delete`, `allocations/(:num)/delete`), `GET tasks/(:num)/done` pre panel.

### `app/Commands/ImportExcel.php` (command, batch)

**Analog:** `app/Commands/UserCreate.php`

**Tvar** (r. 20-74): `final class ... extends BaseCommand`, `@internal`, `$group`, `$name = 'import:excel'`, `$usage`, `$arguments`, `$options` (`--dry-run`, `--client`), `run(array $params): int`, `CLI::error()` + `return EXIT_ERROR`, `CLI::write(..., 'green')` + `EXIT_SUCCESS`, `catch (DomainException|InvalidArgumentException $e)`:
```php
$admin = array_key_exists('admin', $params) || CLI::getOption('admin') !== null;
...
try { $user = (new UserProvisioner())->create(...); }
catch (DomainException|InvalidArgumentException $e) { CLI::error($e->getMessage()); return EXIT_ERROR; }
```
Doplniť guard `class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)` s chybou "len dev" (Pitfall 8). Čas cez `service('clock')`, nie `new DateTime` (PHPStan pravidlo). Import ide cez `TaskService`/`AllocationService`, audit zapisuje v CLI režime `ip='cli'` (AuditLogger).

### `app/Libraries/Work/Import/*` (no analog)

Pozri "No Analog Found". Použiť 04-RESEARCH.md "Import: čítanie hyperlinku", Pitfalls 1-5 a 8. `ImportPlanner`/`ImportRunner` nesmú závisieť od PhpSpreadsheet (čistá logika, testovateľná bez knižnice); iba `ExcelReader` smie.

### `app/Language/{sk,en}/Tasks.php` (i18n)

**Analog:** `app/Language/sk/Clients.php` a `en/Clients.php` (pole `return ['key' => 'text', ...]`, zarovnané `=>`). Povinné kľúče v oboch jazykoch (`tests/unit/I18n/LangKeysTest.php`). Doplniť `App.php` ak treba (`navTasks` už existuje, `app/Language/sk/App.php` r. 9). Parametre cez `lang('Clients.archiveConfirm', [$name])`.

### `public/assets/js/app.js` (Alpine CSP komponenty)

**Analog:** `Alpine.data('clientForm')` (r. 70-83) a `copySecret` (r. 84-98):
```js
window.Alpine.data('clientForm', function () {
  return {
    identifierType: '',
    init: function () { this.identifierType = this.$el.dataset.identifierType || ''; },
    setIdentifier: function () { this.identifierType = this.$event.target.value; },
    get identifierDisabled() { return this.identifierType === 'none'; }
  };
});
```
Pre `taskForm`: stav `hours`, `rate`, `currency` z `this.$el.dataset`, `get sumText()` s BigInt (04-RESEARCH "Živý prepočet"), vstup `x-on:input="setHours"` (metódy, nie výrazy). Offcanvas hook: po `htmx:afterSwap` otvoriť `bootstrap.Offcanvas.getOrCreateInstance(el).show()` (nový vzor, zatiaľ žiadny v `app.js`). ES5 štýl (`function`, `var`), súbor je IIFE.

### `tests/_support/MasterFixtures.php` (úprava)

**Analog:** ten istý súbor. `resetMasterTables()` r. 23: pridať `'billing_allocations', 'tasks'` do poľa **pred** `'clients'` (existujúca kontrola `to_regclass` ošetrí, že tabuľky nemusia existovať). Nové helpery rovnakého tvaru ako `createClient()` r. 86-110 (`$overrides + [...]`, volanie služby pod `$this->rw()`, vrátiť id): `createTask()`, `createAllocation()`, `lockAllocation($id)` = `$this->owner()->query('UPDATE billing_allocations SET invoice_id = ...')` (app_rw na `invoice_id` právo nemá).

### Integračné testy `tests/integration/Work/*` (test)

**Analog:** `tests/integration/Master/ClientServiceTest.php` (r. 1-62) a `MasterRolesTest.php`

**Kostra:**
```php
#[Group('integration')]
final class ClientServiceTest extends PgTestCase
{
    use MasterFixtures;
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('clock', new FrozenClock('2026-10-25T10:00:00+02:00'));
        $this->resetMasterTables();
    }
    protected function tearDown(): void { $this->resetMasterTables(); Services::reset(); parent::tearDown(); }
    private function service(): ClientService { return new ClientService($this->rw(), new AuditLogger($this->rw())); }
    private function audits(string $action): int {...}
```
**Oprávnenia** (`MasterRolesTest` r. 36-46): `$this->assertPermissionDenied(fn () => $this->rw()->query('UPDATE ... SET invoice_id = 1'))` (PgTestCase r. 60-71 regex `permission denied|must be owner`). Test triggera: nastaviť `invoice_id` cez `owner()`, potom `rw()` UPDATE/DELETE očakáva `DatabaseException` (A3). Vlastné hodnoty uložené cez `owner()`, čítanie cez `owner()`.

### Feature testy `tests/feature/Work/*` (test)

**Analog:** `tests/feature/Master/ClientsTest.php` r. 1-60: `extends AuthTestCase`, `use MasterFixtures`, v `setUp` `createUser`, `enrollTotp`, `loginAs`, FrozenClock; `body(TestResponse)` helper. Pre dashboard doplniť kontrolu hlavičky `HX-Trigger` a aktívneho odkazu "Úlohy".

### `tests/js/task-form.test.js` (test)

**Analog:** `tests/js/client-form.test.js` (r. 1-40): `vm.runInNewContext(SOURCE, { document, window })` s mock `Alpine.data`, `boot()` vráti komponenty, `make()` nastaví `c.$el = { dataset: {...} }` a zavolá `init()`; `node:test` + `assert/strict`. Paritné vektory s `MoneyTest` (`8.1×27 = 218.70`, `0.01×0.5 = 0.01` half-up).

### `app/Libraries/Master/ClientService.php` (úprava: zmena meny)

**Analog:** `update()` r. 59-87. Po `FOR UPDATE` na klientovi (r. 68) a pred `UPDATE` pridať kontrolu: ak `$row['currency'] !== $values['currency']` a existuje časť (`SELECT 1 FROM billing_allocations a JOIN tasks t ON t.id = a.task_id WHERE t.client_id = :c: AND a.invoice_id IS NULL LIMIT 1`), vyhodiť doménovú výnimku (kontroler `Settings\Clients::update` ju premení na 422 s chybou pri `currency`). Dôležité: `ClientService` má `to_regclass`-neznalú SQL; v testoch Phase 3 tabuľky `tasks` už budú existovať po migrácii.

## Shared Patterns

### Transakcia + audit
**Source:** `app/Libraries/Platform/Transaction.php` (`Transaction::run($db, callable)`), `app/Libraries/Audit/AuditLogger.php` (`record`, `recordChange`)
**Apply to:** `TaskService`, `AllocationService`, `ImportRunner`
Každá zmena `tasks` a `billing_allocations` je v `Transaction::run` a audit sa píše v tej istej transakcii (D-13). `record($entityType, $id, $action, $before, $after)` pre create/delete/archive-typ udalosti, `recordChange(..., $before, $after)` pre update (vráti false pri bezzmene).

### Peniaze a hodiny bez float
**Source:** `app/Libraries/Platform/Money.php`, nový `Hours.php`
**Apply to:** všetky služby, importer, views (zobrazenie)
`numeric` sa vracia ako string; súčty cez bcmath; PHPStan pravidlo (`tests/_support/PhpStan/ForbiddenCallsRule.php`) zakazuje float a čas mimo `Clock`. Čas: `service('clock')->now()`, nie `new DateTime`.

### Zámok riadkom pred operáciou
**Source:** `ClientService::update()` r. 68 a `setArchived()` r. 105-108
**Apply to:** `TaskService`, `AllocationService`
`SELECT ... FOR UPDATE` ako prvý príkaz v transakcii; v Phase 4 vždy na riadku `tasks` (Pitfall 10), Phase 5 musí zachovať poradie zámkov (úloha, potom časť).

### Validácia vstupu a 422
**Source:** `ClientInput` + `Settings\Clients::create` r. 42-52
**Apply to:** `Tasks`, `Allocations` kontrolery
`fromPost` (whitelist) → `validate` (chyby cez `lang`) → pri chybe `setStatusCode(422)` + znovu vykreslený formulár/fragment (htmx má `responseHandling` pre 422 povolené v `layouts/app.php`).

### Flash notice po úspechu
**Source:** `Settings\Clients` r. 55
**Apply to:** neajax POST toky
```php
session()->setFlashdata('app_notice', ['type' => 'success', 'message' => lang('Clients.saved')]);
return redirect()->to('/settings/clients');
```

### htmx fragment vs. celá stránka
**Source:** `app/Helpers/htmx_helper.php` (`htmx_view($fragment, $data, $page)`), filter `HtmxVary`, hlavička `Vary: HX-Request` (Clients r. 110)
**Apply to:** zoznam úloh, dlaždice, zoznam častí, panel

### CSRF pre htmx a formuláre
**Source:** `<?= csrf_field() ?>` vo formulároch (`form.php` r. 41); `hx-headers` na `<body>` + filter `HtmxCsrf` (fáza 2)
**Apply to:** všetky POST akcie; žiadny vlastný token.

### Dvojklik
**Source:** atribút `data-submit-once` na `<form>` (`form.php` r. 39) + test `tests/js/submit-once.test.js`
**Apply to:** formuláre panelu "Zapracované" a "Pridať časť"; serverová idempotencia pri `status='done'` (409).

### Texty, CSP, dotykové ciele
**Source:** `lang()` + `esc()`; `.btn-touch { min-width: 44px; min-height: 44px; }` (`public/assets/css/app.css` r. 178)
**Apply to:** všetky views
Testy `ViewsHardcodedTextTest`, `ViewsCspTest` a `LangKeysTest` zlyhajú pri hardcoded texte, inline štýle či chýbajúcom kľúči v `sk` alebo `en`.

### Autorizácia
**Source:** `app/Filters/AuthGate.php` (globálny filter, iba admin)
**Apply to:** všetky nové routy automaticky; pridať feature test, že anonym nedostane obsah.

## No Analog Found

| Súbor | Role | Data Flow | Dôvod |
|-------|------|-----------|-------|
| trigger `alloc_guard` (v `CreateBillingAllocations`) | migration/guard | event-driven (DB) | V `app/Database/Migrations/` nie je žiaden `CREATE TRIGGER`/`FUNCTION`; použiť 04-RESEARCH.md Pattern 3 |
| pohľad `task_billing_v` | migration | read-model | Žiaden `CREATE VIEW` v projekte; použiť 04-RESEARCH.md Pattern 2, overiť SELECT pod `app_rw` (A2) |
| `Import/ExcelReader`, `ImportPlanner`, `ImportRunner`, `ImportRow` | service | file-I/O, batch | Žiaden súborový import; použiť 04-RESEARCH.md (bez `setReadDataOnly`, hyperlinky len 5/16, import_key s ordinálom) |
| offcanvas panel zospodu (`_done_panel`) + JS hook `htmx:afterSwap` | view + JS | event-driven | V projekte je len Bootstrap modal (`form.php` r. 214-232), offcanvas nie; vzor v 04-RESEARCH.md Pattern 5 |
| BigInt kalkulácia v `taskForm` | component | transform | `clientForm` nerobí aritmetiku; použiť 04-RESEARCH.md "Živý prepočet" a Pitfall 7 |
| `HX-Trigger` odpoveď (`allocationChanged`) | controller | pub-sub (htmx) | V `app/` zatiaľ žiadne použitie (iba `HX-Redirect` v `AuthGate`) |

## Metadata

**Analog search scope:** `app/Controllers`, `app/Libraries/{Master,Platform,Audit}`, `app/Database/{Migrations,Support}`, `app/Commands`, `app/Views/{settings/clients,home,demo,layouts}`, `app/Config/Routes`, `app/Language`, `public/assets/js/app.js`, `tests/{_support,unit,integration,feature,js}`
**Files scanned:** ~45 (pretrasené zoznamy, prečítaných ~25)
**Pattern extraction date:** 2026-10-25
