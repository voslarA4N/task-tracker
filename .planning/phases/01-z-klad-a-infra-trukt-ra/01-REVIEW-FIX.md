---
phase: 01-z-klad-a-infra-trukt-ra
fixed_at: 2026-10-24T00:10:00+02:00
review_path: .planning/phases/01-z-klad-a-infra-trukt-ra/01-REVIEW.md
iteration: 1
findings_in_scope: 11
fixed: 11
skipped: 0
partially_fixed: [WR-03, WR-04]
status: partial
---

# Phase 1: Code Review Fix Report

**Fixed at:** 2026-10-24T00:10:00+02:00
**Source review:** .planning/phases/01-z-klad-a-infra-trukt-ra/01-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 11 (2 critical, 9 warnings; Info findings were out of scope)
- Fixed: 11. Nine are fully fixed. WR-03 and WR-04 got the smallest safe change, and the rest of each moves to Phase 12 (production).
- Skipped: 0
- Status is `partial` only because WR-03 and WR-04 are not fully resolved.

**Final checks (main, after fast-forward):**
- `make check`: exit 0. PHPStan main config `[OK] No errors`. PHPStan views config `[OK] No errors`. Self-test `app.forbiddenTime=18 app.forbiddenFloat=20 app.forbiddenBcmath=2`. cs-fixer `Found 0 of 90 files`. PHPUnit `OK (138 tests, 2712 assertions)`.
- `make smoke`: `SMOKE OK`, all 18 lines OK, including the new `asset 404 not immutable` and `js app-alert`. nginx was restarted first so it picks up the WR-05 template change.

## Fixed Issues

### CR-01: CSRF 403 fragment is never shown; the generic "server error" message replaces it

**Files modified:** `public/assets/js/app.js`, `tests/js/app-alert.test.js` (new), `tests/feature/HtmxCsrfTest.php`, `scripts/smoke.sh`, `.github/workflows/ci.yml`
**Commit:** e55bf77
**Status:** fixed: requires human verification (one browser check is left, see below)
**Applied fix:** `app.js` now has an `htmx:afterSwap` listener. When the swap target is `#app-alert`, it removes `hidden`. The `htmx:responseError` listener returns early when `e.detail.target` is `#app-alert`, because in htmx 2 the server fragment is swapped before `responseError` fires. A 403 or 5xx that targets any other element still shows `data-msg-server`. The layout config (`403 swap:true,error:true`) is unchanged, and there is no inline script or style.

Automated guards:
- `tests/js/app-alert.test.js` (`node --test tests/js/`) runs the real `app.js` against a minimal fake DOM. It fires events in htmx 2.0.11 order: beforeRequest, swap, afterSwap, responseError. It checks five things:
  - the CSRF fragment ends up visible and is not overwritten;
  - the result is the same in either event order;
  - a generic 5xx shows the server message;
  - a sendError shows the network message;
  - a swap into another target does not un-hide the alert, and the next request hides it again.
  With the old `app.js`, 2 of the 5 tests fail. The suite runs in CI (new step) and in `make smoke` (`js app-alert`, SKIP if node is missing on the host).
- `HtmxCsrfTest`: the htmx 403 body must equal `view('fragments/alert', ['message' => lang('App.csrfFailed')])`. A new `testLayoutSwaps403IntoVisibleAlert` checks the contract between layout and `app.js`: `#app-alert` is present and hidden, the htmx-config rule for 403 has `swap:true`, `app.js` has the afterSwap listener and the responseError guard, and the JS test exists.

**Still needs a browser:** On `/demo`, delete the CSRF token (for example the `hx-headers` value) and submit. `#app-alert` should show `App.csrfFailed` ("Platnosť formulára vypršala...") with no CSP violation in the console. The fake DOM does not prove real htmx event targets or how the alert renders.

### CR-02: `make reset` production guard is bypassable

**Files modified:** `scripts/db-reset.sh`, `scripts/lib-env-guard.sh` (new), `tests/unit/Scripts/DbResetGuardTest.php` (new)
**Commit:** 15b3681
**Applied fix:** The guard moved into `scripts/lib-env-guard.sh`. It normalizes the value: drops the inline `# comment`, double and single quotes, spaces, tabs and CR. It reads the first `CI_ENVIRONMENT` line, ignores commented lines and accepts an `export` prefix, which matches how CI4 DotEnv reads the file. Only `development` and `testing` are allowed. Everything else is refused, including a missing `.env`, a missing key and an empty value. If `CI_ENVIRONMENT` is set in the process environment, it must also be on the allow-list. `db-reset.sh` also asks the php container for its `CI_ENVIRONMENT`. If the container is unreachable, or the value is set and not allowed, the script refuses. All of this happens before `DROP DATABASE`.
**Test (isolated, dev and test DBs untouched):** `DbResetGuardTest` copies the real `db-reset.sh` and the lib into a temp dir with a temp `.env` and puts a fake `docker` on PATH that only logs its calls. 26 data cases cover quoted, CRLF, tab, wrong-case and staging values, duplicates, commented lines, process env and container env. Separate tests cover an unreachable container and the guard-before-DROP order. Each case asserts either `REFUSED` with no `DROP DATABASE` call, or success. Against the old script, 14 of the 28 tests fail.

### WR-01: preload.php excludes the Postgre driver

**Files modified:** `preload.php`
**Commit:** 26b7914
**Applied fix:** The exclude list now has `/system/Database/MySQLi/` in place of `/system/Database/Postgre/`. I checked this in a locally built prod image with `opcache.preload` and `preload_user=www-data`: `Postgre\Connection` is preloaded and `MySQLi\Connection` is not. Note that preload already exits with 255 under the dev image (`opcache-dev.ini`), with or without this change. CI checks preload with the prod image only.

### WR-02: Prod image makes the entire application tree writable by www-data

**Files modified:** `Dockerfile`
**Commit:** 62db674
**Applied fix:** `--chown=www-data:www-data` is removed from the prod `COPY --from=vendor`. `entrypoint.sh` still creates `writable/` and chowns it. Checked in the prod image: `app`, `vendor`, `public` and `preload.php` are read-only for www-data, `writable/` is writable, preload works, and `php-fpm -t` passes.

### WR-03: Production compose is non-functional and would lose state (partial)

**Files modified:** `compose.yaml`
**Commit:** 82d754c
**Applied fix:** This is the smallest safe change. A header comment in `compose.yaml` says it is a base only and must not be deployed alone. It lists what is missing: `.env` and secrets for php, a `writable` volume, nginx ports, certs and `public/`. It also notes that `compose.prod.yaml` will be added in Phase 12. `docker compose config -q` still validates.
**Not done:** the actual prod compose. It depends on Phase 12 decisions (TLS via certbot, secrets layout, backups, how `public/` reaches nginx), and I did not want to invent that architecture here.

### WR-04: DB passwords are passed as plain env vars and on the psql command line (partial)

**Files modified:** `docker/postgres/provision.sql`, `docker/postgres/init/10-provision.sh`, `scripts/db-reset.sh`, `scripts/test-db.sh`, `.github/workflows/ci.yml`
**Commit:** 378cfb3
**Applied fix:** `provision.sql` now reads the role passwords with `\getenv owner_pw DB_OWNER_PASSWORD` and `\getenv rw_pw DB_RW_PASSWORD`. No caller passes them as `-v` arguments anymore, so they are gone from `/proc/*/cmdline`. If a variable is missing, `:'owner_pw'` stays unsubstituted and `ON_ERROR_STOP` aborts (exit 3, tested). CI now passes them with `-e`. I tested it with a throwaway DB `tt_wr04_probe`, then dropped it. app_rw logged in with the existing password and the dev and test DBs were untouched. `make check` (test-db.sh) then ran the new provisioning path for real.
**Not done:** compose `secrets:` and `POSTGRES_PASSWORD_FILE`. The passwords are still in the db container environment, visible through `docker inspect`. This belongs to the Phase 12 production secrets design.

### WR-05: `/assets/` 404 responses are cached as `immutable` for a year

**Files modified:** `docker/nginx/templates/default.conf.template`, `scripts/smoke.sh`
**Commit:** 6b29866
**Applied fix:** `always` is removed from the `/assets/` `Cache-Control` line. The security-header snippet keeps `always`. `nginx -t` passes. A new smoke check `asset 404 not immutable` passes, and live, `/assets/vendor/nope.js` returns a 404 with no Cache-Control.

### WR-06: Error pages load unversioned `/assets/css/app.css`

**Files modified:** `app/Views/errors/html/error_400.php`, `error_404.php`, `production.php`, `tests/feature/LayoutTest.php`
**Commit:** b4d53eb
**Applied fix:** The error pages now use `asset_url('css/app.css')` and `service('request')->getLocale()` for `lang`. A `try/catch (Throwable)` falls back to the old literal values, so an error page cannot fail on its own while rendering an early error. New test `testErrorPagesUseVersionedCssAndRequestLocale`. Live 404: `href="/assets/css/app.css?v=..."`, `lang="sk"`.

### WR-07: The ForbiddenCallsRule guard rail has large gaps

**Files modified:** `tests/_support/PhpStan/ForbiddenCallsRule.php`, `tests/_support/PhpStan/Fixtures/ForbiddenCalls.php`, `tests/_support/PhpStan/selftest.php`, `phpstan.views.neon` (new), `composer.json` (scripts only), `tests/unit/Platform/MoneyTest.php`
**Commit:** 10cdc37
**Applied fix:**
- **Float:** added `floor`, `ceil`, `fdiv`, `fmod`, `pow`, `sqrt`, float literals, the `/` and `**` operators (also `/=` and `**=`) outside Money, `FILTER_VALIDATE_FLOAT`, `settype(..., 'float'|'double')`, and printf-family functions with `%f`, `%e` or `%g` formats.
- **Time:** added `date_create_(immutable_)from_format`, `getdate`, `localtime`, `idate`, `hrtime`, `gettimeofday`, `strftime` and `gmstrftime`. Every static `DateTime` and `DateTimeImmutable` factory is now forbidden outside Clock.
- **Views:** `phpstan.views.neon` (level 0, only this rule) analyses `app/Views` and runs as part of `composer stan`. I checked that it catches `date()`, `number_format()` and `/` in a view.
- **Self-test:** the fixture now expects 18 time, 20 float and 2 bcmath errors. A new `allowed()` block must stay clean.
- **MoneyTest:** its deliberate `1.5` gets an explicit ignore.

`IntlDateFormatter` is deliberately not forbidden. It formats a value it is given and does not read the clock, and later phases will need it for locale-aware dates.

### WR-08: Secret scan and .gitignore miss `.env.*` variants

**Files modified:** `.gitignore`, `.dockerignore`, `.github/workflows/ci.yml`
**Commit:** 4ef1dfe
**Applied fix:** `.gitignore` now covers `.env.*` and keeps `!.env.example`. I also added `.env.*` to `.dockerignore`, because the prod `COPY . .` would otherwise put such files into the image. The CI scan now matches `(^|/)\.env(\.[^/]+)?$` and excludes `.env.example`. Tested: `.env.production` and `.env.local.bak` are ignored, `.env.example` is not, and the scan flags `.env.production` and `x/.env.bak` on sample input but passes the current tree. No gitleaks was added.

### WR-09: The default-group override in Database.php

**Files modified:** `app/Config/Database.php`, `tests/unit/Config/DatabaseConfigTest.php` (new)
**Commits:** 60ba4be, af7b794 (cs-fixer layout of the test, found by the final `make check`)
**Applied fix:** The logic is now a pure static method, `Database::migrationOwnerGroup()`. In `testing`, only `tests_owner` may replace the `tests` default. With `-g owner`, the default stays `tests` and the run fails closed as app_rw. argv is parsed the same way as `CLI::parseCommandLine()` plus `Console::run()`. I checked that `spark --no-header migrate:status -g owner` really runs `migrate:status` in CI4, and it is detected. The group names now live in one place, `migrationOwnerGroups()`. `-g=owner` is deliberately not recognized, because CI4 itself does not parse it as group `owner`. DatabaseConfigTest has 15 argv cases plus a check that testing defaults to `tests`.

---

_Fixed: 2026-10-24T00:10:00+02:00_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
