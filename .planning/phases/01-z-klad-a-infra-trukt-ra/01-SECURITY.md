---
phase: 01
slug: z-klad-a-infra-trukt-ra
status: verified
threats_open: 0
asvs_level: 1
created: 2026-10-24
---

# Phase 01 — Bezpečnosť (Základ a infraštruktúra)

> Audit overuje, že každá deklarovaná mitigácia existuje v kóde alebo konfigurácii. Dokumentácia sa nepovažuje za dôkaz.

## Hranice dôvery

| Hranica | Popis | Dáta |
|---------|-------|------|
| Packagist / Docker Hub / npm → build | cudzí kód vstupuje do obrazov, `vendor/` a `public/assets/vendor` | kód tretích strán |
| Pracovný adresár → verejný repozitár | `.env`, kľúče mkcert nesmú do gitu | tajomstvá |
| Host → kontajnery | publikované porty | DB, Mailpit |
| Prehliadač → nginx → php-fpm | nedôveryhodné HTTP požiadavky | formuláre, hlavičky htmx |
| app_rw / app_owner → PostgreSQL | každý SQL príkaz aplikácie | peňažné údaje |
| GitHub PR → CI runner | kód z PR beží v CI | zdrojový kód |

## Register hrozieb

| ID | Kategória | Disp. | Stav | Dôkaz |
|----|-----------|-------|------|-------|
| T-01-SC | Tampering | mitigate | CLOSED | `composer.lock` commitnutý; obrazy pripnuté (`Dockerfile:2` `php:8.5-fpm-alpine`, `compose.yaml` `postgres:18-alpine`, `nginx:1.30-alpine`, `compose.override.yaml` `mailpit:v1.32`, `install-php-extensions:2.12`); checkpoint 01-01 Task 2 "approved" (01-01-SUMMARY.md:33); `scripts/vendor-assets.sh:17` iba `npm pack`; `public/assets/vendor/SHA256SUMS` a `sha256sum -c` prešlo pre všetky 4 súbory |
| T-01-01 | Info disclosure | mitigate | CLOSED | `.gitignore:2,8,9,10` (`.env`, `/docker/certs/`, `*.pem`, `*-key.pem`); `git check-ignore -v` potvrdil `.env`, `docker/certs/x.pem`, `a-key.pem`; `.dockerignore` vylučuje `.env`, `.env.*`, `docker/certs`; `git ls-files` neobsahuje `.env` ani `.pem` |
| T-01-02 | Info disclosure | mitigate | CLOSED | `compose.override.yaml`: porty 80, 443, 5432 a 8025 viazané na `127.0.0.1`; `compose.yaml` nepublikuje žiadne porty |
| T-01-03 | Info disclosure | mitigate | CLOSED | `compose.yaml:36-38` `${DB_*_PASSWORD:?}`; v compose súboroch nie sú natvrdo zapísané heslá; `.env.example` obsahuje len `change-me-*` |
| T-01-05 | Info disclosure | mitigate | CLOSED | `docker/php/conf.d/app.ini` `expose_php = Off`; `Dockerfile:29` prod target `php.ini-production`; `app/Config/Boot/production.php:15` `display_errors=0` |
| T-01-06 | Spoofing | mitigate | CLOSED | `default.conf.template` `return 301 https://` z :80, `listen 443 ssl`, TLS 1.2/1.3; HSTS `add_header ... always` v `security-headers.conf.template` |
| T-01-07 | Tampering (XSS) | mitigate | CLOSED | CSP v `security-headers.conf.template`: `script-src 'self'`, `object-src 'none'`, `base-uri 'self'`, bez `unsafe-*`; `scripts/smoke.sh:44` kontroluje absenciu `unsafe-` |
| T-01-08 | Tampering | mitigate | CLOSED | CSP `frame-ancestors 'none'` (snippet) |
| T-01-09 | Info disclosure | mitigate | CLOSED | `default.conf.template`: `location = /index.php` je jediný FastCGI, `location ~ \.php$ { return 404; }`, `location ~ /\. { deny all; }`, `root .../public` |
| T-01-10 | Info disclosure | mitigate | CLOSED | `location = /index.php`: `fastcgi_hide_header Cache-Control`, `add_header Cache-Control "no-store" always` |
| T-01-11 | Info disclosure | mitigate | CLOSED | `server_tokens off`; `fastcgi_hide_header X-Powered-By`; `expose_php = Off` |
| T-01-12 | Info disclosure | mitigate | CLOSED | `docker/certs/` a `*-key.pem` ignorované (kľúče existujú na disku, v `git ls-files` nie sú) |
| T-01-13 | DoS | mitigate | CLOSED | `client_max_body_size 5m`, `fastcgi_read_timeout 30s` |
| T-01-14 | Tampering | mitigate | CLOSED | `app/Libraries/Platform/Money.php:26,51` regex s modifikátorom `D`; `strict_types`; `OverflowException` (r.106); testy `MoneyTest` |
| T-01-15 | Tampering | mitigate | CLOSED | `Money.php`: všetky `bc*` volania s explicitným scale (r.30,55,62,67,77); zaokrúhľovanie `bcround` HalfAwayFromZero na jednom mieste |
| T-01-16 | Repudiation | mitigate | CLOSED | `Platform/Clock.php` (jediný zdroj času, `appTimezone`), `Services::clock()`; `TimezoneConsistencyTest`, `ClockTest` |
| T-01-17 | Tampering (XSS) | mitigate | CLOSED | htmx config v `Views/layouts/app.php:8-9` `allowEval=false`, `allowScriptTags=false`; Alpine CSP build; `tests/unit/Security/ViewsCspTest.php` |
| T-01-18 | Tampering | mitigate | CLOSED | `public/assets/js/app.js:12` `textContent`, bez `innerHTML`; správy z `data-msg-*` |
| T-01-19 | Info disclosure | mitigate | CLOSED | `Views/errors/html/production.php` bez detailov výnimky; `error_404.php:27` `$message` len pri `ENVIRONMENT !== 'production'` |
| T-01-20 | Info disclosure | mitigate | CLOSED | `Views/layouts/app.php:11` `historyCacheSize => 0` |
| T-01-21 | Tampering | mitigate | CLOSED | `provision.sql` default privileges `SELECT, INSERT` pre app_rw; UPDATE/DELETE iba `GrantsPrivileges::grantMutable()`; žiadny TRUNCATE; `DbRolesTest::testRwCannotUpdateDeleteTruncateInsertOnly`, `testMutableTable...` |
| T-01-22 | EoP | mitigate | CLOSED | `provision.sql`: `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT`, `REVOKE CREATE ON SCHEMA public FROM PUBLIC`; `DbRolesTest::testRwIsNotPrivileged`, `testRwCannotRunDdl` |
| T-01-23 | Info disclosure | mitigate | CLOSED | `provision.sql`: `REVOKE ALL ON DATABASE ... FROM PUBLIC`, `GRANT CONNECT` len app_owner, app_rw |
| T-01-24 | Tampering | mitigate | CLOSED | `scripts/db-reset.sh` + `lib-env-guard.sh` allow-list `development\|testing` (fail closed, host aj kontajner); `DevSeeder::assertAllowed()` vyhodí výnimku pre `production`; testy `DbResetGuardTest`, `DevSeederGuardTest` |
| T-01-25 | Tampering (SQLi) | mitigate | CLOSED | `app/Database/Support/GrantsPrivileges.php` whitelist regex `^[a-z_][a-z0-9_]*$` + `escapeIdentifiers()` |
| T-01-26 | Info disclosure | mitigate | CLOSED | `provision.sql` `\getenv` + `:'owner_pw'` (citovanie), heslá nie sú v SQL ani argv; `.env` ignorovaný |
| T-01-27 | Info disclosure | mitigate | CLOSED | `scripts/spikes/` mimo `public/`; nginx root `public/`, FastCGI len `/index.php`, ostatné `.php` 404 |
| T-01-28 | DoS | mitigate | CLOSED | `app.ini` `memory_limit = 256M`; `scripts/spikes/mpdf.php:134` meria `peak_memory_mb`; odporúčanie zapísané v `.planning/PROJECT.md:86` |
| T-01-30 | Tampering | mitigate | CLOSED | `tests/_support/PhpStan/ForbiddenCallsRule.php` identifikátory `app.forbiddenFloat`, `app.forbiddenBcmath`; zapojené v `phpstan.neon.dist` |
| T-01-31 | Repudiation | mitigate | CLOSED | `ForbiddenCallsRule.php:141` `app.forbiddenTime` |
| T-01-32 | Tampering | mitigate | CLOSED | `composer.json` skript `stan` spúšťa `tests/_support/PhpStan/selftest.php` s očakávaným počtom chýb (18/20/2) |
| T-01-33 | Tampering (CSRF) | mitigate | CLOSED | `Config/Filters.php:29,77` globálny `csrf` => `HtmxCsrf` (volá `service('security')->verify()`); `Security.php` `csrfProtection='session'`, `tokenRandomize=true`; `HtmxCsrfTest` 403 bez a s nesprávnym tokenom |
| T-01-34 | Tampering (XSS) | mitigate | CLOSED | `esc()` vo views (`_list.php`, `_calculator.php`), `allowScriptTags=false`, CSP `script-src 'self'`, `ViewsCspTest` |
| T-01-35 | Info disclosure | mitigate | CLOSED | `app/Filters/HtmxVary.php` `Vary: HX-Request` (globálny after filter); `htmx_helper.php` `is_htmx_fragment_request()` vylučuje `HX-History-Restore-Request`; nginx `no-store` |
| T-01-36 | Spoofing | mitigate | CLOSED | `Config/Cookie.php:57,66,90` `secure=true`, `httponly=true`, `samesite='Lax'`; `smoke.sh:84-91`; `SecurityConfigTest::testCookieConfig` |
| T-01-37 | Tampering | mitigate | CLOSED | `Controllers/Demo.php` regex `regex_match` na hodiny a sadzbu, `Money` výnimky mapované na 422; `HtmxCsrfTest` 422/range testy |
| T-01-38 | DoS | mitigate | CLOSED | `Security.php:85` `redirect = false`; `HtmxCsrf` vráti 403; `testMissingTokenWithoutHtmxGivesFullPage403NotRedirect`; `SecurityConfigTest` |
| T-01-39 | Tampering (SQLi) | mitigate | CLOSED | `Models/DemoCalculationModel.php` Model/Query Builder, `allowedFields` whitelist; jediný surový SQL (`sqlTotal`) bez vstupu |
| T-01-40 | Tampering | mitigate | CLOSED | migrácia `CreateDemoCalculations` bez `grantMutable()`; `DemoCalculationsTest::testInsertOnlyForRw` |
| T-01-41 | Tampering | mitigate | CLOSED | migrácia: `CHECK (amount = round(hours * rate, 2))`; `MoneyDbParityTest`, `testAmountCheckConstraint` |
| T-01-42 | Tampering (CSRF) | mitigate | CLOSED | globálny `csrf` filter platí pre `/demo/calculations`; `DemoPersistTest` |
| T-01-43 | Tampering (XSS) | mitigate | CLOSED | `Views/demo/_list.php` `esc()` na `hours`, `rate`, `amount`, `total` |
| T-01-45 | Info disclosure | mitigate | CLOSED | `.github/workflows/ci.yml` krok "Tajomstva nesmú byť v gite" (`git ls-files` grep, `exit 1`) |
| T-01-46 | EoP | mitigate | CLOSED | `ci.yml` `permissions: contents: read`; bez secrets a bez `pull_request_target` |
| T-01-47 | Tampering | mitigate | CLOSED | `ci.yml` spúšťa `composer check` na `push` a `pull_request` |
| T-01-48 | Tampering | mitigate | CLOSED | `ci.yml` `composer install` z `composer.lock`; obrazy `postgres:18-alpine`, `php:8.5-fpm-alpine` (cez Dockerfile) |

## Prijaté riziká (Accepted Risks Log)

| ID | Kategória | Zdôvodnenie | Stav | Kedy prehodnotiť |
|----|-----------|-------------|------|------------------|
| T-01-04 | EoP php-fpm workery | Oficiálny obraz beží ako `www-data`, zápis len do `writable/`. | CLOSED (accepted) | Phase 12 (read-only FS) |
| T-01-29 | Tampering mPDF fonty | Fonty DejaVu zo schváleného balíka, pripnuté v `resources/fonts/` s licenciou `LICENSE-DejaVu.txt`. | CLOSED (accepted) | pri zmene fontov |
| T-01-44 | DoS rast demo tabuľky | Dev-only ukážka, porty len na `127.0.0.1`. | CLOSED (accepted) | Phase 2 (prihlásenie) |
| T-01-49 | Info disclosure CI heslá DB | `ci-owner`, `ci-rw`, `ci-superuser` sú len pre efemérnu CI DB bez dát. | CLOSED (accepted) | pri zmene CI |

## Neregistrované flagy

Žiadne. Sekcie Threat Flags v SUMMARY súboroch neobsahujú novú útočnú plochu.

## Poznámky auditu (informatívne, nie blokery)

- `compose.yaml` je podľa vlastného komentára len základ. Produkčný `compose.prod.yaml` (secrets, certifikáty, porty) pribudne vo Phase 12, preto sa tam mitigácie T-01-02 a T-01-03 musia znova overiť.
- Dodatočné nálezy z 01-VERIFICATION (CR-01 a ďalšie) sa týkali UX a guardov a boli opravené v 01-REVIEW-FIX (skipped: 0). Overené sú guardy `db-reset` aj `app.js`.

## Audit

| Dátum | Overených | Uzavretých | Otvorených | Audítor |
|-------|-----------|------------|------------|---------|
| 2026-10-24 | 50 | 50 | 0 | gsd-security-auditor |
