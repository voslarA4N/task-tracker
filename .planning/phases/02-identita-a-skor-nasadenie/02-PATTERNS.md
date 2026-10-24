# Phase 2: Identita a skoré nasadenie - Pattern Map

**Mapped:** 2026-10-24
**Files analyzed:** 52 (new/modified, grouped where they share a pattern)
**Analogs found:** 40 / 52 (the remaining 12 are deployment/PWA/Shield-specific files with no in-repo analog; use RESEARCH.md code examples)

Scope searched: `app/`, `tests/`, `docker/`, `scripts/`, `compose*.yaml`, `Dockerfile`, `.github/workflows/ci.yml`, `public/assets`. The repo is a Phase 1 skeleton: one demo controller, two filters, one migration, one spark command, one Clock library. Conventions below come from those files, so copy them literally.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Filters/AuthGate.php` | filter (deny-by-default) | request-response | `app/Filters/HtmxCsrf.php` | exact (role + htmx branch) |
| `app/Filters/DeviceLogin.php` | filter | request-response | `app/Filters/HtmxCsrf.php` | role-match |
| `app/Filters/LocaleNegotiation.php` | filter | request-response | `app/Filters/HtmxVary.php` | role-match |
| `app/Config/Filters.php` (modify) | config | n/a | itself (lines `aliases`, `globals`) | exact |
| `app/Config/Auth.php`, `AuthGroups.php` (new) | config | n/a | `app/Config/Security.php` / `Session.php` (Config class style) | partial |
| `app/Config/Session.php`, `Encryption.php`, `Cookie.php` (modify) | config | n/a | themselves | exact |
| `app/Config/Routes.php` (modify) | route | request-response | itself | exact |
| `app/Controllers/Auth/Login.php`, `TwoFactor.php`, `PasswordReset.php` | controller | request-response (form POST + validation) | `app/Controllers/Demo.php` | exact |
| `app/Controllers/Settings/Security.php` | controller | CRUD + htmx fragment | `app/Controllers/Demo.php` | exact |
| `app/Controllers/Pwa.php` (`/sw.js`, `/offline`) | controller | request-response | `app/Controllers/Home.php` | role-match |
| `app/Authentication/Actions/TotpAction.php` | service (Shield action) | request-response | none in repo | no analog |
| `app/Libraries/Auth/TotpService.php`, `BackupCodeService.php`, `TrustedDeviceService.php`, `LockoutService.php`, `AuditLog.php`, `PasswordResetService.php`, `SecurityNotifier.php` | service | CRUD / transform | `app/Libraries/Platform/Clock.php` + `app/Models/DemoCalculationModel.php` | role-match |
| `app/Libraries/Platform/PsrClock.php` | utility (adapter) | transform | `app/Libraries/Platform/Clock.php` | exact |
| `app/Models/*` for new tables (if used) | model | CRUD | `app/Models/DemoCalculationModel.php` | exact |
| `app/Commands/UserCreate.php`, `UserReset2fa.php`, `UserPassword.php` | command | CLI batch | `app/Commands/MailTest.php` | exact |
| `app/Database/Migrations/*_AuthSecurityTables.php`, `*_GrantShieldMutable.php`, `*_AuthEvents.php` | migration | DDL | `app/Database/Migrations/2026-10-23-000001_CreateDemoCalculations.php` + `app/Database/Support/GrantsPrivileges.php` | exact |
| `app/Language/{sk,en}/Login.php`, `TwoFactor.php`, `Account.php`, `Pwa.php` | config (lang) | n/a | `app/Language/sk/App.php` | exact |
| `app/Views/layouts/auth.php` | view (layout) | n/a | `app/Views/layouts/app.php` | exact |
| `app/Views/auth/*`, `settings/security*.php`, `pwa/offline.php`, `layouts/_install_hint.php` | view | request-response | `app/Views/demo/index.php`, `demo/_calculator.php`, `fragments/alert.php` | exact |
| `app/Views/layouts/_nav_items.php` (modify: Viac -> Zabezpečenie) | view | n/a | itself | exact |
| `public/assets/js/pwa.js` (new, 02-11/02-13: install hint + SW register; loaded by both layouts and `/offline`) | frontend | event-driven | `public/assets/js/app.js` | role-match |
| `public/assets/js/app.js` (modify: only Alpine components `copySecret` 02-12, `codesAck` 02-15) | frontend | event-driven | itself | exact |
| `public/manifest.webmanifest`, `public/assets/icons/*` | static | n/a | none | no analog |
| `docker/nginx/templates/default.conf.template` (modify) | config | request-response | itself | exact |
| `docker/nginx/realip/cloudflare.conf` (new, 02-04; baked to `/etc/nginx/realip.d/` only in the staging nginx image, included via `include /etc/nginx/realip.d/*.conf`) | config | n/a | `snippets/security-headers.conf.template` | role-match |
| `docker/nginx/Dockerfile` (new, bakes `public/`) | config | build | root `Dockerfile` | role-match |
| `compose.staging.yaml` (new) | config | n/a | `compose.yaml` + `compose.override.yaml` | exact |
| `scripts/deploy.sh`, `scripts/smoke-staging.sh`, `scripts/cf-ips-check.sh`, `scripts/make-icons.sh` | script | batch | `scripts/smoke.sh`, `scripts/lib-env-guard.sh` | role-match |
| `.github/workflows/ci.yml` (+ deploy job) | CI | batch | itself | exact |
| `.env.example` (modify) | config | n/a | itself | exact |
| `tests/_support/AuthTestCase.php` | test support | n/a | `tests/_support/PgTestCase.php` | exact |
| `tests/feature/Auth/*Test.php`, `tests/feature/PwaTest.php` | test (feature) | request-response | `tests/feature/HtmxCsrfTest.php` | exact |
| `tests/integration/{Lockout,BackupCode,TotpReplay,AuditLog,Roles}Test.php` | test (integration) | CRUD | `tests/integration/DbRolesTest.php` | exact |
| `tests/unit/Auth/*Test.php` | test (unit) | transform | `tests/unit/Platform/ClockTest.php` | exact |
| `tests/js/install-hint.test.js` | test (js) | event-driven | `tests/js/app-alert.test.js` | exact |
| Existing tests to update (`DemoPersistTest`, `LayoutTest`, `HtmxCsrfTest`) | test | request-response | themselves | exact |

## Pattern Assignments

### `app/Filters/AuthGate.php` and `app/Filters/DeviceLogin.php` (filter, request-response)

**Analog:** `app/Filters/HtmxCsrf.php` (whole file, 1-40)

**Conventions:** `declare(strict_types=1)`, `final class ... implements FilterInterface`, `before()` returns `?ResponseInterface` (null = continue), `after()` returns null. htmx branch keyed on `$request->getHeaderLine('HX-Request') === 'true'`.

```php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class HtmxCsrf implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        try {
            service('security')->verify($request);
        } catch (SecurityException) {
            $response = service('response')->setStatusCode(403);

            if ($request->getHeaderLine('HX-Request') === 'true') {
                return $response
                    ->setHeader('HX-Retarget', '#app-alert')
                    ->setHeader('HX-Reswap', 'innerHTML')
                    ->setBody(view('fragments/alert', ['message' => lang('App.csrfFailed')]));
            }
            return $response->setBody(view('errors/csrf_403', ['title' => lang('App.title')]));
        }
        return null;
    }
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    { return null; }
}
```

AuthGate adaptation: anonymous htmx -> `setStatusCode(401)->setHeader('HX-Redirect', '/login')` (RESEARCH A12, test it); non-htmx -> `redirect()->to('/login')` (or `/2fa` when pending). Allowlist (as planned in 02-06 and pinned by a test): `ALLOW_EXACT = ['login', 'logout', 'offline', 'sw.js', 'manifest.webmanifest']`, `ALLOW_PREFIX = ['2fa', 'password/', 'assets/']`. There is no `/health` route (container healthchecks do not use an HTTP app route), so it is not allowlisted. Use `service('clock')` for the 2 h idle check (never `time()`/`new DateTime`).

**Registration pattern** (`app/Config/Filters.php` lines `aliases`/`globals`): add aliases `'authgate' => AuthGate::class`, `'devicelogin' => DeviceLogin::class`, `'locale' => LocaleNegotiation::class`. Globals `before` is currently `['csrf']`; required order per RESEARCH diagram: `forcehttps` (in `required`), `devicelogin`, `csrf`, `authgate`. `htmxvary` stays in `globals.after`. Keep the `use App\Filters\...` import block alphabetical as in the file.

### `app/Filters/LocaleNegotiation.php` (filter)

**Analog:** `app/Filters/HtmxVary.php` (minimal filter shape, `after()` returns `ResponseInterface`). Core: `service('request')->setLocale()` from `negotiate('language', config(App::class)->supportedLocales)` with `sk` default; must not throw when the negotiated language has no lang dir (D-23). Layout reads locale via `service('request')->getLocale()` (`app/Views/layouts/app.php` line `<html lang=...>`).

---

### `app/Controllers/Auth/Login.php`, `TwoFactor.php`, `PasswordReset.php`, `Settings/Security.php` (controller, request-response)

**Analog:** `app/Controllers/Demo.php`

**Imports + class shape** (lines 1-13): `declare(strict_types=1); namespace App\Controllers; final class Demo extends BaseController`. For subfolders use `App\Controllers\Auth` and extend `\App\Controllers\BaseController` (helpers `asset`, `security`, `htmx` are preloaded there).

**Validate-then-render with 422** (lines 25-36, 80-97):
```php
$values = $this->postedValues();
if (! $this->validates($values)) {
    return $this->invalid($values, $this->validator->getErrors());
}
...
private function validates(array $values): bool
{
    $rules = [
        'hours' => [
            'label'  => lang('Demo.hours'),
            'rules'  => ['required', 'regex_match[/^\d{1,5}(\.\d{1,2})?$/]', 'greater_than[0]'],
            'errors' => ['regex_match' => lang('Demo.errorDecimal', [lang('Demo.hours')])],
        ],
    ];
    return $this->validateData($values, $rules);
}
private function invalid(array $values, array $errors): ResponseInterface
{
    return $this->response
        ->setStatusCode(422)
        ->setBody(htmx_view('demo/_calculator', $this->data($values, $errors, null), 'demo/index'));
}
```
Reuse: `htmx_view($fragment, $data, $page)` from `app/Helpers/htmx_helper.php` for pages that also work as fragments; every user-visible string via `lang('File.key')`; model/service calls take `service('clock')->now()` (Demo.php line 53). Login failures must show the single generic copy from UI-SPEC regardless of cause (RESEARCH Anti-patterns). Auth forms must work without JS (classic POST + redirect). Return type convention: `ResponseInterface|string`.

### `app/Controllers/Pwa.php` (controller: `/sw.js`, `/offline`)

**Analog:** `app/Controllers/Home.php` (`return view('home/index', ['title' => lang('App.title')]);`). For `sw.js` return `$this->response->setContentType('application/javascript')->setHeader('Cache-Control','no-cache')->setHeader('Service-Worker-Allowed','/')->setBody(...)`; build precache list with `asset_url()` (`app/Helpers/asset_helper.php`). SW body: RESEARCH "Service worker" snippet verbatim. `/offline` must contain no CSRF token or user data, so use a layout that does not emit `csrf_hash()` (note `layouts/app.php` puts `csrf_hash()` in `hx-headers`; use `layouts/auth.php` or a dedicated minimal view).

---

### `app/Libraries/Auth/*` services and `app/Libraries/Platform/PsrClock.php` (service/utility)

**Analog:** `app/Libraries/Platform/Clock.php` (+ `FrozenClock.php`)

```php
namespace App\Libraries\Platform;
class Clock {
    public function __construct(?string $timezone = null)
    { $this->timezoneName = $timezone ?? config(App::class)->appTimezone; }
    public function now(): DateTimeImmutable { return new DateTimeImmutable('now', $this->timezone()); }
}
final class FrozenClock extends Clock { public function __construct(string $instant, ?string $timezone = null) {...} }
```
Rules: all time through `service('clock')` (30 day expiry, 15 min lock, TOTP step, 2 h idle, 60 min reset). `PsrClock` is the final class from RESEARCH "PSR-20 adapter" wrapping `Clock` (same `final class`, `declare(strict_types=1)`, `readonly` ctor property style). Services should accept `Clock` in the constructor so tests inject `FrozenClock`. Atomic claims (TOTP step, backup code, lockout upsert) use raw `$db->query(...)` and `$db->affectedRows()`; SQL snippets are in RESEARCH "Code Examples" (Lockout upsert, Atomic backup-code consume).

### `app/Models/*` (model, CRUD) for `trusted_devices`, `backup_codes`, etc.

**Analog:** `app/Models/DemoCalculationModel.php` (read it before writing models; it uses the `app_rw` default connection, `record(...)` taking `DateTimeImmutable $now`, and string-typed numeric results). Not re-read here; planner should open it once. Note `app_rw` has only SELECT/INSERT unless the table got `grantMutable()`.

---

### `app/Commands/UserCreate.php`, `UserReset2fa.php`, `UserPassword.php` (command, CLI)

**Analog:** `app/Commands/MailTest.php`

```php
final class MailTest extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'mail:test';
    protected $description = '...';
    protected $usage       = 'mail:test [subject]';
    protected $arguments   = ['subject' => '...'];

    public function run(array $params): int
    {
        ...
        CLI::write($email->printDebugger(['headers']), 'red');
        return EXIT_ERROR;
        ...
        CLI::write('Odoslané: ' . $subject, 'green');
        return EXIT_SUCCESS;
    }
}
```
Adapt: `$group = 'Auth'`, names `user:create` / `user:reset-2fa` / `user:password`; password via `CLI::prompt` interactively (D-09, never as argument; use a hidden read, not `CLI::prompt` echo, if possible); return `EXIT_SUCCESS/EXIT_ERROR`; messages in Slovak; write audit event in `auth_events`. Commands run as `app_rw` (RESEARCH spike criterion 6), so the tables they touch need grants.

---

### Migrations (migration, DDL)

**Analog:** `app/Database/Migrations/2026-10-23-000001_CreateDemoCalculations.php` + `app/Database/Support/GrantsPrivileges.php`

```php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

final class CreateDemoCalculations extends Migration
{
    public function up(): void
    {
        // Bez grantMutable(): tabulka je insert-only pre app_rw (default privileges).
        $this->db->query(<<<'SQL'
            CREATE TABLE demo_calculations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                ...
                created_at timestamptz NOT NULL,
                CONSTRAINT demo_calculations_hours_check CHECK (hours > 0)
            )
            SQL);
        $this->db->query("COMMENT ON TABLE demo_calculations IS '...'");
    }
    public function down(): void { $this->db->query('DROP TABLE IF EXISTS demo_calculations'); }
}
```
Mutable tables: `use \App\Database\Support\GrantsPrivileges;` then `$this->grantMutable('trusted_devices');` (trait lines: validates `^[a-z_][a-z0-9_]*$`, runs `GRANT UPDATE, DELETE ON TABLE ... TO app_rw`). File naming `YYYY-MM-DD-NNNNNN_Name.php`, `final class`, raw SQL heredoc, `timestamptz` for our tables (Shield uses `timestamp without time zone`, RESEARCH Pitfall 16). Per RESEARCH inventory: `auth_events` (and Shield `auth_logins`, `auth_token_logins`) stay insert-only; grant mutable for `users`, `auth_identities`, `auth_remember_tokens`, `auth_groups_users`, `auth_permissions_users`, `user_security`, `trusted_devices`, `backup_codes`, `auth_lockouts`, `password_resets`. Shield's own migration runs via `spark migrate -g owner --all`; the grants migration must sort after it. Apply with `spark migrate -g owner`, tests with `composer test:migrate` (`tests_owner`).

---

### `app/Language/{sk,en}/Login.php`, `TwoFactor.php`, `Account.php`, `Pwa.php`

**Analog:** `app/Language/sk/App.php` (`declare(strict_types=1); return ['key' => 'text', ...]`, aligned `=>`). Every key must exist in both `sk` and `en` (`tests/unit/I18n/LangKeysTest.php`). Do NOT name files `Auth.php` or `Security.php` (collide with Shield/CI4 core, RESEARCH Pitfall 6; UI-SPEC names must be renamed). Shield's own language strings may also need `sk` overrides if its messages surface.

### Views (`layouts/auth.php`, `auth/*`, `settings/security*.php`, `pwa/offline.php`, `_install_hint.php`)

**Analog:** `app/Views/layouts/app.php`, `app/Views/errors/csrf_403.php`, `app/Views/layouts/_nav_items.php`

Head/meta/asset block to copy into `layouts/auth.php` (drop header/nav/tabbar):
```php
helper(['asset', 'security']);
...
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<link rel="stylesheet" href="<?= esc(asset_url('vendor/bootstrap-5.3.8.min.css'), 'attr') ?>">
<script src="<?= esc(asset_url('js/app.js'), 'attr') ?>" defer></script>
<body hx-headers="<?= esc(json_encode([csrf_header() => csrf_hash()], JSON_THROW_ON_ERROR), 'attr') ?>" ...>
<div id="app-alert" role="alert" aria-live="polite" hidden></div>
```
Page views extend a layout and fill sections (csrf_403.php): `<?= $this->extend('layouts/app') ?>`, `<?= $this->section('content') ?> ... <?= $this->endSection() ?>`, `<div class="app-card">`. Add to head for PWA: `<link rel="manifest" href="/manifest.webmanifest">`, `apple-touch-icon`, `apple-mobile-web-app-capable`, `apple-mobile-web-app-title` = "Work Tracker". Output: `esc(lang('X.key'))`, attributes `esc(..., 'attr')`. No inline `<script>`, `style=`, `on*=` (`tests/unit/Security/ViewsCspTest.php` scans all of `app/Views` recursively, regexes at lines 17-23) and no hardcoded text (`ViewsHardcodedTextTest`). Alpine uses the CSP build only (`x-data="appShell"` registered in `app.js`; no inline expressions beyond what the CSP build allows). `_nav_items.php`: replace the disabled `<span aria-disabled="true"><?= esc(lang('App.navMore')) ?></span>` with a link to the Viac/Zabezpečenie page and keep the `aria-current` path logic.

### `public/assets/js/pwa.js` (new) and `public/assets/js/app.js` (modify)

**Analog:** `app.js` itself. IIFE `(function () { 'use strict'; ... })();` with `document.addEventListener('DOMContentLoaded', ...)`, ES5 `var`/function style, strings read from `body.dataset.msg*` (data attributes rendered via `lang()`), never hardcoded. SW registration (`navigator.serviceWorker.register('/sw.js', { scope: '/' })`, 02-13) and install hint logic (`window.WtPwa.shouldShowInstallHint`, `navigator.standalone`, localStorage dismissal, 02-11) live in the separate `pwa.js` (it must also run on `/offline`, which loads no htmx/Alpine), testable with `node --test` like `tests/js/app-alert.test.js`. `app.js` only receives Alpine components (`copySecret`, `codesAck`).

---

### Nginx: `docker/nginx/templates/default.conf.template` (modify) and `docker/nginx/realip/cloudflare.conf`

**Analog:** the current template and `snippets/security-headers.conf.template`.

Existing facts to preserve:
- `${APP_DOMAIN}` envsubst templating, `ssl_certificate /etc/nginx/certs/work-tracker.loc.pem;` hardcoded (lines 14-15): parametrize (`${SSL_CERT}`/`${SSL_KEY}`) with dev defaults in `compose.yaml` (`environment:` block already uses `${APP_DOMAIN:-work-tracker.loc}` default syntax).
- Every `location` that calls `add_header` must re-include `include /etc/nginx/conf.d/snippets/security-headers.conf;` (see `^~ /assets/` and `= /index.php` blocks) because `add_header` inheritance resets per level. New `location = /sw.js` and `= /manifest.webmanifest` blocks need it too.
- PHP location pattern to copy for `/sw.js` (served by controller): `fastcgi_hide_header Cache-Control; fastcgi_hide_header X-Powered-By; add_header Cache-Control "no-store" always; fastcgi_pass php:9000; include fastcgi_params; fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php; fastcgi_param HTTPS on;` but with `Cache-Control "no-cache"` for sw.js.
- `limit_req_zone` + `map` go in `http` context: template files in `/etc/nginx/templates/*.template` land in `conf.d/`, which is http-level, so top-level `map`/`limit_req_zone` before `server {}` works (the file already has top-level `server_tokens off;`). Use `$request_uri` (not `$uri`) inside `= /index.php`.
- Realip snippet (as planned in 02-04): plain file `docker/nginx/realip/cloudflare.conf` with `set_real_ip_from <CF range>;` lines + `real_ip_header CF-Connecting-IP; real_ip_recursive off;`, copied by `docker/nginx/Dockerfile` to `/etc/nginx/realip.d/` and pulled into the `server` block by `include /etc/nginx/realip.d/*.conf;` (empty glob in dev = unchanged behaviour). Not an envsubst template. `scripts/cf-ips-check.sh` compares it with the live CF lists.
- HSTS comes from `${NGINX_HSTS_VALUE}` (dev `max-age=300`, compose.yaml default `max-age=31536000`); staging should set a deliberate value.

### `compose.staging.yaml` (new)

**Analog:** `compose.yaml` (services php/nginx/db, postgres tuning flags `shared_buffers=64MB ...`, `pgdata:/var/lib/postgresql`, healthchecks) + `compose.override.yaml` (what dev adds that staging must NOT have: `bind mounts`, `ports 127.0.0.1`, `mailpit`, `vendor`/`writable` volumes). Staging differs by: `image: ghcr.io/voslara4n/task-tracker-php:${IMAGE_TAG}` instead of `build:`, named volume for `writable`, nginx image baked with `public/` (no bind mount), certs at `/etc/letsencrypt`, published `80/443`, `.env` bind-mounted read-only for uid 82 (Pitfall 12), `certbot/dns-cloudflare:v5.8.0` service/profile, DB secrets via env (WR-04 stays deferred). Keep the `name: task-tracker` header and the explanatory-comment-at-top style of `compose.yaml`. Pin images to minor, as in `compose.yaml`.

### `Dockerfile` (modify) / `docker/nginx/Dockerfile` (new)

**Analog:** root `Dockerfile` multi-stage layout (`base` -> `dev`/`vendor` -> `prod`, `composer install --no-dev ... && composer dump-autoload --no-dev --classmap-authoritative` at the `vendor` stage). New packages (shield, otphp, bacon, psr/clock) land through `composer.json`; check `ext-iconv`/sodium exist (RESEARCH says verified). The nginx Dockerfile copies `public/` from the same build context so asset hashes match the PHP image tag.

### Scripts: `scripts/deploy.sh`, `smoke-staging.sh`, `cf-ips-check.sh`, `make-icons.sh`

**Analog:** `scripts/smoke.sh` and `scripts/lib-env-guard.sh`

Conventions: `#!/bin/sh`, `set -eu`, `cd "$(dirname "$0")/.."`, Slovak comments, helper trio and exit summary:
```sh
FAILS=0
pass() { echo "OK    $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
skip() { echo "$1"; }
...
if [ "$FAILS" -eq 0 ]; then echo "SMOKE OK"; exit 0; fi
echo "SMOKE FAILED ($FAILS)"; exit 1
```
Reusable checks to lift into `smoke-staging.sh` (change domain handling to read `APP_DOMAIN`/arg, drop Mailpit and docker-compose-ps parts): `2. HTTPS + HTTP/2` (`curl -w '%{http_code} %{http_version}'`), `3. redirect` 301, `check_headers()` (CSP no `unsafe-`, HSTS, nosniff, Referrer-Policy, Permissions-Policy, no `x-powered-by`, no `server: nginx/`, `no-store` for html), gzip, vendor-cache immutable, cookie flags loop. Replace the `/` 200 expectation with `302 -> /login` (AUTH-09), assert `/sw.js` `cache-control: no-cache`, `cf-cache-status` not `HIT` for HTML, session cookie value changes across login (Pitfall 3). Never use `curl -k`. For `deploy.sh`: use `lib-env-guard.sh` idea inversely (refuse to run migrate without a verified non-empty `pg_dump -Fc` + `pg_restore -l`). `scripts/lib-env-guard.sh` `require_dev_env` must NOT be sourced by deploy/smoke-staging (they run in `production`); destructive scripts (`db-reset.sh`) keep it. Add a unit test mirroring `tests/unit/Scripts/DbResetGuardTest.php` if a new script is destructive.

### `.github/workflows/ci.yml` (modify)

**Analog:** itself. Keep job `check` untouched (steps: secrets-not-in-git grep, node tests, `docker build --target dev` and `--target prod`, DB provisioning via `psql -f provision.sql`, `.env` from `.env.example` with `sed`, `composer install && composer test:migrate && composer check`, preload check). Add `deploy` job per RESEARCH skeleton: `needs: check`, `if: github.ref == 'refs/heads/main' && github.event_name == 'push'`, `permissions: { contents: read, packages: write }` (workflow-level default is `contents: read`), uses `actions/checkout@v4`/`setup-node@v4` pinned majors like existing steps. Add a step running `spark migrate` from the **prod** image against the CI DB (RESEARCH deploy note). The existing `concurrency: ci-${{ github.ref }}` has `cancel-in-progress: true`; the deploy job needs its own `concurrency` group with `cancel-in-progress: false`. Extend the secrets-in-git regex only if new file types (e.g. `cloudflare.ini`) could be committed.

---

### Tests

**Feature tests** — analog `tests/feature/HtmxCsrfTest.php`:
```php
#[Group('feature')]
final class HtmxCsrfTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTPS', 'on');
    }
    private function htmx(array $extra = []): array { return ['HX-Request' => 'true'] + $extra; }
    private function post(array $headers, array $post): TestResponse
    { return $this->withHeaders($headers)->call('post', '/demo/calculate', $post); }
```
Use `#[Group('feature')]`, `@internal` docblock, `final class`, `$result->assertStatus(...)`, `$result->response()->getHeaderLine('HX-Retarget')`, token via `csrf_hash()` + `X-CSRF-TOKEN` header. Existing tests that hit `/demo` (`HtmxCsrfTest`, `DemoPersistTest`, `LayoutTest`) will now be redirected by AuthGate: update them to log in through `AuthTestCase` helper (FeatureTestTrait `withSession(...)` with Shield session shape) rather than weakening the gate. `HtmxCsrfTest::testLayoutSwaps403IntoVisibleAlert` asserts the layout contract `<div id="app-alert" ... hidden>` and htmx-config meta: keep `layouts/app.php` compatible.

**Integration tests** — analog `tests/integration/DbRolesTest.php` + `tests/_support/PgTestCase.php`:
```php
#[Group('integration')]
final class DbRolesTest extends PgTestCase
{
    public static function setUpBeforeClass(): void { ... self::ownerConn() ... CREATE TABLE ... }
    public function testRwCanInsertAndSelect(): void { $this->rw()->query("INSERT ..."); }
    $this->assertPermissionDenied(fn () => $this->rw()->query("UPDATE _probe_insert_only SET note='b'"));
```
Use `$this->owner()` for setup/teardown and `$this->rw()` for the code under test; `assertPermissionDenied()` to prove `auth_events` is insert-only for `app_rw` (AUDIT test; also extend `DbRolesTest` with a Shield `touchIdentity`/`addGroup` as `app_rw` per Pitfall 5). Lockout/TOTP tests inject `new FrozenClock('2026-10-24T10:00:00+02:00')` (ISO 8601 `DATE_ATOM`, see `FrozenClock` ctor) via `Services::injectMock('clock', ...)`. `tests/_support/AuthTestCase.php` extends `PgTestCase` (or `CIUnitTestCase` + `FeatureTestTrait`) and offers: create user with TOTP, pending-login helper, device helper.

**Unit tests** — analog `tests/unit/Platform/ClockTest.php` and `tests/unit/Config/SecurityConfigTest.php` (e.g. new `EncryptionConfigTest`: assert `driver === 'Sodium'`). Not re-read here; open one before writing.

**JS tests** — analog `tests/js/app-alert.test.js` (node `--test`, run in CI step "JS testy" and `make smoke`).

**Static tests that new code must satisfy:** `ViewsCspTest`, `ViewsHardcodedTextTest`, `LangKeysTest` (sk/en key parity), PHPStan (`phpstan.neon.dist`, `phpstan.views.neon`; custom `ForbiddenCallsRule` in `tests/_support/PhpStan/ForbiddenCallsRule.php` - read it before using `time()`, `float`, `date()` etc.), `php-cs-fixer` (`.php-cs-fixer.dist.php`). `phpunit.dist.xml` has `failOnDeprecation/Warning/Risky/Notice`: otphp must get a PSR clock or the deprecation fails tests.

## Shared Patterns

### Deny-by-default + htmx-aware responses
**Source:** `app/Filters/HtmxCsrf.php` lines 17-32; `app/Helpers/htmx_helper.php` (`is_htmx()`, `is_htmx_fragment_request()`, `htmx_view()`)
**Apply to:** `AuthGate`, `DeviceLogin`, every auth controller. Fragment vs full page is decided by `htmx_view`; error alerts go to `#app-alert` via `HX-Retarget`/`HX-Reswap` and `view('fragments/alert', ['message' => lang(...)])`.

### All time via Clock
**Source:** `app/Libraries/Platform/Clock.php`, `FrozenClock.php`; `Demo.php` line 53 `service('clock')->now()`
**Apply to:** lockouts, TOTP step, device expiry/Max-Age, idle timeout, reset-token expiry, audit timestamps, otphp (`PsrClock`).

### DB privileges
**Source:** `app/Database/Support/GrantsPrivileges.php` + `CreateDemoCalculations` comment
**Apply to:** every new migration. Default = insert-only for `app_rw`; call `grantMutable()` only for non-document, non-audit tables. Migrations run only as `app_owner` (`spark migrate -g owner`), never from the app.

### Texts, CSP, assets
**Source:** `app/Language/sk/App.php`, `app/Views/layouts/app.php`, `app/Helpers/asset_helper.php`, `ViewsCspTest`
**Apply to:** all views, offline page, install hint, SW precache. `asset_url('path')` (adds version) for every asset; `lang()` for every string; no inline script/style/handler.

### CSRF
**Source:** `Filters::$globals['before'] = ['csrf']` -> `HtmxCsrf`; layout `hx-headers` with `csrf_header()`/`csrf_hash()`
**Apply to:** login, 2FA, reset, settings forms (classic forms need a hidden `csrf_field()`; `Config\Security` must stay non-regenerating, see `tests/unit/Config/SecurityConfigTest.php`). Do not add auth routes to a CSRF exemption.

### Cookies
**Source:** `app/Config/Cookie.php` (`secure=true`, `httponly=true`, `samesite='Lax'`, `path='/'`, `domain=''`, `prefix=''`), `app/Config/Session.php` (`FileHandler`, `cookieName='ci_session'`, `expiration=7200`, `savePath=WRITEPATH.'session'`, `matchIP=false`)
**Apply to:** change `Session::$cookieName` to `__Host-ci_session`, `expiration` to 2592000 (RESEARCH Pattern 4); build the device cookie with `CodeIgniter\Cookie\Cookie` + `withPrefix('__Host-')`. Smoke script already checks `Secure/HttpOnly/SameSite=Lax` on all Set-Cookie headers (smoke.sh item 9) - the new cookies must satisfy it.

### Spark commands and Slovak CLI messages
**Source:** `app/Commands/MailTest.php` (group/name/usage/arguments, `CLI::write(..., 'green'|'red')`, `EXIT_SUCCESS/EXIT_ERROR`).

### Shell scripts
**Source:** `scripts/smoke.sh` (`set -eu`, pass/fail/skip, summary) and `scripts/lib-env-guard.sh`.

## No Analog Found

| File | Role | Data Flow | Reason / use instead |
|---|---|---|---|
| `app/Authentication/Actions/TotpAction.php` | Shield action | request-response | No Shield in repo yet. RESEARCH "TotpAction (gateway)" snippet; spike test `ShieldPendingStateTest` first |
| `app/Libraries/Auth/TotpService.php` | service | transform | RESEARCH "TotpService: step matching" (use `at()` loop, NOT `verify($c,null,1)`) and "QR SVG inline" |
| `app/Config/Auth.php`, `AuthGroups.php` | config | n/a | RESEARCH "Auth config essentials" (extend `\CodeIgniter\Shield\Config\Auth`) |
| `public/manifest.webmanifest`, icons, `scripts/make-icons.sh` | static/script | n/a | UI-SPEC + CONTEXT D-21/D-22; PNG 180/192/512/maskable from committed SVG |
| Service worker body | frontend | event-driven | RESEARCH "Service worker" snippet |
| Cloudflare realip snippet / `limit_req` | nginx config | n/a | RESEARCH "nginx pieces" |
| `certbot` DNS-01 service, Hetzner firewall, CF dashboard settings | ops | n/a | RESEARCH Staging sections / owner checklist (manual) |
| `scripts/deploy.sh` (pg_dump gate + migrate) | script | batch | RESEARCH "GHA deploy skeleton" + `deploy.sh` paragraph |
| GHCR build/push + SSH deploy job | CI | batch | RESEARCH "GHA deploy skeleton" |
| Mail `log` transport | service | event-driven | Config `app/Config/Email.php` exists (protocol/SMTP via `.env`, Mailpit in dev); a custom metadata-only logging transport has no analog |
| iPhone UAT checklist | doc | n/a | RESEARCH Validation "manual" row |

## Metadata

**Analog search scope:** `app/**`, `tests/**`, `docker/**`, `scripts/**`, `compose*.yaml`, `Dockerfile`, `.github/workflows/ci.yml`, `public/assets/js`, `.env.example`.
**Files scanned:** ~110 tracked files listed; ~25 read in full or in part.
**Not re-read (open before use):** `app/Models/DemoCalculationModel.php`, `tests/unit/Platform/ClockTest.php`, `tests/unit/Config/SecurityConfigTest.php`, `tests/_support/PhpStan/ForbiddenCallsRule.php`, `app/Config/Email.php`, `app/Views/demo/*`, `app/Helpers/asset_helper.php`, `02-UI-SPEC.md` (view-level details such as exact copy and component layout).
**Pattern extraction date:** 2026-10-24
