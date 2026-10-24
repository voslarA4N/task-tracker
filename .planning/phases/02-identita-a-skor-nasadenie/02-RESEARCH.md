# Phase 2: Identita a skoré nasadenie - Research

**Researched:** 2026-10-24
**Domain:** Authentication (Shield 1.4.1 + custom TOTP action), session/device trust, iOS PWA skeleton, Cloudflare-fronted Docker staging with GHCR deploy
**Confidence:** MEDIUM-HIGH (Shield/otphp/CI4 internals read from source = HIGH; iOS cookie persistence, Cloudflare behaviour, Hetzner firewall = MEDIUM/LOW and need real-device / real-server confirmation)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Staging a nasadenie**
- **D-01:** Staging beží na **`work-tracker.vostack.dev`**. Doména `vostack.dev` je registrovaná v Cloudflare (2026-10-24) a záznam smeruje na nový server. Doména je v konfigurácii premenná (`DOMAIN` v `.env`), nie natvrdo v kóde.
- **D-02:** **Cloudflare proxy je zapnutá (oranžový obláčik).** Dôsledky pre plán:
  - TLS režim Cloudflare **Full (strict)**. Na serveri je platný certifikát Let's Encrypt, ktorý sa vydá cez **DNS-01 s Cloudflare API tokenom** (token len s právom `Zone:DNS:Edit` pre `vostack.dev`, uložený mimo gitu). Alternatívou je Cloudflare Origin CA certifikát, planner zváži, čo je jednoduchšie a podľa NFR-03 prijateľné.
  - nginx berie skutočnú IP klienta z `CF-Connecting-IP` (`real_ip_header`, `set_real_ip_from`) **iba z oficiálnych rozsahov Cloudflare**. Denník prihlásení aj limit pokusov musia vidieť skutočnú IP, nie IP Cloudflare.
  - Firewall servera (Hetzner Cloud Firewall alebo ufw) povolí 80/443 iba z rozsahov Cloudflare a SSH iba s kľúčom.
  - Treba overiť, že Cloudflare nemení ani necachuje HTML a hlavičky (CSP, HSTS, `Cache-Control: no-store`, `Vary: HX-Request`). Vypnúť Rocket Loader, Email Obfuscation a Auto Minify, ktoré vkladajú inline skripty a rozbíjajú prísnu CSP. Overiť aj, že `sw.js` sa doručí s `no-cache`.
- **D-03:** Staging je **zároveň budúca produkcia** (jeden server). Po Phase 12 sa vyčistí DB a server sa prepne na ostrú prevádzku.
- **D-04:** Server je už objednaný: **Hetzner Cloud cx23** (x86), 2 vCPU, 4 GB RAM, 40 GB disk, IP `2.31.74.48`, privátna IP `10.0.0.2`, ID `#172176757`. Obrazy stačí stavať pre `linux/amd64`.
- **D-05:** Aj pri 4 GB RAM ostáva cieľ **512 MB v pokoji** (NFR-02). Ladenie PG/FPM sa drží CLAUDE.md, rezervu nechávame pre build, zálohy a prípadný menší server.
- **D-06:** Nasadzovanie ide cez **GitHub Actions + GHCR**. CI postaví produkčný obraz (bez dev závislostí, `--classmap-authoritative`) a pushne ho do `ghcr.io` (repo `voslarA4N/task-tracker`). Server obraz stiahne a spustí `docker compose up -d`.
- **D-07:** Nasadenie sa spúšťa **automaticky pri pushi do `main` po zelenom CI** (testy, PHPStan, cs). Pri jednom serveri, ktorý bude produkciou, musí byť deploy bezpečný sám o sebe, viď D-08.
- **D-08:** **Migrácie bežia automaticky v rámci deployu**, ale až po `pg_dump` zálohe na serveri (lokálny súbor s rotáciou, kým nie je restic z Phase 12). Spúšťajú sa ako `spark migrate -g owner`. Pri chybe migrácie sa deploy zastaví, starý kontajner beží ďalej a nový sa nespustí.

**Prvý účet a obnova 2FA**
- **D-09:** Prvý admin účet vzniká **iba spark príkazom na serveri** (napr. `spark user:create --admin`, heslo zadané interaktívne, nie ako argument). Na webe nie je žiadna registrácia ani úvodná stránka „vytvor admina“.
- **D-10:** Pri prvom prihlásení sa vynúti nastavenie TOTP: QR kód (inline SVG, generovaný lokálne) a tajomstvo na ručné zadanie. TOTP sa aktivuje až po overení prvého kódu.
- **D-11:** **10 záložných kódov** sa po aktivácii ukáže **raz na obrazovke** spolu s tlačidlom **„Stiahnuť .txt“**. Pokračovať sa dá až po potvrdení „Uložil som si ich“. Potom sa kódy už nikdy nezobrazia, dajú sa iba pregenerovať (staré tým prestanú platiť).
- **D-12:** Pri strate telefónu aj kódov existuje **iba núdzový reset cez spark na serveri** (napr. `spark user:reset-2fa <email>`). Zmaže TOTP tajomstvo, záložné kódy aj dôveryhodné zariadenia, zapíše udalosť do denníka a pri ďalšom prihlásení sa 2FA nastaví nanovo. Obnova 2FA cez web alebo e-mail **neexistuje**.
- **D-13:** E-mailové **upozornenia pri citlivých zmenách**: zmena hesla, reset hesla, reset 2FA, pregenerovanie záložných kódov, nové dôveryhodné zariadenie a dočasný zámok účtu (D-18). E-mail neobsahuje žiadne tajomstvá ani odkazy, ktoré by niečo menili.
- **D-14:** **SMTP na stagingu sa do Phase 7 nenastavuje.** Odosielanie (reset hesla, upozornenia) sa naprogramuje cez CI4 Email a otestuje cez Mailpit vo vývoji. Na stagingu sa e-maily iba logujú (napr. vlastný „log“ transport). Kým nie je SMTP, reset hesla na stagingu nahrádza spark príkaz (napr. `spark user:password <email>`). Obnova hesla e-mailom musí byť hotová a otestovaná (AUTH-06), len sa na stagingu nedoručí.

**Relácie a dôveryhodné zariadenia**
- **D-15:** „Zapamätať zariadenie“ preskočí **heslo aj TOTP** na 30 dní. Technicky ide o kombináciu trvalej prihlasovacej cookie a dôkazu 2FA viazaného na zariadenie. Pasca 9 však platí: Shield remember-me sa nesmie stať obchádzkou TOTP. Prihlásenie z remember cookie je platné iba vtedy, ak je zároveň platný záznam dôveryhodného zariadenia (hash tokenu v DB, neodvolaný, neexpirovaný). Ako to presne poskladať (vlastný token vs Shield remember-me + väzba), rozhodne výskum alebo spike.
- **D-16:** Začiarknutie „zapamätať zariadenie“ je **predvolene zapnuté**.
- **D-17:** 30 dní sa počíta **pevne od zadania TOTP** (nie posuvne). Po uplynutí sa vyžaduje heslo aj TOTP, aj pri dennom používaní. Odvolanie zariadenia, zmena hesla a reset 2FA zneplatnia dôveru okamžite.
- **D-18:** Na **nedôveryhodnom** zariadení sa relácia ukončí po **2 hodinách nečinnosti**.
- **D-19:** Obmedzenie pokusov: **5 chybných pokusov** (heslo alebo TOTP/záložný kód) vedie k **dočasnému zámku na 15 minút**, počítanému **pre IP aj pre účet**. Účet sa nikdy nezamkne natrvalo. Pri zámku účtu príde e-mail (D-13). Každý pokus aj zámok sa zapíše do denníka prihlásení.
- **D-20:** **Nastavenia → Zabezpečenie** je jedna stránka: zmena hesla, stav 2FA a pregenerovanie záložných kódov, zoznam dôveryhodných zariadení (názov/UA, prvé a posledné použitie, expirácia) s tlačidlom **Odvolať** a **posledných 50 prihlásení** (čas, úspech/neúspech, dôvod, IP, zariadenie). Na mobile je prístupná cez spodnú lištu Viac → Zabezpečenie.

**PWA identita**
- **D-21:** Názov aplikácie je **„Work Tracker“** (`name` aj `short_name` v manifeste, `apple-mobile-web-app-title`).
- **D-22:** Ikona je **jednoduchá SVG ikona** (písmená alebo symbol na modrom podklade `#0d6efd`). Z nej sa vygenerujú PNG 180 (`apple-touch-icon`), 192, 512 a maskable variant. Zdrojové SVG je v repozitári, aby sa ikona dala neskôr vymeniť.
- **D-23:** Jazyk prihlasovacej stránky (pred prihlásením) sa vyberie podľa **`Accept-Language`** z podporovaných jazykov, predvolená je **slovenčina**. Po prihlásení platí jazyk z nastavení používateľa. V `app/Language` sú dnes len `sk` a `en`, PL a CZ pribudnú neskôr (E12). Výber musí fungovať aj s jazykmi, ktoré ešte nemajú preklad.
- **D-24:** Návod na inštaláciu sa zobrazí ako **zatvárateľný panel** („Zdieľať → Pridať na plochu“), **iba v mobilnom Safari mimo standalone režimu**. V nainštalovanej PWA sa nezobrazí. Zatvorenie sa pamätá lokálne (localStorage je tu v poriadku, ide len o pohodlie).

### Claude's Discretion
- Či sa TOTP zavesí do Shield ako akcia (`ConditionalActionInterface`/gateway) alebo do vlastného filtra. Rozhodne spike podľa poznámky v ROADMAP. Pevná požiadavka: stav „čaká na 2FA“ nie je prihlásenie (AUTH-09) a existuje integračný test bypassu.
- Presný mechanizmus remember-me a dôveryhodného zariadenia (D-15). Musí dodržať pascu 9 a pascu 10: `__Host-` cookie, `Secure`, `HttpOnly`, `SameSite=Lax`, `Max-Age`, rotácia tokenu pri použití.
- Session handler (súbory vs DB), dĺžka `Session::$expiration` a cookie `Max-Age` tak, aby platilo D-17/D-18 a iOS PWA sa neodhlasovala.
- Názvy spark príkazov, štruktúra GHA workflow (jeden `ci.yml` s jobom `deploy` alebo samostatný `deploy.yml`), spôsob prístupu servera ku GHCR (deploy token, SSH kľúč v GitHub Secrets) a kde na serveri ležia `.env` a tajomstvá.
- Podoba offline stránky a dizajn ikony (v rámci D-22).
- Rola `accountant`: iba v dátovom modeli a seede rolí. UI pre ňu sa nerobí (AUTH-08 ho uvádza ako voliteľné).

### Deferred Ideas (OUT OF SCOPE)
- Voľba produkčného SMTP a DNS záznamy SPF/DKIM/DMARC pre `vostack.dev`: Phase 7. Dovtedy sa e-maily na stagingu iba logujú.
- UI pre rolu `accountant`: neskôr, podľa potreby (model je pripravený vo Phase 2).
- Plné zálohy (restic na Storage Box, rotácia 14/8/12, test obnovy) a finálny produkčný compose: Phase 12. Phase 2 robí len lokálny `pg_dump` pred migráciou.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AUTH-01 | Login e-mail + heslo (Argon2id) | Shield `Session::attempt()`; `Config\Auth::$hashAlgorithm = PASSWORD_ARGON2ID` (PHP 8.5 alpine image has argon2id, verified); OWASP params m=19456 KiB, t=2, p=1; min length 12; generic error + dummy hash for unknown user |
| AUTH-02 | Povinná TOTP, QR lokálne, replay ochrana | Shield login action (`ActionInterface` + `ConditionalActionInterface`, `appliesTo()` always true); otphp 11.5.0 `at()` step loop (NOT `verify(...,1)`, leeway is seconds); bacon `SvgImageBackEnd`; atomic `last_used_step` claim; secret encrypted with CI4 Sodium handler |
| AUTH-03 | 10 záložných kódov, hash, pregenerovanie | `backup_codes` table, HMAC-SHA256 with `.env` pepper, atomic `UPDATE ... WHERE used_at IS NULL`, regenerate = delete+insert in one tx, shown once from encrypted session slot |
| AUTH-04 | Rate limit heslo aj TOTP | DB table `auth_lockouts` (atomic upsert, per IP and per account-key, 5 fails -> 15 min), plus nginx `limit_req` on auth URLs; real IP via nginx `realip` from Cloudflare ranges |
| AUTH-05 | Zapamätať zariadenie 30 dní, zoznam, odvolanie | Own `trusted_devices` table + `__Host-wt_device` cookie + `DeviceLogin` filter; Shield remember-me DISABLED (sliding expiry, no device binding, no TOTP proof) |
| AUTH-06 | Obnova hesla e-mailom neobíde TOTP | Own `password_resets` (hashed token, 60 min, single use); reset sets password only, never logs in, bumps `auth_epoch`, revokes devices |
| AUTH-07 | Denník prihlásení | Own insert-only `auth_events` (app_rw has no UPDATE/DELETE); settings page shows last 50 |
| AUTH-08 | Roly `admin`, `accountant` | `Config\AuthGroups` with the two groups; Shield `auth_groups_users`; `spark user:create --admin|--accountant` |
| AUTH-09 | Pending 2FA nie je prihlásenie | Shield `STATE_PENDING` is fail-closed (`loggedIn()` false); global deny-by-default gate filter with allowlist; route-enumeration regression test |
| PWA-01 | Manifest, SW, offline, iOS | Static `manifest.webmanifest`, controller-served `/sw.js` (no-cache, versioned precache), network-only navigations, `/offline` fallback, JS-toggled install hint, persistent cookies |
| NFR-02 | <= 512 MB v pokoji | `compose.staging.yaml` with PG 64 MB shared_buffers, FPM `ondemand`; measure with `docker stats` in staging smoke |
| NFR-03 | HTTPS, CSP, HSTS, cookies, šifrované TOTP | LE DNS-01 via `certbot/dns-cloudflare:v5.8.0`, existing nginx security headers, `__Host-` cookies, Sodium secretbox for TOTP secret |
| NFR-08 | Skorý HTTPS staging | GHA build -> GHCR -> SSH deploy with pg_dump + migrate gate; deploy only after the deny-by-default gate exists |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

- Stack locked: PHP 8.5 FPM, CI4 4.7.5, Shield 1.4.1, otphp 11.5.0 + bacon-qr-code 3.1.1 (SVG), PG 18, nginx 1.30, Bootstrap 5.3.8, htmx 2.0.11, Alpine **CSP build** 3.17.5, certbot container with webroot (D-02 overrides to DNS-01 because origin is Cloudflare-proxied).
- No SPA, no build pipeline; local JS/CSS with version in file name; **no CDN**.
- Money `numeric(12,2)`, no float (not touched in this phase).
- Strict CSP set in nginx only: no inline script/style/`onclick`/`style=""`; htmx `allowEval=false`, `includeIndicatorStyles=false`.
- Security: HTTPS, CSP, HSTS, CSRF, secure+httpOnly cookies, secrets in `.env` outside git, **encrypted TOTP secrets**.
- Tests: PHPUnit mandatory (this phase: auth flows). Repo currently pins `phpunit/phpunit ^11.5` with `failOnDeprecation=true`, `failOnWarning`, `failOnRisky`, `failOnNotice`.
- Texts only via `lang()` (`ViewsHardcodedTextTest`), no inline scripts/styles (`ViewsCspTest`).
- Migrations only as owner (`spark migrate -g owner|tests_owner`); app runs as `app_rw` with SELECT/INSERT default; UPDATE/DELETE only via `grantMutable()`. Login log must stay insert-only.
- All time via `App\Libraries\Platform\Clock` (`service('clock')`, `FrozenClock` in tests).
- GSD workflow enforcement: edits only through a GSD command.
- **CLAUDE.md corrections found by this research (planner must follow this document where they differ):**
  1. CLAUDE.md says `TOTP::createFromSecret($secret)->verify($code, null, 1)` gives "leeway 1 step = 30 s". **Wrong**: in otphp 11.5.0 the leeway is in **seconds** and must be `< period`; `verify($c, null, 1)` accepts +-1 *second*. Use the step loop in Code Examples. [VERIFIED: source of otphp 11.5.0 `TOTP::verify`]
  2. otphp >= 11.3 triggers a `trigger_deprecation` when no PSR-20 clock is passed; with `failOnDeprecation=true` that fails tests. Pass a clock adapter. [VERIFIED: source]
  3. `Config\Encryption` currently has `$driver = 'OpenSSL'`; CLAUDE.md says Sodium. Switch to `Sodium` (32-byte key, `spark key:generate`). [VERIFIED: repo + CI4 4.7.5 SodiumHandler source]

## Summary

Shield 1.4.1 can carry the mandatory TOTP step natively. Reading the 1.4.1 source shows `Session::attempt()` starts the configured `login` action, which puts the session in `STATE_PENDING`; `loggedIn()` is false in that state, `SessionAuth` redirects pending users to the route named `auth-action-show`, and the state is only left through `Session::checkAction()` -> `completeLogin()`. A conditional/gateway custom action (`appliesTo()` returning true for every user) with a random nonce as the Shield identity secret is therefore fail-closed and needs no patching of Shield. The spike required by the roadmap should be a short confirmation of this (pass criteria listed below), not an open exploration; fallback B (TOTP in a filter over `loggedIn()`) is **worse** (fail-open) and should only be used if the spike fails.

Shield's own **remember-me must not be used** for D-15/D-17: its token expiry slides on every use (`refreshRememberMeToken` recalculates `rememberLength`), it carries no device identity or TOTP proof, and in `checkUserState()` a remember cookie restores a full logged-in state unless an action identity happens to exist in the DB. Build a small own `trusted_devices` table + `__Host-wt_device` cookie + a `DeviceLogin` filter that calls `Session::login()` only for a valid, unrevoked, unexpired device row (fixed 30-day `expires_at` set at TOTP entry). Everything else the phase needs that Shield lacks (lockout table, rich audit log, password reset, backup codes, device list, notifications) is small custom code on top of insert-only/mutable tables following the existing `grantMutable()` convention. Shield's own tables are created by its migration under the owner role and need a follow-up migration granting `UPDATE/DELETE` on the mutable ones.

Staging is a deployment problem more than a code problem: Cloudflare proxy (Full strict) + Let's Encrypt via DNS-01 + nginx `realip` limited to Cloudflare ranges + Hetzner Cloud Firewall + GitHub Actions (build -> GHCR -> SSH -> pg_dump -> migrate -> `up -d`). The only thing that cannot be proven by automation is the iPhone behaviour (standalone cookie persistence, 30-day no re-login, offline page); it must be a manual UAT checklist, and the 30-day part cannot be completed inside the phase window (see Open Questions).

**Primary recommendation:** Build the deny-by-default auth gate first, deploy to staging immediately behind it (no users exist until `spark user:create`), then layer password login, TOTP action, backup codes, trusted devices, reset flow and PWA as vertical slices; use Shield's native pending state for 2FA and an own device-trust table instead of Shield remember-me.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| TLS termination, HTTP/2, HSTS/CSP headers | CDN (Cloudflare edge) + nginx origin | — | CF Full (strict) re-encrypts to origin; headers set only in nginx (project decision) |
| Real client IP | nginx (`realip` from CF ranges) | API/Backend reads `REMOTE_ADDR` | App must never trust `CF-Connecting-IP` itself |
| Coarse brute-force throttle | nginx `limit_req` | API/Backend lockouts | Cheap, runs before PHP |
| Password check, pending state, session | API/Backend (Shield Session authenticator) | Database (sessions in files on volume, users/identities) | Server-side authority |
| TOTP verify, replay guard, backup codes | API/Backend | Database | Atomic DB claim prevents replay/double-use |
| Trusted-device trust | API/Backend (filter + `trusted_devices`) | Browser (HttpOnly cookie) | Cookie is only a bearer of a hashed token |
| Lockout counters, audit log | Database (mutable `auth_lockouts`, insert-only `auth_events`) | API/Backend | DB roles enforce insert-only |
| QR code | API/Backend (bacon SVG inline) | Browser renders | Secret never leaves origin |
| Install hint show/hide, SW registration | Browser (`app.js`) | API/Backend renders hidden panel | `navigator.standalone` only exists client-side |
| Offline fallback, static precache | Browser (service worker) | API serves `/sw.js` + `/offline` | HTML never cached |
| Deploy, migration gate, backup-before-migrate | CI (GitHub Actions) + server shell script | Database | Migrations as `app_owner` only in deploy |
| Origin lock-down | Hetzner Cloud Firewall | nginx realip allowlist | Both needed or CF header can be spoofed |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `codeigniter4/shield` | v1.4.1 (2026-08-18) [VERIFIED: packagist p2 + tarball read] | users, groups, Session authenticator, login actions, Argon2id hashing | Locked in CLAUDE.md; 1.4 has `ConditionalActionInterface` + gateway doc |
| `codeigniter4/settings` | v2.4.0 (transitive, `^2.1`) [VERIFIED: packagist] | Shield dependency (`setting()`) | Required by Shield |
| `spomky-labs/otphp` | 11.5.0 (2026-06-06) [VERIFIED: packagist + source read] | TOTP generate/compare | Locked; use `at()` not `verify()` leeway |
| `bacon/bacon-qr-code` | v3.1.1 (2026-04-05) [VERIFIED: packagist + source read] | inline SVG QR (`SvgImageBackEnd`, no `style` attrs, needs ext-iconv + XMLWriter) | Locked; CSP-safe output |
| `psr/clock` | `^1.0` (transitive of otphp) | PSR-20 interface for otphp | Add as explicit `require` since we implement it |
| `certbot/dns-cloudflare` image | v5.8.0 (2026-09-01) [VERIFIED: Docker Hub API] | LE DNS-01 | Origin is CF-proxied and firewalled to CF only |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| CI4 `Encryption` service (Sodium driver) | framework 4.7.5 | encrypt TOTP secret, encrypt transient backup-code display slot | Always; key via `spark key:generate` (32 bytes) |
| CI4 Email | framework | notifications, reset mail | `log` transport on staging, Mailpit in dev |
| CI4 `UserAgent` | framework | "iPhone, Safari" device label (`getPlatform()`, `getBrowser()`, `isMobile()`) | Device list, audit log |
| nginx `ngx_http_realip_module` | built into `nginx:1.30-alpine` [VERIFIED: `nginx -V`] | CF client IP | Always on staging |
| `docker/build-push-action`, `docker/login-action` | current majors (verify at write time) | build/push GHCR | CI deploy |
| One-off icon rasterizer (e.g. `rsvg-convert` in a throwaway container) | n/a | produce PNG 180/192/512/maskable from `icon.svg`, commit the PNGs | Not a project dependency |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Shield action for 2FA | TOTP in a filter over `loggedIn()` | Fail-open: `loggedIn()` true while 2FA missing (Pasca 9). Fallback only if spike fails |
| Own trusted-device table | Shield remember-me + side table | Shield's token slides expiry, has no device identity, bypasses pending (see Pitfall 1) |
| LE via DNS-01 | Cloudflare Origin CA cert | Origin CA is simpler (15 y, no renewal, no API token on server) but not "Let's Encrypt" as written in NFR-03/ROADMAP and only trusted by CF. Recommend LE DNS-01 (literal requirement, works if proxy is ever turned off) |
| File sessions | DB sessions | File on named volume is enough for one user (CLAUDE.md); DB handler adds bytea/locking questions |

**Installation:**
```bash
composer require codeigniter4/shield:^1.4.1 spomky-labs/otphp:^11.5 bacon/bacon-qr-code:^3.1 psr/clock:^1.0
# then (publishes Config\Auth etc. is NOT needed: hand-write app/Config/Auth.php, AuthGroups.php; skip `shield:setup` routes)
php spark migrate -g owner --all   # Shield + Settings + app migrations
```

**Version verification:** Packagist p2 (2026-10-24) shows shield v1.4.1 (`php ^8.2`, `settings ^2.1`), otphp 11.5.0 (`php >=8.1`), bacon v3.1.1 (`php ^8.1`, ext-iconv, dasprid/enum). Docker Hub: `certbot/dns-cloudflare` v5.8.0 (2026-09-01). `php:8.5-fpm-alpine` has `PASSWORD_ARGON2ID`, sodium, iconv (verified by running the image).

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| codeigniter4/shield | Packagist | ~4 yrs | ~64k/mo | github.com/codeigniter4/shield | unavailable | Locked by user in CLAUDE.md; source tarball read. Formally `[ASSUMED]` |
| codeigniter4/settings | Packagist | ~5 yrs | ~83k/mo | github.com/codeigniter4/settings | unavailable | Transitive of Shield, official org. `[ASSUMED]` |
| spomky-labs/otphp | Packagist | 12 yrs | ~2.5M/mo | github.com/Spomky-Labs/otphp | unavailable | Locked by user; tag 11.5.0 source read. `[ASSUMED]` |
| bacon/bacon-qr-code | Packagist | 13 yrs | ~12.5M/mo | github.com/Bacon/BaconQrCode | unavailable | Locked by user; tag v3.1.1 source read. `[ASSUMED]` |
| psr/clock | Packagist | established PSR | very high | github.com/php-fig/clock | unavailable | Transitive of otphp. `[ASSUMED]` |

**Packages removed due to slopcheck [SLOP] verdict:** none (slopcheck could not be installed: no `pip` in the research environment).
**Packages flagged as suspicious [SUS]:** none observed. Because slopcheck was unavailable, every package is formally `[ASSUMED]`; all are user-locked in CLAUDE.md and exist on Packagist with official/known repos, so the planner needs at most **one** `checkpoint:human-verify` ("composer.lock diff reviewed") rather than one per package. No npm packages are installed in this phase.

## Architecture Patterns

### System Architecture Diagram

```
iPhone PWA / Browser
   |  HTTPS (HTTP/2)
   v
Cloudflare edge (proxy, Full strict, no HTML cache, Rocket Loader/Email Obfuscation off)
   |  HTTPS to origin :443 (only CF ranges allowed by Hetzner Cloud Firewall)
   v
nginx 1.30  --realip(CF-Connecting-IP, only from CF ranges)--> $remote_addr = real client IP
   |-- /assets/*  (immutable) ------------------------------> static files
   |-- /manifest.webmanifest, /icons (no-cache) ------------> static
   |-- limit_req on /login /2fa /password* ------------------> 429
   '-- everything else --> php-fpm (index.php)
                              |
                  [Filter chain, before]
                  1 forcehttps
                  2 DeviceLogin  -- cookie __Host-wt_device valid? --yes--> Session::login(user) + session{device_id}
                  3 csrf (HtmxCsrf)
                  4 AuthGate (deny by default, allowlist: /login /2fa* /password* /offline /sw.js /manifest /assets /health)
                       |- loggedIn()? --no--> pending? --> 302 /2fa   | HX-Request --> 401 + HX-Redirect
                       |                  \--> 302 /login
                       |- epoch/device/idle checks (auth_epoch, device row, 2h idle if untrusted)
                  [Controller]
   POST /login ---> LockoutService.check(ip, accountKey) --locked--> 429 + event
                    Session::attempt() --fail--> LockoutService.fail + auth_events + generic msg
                                        '-ok--> pending (identity totp_gateway nonce) --> 302 /2fa
   GET/POST /2fa ---> enrolled? no: setup (QR svg, pending secret enc.) | yes: verify
                      TotpService.matchStep (step loop) --> atomic claim last_step
                      or BackupCodeService.consume (atomic UPDATE)
                      ok --> Session::checkAction(identity, nonce) --> logged in
                          --> optional TrustedDeviceService.issue (fixed 30 d) --> Set-Cookie __Host-wt_device
                          --> auth_events + notification event
   Settings > Security --> change password / regenerate codes (re-auth) / revoke devices / last 50 events
   POST /password/forgot --> same answer always; token hash row; mail (log driver on staging)
   POST /password/reset  --> set password, auth_epoch++, revoke devices; NO login
                              |
                              v
                     PostgreSQL 18 (app_rw): users, auth_identities, auth_groups_users (Shield, mutable)
                     user_security, trusted_devices, backup_codes, auth_lockouts, password_resets (mutable)
                     auth_events (insert-only)

Deploy: git push main --> GHA check (existing) --> build php+nginx images --> GHCR
        --> ssh server: scp compose.staging.yaml+scripts; docker login ghcr (job token);
            pg_dump -Fc (rotate) --> compose pull --> run --rm migrate -g owner --> up -d --wait --> smoke
```

### Recommended Project Structure
```
app/
├── Authentication/Actions/TotpAction.php        # Shield login action (ConditionalActionInterface, gateway)
├── Commands/{UserCreate,UserReset2fa,UserPassword}.php
├── Config/{Auth.php,AuthGroups.php,Session.php(update),Filters.php(update),Encryption.php(update),Settings.php?}
├── Controllers/Auth/{Login,TwoFactor,PasswordReset}.php, Settings/Security.php, Pwa.php (sw.js, offline, manifest hints)
├── Database/Migrations/..._{AuthSecurityTables,GrantShieldMutable,AuthEvents,...}.php
├── Filters/{DeviceLogin,AuthGate,LocaleNegotiation}.php   # HtmxCsrf/HtmxVary exist
├── Libraries/Auth/{TotpService,BackupCodeService,TrustedDeviceService,LockoutService,AuditLog,PasswordResetService,SecurityNotifier}.php
├── Libraries/Platform/PsrClock.php              # PSR-20 adapter around Clock
├── Language/{sk,en}/{Login,TwoFactor,Account,Pwa}.php     # NOT Auth.php / Security.php (collide with Shield / CI4 core lang files)
├── Views/{layouts/auth.php,auth/*,settings/security*.php,pwa/offline.php,layouts/_install_hint.php}
docker/nginx/{Dockerfile,templates/snippets/cloudflare-realip.conf,...}
compose.staging.yaml  scripts/{deploy.sh,smoke-staging.sh,make-icons.sh,cf-ips-check.sh}
public/{manifest.webmanifest,assets/icons/*}
.github/workflows/ci.yml  (+ deploy job)  or deploy.yml
```

### Pattern 1: Shield login action as gateway (fail-closed 2FA)
**What:** One custom action registered as `Auth::$actions['login']`. `appliesTo()` returns `true` for every user (2FA is mandatory). `createIdentity()` stores a random nonce as the Shield identity secret (type `totp_gateway`). Our own controller (routes named `auth-action-show`) verifies TOTP/backup code and then calls `checkAction($identity, $identity->secret)`, which deletes the identity, clears `auth_action` and runs `completeLogin()`.
**When to use:** Always for the web login.
**Why it is safe:** `Session::checkUserState()` returns `STATE_PENDING` while the session has a user id and `auth_action`; `loggedIn()` is false; `SessionAuth` redirects to `auth-action-show`. [VERIFIED: Shield 1.4.1 `Session.php`, `SessionAuth.php`, `docs/references/authentication/auth_actions.md` "Gateway Actions"]
**Example:** see Code Examples (TotpAction).

### Pattern 2: Deny-by-default gate + route-enumeration test
**What:** A global `before` filter `AuthGate` that redirects (or 401 + `HX-Redirect` for htmx) unless the path matches an explicit allowlist or `loggedIn()` is true. A PHPUnit feature test iterates all registered GET/POST routes and asserts anonymous requests get 302/401 except the allowlist, so every later phase that adds a route without thinking about auth fails CI.
**When to use:** First code change of the phase (makes early staging deploy safe).

### Pattern 3: Own trusted-device bearer token
**What:** `trusted_devices(id, user_id, token_hash char(64) unique, prev_token_hash, rotated_at, label, user_agent, ip_first, created_at, last_used_at, expires_at, revoked_at)`. Cookie `__Host-wt_device` = 32 random bytes (hex/base64url), `Secure; HttpOnly; SameSite=Lax; Path=/; Max-Age = expires_at - now` (absolute, never extended). `DeviceLogin` filter: when no session user and cookie present -> lookup by `sha256(token)` (or `prev_token_hash` within 60 s grace), require `revoked_at IS NULL AND expires_at > now AND user active` -> delete stale `totp_gateway` identities for the user -> `Session::login($user)` -> store `device_id`, `auth_epoch` in session -> rotate token (keep `expires_at`) -> audit `device_login`.
**Rotation caution:** rotate only when it re-creates a session (rare), not on every request, and keep `prev_token_hash` valid ~60 s: parallel htmx requests carrying the old cookie must not log the user out.
**Per-request invalidation:** the gate loads `trusted_devices` by `device_id` from session (one indexed query) and `user_security.auth_epoch`; mismatch -> `logout()`. This is what makes "revoke / change password / reset 2FA" immediate (D-17).

### Pattern 4: Session and cookie settings that survive iOS
- `Config\Session`: `expiration = 2592000` (30 d), `matchIP = false`, `regenerateDestroy = false`, `timeToUpdate = 300` (keep), `cookieName = '__Host-ci_session'`, `driver = FileHandler` on the `writable` named volume. CI4 4.7.5 `Session` builds the cookie as persistent (`expires = now + expiration`) and re-sends it on each request while the id is unchanged (sliding). [VERIFIED: CI4 4.7.5 `Session.php` lines 62-150, 600-640] The session cookie name ignores `Config\Cookie::$prefix` (`withPrefix('')`) so the `__Host-` prefix must be in `cookieName`; `Cookie` validation requires `secure`, `path=/`, empty `domain` (already the case in `Config\Cookie`). [VERIFIED: `Cookie.php::validatePrefix`]
- Why 30 d and not 7200: the server enforces the 2 h idle rule itself for **untrusted** sessions (filter with `last_activity` via `Clock`; also gives the "odhlásený po 2 hodinách" message instead of a silent expired cookie), and trusted sessions must not drop to a stale CSRF token after 2 h (session CSRF strategy: a new session means all open pages' tokens are invalid -> 403).
- Pending state expires after ~10 min (`pending_since` in session, `[ASSUMED]` value).
- Shield: `allowRemembering=false`, `allowRegistration=false`, `allowMagicLinkLogins=false`, `recordActiveDate=false` (avoids a DB write per request).

### Anti-Patterns to Avoid
- **Using `Session::attempt()` with no action and flagging `totp_pending` in the session:** `loggedIn()` is true, any code (current or future) that checks it is open. Use the Shield pending state.
- **Calling `$totp->verify($code, null, 1)` expecting +-30 s.** It is +-1 second.
- **Letting Shield's remember cookie authenticate**: leave `allowRemembering=false`.
- **Resetting lock counters on password success** (before 2FA): attacker with the password could farm unlimited TOTP guesses. Reset only after full login.
- **Distinct messages / early return for unknown e-mail** (Shield `check()` returns before hashing for unknown users -> timing oracle). Hash a dummy in that branch; always use the generic copy from UI-SPEC.
- **Logging reset URLs or e-mail bodies on staging** (Pasca 18): `log` transport records metadata only (recipient, template, subject), never the body.
- **Caching `/` or any HTML in the service worker**, or returning redirected responses from the cache.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| TOTP/HOTP math, base32, provisioning URI | own HMAC code | `spomky-labs/otphp` `at()`, `getProvisioningUri()` | RFC edge cases; but own step-matching loop (needs matched step for replay) |
| QR encoding | own encoder or external QR API | `bacon/bacon-qr-code` + `SvgImageBackEnd` | Secret must not leave origin; SVG output has no inline styles |
| Password hashing/validation | `password_hash` calls scattered around | Shield `service('passwords')` with `Auth::$hashAlgorithm=PASSWORD_ARGON2ID`, `needsRehash` on login | Handles rehash, validators |
| Session state machine (anonymous/pending/logged-in) | custom flags | Shield `Session` authenticator + actions | Fail-closed pending |
| Secret encryption | `openssl_*` | CI4 `service('encrypter')` Sodium driver | Authenticated encryption, nonce handling |
| CSRF | custom tokens | existing `HtmxCsrf` + CI4 `Security` (session strategy, `regenerate=false`) | Already built in Phase 1 |
| Cookie attributes/prefix validation | raw `setcookie` | CI4 `Cookie` class with `withPrefix('__Host-')` | Validates `__Host-` rules |
| Device label from UA | regex zoo | CI4 `UserAgent` (`getPlatform`, `getBrowser`) | Config has iPhone/iPad -> iOS |
| LE certificate issue/renew | own ACME | `certbot/dns-cloudflare` | Standard |
| Migration backup/rotation shell | ad hoc | one `scripts/deploy.sh` (set -eu, pg_dump -Fc, `pg_restore -l` sanity check, keep 14) | Needed for D-08 |

**Key insight:** The security value is in the *state machine and the atomic DB claims* (pending is not logged in, last_step/backup-code claims are single UPDATEs, device trust is a hashed row with fixed expiry), not in clever code; lean on Shield/CI4 for primitives and keep custom code to small services with tests.

## Runtime State Inventory

> This phase is not a rename/refactor; it adds new state. Instead of the rename inventory, the migration/provisioning state that the plan must create or touch.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | New tables (owner migration, then grants): Shield `users`, `auth_identities`, `auth_logins`, `auth_token_logins`, `auth_remember_tokens`, `auth_groups_users`, `auth_permissions_users`, Settings `settings`; own `user_security`, `trusted_devices`, `backup_codes`, `auth_lockouts`, `password_resets`, `auth_events` (insert-only) | migration + follow-up `grantMutable()` migration for `users`, `auth_identities`, `auth_remember_tokens`, `auth_groups_users`, `auth_permissions_users`, `user_security`, `trusted_devices`, `backup_codes`, `auth_lockouts`, `password_resets`; leave `auth_logins`, `auth_token_logins`, `auth_events` insert-only; `settings`: SELECT only |
| Live service config | Cloudflare zone `vostack.dev` (proxy, SSL Full strict, Rocket Loader/Email Obfuscation off, cache bypass), Hetzner Cloud Firewall, GitHub Secrets (SSH key, host key, CF token if used in CI) | Manual owner checklist (Wave 0 / ops plan); not in git |
| OS-registered state | Server: Docker + compose plugin, deploy user, `/opt/work-tracker`, cron/systemd timer for `certbot renew`, `/var/backups/work-tracker` rotation | Bootstrap script/README, executed once by owner |
| Secrets/env vars | `.env` on server (0640 root:www-data, mounted read-only into php): `encryption.key`, `auth.backupCodePepper` (new), DB passwords, `app.baseURL`, mail driver; `cloudflare.ini` 0600 for certbot; backup of `encryption.key` + pepper off-server | Create once; document; **key loss = TOTP secrets unreadable** (spark reset-2fa is the recovery) |
| Build artifacts | Prod image rebuilt per deploy; nginx image now bakes `public/` (new Dockerfile) so assets and SW match the PHP image tag | Build both in CI, same tag |

**Nothing found in category "OS-registered state" beyond the above:** nothing is registered on the dev machine.

## Common Pitfalls

### Pitfall 1: Shield remember-me bypasses 2FA and slides expiry
**What goes wrong:** With `allowRemembering=true`, `checkUserState()` -> `checkRememberMe()` -> `startLogin()` + `STATE_LOGGED_IN` for any valid remember cookie; the action only becomes pending if an action identity row happens to exist. Also `refreshRememberMeToken()` recalculates expiry every use (sliding, contradicts D-17).
**How to avoid:** `allowRemembering=false`; own `trusted_devices` (Pattern 3). Test: with the Shield remember table populated manually, no login occurs.
**Warning signs:** any use of `auth()->remember()` in code.

### Pitfall 2: `Session::login()` throws on stale action identities
**What goes wrong:** `login()` throws `LogicException` if `auth_identities` contains an action-type identity for the user (left behind by an abandoned password step) or if `auth_action` is in session. `DeviceLogin` would 500.
**How to avoid:** before `login()` in `DeviceLogin`, `deleteIdentitiesByType($user, 'totp_gateway')`. Also `startLogin()` throws when the session already has a user id: the `/login` controller must `logout()` an abandoned pending session first (or redirect if already logged in).

### Pitfall 3: Shield session regeneration is skipped in `testing`
**What goes wrong:** `startLogin()` calls `session()->regenerate(true)` and regenerates CSRF only when `ENVIRONMENT !== 'testing'`, so PHPUnit cannot prove fixation protection.
**How to avoid:** staging smoke script asserts the session cookie value differs before/after login (curl cookie jar), plus a manual note in UAT.

### Pitfall 4: otphp leeway and deprecation traps
See CLAUDE.md corrections. Also `generate()` default secret size is 64 bytes (very long base32 for manual entry): pass `20`. Secret must be stored encrypted; the otpauth URI must never be logged.

### Pitfall 5: Shield tables are insert-only for `app_rw`
**What goes wrong:** Phase 1 default privileges give `app_rw` only SELECT/INSERT on new tables; Shield's `touchIdentity`, `purgeRememberTokens`, `addGroup`, `UserModel::save` (rehash) fail with "permission denied" at runtime (login works in tests as owner but fails on staging).
**How to avoid:** grants migration (see inventory) and an integration test (extend `DbRolesTest`) that performs those operations as `app_rw` (`tests` connection).

### Pitfall 6: Language file name collisions
`app/Language/*/Auth.php` and `Security.php` merge with Shield's `Auth` and CI4 core `Security` lang files (CI4 loads same-named files from all namespaces). Use `Login`, `TwoFactor`, `Account`, `Pwa` (UI-SPEC names `Auth.php`/`Security.php` should be renamed or keys must be prefixed carefully). [VERIFIED: CI4 and Shield both ship `Language/en/{Security,Auth}.php`; merge behaviour is CI4 standard `[ASSUMED]` to override per key]

### Pitfall 7: Service worker caching HTML / redirects / `?v=` mismatch
SW is network-only for navigations (fallback `/offline` only on fetch failure), never `respondWith` for non-GET or XHR (htmx), cache-first only for same-origin `/assets/`. Serve `/sw.js` from a controller so the precache list uses `asset_url()` (`?v=filemtime`) values and a version constant; send `Cache-Control: no-cache`, `Service-Worker-Allowed: /`. Test that `/sw.js` and manifest are not served from Cloudflare cache stale (`cf-cache-status` in smoke).

### Pitfall 8: Cloudflare in front of a strict CSP / Bot protection
Rocket Loader and Email Obfuscation inject inline scripts; Auto Minify was removed by Cloudflare in 2024 `[ASSUMED, verify in dashboard]`. Bot Fight Mode / managed challenges can answer XHR (htmx) or SW fetches with an HTML challenge `[ASSUMED]`: keep them off for this zone or add a skip rule. Check `cf-cache-status` on HTML = `DYNAMIC`/`BYPASS`; static `no-cache` JS may be `REVALIDATED` (CF caches and revalidates, per CF docs). [CITED: developers.cloudflare.com/cache/concepts/default-cache-behavior, /cache/troubleshooting/investigating-uncached-responses]

### Pitfall 9: Real IP spoofing / lockout on Cloudflare IPs
`set_real_ip_from` must list only CF ranges (14 IPv4 + 6 IPv6 at 2026-10-24 from `https://www.cloudflare.com/ips-v4|ips-v6`), `real_ip_header CF-Connecting-IP`, `real_ip_recursive off`. Without it every client shares a CF IP and one attacker locks everyone; with a too-wide list any host can spoof. Hetzner firewall must also restrict 80/443 to CF ranges. Group IPv6 lock keys by /64. Add `scripts/cf-ips-check.sh` (compare committed list to live list) for drift.

### Pitfall 10: Docker published ports bypass ufw
Use the **Hetzner Cloud Firewall** (outside the host) for the 80/443-from-CF rule; ufw alone does not filter Docker-published ports `[ASSUMED, well-known iptables behaviour]`. SSH must stay open to GitHub-hosted runner IPs (dynamic) with key-only auth + password auth off; do not try to IP-restrict SSH if deploys use hosted runners.

### Pitfall 11: GHCR needs lowercase names
Repo owner is `voslarA4N`; image must be `ghcr.io/voslara4n/task-tracker-php`. Job needs `permissions: packages: write` (workflow default is `contents: read`). Server-side pull: `docker login ghcr.io` using the job's `GITHUB_TOKEN` piped over SSH (short-lived, no long-lived PAT on the server) `[ASSUMED, verify package linked to repo and `packages: read`]`; fallback is a read-only PAT stored in `~/.docker/config.json`.

### Pitfall 12: `.env` and `clear_env=yes`
PHP-FPM has `clear_env=yes`; CI4 reads `/var/www/html/.env`. Prod image excludes `.env` (`.dockerignore`), so staging compose must bind-mount it read-only with mode readable by `www-data` (uid/gid 82 on alpine). Missing `encryption.key` -> TOTP setup throws at runtime: add a boot check/smoke assertion.

### Pitfall 13: CSRF vs stale pages after session change
Session-strategy CSRF means a re-created session invalidates tokens on already-open pages. Trusted-device re-login after an expired session produces one 403 on the first POST of an old page; existing handler shows "Platnosť formulára vypršala". Acceptable; the 30-day session expiry makes it rare.

### Pitfall 14: htmx and auth redirects
Anonymous htmx request must not receive the login page as a fragment: gate returns `401` + `HX-Redirect: /login` (htmx processes `HX-Redirect` before swap decisions `[ASSUMED, add a feature test + JS test]`). Login/2FA forms work without JS (classic POST + redirect).

### Pitfall 15: Lockout as DoS vector
D-19 lets an attacker keep the single owner locked out on untrusted devices (5 tries per 15 min). Mitigations already in decisions: trusted devices are not affected (cookie login never touches password counters), e-mail notice, `spark` recovery. Do not extend the lock on attempts made *while* locked (otherwise permanent lock).

### Pitfall 16: Timestamp types
Shield tables use `timestamp without time zone`; project tables use `timestamptz` and tests assert PHP/DB timezone equal (`Europe/Bratislava`). Compare times only through `Clock`; do not mix Shield `Time::now()` values with our `timestamptz` comparisons in SQL without testing.

## Code Examples

### PSR-20 adapter (otphp requires a clock, avoids deprecation)
```php
// Source: otphp 11.5.0 TOTP.php (trigger_deprecation when $clock === null)
namespace App\Libraries\Platform;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class PsrClock implements ClockInterface
{
    public function __construct(private readonly Clock $clock) {}

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
```

### TotpService: step matching with replay claim
```php
// Source: otphp 11.5.0 (at() public, leeway in seconds); RFC 6238 §5.2
use OTPHP\TOTP;

final class TotpService
{
    private const PERIOD = 30;

    public function newSecret(PsrClock $clock): string
    {
        return TOTP::generate($clock, 20)->getSecret(); // 160-bit, 32 base32 chars
    }

    /** @return int|null matched time step (> $lastStep) or null */
    public function matchStep(string $secret, string $code, ?int $lastStep, PsrClock $clock): ?int
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return null;
        }
        $totp  = TOTP::createFromSecret($secret, $clock);
        $now   = $clock->now()->getTimestamp();
        $match = null;
        foreach ([-1, 0, 1] as $d) {                       // no early exit
            $ts = $now + $d * self::PERIOD;
            if (hash_equals($totp->at($ts), $code)) {
                $step = intdiv($ts, self::PERIOD);
                if ($lastStep === null || $step > $lastStep) {
                    $match = max($match ?? $step, $step);
                }
            }
        }
        return $match;
    }
}
// Atomic claim (second request with the same code affects 0 rows):
// UPDATE user_security SET totp_last_step = :s
//  WHERE user_id = :u AND (totp_last_step IS NULL OR totp_last_step < :s)   -> affectedRows() === 1
```

### QR SVG inline (no GD/Imagick, strip XML prolog)
```php
// Source: bacon/bacon-qr-code 3.1.1 README + SvgImageBackEnd (attributes only, no style)
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$writer = new Writer(new ImageRenderer(new RendererStyle(200, 4), new SvgImageBackEnd()));
$svg    = $writer->writeString($totp->getProvisioningUri());
$svg    = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);   // inline in HTML; never log the URI
```
Set label = account e-mail, issuer = "Work Tracker" via `$totp->setLabel()`/`setIssuer()` before `getProvisioningUri()`.

### TotpAction (gateway)
```php
// Source: Shield 1.4.1 ActionInterface/ConditionalActionInterface, Email2FA::createIdentity, Session::checkAction
final class TotpAction implements ActionInterface, ConditionalActionInterface
{
    public const TYPE = 'totp_gateway';

    public function appliesTo(User $user): bool { return true; }   // mandatory for everyone, fail closed
    public function getType(): string { return self::TYPE; }

    public function createIdentity(User $user): string
    {
        $m = model(UserIdentityModel::class);
        $m->deleteIdentitiesByType($user, self::TYPE);
        return $m->createCodeIdentity(
            $user,
            ['type' => self::TYPE, 'name' => 'login', 'extra' => lang('TwoFactor.pendingMessage')],
            static fn (): string => bin2hex(random_bytes(16)),
        );
    }

    public function show()  { return redirect()->to('/2fa'); }
    public function handle(IncomingRequest $request) { return redirect()->to('/2fa'); }
    public function verify(IncomingRequest $request) { return redirect()->to('/2fa'); }
}

// In TwoFactor controller, after TOTP/backup code accepted:
$auth     = auth('session')->getAuthenticator();
$user     = $auth->getPendingUser();                 // null => redirect /login
$identity = model(UserIdentityModel::class)->getIdentityByType($user, TotpAction::TYPE);
$auth->checkAction($identity, $identity->secret);    // completes login, clears pending
```

### Auth config essentials
```php
// app/Config/Auth.php (extends \CodeIgniter\Shield\Config\Auth)
public array $actions = ['register' => null, 'login' => TotpAction::class];
public bool  $allowRegistration = false;
public bool  $allowMagicLinkLogins = false;
public bool  $recordActiveDate = false;
public array $sessionConfig = ['field' => 'user', 'allowRemembering' => false, 'rememberCookieName' => 'remember', 'rememberLength' => 30 * DAY];
public int   $minimumPasswordLength = 12;
public string $hashAlgorithm = PASSWORD_ARGON2ID;
public int $hashMemoryCost = 19456;   // OWASP minimum m=19 MiB, t=2, p=1
public int $hashTimeCost = 2;
public int $hashThreads = 1;
```

### Atomic backup-code consume
```sql
UPDATE backup_codes SET used_at = :now
 WHERE user_id = :u AND code_hash = :h AND used_at IS NULL;   -- affectedRows() === 1 => accepted
-- code_hash = hash_hmac('sha256', normalizedCode, $pepper); pepper from .env, not in DB
```
Alphabet Crockford-style (no 0/O/1/I/L), 10 chars shown as `XXXXX-XXXXX` (50 bits; safe with pepper + throttling, see Assumption A9).

### Lockout upsert (single statement, atomic)
```sql
INSERT INTO auth_lockouts (kind, subject, fail_count, window_start, locked_until)
VALUES (:kind, :subject, 1, :now, NULL)
ON CONFLICT (kind, subject) DO UPDATE SET
  fail_count   = CASE WHEN auth_lockouts.locked_until IS NOT NULL AND auth_lockouts.locked_until > :now THEN auth_lockouts.fail_count
                      WHEN auth_lockouts.window_start < :now - interval '15 minutes' THEN 1
                      ELSE auth_lockouts.fail_count + 1 END,
  window_start = CASE WHEN auth_lockouts.window_start < :now - interval '15 minutes' THEN :now ELSE auth_lockouts.window_start END,
  locked_until = CASE WHEN auth_lockouts.locked_until IS NOT NULL AND auth_lockouts.locked_until > :now THEN auth_lockouts.locked_until
                      WHEN auth_lockouts.fail_count + 1 >= 5 AND auth_lockouts.window_start >= :now - interval '15 minutes' THEN :now + interval '15 minutes'
                      ELSE NULL END;
```
`kind` in (`ip`,`account`); `subject` = IP (IPv6 -> /64) or `sha256(lower(email))` (same behaviour for non-existent accounts). Reset `account` row only after full login. Planner: treat the SQL as a sketch; cover boundaries with FrozenClock tests.

### nginx pieces
```nginx
# snippets/cloudflare-realip.conf  (generated from https://www.cloudflare.com/ips-v4 and /ips-v6, committed)
set_real_ip_from <each CF range>;
real_ip_header   CF-Connecting-IP;
real_ip_recursive off;

# http context
map $request_uri $auth_limit_key { ~^/(login|2fa|password) $binary_remote_addr; default ""; }  # empty key = not limited
limit_req_zone $auth_limit_key zone=authz:1m rate=20r/m;
limit_req_status 429;
# in location = /index.php:   limit_req zone=authz burst=10 nodelay;   (use $request_uri, $uri is /index.php there)

location = /sw.js                 { add_header Cache-Control "no-cache" always; ...fastcgi to index.php (controller) }
location = /manifest.webmanifest  { default_type application/manifest+json; add_header Cache-Control "no-cache" always; try_files $uri =404; }
```
Existing template hard-codes `work-tracker.loc.pem` for `ssl_certificate`: parametrize via env (`SSL_CERT`, `SSL_KEY`) with dev defaults; staging points to `/etc/letsencrypt/live/${APP_DOMAIN}/fullchain.pem`. Keep `security-headers.conf` included in every `location` that sets `add_header` (nginx `add_header` inheritance resets per level; the existing template already re-includes it).

### Service worker (served by a controller, no HTML caching)
```js
// Source: pattern from CONTEXT/UI-SPEC + MDN SW lifecycle; PRECACHE and VERSION injected by controller from asset_url()
const CACHE = 'wt-static-' + VERSION;
self.addEventListener('install', e => e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(
  caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin) return;               // htmx POST/XHR pass through
  if (r.mode === 'navigate') {                                                   // network-only, offline fallback only
    e.respondWith(fetch(r).catch(() => caches.match('/offline')));
    return;
  }
  if (u.pathname.startsWith('/assets/')) {
    e.respondWith(caches.match(r).then(hit => hit || fetch(r).then(res => {
      if (res.ok && !res.redirected) { const c = res.clone(); caches.open(CACHE).then(x => x.put(r, c)); }
      return res; })));
  }
});
```
Precache list: `/offline`, bootstrap/app.css/app.js/htmx/alpine URLs exactly as rendered by `asset_url()`, icons. `/offline` is the only HTML ever cached and contains no user data/CSRF token.

### GHA deploy skeleton
```yaml
# Source: GitHub docs for GHCR + docker/build-push-action; adapt to existing ci.yml (job `check` stays)
  deploy:
    needs: check
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    runs-on: ubuntu-latest
    environment: staging
    concurrency: { group: deploy-staging, cancel-in-progress: false }
    permissions: { contents: read, packages: write }
    steps:
      - uses: actions/checkout@v4
      - uses: docker/login-action@v3   # verify current major
        with: { registry: ghcr.io, username: ${{ github.actor }}, password: ${{ secrets.GITHUB_TOKEN }} }
      # build php (target prod) and nginx image, tag :sha-${{ github.sha }} and :main, push (names lowercase)
      - name: ssh deploy                # plain ssh with pinned known_hosts from a secret, no third-party action
        run: |
          install -m700 -d ~/.ssh; echo "$SSH_KEY" > ~/.ssh/id; chmod 600 ~/.ssh/id; echo "$KNOWN_HOSTS" > ~/.ssh/known_hosts
          scp -i ~/.ssh/id compose.staging.yaml scripts/deploy.sh deploy@$HOST:/opt/work-tracker/
          scp -r -i ~/.ssh/id docker/postgres deploy@$HOST:/opt/work-tracker/docker/
          echo "$GITHUB_TOKEN" | ssh -i ~/.ssh/id deploy@$HOST 'docker login ghcr.io -u "$GH_ACTOR" --password-stdin'   # token only valid during the job
          ssh -i ~/.ssh/id deploy@$HOST "IMAGE_TAG=sha-$GITHUB_SHA /opt/work-tracker/deploy.sh"
```
`deploy.sh`: `set -eu`; `pg_dump -Fc` via `docker compose exec -T db` to `/var/backups/work-tracker/pre-<ts>.dump` (chmod 600, non-empty, `pg_restore -l` ok, keep 14); `docker compose pull`; `docker compose run --rm --no-deps php php spark migrate -g owner --all` (abort on failure; old stack keeps running); `docker compose up -d --wait`; staging smoke; record `.last-good-tag`. Also add a CI step that runs the **prod image** `spark migrate` against the ephemeral CI database (guards classmap-authoritative surprises with Shield migrations).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Shield only email-code 2FA action | Shield 1.4: `ConditionalActionInterface` + documented "Gateway Actions" | 1.4.x [VERIFIED: docs in 1.4.1 tarball] | Custom TOTP action is a supported pattern |
| otphp `verify()` with null clock | PSR-20 clock param (mandatory in 12.0) | otphp 11.3.0 | Pass adapter now |
| LE HTTP-01 webroot | DNS-01 with Cloudflare token behind proxy+firewall | n/a | origin not reachable on 80 from the internet |
| Cloudflare Auto Minify | removed from CF | 2024 [ASSUMED] | Only Rocket Loader/Email Obfuscation matter |

**Deprecated/outdated:** Shield `remember-me` for device trust (sliding expiry); `php:8.5` argon2 flag concerns (image has Argon2id, verified).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Hetzner Cloud Firewall can express CF ranges (about 20 rules/IPs fit limits) and Docker-published ports bypass ufw | Pitfall 10 | Origin exposed to non-CF traffic; use iptables DOCKER-USER rules instead |
| A2 | `GITHUB_TOKEN` (with `packages: read`) can pull the GHCR package the same repo published | Pitfall 11 | Need long-lived read PAT on server |
| A3 | Cloudflare Rocket Loader / Email Obfuscation / Bot Fight Mode can break CSP/htmx/SW; Auto Minify removed | Pitfall 8 | Wasted config step or missed breakage |
| A4 | iOS Safari honours `__Host-` prefix and keeps `Max-Age` cookies in the standalone PWA for 30 days; WebKit ITP 7-day cap does not hit home-screen apps (WebKit statement says exempt, one 2022 bug report says otherwise) | Pattern 3/4, Open Q 1 | Re-login after 7 days; success criterion 5 unmet; needs real-device test |
| A5 | `Config\Settings::$handlers = ['array']` avoids per-request DB reads of `setting()` and still falls back to Config values | Pattern 4 / perf | Extra queries per request (measure first) |
| A6 | Alpine CSP build handles a simple getter binding (`:disabled="notAcked"`) | UI | Use Alpine.data getter or plain JS in app.js |
| A7 | Explicit logout should revoke the current trusted device and delete the cookie | Pattern 3 | Owner may expect logout to keep device trust; confirm |
| A8 | Pending-2FA window of 10 minutes | Pattern 4 | Tune |
| A9 | Backup code 10 chars (~50 bits) with HMAC pepper is acceptable although PITFALLS.md asked >= 64 bits | Code Examples | Offline attack only possible with pepper+DB; else lengthen to `XXXXX-XXXXX-XXX` (UI-SPEC change) |
| A10 | PNG icons produced once with `rsvg-convert` (or equivalent) in a throwaway container and committed | PWA | Any rasterizer works |
| A11 | Staging "log" mail transport writes metadata only (no body/reset URL) | Anti-patterns | CONTEXT D-14 says "emails are logged": confirm that metadata-only is acceptable |
| A12 | htmx 2.0.11 acts on `HX-Redirect` for a 401 response | Pitfall 14 | Fall back to 200 + `HX-Redirect` |
| A13 | Shield migration runs unchanged on PG 18 through CI4 Forge as `app_owner` (Shield CI covers PostgreSQL) | Inventory | Spike catches it; patch by custom migration |
| A14 | Shield `UserModel` validation accepts a null/derived `username` when creating users from our spark command | Commands | Derive username from e-mail local part |
| A15 | CI4 merges same-named lang files from several namespaces key by key | Pitfall 6 | Rename files anyway (recommended) |
| A16 | `certbot/dns-cloudflare` usage (`dns_cloudflare_api_token` in a 0600 ini, `certonly --dns-cloudflare`) | Staging | Standard but unverified in this session |

## Open Questions (RESOLVED)

Each question is resolved in the plan set. Questions that need an owner preference are planned with the recommended default as the resolution; plan 02-01 Task 2 only confirms it (or records an explicit deviation) and downstream plans read the value from `02-01-SUMMARY.md` `## Owner decisions`. Both option branches are fully planned, so no downstream plan is blocked on an unknown.

1. **30-day iPhone criterion cannot finish inside the phase.** RESOLVED -> owner decision **O-6**, planned resolution `followup`.
   - Known: criterion 5 needs "no login more often than once per 30 days"; WebKit says home-screen apps are exempt from the 7-day cap, one bug report disagrees.
   - Resolution: phase UAT (02-20, `docs/ops/iphone-uat.md`) checks install/standalone/offline/cookie survival after force-quit; 02-01 writes two STATE.md follow-up todos (7-day and 30-day checks). Device cookie is set only via `Set-Cookie` (never JS), implemented in 02-16. Alternative `block` (phase verification waits) is planned as a STATE.md blocker line.
2. **Remember checkbox at first enrolment.** RESOLVED -> owner decision **O-5**, planned resolution `yes`: the "Uložil som si ich / Pokračovať" step on `/2fa/backup-codes` carries the same "Zapamätať zariadenie" checkbox (default on, D-16); implemented in 02-16 (`confirmBackupCodes()` passes `remember`). Pending window during enrolment is 30 min (`ENROL_TTL`, 02-12/02-15) so first-time setup does not force a second password entry.
3. **Logout vs device trust** (A7). RESOLVED -> owner decision **O-1**, planned resolution `revoke`: explicit logout revokes the current trusted device (02-16).
4. **Mail `log` transport content** (A11). RESOLVED -> owner decision **O-3**, planned resolution `metadata` (recipient, subject, template key, time; never body tokens, Pitfall 18 wins even with `full`); implemented in 02-05.
5. **LE DNS-01 vs Origin CA.** RESOLVED -> owner decision **O-4**, planned resolution `letsencrypt` (DNS-01 via `certbot/dns-cloudflare`; CF API token scoped `Zone:DNS:Edit` for `vostack.dev` stored only in `/opt/work-tracker/secrets/cloudflare.ini` (0600)); both branches planned in 02-02 (runbook) and 02-07 (`deploy.sh` `ensure_cert`, `cert-renew.sh`).
6. **Backup code length** (A9). RESOLVED -> owner decision **O-2**, planned resolution `10` characters Crockford-style base32 (`XXXXX-XXXXX`, HMAC with off-DB pepper + shared 5/15 lockout); `13` branch planned in 02-15 `BackupCodeService`.
7. **UI-SPEC lang file names** (`Auth.php`, `Security.php`) collide (Pitfall 6). RESOLVED in plans: language files are `Login.php` (02-08), `TwoFactor.php` (02-12), `Account.php` (02-17), `Pwa.php` (02-11), plus `Notify.php`, `PasswordReset.php`, `App.php`; no `Auth.php` / `Security.php` is created.
8. **DNS not resolvable from the research host / no SSH keys on the dev box.** RESOLVED -> plan **02-02** (owner human-action checkpoint: Cloudflare A record, Hetzner firewall, `server-bootstrap.sh`, deploy key, GitHub Secrets) with automated verification (`dig`, SSH key-only login, firewall timeout) before any deploy plan runs.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker + compose (dev box) | build/test | yes | Docker 29.9.2 | — |
| Host PHP | not needed (tests run in container) | yes (8.3.6, not 8.5) | — | use `make` targets |
| `gh` CLI | secrets/workflow setup | yes, logged in as voslarA4N | — | web UI |
| Node | `tests/js` | yes | — | — |
| `pip`/slopcheck | package audit | no | — | all packages `[ASSUMED]`, single human-verify |
| Hetzner server `2.31.74.48` | staging | not verifiable from here | cx23 per CONTEXT | — |
| DNS `work-tracker.vostack.dev` | staging, LE | **does not resolve from research host** | — | owner confirms / waits propagation |
| SSH key for deploy | CD | not present on dev box | — | owner generates deploy key, adds to GH Secrets + server |
| Cloudflare API token | certbot DNS-01 | owner-provided | — | Origin CA cert alternative |
| `nginx:1.30-alpine` realip module | CF IP | yes (`--with-http_realip_module`) | 1.30 | — |
| `php:8.5-fpm-alpine` Argon2id/sodium/iconv | auth | yes (verified) | 8.5 | — |

**Missing dependencies with no fallback (owner actions, block staging plan):** server SSH access/deploy user, Cloudflare zone settings + API token, GitHub Secrets, Hetzner Cloud Firewall rules, reachable DNS record.
**Missing with fallback:** slopcheck (single human-verify instead).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 (repo `composer.json`; CLAUDE.md mentions ^10.5 pin, repo is ahead) with `CIUnitTestCase`, `FeatureTestTrait`, own `Tests\Support\PgTestCase` (owner/rw connections) |
| Config file | `phpunit.dist.xml` (failOnDeprecation/Warning/Risky/Notice on; suites `unit`, `integration`, `feature`) |
| Quick run command | `make test-unit` (= `composer test:unit`) ; single file: `docker compose exec -T -u www-data php vendor/bin/phpunit --filter TotpServiceTest` |
| Full suite command | `make test` (then `composer check` = stan + cs + test); JS: `node --test tests/js/*.test.js` |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTH-01 | login ok/fail generic message, Argon2id hash, rehash, unknown user path hashes dummy | feature+unit | `phpunit --filter LoginTest` | no - Wave 0 `tests/feature/Auth/LoginTest.php`, `tests/unit/Auth/PasswordConfigTest.php` |
| AUTH-02 | step matching (+-1 step, wrong code, `^\d{6}$`), replay (same code twice, older step after newer), first-code activation, secret stored encrypted | unit + integration (DB claim) | `phpunit --filter TotpServiceTest` | no - `tests/unit/Auth/TotpServiceTest.php`, `tests/integration/TotpReplayTest.php` |
| AUTH-03 | 10 codes, hashed (no plaintext in DB), consume once (second returns 0 rows), regenerate invalidates old, shown once | integration | `phpunit --filter BackupCodeTest` | no - `tests/integration/BackupCodeTest.php` |
| AUTH-04 | 5 fails -> lock 15 min per IP and per account key (also for unknown e-mail), no extension while locked, unlock after 15 min (FrozenClock), reset only after full login, TOTP fails count | integration | `phpunit --filter LockoutTest` | no - `tests/integration/LockoutTest.php` |
| AUTH-05 | device issue (fixed 30 d), cookie attrs (`__Host-`, Secure, HttpOnly, Lax, Max-Age), valid cookie logs in, expired/revoked/tampered does not, revoke/pw change/2FA reset kill existing sessions, no sliding, Shield remember cookie ignored, rotation grace | feature+integration | `phpunit --filter TrustedDeviceTest` | no - `tests/feature/Auth/TrustedDeviceTest.php` |
| AUTH-06 | forgot always same response, token hashed + expires 60 min + single use, reset does not log in, TOTP still required afterwards, devices revoked, mail not containing secrets beyond link | feature | `phpunit --filter PasswordResetTest` | no - `tests/feature/Auth/PasswordResetTest.php` |
| AUTH-07 | event rows for password fail/ok, totp fail/ok, backup used, lock, reset, device login; no secrets/codes stored; non-email identifier stored hashed; `app_rw` cannot UPDATE/DELETE | integration | `phpunit --filter AuditLogTest` (+ extend `DbRolesTest`) | no - `tests/integration/AuditLogTest.php` |
| AUTH-08 | groups `admin` and `accountant` exist, `user:create --admin` assigns group | unit/integration | `phpunit --filter RolesTest` | no - `tests/integration/RolesTest.php` |
| AUTH-09 | after password step `/`, `/demo`, every route -> 302 `/login` or `/2fa`; htmx -> 401 + `HX-Redirect`; route-enumeration test with allowlist; session user id without completed action is not logged in | feature | `phpunit --filter AuthGateTest` | no - `tests/feature/Auth/AuthGateTest.php` (+ update existing `LayoutTest`, `HtmxCsrfTest`, `DemoPersistTest` to authenticate or hit allowlisted paths) |
| PWA-01 | manifest fields, apple-touch-icon link, `/sw.js` headers and content (no HTML cache rules, version), `/offline` has no CSRF/user data, install hint hidden by default, views pass CSP + hardcoded-text tests | feature + js | `phpunit --filter PwaTest`; `node --test tests/js/*.test.js` | no - `tests/feature/PwaTest.php`, `tests/js/install-hint.test.js` |
| NFR-03 | Set-Cookie flags, headers via smoke (staging), Sodium driver configured, encrypted secret round-trip | unit + smoke | `phpunit --filter EncryptionConfigTest`; `scripts/smoke-staging.sh` | no |
| NFR-02/NFR-08 | idle memory < 512 MB total, HTTPS 200 HTTP/2, 302 to login, security headers, `cf-cache-status` not HIT for HTML, `sw.js` no-cache, session cookie changes across login | smoke (script) | `scripts/smoke-staging.sh` | no - extends existing `scripts/smoke.sh` |
| manual | iPhone: Add to Home Screen, standalone, offline page, no re-login after force-quit, device list/revoke | manual UAT (`human_verify_mode: end-of-phase`) | checklist in plan | n/a |

### Sampling Rate
- **Per task commit:** `make test-unit` (+ the single `--filter` file for touched service)
- **Per wave merge:** `make test` then `composer check`
- **Phase gate:** full suite green + staging smoke green + iPhone UAT before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/_support/AuthTestCase.php` - helpers: create user with TOTP, frozen clock injection (`Services::injectMock('clock', new FrozenClock(...))`), login-pending helper, device helper
- [ ] Migrations visible to test DB via `composer test:migrate` (Shield and Settings migrations run under `tests_owner --all`; add grants migration)
- [ ] Update existing feature tests that now hit the gate (`DemoPersistTest`, `LayoutTest`, `HtmxCsrfTest`) to authenticate through a test helper
- [ ] Spike test `ShieldPendingStateTest` (pass criteria below)
- [ ] `ViewsCspTest` / `ViewsHardcodedTextTest` coverage for new views (they scan `app/Views`; verify they pick up subfolders)
- [ ] Framework install: none (PHPUnit present)

**Spike pass criteria (time-box: half a day):** (1) Shield + Settings migrate as owner on PG 18, grants migration lets `app_rw` do `touchIdentity`, `addGroup`, logout purge; (2) `TotpAction` registered: after correct password `loggedIn()` false, `isPending()` true, `/` redirects to `/2fa`; (3) only `checkAction` flips to logged in; (4) abandoned pending then new `/login` works (no `startLogin` LogicException); (5) `DeviceLogin` can `login()` after stale identity cleanup; (6) `spark` command can create a user as `app_rw`. Any failure -> fallback ladder from ROADMAP (filter over Shield), then custom login.

## Security Domain

### Applicable ASVS Categories (Level 1, `security_enforcement` on)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Argon2id (m=19456,t=2,p=1), min length 12, generic errors, TOTP mandatory, lockouts, no default accounts (CLI-only first admin) |
| V3 Session Management | yes | Shield session, regenerate on login (prod), `__Host-` HttpOnly Secure SameSite=Lax cookies, 2 h idle for untrusted, server-side invalidation via `auth_epoch` + device row, logout destroys session |
| V4 Access Control | yes | deny-by-default `AuthGate`, route-enumeration test, Shield groups `admin`/`accountant` |
| V5 Input Validation | yes | CI4 validation (e-mail <= 254, code `^\d{6}$`, backup-code charset), output escaping `esc()` |
| V6 Cryptography | yes | CI4 Sodium secretbox for TOTP secret, `random_bytes` tokens, SHA-256/HMAC for stored token/code hashes, `hash_equals`, no custom crypto |
| V7 Error Handling and Logging | yes | insert-only `auth_events`, no secrets/codes/tokens/otpauth URI in logs, mail log metadata-only |
| V8 Data Protection | yes | `Cache-Control: no-store`, backup codes shown once from encrypted slot, `.env` 0640 |
| V9 Communications | yes | TLS 1.2+/HSTS, CF Full (strict), origin firewall |
| V14 Configuration | yes | CSP and headers in nginx, CF settings checklist, pinned image tags, secrets out of git (existing CI check) |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Pending-2FA treated as logged in | Elevation | Shield pending state + gate + enumeration test |
| TOTP replay / code reuse | Spoofing | `last_step` atomic claim, steps > last only |
| TOTP/backup brute force | Spoofing | per-IP and per-account lockouts, nginx `limit_req`, 6-digit with 3-step window only |
| Credential stuffing / enumeration | Information disclosure | generic message, dummy hash, lock key hashed e-mail |
| Stolen trusted-device cookie | Spoofing | hashed token, `__Host-`, HttpOnly, fixed 30 d, revocation list, notification e-mail on new device |
| Password reset bypassing 2FA | Elevation | reset never logs in, revokes devices/sessions |
| Session fixation | Spoofing | regenerate id after password step and after 2FA (prod), verified by smoke |
| Spoofed `CF-Connecting-IP` | Spoofing/Repudiation | realip only from CF ranges + firewall to CF only |
| CSRF on auth forms | Tampering | `HtmxCsrf`, session strategy, SameSite=Lax |
| XSS leaking cookies / inline injection | Tampering | CSP no inline, HttpOnly, `esc()`, QR SVG from library only |
| Audit log tampering | Repudiation | insert-only DB role, no UPDATE/DELETE grant (test) |
| Secret leakage via logs/mail | Information disclosure | metadata-only mail log, no tokens in `auth_events`, `.env` outside git |
| Lockout DoS of single owner | Denial of service | trusted devices bypass password path, mail notice, spark recovery, no lock extension while locked |
| Supply chain (new composer packages) | Tampering | user-locked packages, lock file review checkpoint |

## Suggested slice order (MVP / vertical slices)

1. **Spike + foundation** (Shield install, config, migrations, grants, roles, Argon2id, session/cookie config, `AuthGate`, route-enumeration test, `spark user:create`). Safe-to-deploy point.
2. **Early staging** (compose.staging, nginx realip/LE/limits, GHCR image build, deploy.sh with pg_dump + migrate, CF/Hetzner owner checklist, smoke). Needs only slice 1 (everything redirects to login, no users). Owner ops tasks run in parallel with slices 3-6.
3. **Password login + lockouts + audit log** (AUTH-01, 04, 07 backend; `auth` layout).
4. **TOTP enrol/verify + backup codes + replay** (AUTH-02, 03; encryption config).
5. **Trusted devices + idle + Security settings page** (AUTH-05, 07 view, D-20).
6. **Password reset + notifications + `spark user:reset-2fa|password`** (AUTH-06, D-12..14).
7. **PWA skeleton** (manifest, icons, `/sw.js`, `/offline`, install hint, locale negotiation) (PWA-01).
8. **iPhone UAT checklist + NFR-02 memory measurement + CF header verification** (manual, end of phase).

## Sources

### Primary (HIGH confidence)
- Shield 1.4.1 source tarball (`codeload.github.com/codeigniter4/shield/tar.gz/v1.4.1`): `Session.php`, `SessionAuth.php`, `AuthRates.php`, `ActionController.php`, `Email2FA.php`, `Config/Auth.php`, migration, `docs/references/authentication/auth_actions.md`
- CodeIgniter 4.7.5 source tarball: `Session/Session.php`, `Cookie/Cookie.php`, `Encryption/Handlers/SodiumHandler.php`
- otphp 11.5.0 and BaconQrCode v3.1.1 source tarballs (`TOTP.php`, `OTP.php`, `SvgImageBackEnd.php`)
- Packagist p2/package JSON (versions, requirements, downloads) 2026-10-24
- Docker Hub API (`certbot/dns-cloudflare` tags); `docker run` checks of `nginx:1.30-alpine -V` and `php:8.5-fpm-alpine` extensions
- Repo files: `CLAUDE.md`, CONTEXT.md, UI-SPEC, PITFALLS.md, `app/Config/*`, `docker/*`, `compose*.yaml`, `.github/workflows/ci.yml`, `phpunit.dist.xml`, `docker/postgres/provision.sql`
- OWASP Password Storage Cheat Sheet (Argon2id m=19456 KiB, t=2, p=1) https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html

### Secondary (MEDIUM confidence)
- Cloudflare docs on default cache behaviour and uncached/revalidated responses https://developers.cloudflare.com/cache/concepts/default-cache-behavior , https://developers.cloudflare.com/cache/troubleshooting/investigating-uncached-responses/ (pages differ on `no-cache`; check `cf-cache-status` live)
- WebKit ITP statement on home-screen apps having their own day counter https://www.theregister.com/2020/03/26/apple_relax_were_not_totally/ and Apple Developer Forums https://developer.apple.com/forums/thread/710157

### Tertiary (LOW confidence)
- WebKit Bugzilla report of a standalone PWA losing auth cookie after 7 days https://bugs.webkit.org/show_bug.cgi?id=237350 (2022, iOS 15.3, unresolved in results)
- Hetzner firewall limits, GHCR `GITHUB_TOKEN` pull, Cloudflare Bot Fight Mode effects, certbot dns-cloudflare specifics: training knowledge, tagged `[ASSUMED]` in the log

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - versions from Packagist, APIs read from source; packages user-locked (slopcheck unavailable)
- Architecture (Shield action, device table, gate): HIGH for Shield internals (source read), MEDIUM until the spike confirms migrations/grants on PG 18
- Pitfalls: MEDIUM-HIGH - code-level ones verified in source; iOS/Cloudflare/Hetzner ones MEDIUM-LOW
- Staging/CD: MEDIUM - standard patterns, nothing executed against the real server or Cloudflare

**Research date:** 2026-10-24
**Valid until:** 2026-11-23 (Shield/CI4 stable; re-check CF/GHA action majors at implementation)
