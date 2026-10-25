---
phase: 02-identita-a-skor-nasadenie
fixed_at: 2026-10-25T11:05:17Z
review_path: .planning/phases/02-identita-a-skor-nasadenie/02-REVIEW.md
iteration: 1
findings_in_scope: 6
fixed: 6
skipped: 0
status: all_fixed
---

# Phase 2: Code Review Fix Report

**Fixed at:** 2026-10-25T11:05:17Z
**Source review:** .planning/phases/02-identita-a-skor-nasadenie/02-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 6 (WR-01..WR-06; IN-* out of scope)
- Fixed: 6
- Skipped: 0

Verification: `make check` (PHPStan, php-cs-fixer, PHPUnit 358 tests) green, `node --test tests/js` (26) green, `make migrate` + `composer test:migrate` OK, dev `HTTPS_PORT=8443 HTTP_PORT=8080 sh scripts/smoke.sh` = SMOKE OK. Every new test was run against the pre-fix code and failed there.

**Owner action required before the next deploy (WR-06):** follow `docs/ops/staging.md` section 15 on the server. Until then `deploy.sh` stops at its preflight check with a pointer to that section. It makes no compose calls, and the running stack stays unchanged.

## Fixed Issues

### WR-01: nginx auth rate limit is bypassable via `//login` and `/index.php/login`

**Files modified:** `docker/nginx/templates/default.conf.template`, `scripts/smoke.sh`
**Commit:** f16c376
**Applied fix:** The `limit_req` key is now computed from `"$uri|$args"` instead of the raw `$request_uri`. `$uri` is the normalised form: percent-decoded, slashes merged, dot segments resolved. Two map entries cover the CI4 routing forms:
- `/(index.php/)?(login|2fa|password)`
- the query-string form `/?/login` and `/index.php?/login`, which CI4 also routes to the controller. This form was found while verifying and was not in the review.

`limit_req` is also applied in `location /`, before the internal redirect to `/index.php`, and `merge_slashes on` is now explicit. nginx counts each request once (11x 200 then 429 with burst 10, same as `/login` before the fix).

Verified on the dev nginx: `//login`, `/index.php/login`, `/?/login`, `/index.php?/login`, `/%6cogin`, `/x/../login`, `/index.php//2fa` and `/password/forgot` are now throttled. `/`, `/more`, assets, the manifest and `/sw.js` are not (25/25 normal responses).

`smoke.sh` gained a final check: it exhausts the bucket via `/login`, then expects 429 for the bypass forms and 302 for `/`.

### WR-02: A pending session established before a password change or reset survives it

**Files modified:** `app/Controllers/Auth/Login.php`, `app/Controllers/Auth/TwoFactor.php`, `app/Libraries/Auth/AccountRecovery.php`, `tests/_support/AuthTestCase.php`, `tests/feature/Auth/LoginTest.php`, `tests/feature/Auth/TotpVerifyTest.php`
**Commit:** 147517c
**Applied fix:**
- `Login::attempt` stores `pending_epoch` (the auth epoch at the password step).
- `pendingUserOrRedirect()` logs out the pending session if the epoch has moved since then.
- `completeLogin()` stamps `pending_epoch` instead of the current epoch, so a bump that races the check is still rejected by `AuthGate`.
- `setPassword()` deletes the `totp_gateway` identity inside its transaction, as `resetTwoFactor()` already did.
- Tests cover: a password change during pending, an epoch bump during pending, and a completed login that carries the epoch from the password step.

Status: fixed. This is a logic change in the auth flow. The tests cover the scenario in the review, but a human should confirm it.

### WR-03: Password-reset request has no app-level throttle, leaks account existence via timing, and lets anyone invalidate the owner's link

**Files modified:** `app/Libraries/Platform/AfterResponse.php` (new), `app/Config/Services.php`, `app/Controllers/Auth/PasswordReset.php`, `app/Libraries/Auth/PasswordResetService.php`, `tests/feature/Auth/PasswordResetTest.php`, `tests/unit/Platform/AfterResponseTest.php` (new)
**Commit:** a2d5b54
**Applied fix:**
1. **Timing.** Account lookup, token issue, audit and SMTP now all run after the response, through the new `service('afterResponse')`. Under PHP-FPM it registers a shutdown function that does `session_write_close()`, then `fastcgi_finish_request()`, then runs the task. In CLI and tests it runs the task immediately. Before the response, the request does the same work whether or not the account exists. A feature test with a held `AfterResponse` proves no token and no mail exist before the response, and the two response bodies are identical. Verified on the dev FPM stack: the deferred mails arrived in Mailpit.
2. **Throttle.** At most 3 tokens per account per hour and 5 per IP per hour. The counts come from `password_resets` under `pg_advisory_xact_lock`, so no migration was needed. Extra requests get the same response, issue nothing, and log `password_reset.throttled`.
3. **Link invalidation.** A new request no longer deletes still-valid tokens; it only deletes expired unused ones. All unused tokens are still cleared after a successful reset (`setPassword`). The old test `testNewRequestInvalidatesPreviousToken` was replaced by `testNewRequestKeepsPreviousTokenValid` and `testSuccessfulResetInvalidatesOtherOutstandingTokens`.

Status: fixed. Logic and behaviour change; needs human verification.

### WR-04: Password reset works for accounts without enrolled TOTP

**Files modified:** `app/Libraries/Auth/PasswordResetService.php`, `app/Libraries/Auth/AccountRecovery.php`, `tests/feature/Auth/PasswordResetTest.php`, `docs/ops/staging.md`
**Commit:** 7242774
**Applied fix:**
- `request()` issues a token only when `user_security.totp_enabled_at IS NOT NULL`. Other accounts get the same generic response, no token and no mail.
- `findValid()` and `consume()` require active TOTP as well, so a token issued before `user:reset-2fa` cannot lead into enrolment.
- `resetTwoFactor()` deletes unused reset tokens.
- `docs/ops/staging.md` section 12 says that non-enrolled accounts are recovered with `spark user:password` (D-14). It also documents the WR-03 limits.

### WR-05: `LocaleNegotiation` never applies the stored locale for pending users

**Files modified:** `app/Filters/LocaleNegotiation.php`, `tests/feature/Auth/TotpVerifyTest.php`
**Commit:** 4b31bb5
**Applied fix:** Pending sessions now use `Session::getPendingUser()`, because Shield's `Auth::user()` returns null in the pending state. New test: a pending user with `locale = 'en'` and `Accept-Language: sk` gets `<html lang="en">` and the English text on `/2fa/verify`.

### WR-06: Runtime php container receives DB superuser and owner passwords; superuser password passed as env

**Files modified:** `compose.staging.yaml`, `scripts/deploy.sh`, `docker/postgres/init/10-provision.sh`, `docs/ops/staging.md`, `scripts/server-bootstrap.sh`, `.env.example`, `tests/unit/Scripts/DeployGuardTest.php`, `tests/unit/Scripts/StagingSecretsLayoutTest.php` (new)
**Commit:** 62a7b3b
**Applied fix:** Staging secrets are now split by consumer:

| File | Contents | Mounted into |
|------|----------|--------------|
| `.env` | compose and `deploy.sh` keys only, no passwords | nothing (host only) |
| `app.env` | app_rw, mail, `encryption.key`, pepper | php-fpm, as `/var/www/html/.env` |
| `migrate.env` | `app.env` plus `database.owner.*` | only the new one-off `migrate` service (compose profile `migrate`), which `deploy.sh` now uses for `spark migrate -g owner --all` |
| `secrets/db_{superuser,owner,rw}_password` | one password each | the `db` service as Docker secrets |

The db service now uses `POSTGRES_PASSWORD_FILE` and `DB_*_PASSWORD_FILE`; there is no plain `POSTGRES_PASSWORD`. `10-provision.sh` reads the `*_FILE` variants, still accepts plain env for dev, and fails closed if neither is set.

Dev `compose.yaml` and the override are unchanged; `docker compose config` and `make check` pass.

Backward compatibility is handled as a fail-early check. `deploy.sh` runs `preflight_secrets` before any compose call and stops with a message pointing to `docs/ops/staging.md` section 15 if any of these hold:
- a required file is missing, empty or world-readable
- `.env` still contains passwords or CI4 keys
- `app.env` contains owner or superuser keys
- `migrate.env` lacks `database.owner.password`

The only docker call made in that case is the existing `docker logout` trap. `cert-renew.sh` keeps working in the meantime; this was verified with missing secret files.

`docs/ops/staging.md`:
- section 6 is rewritten for fresh installs
- section 7 is updated
- new section 15 gives the exact owner steps for the live server: back up `.env`, move the existing values (nothing is printed or generated), check key names, lengths and password matches without showing values, set permissions (`app.env` 0640 deploy:82, `migrate.env` 0600, `secrets/db_*` 0640 deploy:70, because the postgres init runs as uid 70), redeploy, verify, and roll back if needed.

Verified locally:
- The section-15 steps were run on a mock old-layout `.env`; the resulting layout passes `deploy.sh` preflight.
- With a locally built prod image under a separate compose project: the db initialised from the secret files (roles created), `migrate` ran all migrations as app_owner, and the php container's `.env` had 0 owner/superuser keys and 0 password env vars.
- The superuser password from the file authenticates over TCP.

Remaining owner-only work (cannot be done from here): run section 15 on `/opt/work-tracker` before the next push to `main` is deployed. If the deploy runs first, it fails safely and can be re-run after the split.

## Skipped Issues

None.

---

_Fixed: 2026-10-25T11:05:17Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
