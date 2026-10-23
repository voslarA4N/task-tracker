---
phase: 01-z-klad-a-infra-trukt-ra
reviewed: 2026-10-23T21:30:12Z
depth: standard
files_reviewed: 96
files_reviewed_list:
  - .dockerignore
  - .env.example
  - .github/workflows/ci.yml
  - .gitignore
  - .php-cs-fixer.dist.php
  - Dockerfile
  - Makefile
  - app/Commands/MailTest.php
  - app/Config/App.php
  - app/Config/ContentSecurityPolicy.php
  - app/Config/Cookie.php
  - app/Config/Database.php
  - app/Config/Email.php
  - app/Config/Filters.php
  - app/Config/Routes.php
  - app/Config/Security.php
  - app/Config/Services.php
  - app/Config/Session.php
  - app/Controllers/BaseController.php
  - app/Controllers/Demo.php
  - app/Controllers/Home.php
  - app/Database/Migrations/2026-10-23-000001_CreateDemoCalculations.php
  - app/Database/Seeds/DevSeeder.php
  - app/Database/Support/GrantsPrivileges.php
  - app/Filters/HtmxCsrf.php
  - app/Filters/HtmxVary.php
  - app/Helpers/asset_helper.php
  - app/Helpers/htmx_helper.php
  - app/Language/en/App.php
  - app/Language/en/Demo.php
  - app/Language/en/Validation.php
  - app/Language/sk/App.php
  - app/Language/sk/Demo.php
  - app/Language/sk/Validation.php
  - app/Libraries/Platform/Clock.php
  - app/Libraries/Platform/FrozenClock.php
  - app/Libraries/Platform/Money.php
  - app/Models/DemoCalculationModel.php
  - app/Views/demo/_calculator.php
  - app/Views/demo/_list.php
  - app/Views/demo/index.php
  - app/Views/errors/csrf_403.php
  - app/Views/errors/html/error_400.php
  - app/Views/errors/html/error_404.php
  - app/Views/errors/html/production.php
  - app/Views/fragments/alert.php
  - app/Views/home/index.php
  - app/Views/layouts/_nav_items.php
  - app/Views/layouts/app.php
  - compose.override.yaml
  - compose.yaml
  - composer.json
  - docker/nginx/templates/default.conf.template
  - docker/nginx/templates/snippets/security-headers.conf.template
  - docker/php/conf.d/app.ini
  - docker/php/conf.d/opcache-dev.ini
  - docker/php/conf.d/opcache-prod.ini
  - docker/php/entrypoint.sh
  - docker/php/fpm/zz-www.conf
  - docker/postgres/init/10-provision.sh
  - docker/postgres/provision.sql
  - phpstan.neon.dist
  - phpstan.selftest.neon
  - phpunit.dist.xml
  - preload.php
  - public/assets/css/app.css
  - public/assets/js/app.js
  - scripts/certs.sh
  - scripts/db-reset.sh
  - scripts/doctor.sh
  - scripts/lib-mkcert.sh
  - scripts/smoke.sh
  - scripts/spikes/mpdf-fpm.sh
  - scripts/spikes/mpdf.php
  - scripts/test-db.sh
  - scripts/vendor-assets.sh
  - tests/_support/PgTestCase.php
  - tests/_support/PhpStan/Fixtures/ForbiddenCalls.php
  - tests/_support/PhpStan/ForbiddenCallsRule.php
  - tests/_support/PhpStan/selftest.php
  - tests/feature/DemoPersistTest.php
  - tests/feature/HtmxCsrfTest.php
  - tests/feature/LayoutTest.php
  - tests/integration/DbRolesTest.php
  - tests/integration/DemoCalculationsTest.php
  - tests/integration/MoneyDbParityTest.php
  - tests/integration/MpdfSpikeTest.php
  - tests/integration/TimezoneConsistencyTest.php
  - tests/unit/BootTest.php
  - tests/unit/Config/SecurityConfigTest.php
  - tests/unit/Database/DevSeederGuardTest.php
  - tests/unit/I18n/LangKeysTest.php
  - tests/unit/I18n/ViewsHardcodedTextTest.php
  - tests/unit/Platform/ClockTest.php
  - tests/unit/Platform/MoneyTest.php
  - tests/unit/Security/ViewsCspTest.php
findings:
  critical: 2
  warning: 9
  info: 10
  total: 21
status: issues_found
---

# Phase 1: Code Review Report

**Reviewed:** 2026-10-23T21:30:12Z
**Depth:** standard
**Files Reviewed:** 96
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I reviewed the Phase 1 walking skeleton: Docker/nginx/PG provisioning, the CI4 config, the htmx CSRF filter, Money/Clock, the demo calculator, the scripts, CI, and the tests.

The core money path holds up. `Money::timesHours` multiplies at scale 4. That is exact for 2-decimal by 2-decimal inputs. It then rounds once with `HalfAwayFromZero`, which matches PostgreSQL `round(numeric, 2)` and the DB CHECK constraint. No float is used anywhere. The DB role model is sound: app_rw gets SELECT and INSERT only by default, has no TRUNCATE, and cannot run DDL. The `migrate -g owner` switch to the default group fails closed when it is not detected, because app_rw cannot create the `migrations` table.

Two defects need a fix before this ships:
1. Because of how htmx 2 orders its swap and error events, `app.js` overwrites the server-rendered CSRF 403 alert. The CSRF failure message the plan calls for is never shown.
2. The production guard in `db-reset.sh` can be bypassed by a quoted value or a CRLF line ending in `.env`. That guard is the only thing standing in front of `DROP DATABASE ... WITH (FORCE)`.

The warnings mostly cover the production half of the infrastructure: preload excludes the Postgre driver, the whole app tree is writable by www-data, the prod compose is incomplete, the DB superuser password is a plain env var instead of a secret, and `/assets/` 404s are cached as immutable. They also cover gaps in the guard rails (the PHPStan rule, the secret scan).

## Critical Issues

### CR-01: CSRF 403 fragment is never shown; the generic "server error" message replaces it

**File:** `public/assets/js/app.js:27`, `app/Views/layouts/app.php:16,52`, `app/Filters/HtmxCsrf.php:28-32`
**Issue:** `responseHandling` sets 403 to `swap: true, error: true`. In htmx 2.0.11 (confirmed in the vendored `htmx-2.0.11.min.js`), the swap into `HX-Retarget: #app-alert` runs first, then `htmx:responseError` fires when `error` is true. The `htmx:responseError` listener in `app.js` then calls `show(body.dataset.msgServer)`, which overwrites the box with `textContent`. On top of that, the fragment is swapped into `<div id="app-alert" ... hidden>` and nothing removes `hidden`, so even without the listener the fragment would stay invisible.

The net effect: on a CSRF failure the user sees "Na serveri nastala chyba. Skúste to znova." instead of `App.csrfFailed` ("Platnosť formulára vypršala. Obnovte stránku…"). The planned truth in 01-08 ("403 s fragmentom v `#app-alert`") only holds at the HTTP level. `HtmxCsrfTest` and `smoke.sh` only check headers and body, so neither catches this.
**Fix:** Pick one mechanism. Either have the error listener skip responses that were already swapped, or make 403 a non-error swap and un-hide the alert after the swap:
```js
body.addEventListener('htmx:responseError', function (e) {
  if (e.detail.xhr && e.detail.xhr.status === 403) { return; } // fragment already swapped
  show(body.dataset.msgServer);
});
body.addEventListener('htmx:afterSwap', function (e) {
  if (e.detail.target && e.detail.target.id === 'app-alert') {
    e.detail.target.removeAttribute('hidden');
  }
});
```
Also add a JS-level or smoke assertion that the visible alert text equals `App.csrfFailed`.

### CR-02: `make reset` production guard is bypassable and stands in front of `DROP DATABASE ... WITH (FORCE)`

**File:** `scripts/db-reset.sh:5-10`
**Issue:** The guard parses `.env` with `cut -d= -f2 | tr -d ' "'`. That strips spaces and double quotes, but not single quotes or `\r`. All of these are valid CI4 dotenv syntax, and the last is common on a `/mnt/c` checkout edited from Windows:
- `CI_ENVIRONMENT = 'production'` yields `'production'`
- `CI_ENVIRONMENT = production` with CRLF line endings yields `production\r`

Neither string equals `production`, so the script goes on to `DROP DATABASE IF EXISTS task_tracker WITH (FORCE)`. The guard also ignores a `CI_ENVIRONMENT` set in the real process environment. The DevSeeder guard runs after the drop and protects nothing. This is a data-loss risk on the live accounting DB.
**Fix:** Normalize the value fully and fail closed. Better still, ask the running container, which is the ground truth:
```sh
ENV_VALUE=$(docker compose exec -T php php -r 'require "vendor/autoload.php"; (new CodeIgniter\Config\DotEnv(".")).load(); echo getenv("CI_ENVIRONMENT") ?: "production";' 2>/dev/null \
  || grep -E '^CI_ENVIRONMENT' .env | head -n1 | cut -d= -f2- | tr -d " \"'\r\t")
case "$ENV_VALUE" in development|testing) ;; *) echo "REFUSED: CI_ENVIRONMENT=$ENV_VALUE" >&2; exit 1;; esac
```
(Allow-list `development`/`testing` rather than deny-listing `production`.)

## Warnings

### WR-01: preload.php excludes the Postgre driver (the one in use) and preloads MySQLi instead

**File:** `preload.php:44-49`
**Issue:** The exclude list was copied from the CI4 sample, which assumes MySQL. `/system/Database/Postgre/` is excluded, while `/system/Database/MySQLi/` (unused) is preloaded. The only DB driver the app uses gets no preload benefit, and unused code is kept in shared memory on a 512 MB box.
**Fix:** Replace `'/system/Database/Postgre/'` with `'/system/Database/MySQLi/'` in `exclude`.

### WR-02: Prod image makes the entire application tree writable by www-data

**File:** `Dockerfile:31`
**Issue:** `COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html` gives the FPM worker user write access to `app/`, `vendor/`, `public/`, `preload.php` and `.env` if one is ever mounted. Any file-write primitive, such as a future upload or PDF path bug, then becomes persistent code injection. Only `writable/` needs to be writable.
**Fix:** Copy as root (no `--chown`). Let `entrypoint.sh` keep chowning only `writable/` (it already does, at line 5).

### WR-03: Production compose is non-functional and would lose state

**File:** `compose.yaml:4-25`, `.dockerignore:10`, `docker/php/fpm/zz-www.conf:6`
**Issue:** Without the override file:
- `php` has no `.env`: it is excluded from the image, not mounted, and FPM runs with `clear_env=yes`. CI4 therefore boots with an empty DB password and the default `baseURL`.
- `php` has no `writable` volume, so sessions, logs, the mPDF cache and later the issued PDFs live in the container layer and are lost on `up --build`.
- `nginx` has no `ports`, no `/etc/nginx/certs` and no `/var/www/html/public` mount, so `/assets/` is a 404 and the TLS cert paths fail at startup.

The prod target is built in CI, which gives a false sense that prod works.
**Fix:** Either mark `compose.yaml` explicitly as a base that prod must extend (and add a `compose.prod.yaml`), or add the `.env` bind mount or secrets, a `writable` named volume, nginx ports, the certs volume, and a shared `public` (or an nginx image that contains `public/`).

### WR-04: DB passwords are passed as plain env vars and on the psql command line

**File:** `compose.yaml:29-32`, `docker/postgres/init/10-provision.sh:5-7`, `scripts/db-reset.sh:11`, `scripts/test-db.sh:6`
**Issue:** CLAUDE.md requires `POSTGRES_PASSWORD_FILE` (a Docker secret) and "no secrets in compose". Here `POSTGRES_PASSWORD`, `DB_OWNER_PASSWORD` and `DB_RW_PASSWORD` sit in the db container environment, where `docker inspect` shows them in clear text. They are also passed as `psql -v owner_pw=...` arguments, which any process in the container can read from `/proc/*/cmdline`.
**Fix:** Use compose `secrets:` with `POSTGRES_PASSWORD_FILE`. Have the init script read the role passwords from `/run/secrets/*`, and feed them to psql via stdin (`\set owner_pw \`cat /run/secrets/db_owner\``) rather than argv.

### WR-05: `/assets/` 404 responses are cached as `immutable` for a year

**File:** `docker/nginx/templates/default.conf.template:31-36`
**Issue:** `add_header Cache-Control "public, max-age=31536000, immutable" always;` also applies to the `=404` from `try_files`. A request that hits a missing file, for example during a deploy race or a typo, is cached by the browser and the PWA for a year. That breaks the page until the user clears the cache.
**Fix:** Drop `always` from the `Cache-Control` line. The security-header snippet can keep `always`.

### WR-06: Error pages load unversioned `/assets/css/app.css`, which is served as immutable

**File:** `app/Views/errors/html/error_400.php:9`, `error_404.php:9`, `production.php:9`
**Issue:** The layout uses `asset_url('css/app.css')` (with `?v=mtime`), but the error pages hardcode `/assets/css/app.css` with no version. Combined with the 1-year immutable cache, error pages keep the first-seen stylesheet indefinitely after any CSS change. They also hardcode `lang="sk"` regardless of locale.
**Fix:** Use `asset_url('css/app.css')` (load the `asset` helper in these views), and `service('request')->getLocale()` for `lang`.

### WR-07: The ForbiddenCallsRule guard rail has large gaps (views excluded, float-producing ops not caught)

**File:** `phpstan.neon.dist:6-8`, `tests/_support/PhpStan/ForbiddenCallsRule.php:26-35`
**Issue:** `app/Views` is excluded from PHPStan entirely. Views are exactly where `number_format()`, `round()` and `date()` tend to creep in when amounts and dates are formatted, so the "no float, time only via Clock" rule is not enforced there. The rule also misses common float sources: the `/` operator, `floor`, `ceil`, `fdiv`, `pow`, `sqrt`, `settype(..., 'float')`, `FILTER_VALIDATE_FLOAT`, `sprintf('%f')`, float literals. It misses time functions too: `date_create_from_format`, `date_create_immutable_from_format`, `getdate`, `localtime`, `idate`, `hrtime`, `DateTime::createFromImmutable/createFromInterface`, `IntlDateFormatter`. For a project whose core value is "totals always match", a guard with these holes gives false confidence.
**Fix:** Analyse `app/Views` (at least with this rule; baseline other errors if needed). Extend `FLOAT_FUNCTIONS` and `TIME_FUNCTIONS` and add `Node\Expr\BinaryOp\Div` and `Node\Scalar\Float_` (PhpParser 5; `DNumber` in v4) checks outside `Money`. Add fixture cases to `selftest.php`.

### WR-08: Secret scan and .gitignore miss `.env.*` variants

**File:** `.github/workflows/ci.yml:35`, `.gitignore:2`
**Issue:** Only an exact `.env` is ignored and scanned. `.env.production`, `.env.local` and `.env.bak` (typical when preparing deployment) are neither gitignored nor caught by `grep -E '(^|/)\.env$'`, so real SMTP, DB or encryption keys can be committed and pass CI.
**Fix:** In `.gitignore` add `.env.*` and `!.env.example`. In CI use `grep -E '(^|/)\.env(\..+)?$' | grep -v '\.env\.example$'`, and consider a real scanner (gitleaks).

### WR-09: The default-group override in Database.php can defeat the "testing uses tests" safety, and its argv detection is brittle

**File:** `app/Config/Database.php:137-151`
**Issue:**
1. The block runs after the `ENVIRONMENT === 'testing'` override and replaces it. With `CI_ENVIRONMENT=testing php spark migrate -g owner`, the default connection switches to the live `task_tracker` DB, which is exactly what the comment at lines 134-136 says must not happen.
2. Detection requires `argv[1]` to be the command. `php spark --no-header migrate -g owner` or `-g=owner` are not detected. This fails closed (app_rw cannot create `migrations`), but it does so with a confusing permission error.
3. Group names are hardcoded in two places (`in_array($g, ['owner','tests_owner'])`).
**Fix:** In testing, allow only `tests_owner`:
```php
$allowed = ENVIRONMENT === 'testing' ? ['tests_owner'] : ['owner', 'tests_owner'];
$cmd = current(array_filter(array_slice($argv, 1), static fn ($a) => ! str_starts_with((string) $a, '-'))) ?: '';
if (PHP_SAPI === 'cli' && str_starts_with((string) $cmd, 'migrate')) { /* also accept -g=<group> */ }
```

## Info

### IN-01: The demo page returns 500 once the stored total exceeds numeric(12,2)

**File:** `app/Models/DemoCalculationModel.php:41-46`, `app/Controllers/Demo.php:110-121`
**Issue:** `totalAmount()` goes through `Money::sum`, which throws `OverflowException` above 9 999 999 999.99. `data()` is called on every request, including the GET and the error paths, and the exception is not caught, so the whole `/demo` page breaks permanently once that sum is reached (two saves of 9 999 999 999.99 × 1.00 are enough). This is demo-only, but the same pattern (an aggregate bounded by the column type) will recur for yearly turnover totals.
**Fix:** Catch the exception in `data()`, or let aggregates use a wider bound than a single column value.

### IN-02: DevSeeder swaps `$this->db` but leaves `$this->forge` on the app_rw group

**File:** `app/Database/Seeds/DevSeeder.php:28`
**Issue:** `Seeder::__construct` already created `db` and `forge` on the default group. Only `db` is replaced, so any future `$this->forge` use or `$this->call()` sub-seeder runs as app_rw. In `testing`, `'owner'` also points at the dev DB rather than the test DB.
**Fix:** Construct with `Database::connect('owner')` via `parent::__construct($config, $db)`, or set `$this->forge = Database::forge($this->db)` as well. Pick `tests_owner` when ENVIRONMENT is testing.

### IN-03: Non-htmx `POST /demo/calculations` has no Post/Redirect/Get

**File:** `app/Controllers/Demo.php:51-53`
**Issue:** The full-page form submit renders the page directly in the POST response, so a browser refresh re-inserts the row. That is harmless for the demo, but it is a pattern to avoid for invoice issuing.
**Fix:** Return `redirect()->to('/demo')` when the request is not htmx.

### IN-04: nginx `location ~ /\.` will block `/.well-known/acme-challenge/`

**File:** `docker/nginx/templates/default.conf.template:43-45`
**Issue:** The planned certbot webroot flow needs `/.well-known/`.
**Fix:** Add `location ^~ /.well-known/acme-challenge/ { root /var/www/certbot; }` before the deny rule when prod TLS is added.

### IN-05: The `vendor-assets.sh` hashes are generated, never verified

**File:** `scripts/vendor-assets.sh:37`
**Issue:** `SHA256SUMS` is written after download, so a tampered or changed package is silently accepted on re-run.
**Fix:** Commit the expected hashes and run `sha256sum -c` after extraction, or compare against npm `dist.integrity`.

### IN-06: Composer constraints deviate from CLAUDE.md

**File:** `composer.json:22,64`
**Issue:** `phpunit/phpunit: ^11.5`, whereas CLAUDE.md says to keep the appstarter pin `^10.5.16` until CIUnitTestCase support is verified. `minimum-stability: dev` allows dev releases of transitive dependencies that have no stable release.
**Fix:** Document the verification in CLAUDE.md or pin back. Use `minimum-stability: stable`.

### IN-07: `mail:test` and the mPDF spike ship in the prod image

**File:** `app/Commands/MailTest.php:15`, `Dockerfile:25`, `scripts/spikes/mpdf.php`
**Issue:** `COPY . .` puts `tests/`, `scripts/`, `.github/`, the compose files and the Makefile into the prod image. `mail:test` can send through the production SMTP to a hardcoded `dev@work-tracker.loc`. Neither is web-reachable (nginx passes only `index.php`), but both add surface.
**Fix:** Add `tests`, `scripts`, `.github`, `Makefile`, `compose*.yaml` and `phpstan*`/`phpunit*` to a prod-specific ignore, or `rm` them in the vendor stage. Guard `MailTest` with `ENVIRONMENT !== 'production'`.

### IN-08: Weak spots in the test suite

**File:** `tests/unit/Platform/MoneyTest.php:151`, `tests/unit/I18n/ViewsHardcodedTextTest.php:20-27`, `tests/unit/Security/ViewsCspTest.php:40-46`
**Issue:**
- `testResultAlwaysHasTwoDecimals` uses unseeded `mt_rand`, so a failure would not be reproducible.
- `ViewsHardcodedTextTest` has a duplicated, orphaned docblock.
- `ViewsCspTest` scans line by line, so a `<script` tag split across lines (`<script\n>`) is not detected.
**Fix:** Call `mt_srand()` with a fixed seed, remove the stray docblock, and run the CSP regexes over the whole file content with the `s` modifier.

### IN-09: The unused language key `Demo.savedAt` and duplicated DB passwords in `.env.example`

**File:** `app/Language/sk/Demo.php:19`, `app/Language/en/Demo.php:19`, `.env.example:9-44`
**Issue:** `savedAt` is never used. Each DB password must be kept in sync in two to three places by hand (the `DB_*_PASSWORD` and `database.*.password` entries), which invites drift that only shows up as an auth failure.
**Fix:** Remove the key. Document a single source, for example by generating the `database.*` entries in `make` or reading `DB_RW_PASSWORD` in `Config\Database::__construct`.

### IN-10: The CI action is not SHA-pinned, and the prod HSTS default is easy to downgrade

**File:** `.github/workflows/ci.yml:31`, `.env.example:6`
**Issue:** `actions/checkout@v4` is a mutable tag. Also, if `.env.example` is copied to prod unchanged, its `NGINX_HSTS_VALUE = max-age=300` overrides the 1-year default in `compose.yaml`.
**Fix:** Pin to a commit SHA. Mark the HSTS line as dev-only in `.env.example`, or have `doctor.sh` and the prod checks reject `max-age < 31536000` in production.

---

_Reviewed: 2026-10-23T21:30:12Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
