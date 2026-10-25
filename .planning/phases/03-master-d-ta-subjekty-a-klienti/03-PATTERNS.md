# Phase 3: Master dáta (subjekty a klienti) - Pattern Map

**Mapped:** 2026-10-25
**Files analyzed:** 33 (nové/upravené)
**Analogs found:** 29 / 33 (4 bez priameho analógu, vzor z RESEARCH.md)

Všetky cesty sú relatívne ku koreňu repozitára `/mnt/c/personal-projects/task-tracker`. Čísla riadkov sú z čítania súborov k 2026-10-25.

## File Classification

| Nový/upravený súbor | Role | Data Flow | Najbližší analóg | Kvalita |
|---------------------|------|-----------|------------------|---------|
| `app/Database/Support/GrantsPrivileges.php` (úprava: `grantUpdateColumns`) | migration-support | n/a | ten istý súbor, `grantMutable()` | exact |
| `app/Database/Migrations/2026-10-25-00000N_CreateEntities*.php` (entities, entity_versions, entity_bank_accounts, invoice_series, clients, legal_profiles, legal_texts, audit_log) | migration | schema | `2026-10-24-000002_CreateUserSecurity.php` (mutovateľná), `2026-10-24-000004_CreateAuthEvents.php` (insert-only) | exact |
| migrácia seed `legal_profiles` / `legal_texts` | migration | batch seed | `CreateAuthEvents` (štruktúra), seed je nový | partial |
| `app/Libraries/Audit/AuditLogger.php` | service | CRUD (insert-only) | `app/Libraries/Auth/AuditLog.php` | exact |
| `app/Libraries/Master/EntityService.php` | service | CRUD + transakcia | `app/Libraries/Auth/AccountRecovery.php` (transakcia + audit) | exact |
| `app/Libraries/Master/BankAccountService.php` | service | CRUD + transakcia | `AccountRecovery.php` | role-match |
| `app/Libraries/Master/SeriesService.php` | service | CRUD + transakcia | `AccountRecovery.php` | role-match |
| `app/Libraries/Master/ClientService.php` | service | CRUD + transakcia | `AccountRecovery.php` | role-match |
| `app/Libraries/Master/EntityVersionResolver.php`, `EntityDirectory.php`, `BankAccountSelector.php`, `LegalProfileResolver.php` | service (read) | request-response (dotazy) | `app/Libraries/Auth/AuditLog.php::latestForUser` + `TrustedDeviceService` (`$this->db()`, `query` s `:bind:`) | role-match |
| `app/Libraries/Master/SeriesFormat.php`, `Iban.php`, `PortalUrlTemplate.php`, `CcEmails.php`, `VersionDiff.php` | utility (čistá logika) | transform | `app/Libraries/Platform/Money.php` | role-match |
| `app/Libraries/Master/{VatRegime,BillingMode,IdentifierType,Country,DocLanguage}.php` | enum | n/a | `app/Libraries/Auth/AuthEvent.php` (backed enum) | role-match |
| `app/Controllers/Settings/Entities.php` (+ podstránky verzie, účty, rad, ukončenie) | controller | request-response, PRG + 422 | `app/Controllers/Settings/Security.php` + `app/Controllers/Demo.php` | exact |
| `app/Controllers/Settings/Clients.php` | controller | request-response, PRG + 422 | `Settings/Security.php` + `Demo.php` | exact |
| `app/Config/Routes/entities.php`, `app/Config/Routes/clients.php` | route | n/a | `app/Config/Routes/settings.php` | exact |
| `app/Views/settings/entities/*.php`, `settings/clients/*.php` (zoznam, detail, formuláre, `_history`, `_bank_accounts`, `_series`, `_regime_fields` fragment) | view | request-response | `app/Views/settings/security.php` + `_password.php` + `_devices.php`; `demo/_calculator.php` (htmx fragment + invalid-feedback) | exact |
| `app/Views/more/index.php` (úprava) | view | n/a | ten istý súbor | exact |
| `app/Language/{sk,en}/{Entities,Clients}.php` | config (i18n) | n/a | `app/Language/sk/Account.php`, `Demo.php` | exact |
| `public/assets/css/app.css` (úprava, `.master-*`) | config (CSS) | n/a | ten istý súbor | exact |
| `app/Database/Seeds/DevSeeder.php` (úprava) | seeder | batch | ten istý súbor | exact |
| `tests/integration/Master/*` (oprávnenia, rollback auditu, verzie, výber účtu, legal resolver) | test | CRUD | `tests/integration/DbRolesTest.php`, `tests/integration/Auth/AuditLogTest.php` | exact |
| `tests/unit/Master/*` (SeriesFormat, Iban, PortalUrlTemplate, CcEmails, VersionDiff, AuditLogger::diff) | test | transform | `tests/unit/Platform/MoneyTest.php`, `ClockTest.php` | exact |
| `tests/feature/Master/*` (formuláre, ENT-07 podvrhnuté POST) | test | request-response | `tests/feature/Settings/ChangePasswordTest.php` | exact |
| `app/Libraries/Master/BankAccountService` DbBool helper (`DbBool::of`) | utility | transform | `AuditLog::latestForUser` riadok 70 (`in_array(... ['t','true'...])`) | partial |
| Parser / `LegalTextMissing`, `InvalidSeriesFormat` výnimky | utility | n/a | žiadny (`InvalidArgumentException` použité v `Money`) | no analog |

## Pattern Assignments

### `app/Database/Support/GrantsPrivileges.php` (úprava, migration-support)

**Analog:** ten istý súbor, `grantMutable` (riadky 18-26). Nový `grantUpdateColumns` musí používať rovnakú validáciu identifikátora a `assert($this->db instanceof BaseConnection)`.

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

Nová metóda: rovnaký regex aj pre každý stĺpec, potom `GRANT UPDATE (cols) ON TABLE ... TO app_rw`. Test sa pridá do `tests/integration/DbRolesTest.php` (probe tabuľky vzor v `setUpBeforeClass`, riadky 21-45, anonymná trieda s `use GrantsPrivileges`).

---

### Migrácie `2026-10-25-00000N_*.php` (migration, schema)

**Analóg mutovateľná tabuľka:** `app/Database/Migrations/2026-10-24-000002_CreateUserSecurity.php` (riadky 1-33)

```php
final class CreateUserSecurity extends Migration
{
    use GrantsPrivileges;

    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE user_security (
                user_id integer PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
                ...
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL
            )
            SQL);
        $this->db->query("COMMENT ON TABLE user_security IS '...; mutable for app_rw'");
        $this->grantMutable('user_security');   // v Phase 3 nahradiť grantUpdateColumns(...)
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS user_security');
    }
}
```

**Analóg insert-only (`entity_versions`, `audit_log`):** `2026-10-24-000004_CreateAuthEvents.php` riadky 9-37

```php
// Denník prihlásení je insert-only (AUTH-07, Pasca 18): zámerne bez grantMutable().
CREATE TABLE auth_events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at timestamptz NOT NULL,
    user_id integer NULL REFERENCES users (id) ON DELETE SET NULL,
    event text NOT NULL CHECK (event ~ '^[a-z_]{3,40}$'),
    ...
)
$this->db->query('CREATE INDEX auth_events_user_idx ON auth_events (user_id, occurred_at DESC)');
$this->db->query("COMMENT ON TABLE auth_events IS 'Insert-only audit log ...; app_rw has no UPDATE/DELETE'");
```

Pravidlá pre plánovač:
- `final class X extends Migration`, `declare(strict_types=1)`, namespace `App\Database\Migrations`, surový SQL cez heredoc `<<<'SQL'`, `down()` v opačnom poradí (FK `entity_versions` -> `entities`, `audit_log` -> `users` majú vyššie poradové číslo). Názvy súborov `2026-10-25-00000N_Name.php`.
- Insert-only: nevolať ani `grantMutable`, ani `grantUpdateColumns` (default privileges z `provision.sql` dajú `app_rw` SELECT/INSERT).
- `audit_log`: `CHECK (entity_type ~ '^[a-z_]{3,40}$')`, `CHECK (action ~ '^[a-z_]{3,40}$')`, `user_id integer NULL REFERENCES users (id) ON DELETE SET NULL`, `before_data/after_data jsonb`, `ip text NOT NULL` (presne ako `auth_events`).
- `legal_*` seed v migrácii (spúšťa ho `app_owner`), nie v `DevSeeder` (viď nižšie). Migrácie sa spúšťajú `spark migrate -g owner|tests_owner`.

---

### `app/Libraries/Audit/AuditLogger.php` (service, insert-only)

**Analóg:** `app/Libraries/Auth/AuditLog.php` (riadky 1-46). Pozor na kolíziu názvu, nový súbor sa volá `AuditLogger` v namespace `App\Libraries\Audit`.

**Imports + konštruktor** (riadky 5-27):
```php
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\CLIRequest;
use Config\Database;

final class AuditLog
{
    /** @var BaseConnection<mixed, mixed> */
    private readonly BaseConnection $db;

    /** @param BaseConnection<mixed, mixed>|null $db */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
```

**Core pattern** (riadky 29-46): CLI rozlíšenie IP, čas z `service('clock')`, boolean ako `'true'/'false'` reťazec:
```php
$request = service('request');
$cli     = $request instanceof CLIRequest;
$this->db->table('auth_events')->insert([
    'occurred_at' => service('clock')->now()->format('Y-m-d H:i:sP'),
    'user_id'     => $userId,
    'success'     => $success ? 'true' : 'false',
    'ip'          => $cli ? 'cli' : $request->getIPAddress(),
]);
```
Pre `AuditLogger::record(string $entityType, int $entityId, string $action, ?array $before, ?array $after)`: `user_id = auth()->id()` (v CLI NULL), `before_data`/`after_data` = `json_encode(..., JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`, denylist citlivých kľúčov, `diff()` bez zápisu pri prázdnom diffe. Konštruktor s voliteľným `$db` zachovať (testy dosadzujú `$this->rw()`).

**Test analóg:** `tests/integration/Auth/AuditLogTest.php` (riadky 1-40): `PgTestCase`, `#[Group('integration')]`, `TRUNCATE` cez `$this->owner()` v `setUp`/`tearDown`, `new AuditLog($this->rw())`, čítanie cez `owner()`, `Services::resetSingle('request')` v tearDown.

---

### Doménové služby so zápisom: `EntityService`, `BankAccountService`, `SeriesService`, `ClientService` (service, CRUD + transakcia)

**Analóg:** `app/Libraries/Auth/AccountRecovery.php` riadky 84-103 (transakcia, výnimka = rollback, audit v tej istej transakcii):

```php
$db->transException(true)->transStart();

try {
    $user->setPassword($password);
    model(UserModel::class)->save($user);
    ...
    $this->audit->record($event, true, $userId);
    $db->transComplete();
} catch (Throwable $e) {
    $db->transRollback();

    throw $e;
} finally {
    $db->transException(false);
}
```

Použiť presne túto štruktúru (`transException(true)->transStart()` ... `transComplete()`, `catch Throwable` -> `transRollback()` + rethrow, `finally transException(false)`). Služba dostane `AuditLogger` a `BaseConnection` cez konštruktor (ako `TrustedDeviceService`: `?BaseConnection $db = null`, `Database::connect()` fallback). Kontrolu duplicitného `valid_from` urobiť pred INSERTom (`SELECT 1`) a výnimku `DatabaseException` zachytiť mimo transakcie (RESEARCH Pattern 2, bod 3). UPDATE písať ako `$db->query('UPDATE ... SET <explicitné stĺpce> ...')` alebo `table()->set([...])`, nikdy `Model::save()`/`Entity` (stĺpcový grant, Pitfall 1).

**Dotazový štýl so zástupnými `:name:`** (`AuditLog::latestForUser` riadky 55-62, `TrustedDeviceService::issue` riadky 41-58):
```php
$this->db->query(
    'SELECT occurred_at, event ... FROM auth_events WHERE user_id = :u: ORDER BY occurred_at DESC, id DESC LIMIT ' . max(1, $limit),
    ['u' => $userId],
)->getResultArray();
```
`INSERT ... RETURNING id` + `->getRow()` a `(int) $row->id` (TrustedDeviceService riadky 41-60) použiť pri vkladaní entity a verzie.

**Boolean z PG** (`AuditLog` riadok 70):
```php
'success' => in_array($row['success'], [true, 't', 'true', '1', 1], true),
```
Základ pre `DbBool::of()` (Pitfall 3). Dátumy: `service('clock')->today()` (existuje `Clock::today()`, riadky 28-31 v `Clock.php`) a `now()`, nikdy `date()`/`CURRENT_DATE`.

---

### Čítacie služby: `EntityVersionResolver`, `EntityDirectory`, `BankAccountSelector`, `LegalProfileResolver`

**Analóg:** `AuditLog::latestForUser` (vyššie) pre štýl dotazu a mapovanie riadkov na typovaný `array{...}` s PHPDoc. Dotaz verzie k dátumu je v RESEARCH.md § Code Examples ("Verzia platná k dátumu"). `numeric` ostáva string, žiadny `(float)`.

---

### Čisté utility: `SeriesFormat`, `Iban`, `PortalUrlTemplate`, `CcEmails`, `VersionDiff`

**Analóg:** `app/Libraries/Platform/Money.php` (riadky 1-40): `final class`, `declare(strict_types=1)`, súkromný konštruktor + statická továrenská metóda `of()`, striktná validácia regexom s `/D` a `InvalidArgumentException`, žiadny float.

```php
public static function of(string $value): self
{
    if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) !== 1) {
        throw new InvalidArgumentException('Invalid money value.');
    }
    return new self(self::norm(bcadd($value, '0', 2)));
}
```
`SeriesFormat::parse()` -> výnimka `InvalidSeriesFormat` (podtrieda `InvalidArgumentException`), `Iban::of()` rovnaký tvar. Sadzba klienta sa validuje/ukladá ako string cez `Money`/regex (viď Demo nižšie). Mod-97 kód je v RESEARCH.md § Code Examples.

**Test analóg:** `tests/unit/Platform/MoneyTest.php`, `ClockTest.php` (riadky 1-30: `CIUnitTestCase`, `Services::reset()` v `tearDown`, `DataProvider`).

---

### Enumy (`VatRegime`, `BillingMode`, `IdentifierType`, `Country`, `DocLanguage`)

**Analóg:** `app/Libraries/Auth/AuthEvent.php` (backed string enum; `$event->value` sa zapisuje do DB v `AuditLog::record`). Hodnoty musia zodpovedať DB `CHECK` v migrácii.

---

### `app/Controllers/Settings/Entities.php`, `Settings/Clients.php` (controller, request-response, PRG + 422)

**Analógy:** `app/Controllers/Settings/Security.php` (štruktúra, PRG, flash notice, helpery) a `app/Controllers/Demo.php` (validácia, 422, htmx fragment).

**Hlavička a štýl** (Security riadky 5-31): `declare(strict_types=1)`, `namespace App\Controllers\Settings`, `final class X extends BaseController`, `private const`.

**Zobrazenie stránky** (Security riadky 31-37):
```php
public function index(): string
{
    $user = auth()->user();
    assert($user instanceof User);

    return view('settings/security', $this->pageData($user));
}
```
Pozn.: autorizáciu (len `admin`) rieši globálny filter `AuthGate`, žiadny ďalší filter na nové routy netreba.

**PRG po úspechu + flash** (Security riadky 60-62, 102-104):
```php
session()->setFlashdata('app_notice', ['type' => 'info', 'message' => lang('Account.revoked')]);

return redirect()->to('/settings/security');
```
(UI-SPEC vyžaduje `alert-success`; typ v poli `type` zmeniť na `success`, kľúč `app_notice` ostáva.)

**404** (Security riadok 48): `throw PageNotFoundException::forPageNotFound();` pre neexistujúce id.

**Chyba = 422 + re-render** (Security riadky 228-233):
```php
return $this->response
    ->setStatusCode($status)
    ->setBody(view('settings/security', $this->pageData($user) + [$key => $message]));
```

**Whitelist POST + string helper** (Security riadky 276-281):
```php
private function postString(string $name): string
{
    $raw = $this->request->getPost($name);

    return is_string($raw) ? $raw : '';
}
```
ENT-07: nikdy neprijímať `country`/`currency` pri úprave; čítať len explicitný whitelist polí (test: podvrhnuté POST nezmení DB).

**Validácia + htmx fragment + 422** (Demo riadky 20-35, 70-102):
```php
return $this->validateData($values, $rules);   // rules: ['label'=>lang(..), 'rules'=>[..., 'regex_match[/^\d{1,10}(\.\d{1,2})?$/]'], 'errors'=>[...]]
...
return $this->response->setStatusCode(422)
    ->setBody(htmx_view('demo/_calculator', $this->data($values, $errors, null), 'demo/index'));
```
`htmx_view($fragment, $data, $page)` (`app/Helpers/htmx_helper.php`) použiť pre fragment výberu krajiny (`hx-get`, `#entity-regime-fields`). Normalizácia čiarky: `Demo::normalize` (riadky 88-91).

**Formátovanie dátumu v UI** (Security riadky 335-354): `IntlDateFormatter(service('request')->getLocale(), NONE, NONE, config('App')->appTimezone, GREGORIAN, 'd. M. yyyy')`. Ukladať `Y-m-d`.

---

### `app/Config/Routes/entities.php` a `app/Config/Routes/clients.php` (route)

**Analóg:** `app/Config/Routes/settings.php` (riadky 1-13). Súbory v `Config/Routes/` sa načítavajú globom (`app/Config/Routes.php` riadok 12), netreba registrovať. Plány používajú dva súbory: `entities.php` (subjekty, verzie, účty, rady; 03-02, 03-03, 03-04, 03-06, 03-08, 03-09) a `clients.php` (klienti; 03-05, 03-07). Súbor `master.php` sa nevytvára.

```php
<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('settings/security', 'Settings\Security::index');
$routes->post('settings/security/devices/(:num)/revoke', 'Settings\Security::revokeDevice/$1');
```
Všetky zmeny len `post` (CSRF cez `HtmxCsrf`). Poradie: statické cesty (`settings/entities/new`) pred `(:num)`.

---

### Views `app/Views/settings/entities/*`, `settings/clients/*` (view)

**Analógy:** `app/Views/settings/security.php` (extend + sekcie + čiastkové `include`), `_password.php` (formulár s chybou), `_devices.php` (riadok zoznamu, modal potvrdenia, destruktívna akcia), `demo/_calculator.php` (inline `invalid-feedback`, htmx fragment).

**Stránka** (`security.php` riadky 1-10):
```php
<?= $this->extend('layouts/app') ?>
<?= $this->section('subtitle') ?><?= esc(lang('Account.securityTitle')) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?= $this->include('settings/_password') ?>
<?= $this->endSection() ?>
```

**Formulár** (`_password.php` riadky 8-14, 23-26): `method="post"`, `<?= csrf_field() ?>`, `data-submit-once novalidate`, `form-control`, `form-label`, tlačidlo `btn btn-primary btn-touch` so `spinner-border spinner-border-sm d-none`. Karta: `<section class="app-card security-card" aria-labelledby="...">` + `<h2 class="h5">`.

**Chyby poľa** (`demo/_calculator.php` riadky 8-17):
```php
<?php $error = $errors[$name] ?? null; ?>
<input class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" ... value="<?= esc($values[$name] ?? '', 'attr') ?>"
       <?php if ($error !== null): ?>aria-invalid="true" aria-describedby="demo-<?= esc($name, 'attr') ?>-error"<?php endif; ?>>
<div class="invalid-feedback" id="demo-<?= esc($name, 'attr') ?>-error"><?= esc($error) ?></div>
```

**Destruktívna akcia s modalom** (`_devices.php` riadky 17-39): tlačidlo `btn btn-outline-danger btn-sm btn-touch` s `data-bs-toggle="modal"`, modal s `<form method="post" action="/settings/security/devices/<?= (int) $device['id'] ?>/revoke">` + `csrf_field()` + `btn btn-danger w-100`. Použiť pre Ukončiť subjekt, Archivovať klienta/účet.

**Badge** (`_devices.php` riadok 13): `<span class="badge text-bg-secondary">` s textom z `lang()`. Prázdny stav: `fw-semibold` + `text-muted` dvojica (riadky 5-7).

**Pravidlá (strážené testami):** všetky texty `esc(lang('Entities.*'))`, žiadne inline `style`, `on*`, `<script>` (`ViewsCspTest`, `ViewsHardcodedTextTest`), `esc()` na každom výstupe (`'attr'` v atribútoch), `(int)` pre id v URL.

**`app/Views/more/index.php`** (celý súbor, riadky 4-10): pridať dva `<a class="list-group-item list-group-item-action more-item" href="/settings/entities">` nad existujúci odkaz Zabezpečenie (riadok 5):
```php
<a class="list-group-item list-group-item-action more-item" href="/settings/security"><?= esc(lang('Account.securityTitle')) ?></a>
```

---

### `app/Language/{sk,en}/{Entities,Clients}.php` (i18n)

Pozn.: súbor `Audit.php` sa v Phase 3 nevytvára, `AuditLogger` nezobrazuje žiadny preložený text (iba zapisuje do `audit_log`).

**Analóg:** `app/Language/sk/Demo.php` / `Account.php`: `return [ 'key' => 'text', ... ];` s `declare(strict_types=1)`, zástupné `{0}`. `LangKeysTest` (regex `lang\(\s*'([A-Z][A-Za-z]+)\.([A-Za-z0-9_.]+)'`) vyžaduje, aby každý použitý kľúč existoval v `sk`; kľúče musia byť literály v `lang('X.y')` (dynamické `'event_' . $x` ako v Security riadok 318 testom nie sú pokryté). Súbory `Entities` a `Clients` nekolidujú s existujúcimi (`Account, App, Demo, Login, Notify, PasswordReset, Pwa, TwoFactor, Validation`).

---

### `app/Database/Seeds/DevSeeder.php` (úprava)

**Analóg:** ten istý súbor (riadky 17-31): zachovať `assertAllowed(ENVIRONMENT)` a `$this->db = Database::connect('owner');`, nahradiť riadok `CLI::write('DevSeeder: no seed data in phase 1')` idempotentným seedom (SK/PL subjekt, účty, klient All4Net 27.00 EUR, Taskman) cez priamy SQL. Guard test: `tests/unit/Database/DevSeederGuardTest.php` musí ostať zelený.

---

### Testy

**Oprávnenia (ENT-07, insert-only):** `tests/integration/DbRolesTest.php` — `PgTestCase`, `#[Group('integration')]`, `$this->assertPermissionDenied(fn () => $this->rw()->query(...))` (definované v `tests/_support/PgTestCase.php` riadky 60-71; zhoda `/permission denied|must be owner/i`). Pripravené príkazy sú v RESEARCH.md § Code Examples "Test oprávnení".

**Rollback auditu:** `AccountRecovery` vzor (vyššie) + `PgTestCase` `owner()`/`rw()`; služba, ktorá po zápise hodí výnimku, nesmie nechať v DB zmenu ani audit riadok. Čistenie cez `$this->owner()->query('TRUNCATE ...')` (vzor `AuditLogTest::setUp`).

**Feature (formuláre):** `tests/feature/Settings/ChangePasswordTest.php` — `AuthTestCase`, `FrozenClock` cez `Services::injectMock('clock', ...)` a `Services::reset()` v `tearDown`, `$this->withSession()`, `$this->call('post', $url, $data + [csrf_token() => csrf_hash()])`, `loginAs($this->createUser())`. Čas napr. `'2026-10-24T10:00:00+02:00'`. Hranice dňa testovať 31. 10. 23:30 / 1. 11. 00:30 (`ClockTest`).

**Unit:** `tests/unit/Platform/MoneyTest.php`, `ClockTest.php` (viď utility). Existujúce stráže (`LangKeysTest`, `ViewsHardcodedTextTest`, `ViewsCspTest`, PHPStan `ForbiddenCallsRule`, `TimezoneConsistencyTest`) pokryjú nové views a služby, pred písaním kódu skontrolovať, čo zakazujú (`date()`, `float`, `CURRENT_DATE`).

---

## Shared Patterns

### Transakcia + audit v jednej jednotke
**Source:** `app/Libraries/Auth/AccountRecovery.php` riadky 84-103
**Apply to:** `EntityService`, `BankAccountService`, `SeriesService`, `ClientService` (každá zápisová metóda)
Štruktúra `transException(true)->transStart()` / `try` / `transComplete()` / `catch Throwable { transRollback(); throw }` / `finally { transException(false) }`; `AuditLogger::record(...)` ako posledný príkaz pred `transComplete()`.

### Čas a dátumy
**Source:** `app/Libraries/Platform/Clock.php` (`now()`, `today()`), `app/Config/Services.php` riadok 24 (`service('clock')`)
**Apply to:** všetky `valid_from`/`active_to` porovnania, audit `occurred_at`
Nikdy `date()` ani `CURRENT_DATE`. V testoch `FrozenClock`.

### Peniaze
**Source:** `app/Libraries/Platform/Money.php`, `Demo::validates` regex (riadky 78-82)
**Apply to:** `hourly_rate` klienta; `numeric` ako string, bez `float`.

### Autorizácia
**Source:** `app/Filters/AuthGate.php` (globálne, len skupina `admin`)
**Apply to:** všetky nové routy; žiadny ďalší filter.

### CSRF
**Source:** `app/Filters/HtmxCsrf.php`; vo views `<?= csrf_field() ?>`, v testoch `[csrf_token() => csrf_hash()]`
**Apply to:** všetky POST formuláre.

### Chybová odpoveď 422
**Source:** `Security::errorPage` (riadky 228-233), `Demo::invalid` (riadky 97-102)
**Apply to:** všetky validačné chyby formulárov (re-render s hodnotami a chybami).

### Flash notice po PRG
**Source:** `Security.php` riadky 60-62: `session()->setFlashdata('app_notice', ['type' => ..., 'message' => lang(...)])`
**Apply to:** všetky úspešné zápisy.

### Role DB a oprávnenia
**Source:** `GrantsPrivileges`, `provision.sql` default privileges (insert-only pre `app_rw`)
**Apply to:** insert-only: `entity_versions`, `audit_log`; stĺpcový grant: `entities (name_internal, active_from, active_to)`, `entity_bank_accounts`, `invoice_series (format, initial_number)`, `clients`; SELECT-only: `legal_profiles`, `legal_texts`. Nikde DELETE.

## No Analog Found

| File | Role | Data Flow | Dôvod |
|------|------|-----------|-------|
| `grantUpdateColumns` stĺpcové granty (SQL) | migration-support | n/a | existuje len `grantMutable`; vzor SQL z RESEARCH.md Pattern 1 (overené v PG 18.6) |
| Seed `legal_profiles`/`legal_texts` v migrácii | migration | batch | žiadna doterajšia migrácia nesejduje dáta; použiť `$this->db->query('INSERT ...')` v `up()`, obsah z RESEARCH.md Pattern 7 (predbežné znenia, A1/A2) |
| `LegalTextMissing`, `InvalidSeriesFormat` výnimky | utility | n/a | žiadne vlastné výnimky v `app/Libraries`; použiť `InvalidArgumentException`/`RuntimeException` podtriedy |
| `.master-*` CSS triedy | config | n/a | definované v 03-UI-SPEC.md; rytmus a premenné (`--app-border`, `--app-bg`, `--app-tabbar-h`) preberať z existujúceho `public/assets/css/app.css`, nová verzia súboru cez `asset_url` |

## Metadata

**Analog search scope:** `app/Controllers`, `app/Libraries`, `app/Database`, `app/Views`, `app/Language`, `app/Config/Routes`, `tests/`
**Files scanned:** ~25 prečítaných, ~190 vypísaných cez `git ls-files`
**Pattern extraction date:** 2026-10-25
**Pozn. k mená súborov:** `AuditLog` (Auth) už existuje, nový logger MUSÍ byť `App\Libraries\Audit\AuditLogger`.
