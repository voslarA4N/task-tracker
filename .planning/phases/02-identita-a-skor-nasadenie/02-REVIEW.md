---
phase: 02-identita-a-skor-nasadenie
reviewed: 2026-10-25T10:18:43Z
depth: standard
files_reviewed: 95
files_reviewed_list:
  - .env.example
  - .github/workflows/ci.yml
  - Makefile
  - app/Authentication/Actions/TotpAction.php
  - app/Commands/NotifyTest.php
  - app/Commands/ReadsSecretTrait.php
  - app/Commands/UserCreate.php
  - app/Commands/UserPassword.php
  - app/Commands/UserReset2fa.php
  - app/Config/App.php
  - app/Config/Auth.php
  - app/Config/AuthGroups.php
  - app/Config/Autoload.php
  - app/Config/Email.php
  - app/Config/Encryption.php
  - app/Config/Filters.php
  - app/Config/Routes.php
  - app/Config/Routes/auth.php
  - app/Config/Routes/password.php
  - app/Config/Routes/pwa.php
  - app/Config/Routes/settings.php
  - app/Config/Session.php
  - app/Config/Settings.php
  - app/Controllers/Auth/Login.php
  - app/Controllers/Auth/PasswordReset.php
  - app/Controllers/Auth/TwoFactor.php
  - app/Controllers/More.php
  - app/Controllers/Pwa.php
  - app/Controllers/Settings/Security.php
  - app/Database/Migrations/2026-10-24-000001_GrantShieldTables.php
  - app/Database/Migrations/2026-10-24-000002_CreateUserSecurity.php
  - app/Database/Migrations/2026-10-24-000003_CreateAuthLockouts.php
  - app/Database/Migrations/2026-10-24-000004_CreateAuthEvents.php
  - app/Database/Migrations/2026-10-24-000005_AddTotpToUserSecurity.php
  - app/Database/Migrations/2026-10-24-000006_CreateBackupCodes.php
  - app/Database/Migrations/2026-10-24-000007_CreateTrustedDevices.php
  - app/Database/Migrations/2026-10-24-000008_CreatePasswordResets.php
  - app/Database/Support/GrantsPrivileges.php
  - app/Filters/AuthGate.php
  - app/Filters/DeviceLogin.php
  - app/Filters/LocaleNegotiation.php
  - app/Libraries/Auth/AccountRecovery.php
  - app/Libraries/Auth/AuditLog.php
  - app/Libraries/Auth/AuthEvent.php
  - app/Libraries/Auth/BackupCodeService.php
  - app/Libraries/Auth/DeviceLabel.php
  - app/Libraries/Auth/LockoutService.php
  - app/Libraries/Auth/PasswordResetService.php
  - app/Libraries/Auth/SecurityNotifier.php
  - app/Libraries/Auth/TotpService.php
  - app/Libraries/Auth/TrustedDeviceService.php
  - app/Libraries/Auth/UserProvisioner.php
  - app/Libraries/Mail/AppMailer.php
  - app/Libraries/Platform/PsrClock.php
  - app/Views/auth/_backup_codes_grid.php
  - app/Views/auth/backup_codes.php
  - app/Views/auth/backup_verify.php
  - app/Views/auth/forgot.php
  - app/Views/auth/login.php
  - app/Views/auth/reset.php
  - app/Views/auth/totp_setup.php
  - app/Views/auth/totp_verify.php
  - app/Views/emails/password_reset.php
  - app/Views/emails/security_notice.php
  - app/Views/errors/forbidden_403.php
  - app/Views/layouts/_install_hint.php
  - app/Views/layouts/_nav_items.php
  - app/Views/layouts/app.php
  - app/Views/layouts/auth.php
  - app/Views/more/index.php
  - app/Views/pwa/offline.php
  - app/Views/pwa/service_worker.js
  - app/Views/settings/_devices.php
  - app/Views/settings/_events.php
  - app/Views/settings/_password.php
  - app/Views/settings/_twofactor.php
  - app/Views/settings/backup_codes.php
  - app/Views/settings/security.php
  - compose.staging.yaml
  - compose.yaml
  - composer.json
  - docker/nginx/Dockerfile
  - docker/nginx/realip/cloudflare.conf
  - docker/nginx/templates/default.conf.template
  - public/assets/css/app.css
  - public/assets/js/app.js
  - public/assets/js/pwa.js
  - public/manifest.webmanifest
  - scripts/cert-renew.sh
  - scripts/cf-ips-check.sh
  - scripts/deploy.sh
  - scripts/make-icons.sh
  - scripts/server-bootstrap.sh
  - scripts/smoke-staging.sh
  - scripts/smoke.sh
findings:
  critical: 0
  warning: 6
  info: 9
  total: 15
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-10-25T10:18:43Z
**Depth:** standard
**Files Reviewed:** 95
**Status:** issues_found

## Narrative Findings (AI reviewer)

## Summary

I reviewed the whole Phase 2 identity slice: the Shield + custom TOTP gateway flow, the trusted-device cookie, lockout, password reset, settings/security, migrations and grants, nginx/compose/deploy/CI, views and the PWA JS. I cross-checked the vendored Shield `Session` authenticator (`checkAction`, `startLogin`, `login`, `logout`, `getUser`) inside the running php container. I also tested the nginx rate limit against the dev stack.

The core 2FA design holds up. Login completes only through `checkAction()` after a verified TOTP or backup code. TOTP replay is prevented atomically (`claimStep`). Backup codes are spent atomically. The device cookie is `__Host-`, rotated with a grace window, and checked against `revoked_at` and the epoch on every request. Session IDs are regenerated at both the password and the 2FA step. Every view escapes its output. I found no exploitable auth bypass, so there are no BLOCKERs.

The warnings fall into four groups:
- The nginx login rate limit can be bypassed. This is verified with live requests.
- The password-reset flow has gaps: no throttling, a timing oracle, and a takeover path for accounts that have not enrolled TOTP yet.
- A pending (half-authenticated) session survives a password change.
- The runtime container holds DB superuser and owner credentials.

## Warnings

### WR-01: nginx auth rate limit is bypassable via `//login` and `/index.php/login`

**File:** `docker/nginx/templates/default.conf.template:5-9`
**Issue:** The `limit_req` key is derived from `$request_uri` with the anchored regex `~^/(login|2fa|password)`. `$request_uri` is the raw, unnormalised URI. Requests to `//login`, `//2fa/verify`, `//password/forgot` or `/index.php/login` get an empty key, so no limit applies, but CodeIgniter still routes them to the same controllers. I confirmed this against the dev stack (`127.0.0.1:8443`) with 35 GETs per path and one minute between bursts:
```
/login:            11x 200, 24x 429
//login:           35x 200
/index.php/login:  35x 200
```
As a result, the edge throttle described in AUTH-04 does not protect against password spraying, TOTP guessing or reset-mail flooding. Only the app-level lockout remains, and it is per account and per IP.
**Fix:** Key on the normalised `$uri` captured before the internal rewrite, or apply `limit_req` in a location that matches the normalised path. The simplest option is to match the original URI loosely and also cover the index.php form:
```nginx
map $request_uri $auth_limit_key {
    ~^/+(index\.php/+)?(login|2fa|password) $binary_remote_addr;
    default "";
}
```
Also add `merge_slashes on;` (the default, but keep it explicit). Add `//login` and `/index.php/login` cases to `scripts/smoke.sh` so the limit stays verified.

### WR-02: A pending session established before a password change or reset survives it and can still complete login

**File:** `app/Controllers/Auth/TwoFactor.php:424-444, 471-475`; `app/Libraries/Auth/AccountRecovery.php:84-93`
**Issue:** `setPassword()` bumps `auth_epoch` and revokes devices, but it does not invalidate sessions that are in the Shield *pending* state. `pendingUserOrRedirect()` checks only `pending_since`. `completeLogin()` then stamps the *new* epoch into the session (`'auth_epoch' => $devices->currentEpoch($userId)`), so `AuthGate` accepts it.

Failure scenario: an attacker who knows the old password, and also holds a backup code or a TOTP from a stolen phone or an exported `.txt` file, is sitting on `/2fa/verify`. The owner notices and changes or resets the password. Within the 10-minute window the attacker submits the code and gets a fully valid session under the new epoch. The password change was supposed to lock them out. `setPassword()` also leaves the `totp_gateway` identity in place, unlike `resetTwoFactor()`.
**Fix:** Record the epoch at the password step and require it to be unchanged when completing the login:
```php
// Login::attempt, after successful attempt()
session()->set('pending_epoch', (new TrustedDeviceService())->currentEpoch($userId));

// TwoFactor::pendingUserOrRedirect, after the TTL check
if (session('pending_epoch') !== (new TrustedDeviceService())->currentEpoch((int) $user->id)) {
    auth('session')->logout();
    return redirect()->to('/login');
}
```
Also call `model(UserIdentityModel::class)->deleteIdentitiesByType($user, TotpAction::TYPE)` inside `setPassword()`'s transaction, as `resetTwoFactor()` already does.

### WR-03: Password-reset request has no app-level throttle, leaks account existence via timing, and lets anyone invalidate the owner's link

**File:** `app/Controllers/Auth/PasswordReset.php:39-55`; `app/Libraries/Auth/PasswordResetService.php:48-49`
**Issue:** There are three problems here:
1. `send()` performs synchronous SMTP (`SMTPTimeout = 5`) only when the account exists (`$token !== null`). The response body is identical, but the response time differs by the SMTP round-trip. That is an account-enumeration oracle, and the "always the same response" comment at line 54 is meant to prevent exactly that.
2. Nothing in the app limits reset requests. The only limit is the nginx one, which WR-01 shows can be bypassed. Each request emails the owner, so an attacker can flood the inbox.
3. Each request runs `DELETE FROM password_resets WHERE user_id = :u: AND used_at IS NULL` before inserting. An attacker posting the owner's address once a minute keeps invalidating the link the owner just received, which blocks recovery.

**Fix:** Apply the existing `LockoutService` (or a separate per-account/per-IP counter) to `send()`. For example, allow at most 3 reset mails per account per hour and silently drop the rest. Do not delete a still-valid token younger than N minutes; reuse it or skip sending instead. To remove the timing oracle, move mail dispatch out of the request (a queue table plus a cron or spark worker) or add a constant-time floor such as `usleep` up to a fixed budget in both branches.

### WR-04: Password reset works for accounts without enrolled TOTP, so email compromise means full account takeover

**File:** `app/Libraries/Auth/PasswordResetService.php:34-43`; `app/Controllers/Auth/TwoFactor.php:47, 50-63`
**Issue:** `request()` issues a token for any active user, including users whose `user_security.totp_enabled_at IS NULL`. That covers a user freshly created with `user:create` (for example the accountant before first login) and any user after `user:reset-2fa`. After the reset, `/login` leads to `/2fa`, and `hasTotp()` is false, so the user lands on `/2fa/setup`. Whoever is logged in there enrolls *their own* authenticator.

The controller docblock says "po zmene hesla sa vyzaduje heslo aj TOTP", but for these accounts the second factor becomes "enroll a new one". Anyone who can read the mailbox gets the account with no second factor at all.
**Fix:** Only issue reset tokens to users with enrolled 2FA. Otherwise return null (the same generic response), and leave recovery of non-enrolled accounts to the CLI (`user:password`):
```sql
JOIN user_security s ON s.user_id = u.id AND s.totp_enabled_at IS NOT NULL
```

### WR-05: `LocaleNegotiation` never applies the stored locale for pending users, despite its documented contract

**File:** `app/Filters/LocaleNegotiation.php:34-52`
**Issue:** For pending users the filter calls `$auth->user()`. Shield's `Auth::user()` returns `getAuthenticator()->loggedIn() ? getUser() : null` (vendor `Auth.php:98-103`), and `getUser()` returns null in `STATE_PENDING`. So `$user` is always null for pending sessions and the method returns early. Every `/2fa/*` page renders in the Accept-Language locale, not in `user_security.locale`. This contradicts the class docblock ("pre prihlaseneho (aj pending) pouzivatela plati user_security.locale", D-23). Example: an English-locale user on a Slovak iPhone sees the TOTP and backup-code screens in Slovak.
**Fix:**
```php
$authenticator = $auth->getAuthenticator();
$user = $auth->loggedIn()
    ? $auth->user()
    : ($authenticator instanceof Session ? $authenticator->getPendingUser() : null);
```

### WR-06: Runtime php container receives DB superuser and owner passwords; superuser password is passed as an environment variable

**File:** `compose.staging.yaml:14-15, 45-48`; `.env.example:9-11, 22-28`
**Issue:** One `.env` serves both compose interpolation and CI4, and it is bind-mounted into the php container (`./.env:/var/www/html/.env:ro`). It therefore exposes `DB_SUPERUSER_PASSWORD`, `DB_OWNER_PASSWORD` and `database.owner.*` to every PHP-FPM worker. Any file-read or RCE bug in the web app yields Postgres superuser and schema-owner credentials. That defeats the `app_rw` least-privilege design, including the "insert-only `auth_events`" guarantee in AUTH-07: the owner role can `UPDATE`/`DELETE` the audit log.

In addition, `POSTGRES_PASSWORD` is passed as plain env. CLAUDE.md requires `POSTGRES_PASSWORD_FILE` (a Docker secret, not in compose).
**Fix:** Split the files. Use a compose-only `.env` (superuser and provisioning passwords, image tags) and an app `.env` containing only `database.default.*` (app_rw), mail, `encryption.key` and the pepper. Run migrations with a separate one-shot env file (`dc run --rm -v ./.env.migrate:/var/www/html/.env:ro php php spark migrate -g owner --all`). Use `POSTGRES_PASSWORD_FILE: /run/secrets/pg_superuser` together with `secrets:`.

## Info

### IN-01: Lockout check and failure registration are not atomic (TOCTOU)

**File:** `app/Controllers/Auth/Login.php:74-93`; `app/Controllers/Auth/TwoFactor.php:277-301`
**Issue:** `lockedUntil()` is read before the password or TOTP check, and `registerFailure()` is written after it. N concurrent requests (up to `pm.max_children = 4` FPM workers) all pass the check, so one window can allow more than `MAX_FAILS` guesses.
**Fix:** Register the attempt first, with the upsert returning `fail_count` and `locked_until`, then reject if the counter is already at or over the limit. Alternatively, take `pg_advisory_xact_lock(hashtext(subject))` around the check-verify-register sequence.

### IN-02: Enrolment TTL (1800 s) is undercut by redirects through `/2fa` and `AuthGate`, which use the 600 s TTL

**File:** `app/Controllers/Auth/TwoFactor.php:39-47, 103, 128, 145`; `app/Filters/AuthGate.php:132`
**Issue:** A user scanning the QR code more than 10 minutes after the password step who follows any redirect to `/2fa` gets logged out with "pending expired", even though the setup screens allow 30 minutes. Examples: navigating to `/` (AuthGate sends pending users to `/2fa`), or `backupCodes()` finding no codes. `index()` calls `pendingUserOrRedirect()` with the default `PENDING_TTL`.
**Fix:** In `index()`, use `ENROL_TTL` when `! hasTotp($user)`.

### IN-03: TOTP enable and backup-code generation are not atomic

**File:** `app/Controllers/Auth/TwoFactor.php:96-112`
**Issue:** The `UPDATE user_security ... totp_enabled_at` runs before `BackupCodeService::generate()`. If generation throws (for example `auth.backupCodePepper` is unset, or a DB error), 2FA ends up enabled with zero backup codes and the user never sees the codes screen.
**Fix:** Wrap both in one transaction, or generate the codes first and enable TOTP afterwards.

### IN-04: `user:create --locale` is not validated

**File:** `app/Commands/UserCreate.php:47-48`; `app/Libraries/Auth/UserProvisioner.php:66-70`
**Issue:** Any string is accepted. A value longer than 10 characters violates `varchar(10)` and raises a `DatabaseException` that `UserCreate` does not catch (it only catches `DomainException|InvalidArgumentException`). An unsupported value such as `pl` is stored silently and later ignored.
**Fix:** Validate against `config('App')->supportedLocales` in `UserProvisioner::create()` and throw `InvalidArgumentException`.

### IN-05: Shield `users.active` is enforced inconsistently

**File:** `app/Controllers/Auth/Login.php:84-88` vs `app/Libraries/Auth/TrustedDeviceService.php:73`, `app/Libraries/Auth/PasswordResetService.php:37`
**Issue:** Device login and password reset require `u.active = 1`, but password + TOTP login (Shield `attempt()`) does not check `active`. Setting `active = 0` to disable a user therefore blocks the cookie and the reset paths but not a normal login.
**Fix:** Reject inactive users in `Login::attempt()` after a successful `attempt()`, then call `logout()` and return the generic error.

### IN-06: `Login::logout` audits a successful `logout` for anonymous requests

**File:** `app/Controllers/Auth/Login.php:149`
**Issue:** `/logout` is allowlisted. An anonymous POST with a valid session CSRF token writes `logout, success=true, user_id=NULL` into the insert-only audit log, which adds noise that cannot be removed.
**Fix:** Record the event only when `$userId !== null`.

### IN-07: `auth_lockouts` and `password_resets` grow without bound from unauthenticated input

**File:** `app/Libraries/Auth/LockoutService.php:106-138`; `app/Libraries/Auth/PasswordResetService.php:48-59`
**Issue:** Every failed login with a new email creates a permanent `account` row, and rows are deleted only on a successful login for that same subject. Used and expired reset tokens are never deleted either. An unauthenticated client can therefore grow these tables indefinitely.
**Fix:** Add a periodic purge, either a `spark` cron or an opportunistic 1% purge in `registerFailure()`. For example, `DELETE FROM auth_lockouts WHERE COALESCE(locked_until, window_start) < now() - interval '1 day'`, and the equivalent for `password_resets` past `expires_at`.

### IN-08: Duplicated helpers across controllers

**File:** `app/Controllers/Auth/Login.php:164-169`, `app/Controllers/Auth/TwoFactor.php:394-409, 526-531`, `app/Controllers/Settings/Security.php:145-161, 255-266, 283-288`, `app/Libraries/Auth/AccountRecovery.php:139-144`
**Issue:** `userLocale()` is copied four times. The encrypted-codes session display (`displayCodes`) and the `.txt` download are copied between `TwoFactor` and `Settings\Security`. The copies will drift; one example of that is the differing `null` handling of `json_decode` results already present.
**Fix:** Move `userLocale()` into a small `UserSecurityRepository`, and move code display and download into `BackupCodeService` or a shared trait.

### IN-09: `ReadsSecretTrait` leaves the terminal without echo on Ctrl-C

**File:** `app/Commands/ReadsSecretTrait.php:23-29`
**Issue:** On SIGINT the PHP process terminates without running `finally`, so `stty echo` never runs and the operator's shell keeps echo disabled.
**Fix:** Install a handler with `pcntl_signal(SIGINT, fn () => exec('stty echo') ?: exit(130))` (with `pcntl_async_signals(true)`), or use `stty -echo` and restore it through `register_shutdown_function`.

---

_Reviewed: 2026-10-25T10:18:43Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
