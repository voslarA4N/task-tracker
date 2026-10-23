# Walking Skeleton — Task Tracker (evidencia úloh a fakturácia)

**Phase:** 1
**Generated:** 2026-10-23

## Capability Proven End-to-End

Vlastník spustí `make up`, otvorí `https://work-tracker.loc/demo` v prehliadači vo Windows (bez varovania o certifikáte), zadá hodiny a sadzbu do htmx formulára, server vypočíta sumu triedou `Money` (bcmath, half-up), uloží riadok do PostgreSQL ako rola `app_rw` (insert-only tabuľka `demo_calculations`) a stránka bez reloadu ukáže zoznam uložených výpočtov so súčtom; neplatný vstup vráti 422 so slovenskou správou a požiadavka bez CSRF tokenu 403.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | CodeIgniter 4.7.5 (`codeigniter4/appstarter` 4.7.5), PHP 8.5 FPM | Rozhodnuté projektom, 4.7.x je prvá línia s podporou PHP 8.5 |
| Runtime a obrazy | Jeden multi-stage `Dockerfile` (`base` → `dev` / `prod`) nad `php:8.5-fpm-alpine`, rozšírenia `intl pdo_pgsql pgsql gd bcmath zip` cez `install-php-extensions`, OPcache vstavaný | NFR-07: rovnaký základ obrazu pre vývoj aj produkciu, prod s `--classmap-authoritative` a preloadom |
| Orchestrácia | `compose.yaml` (php, nginx, db, spoločné pre dev aj prod) + `compose.override.yaml` (len dev: target `dev`, bind mount, named volumes `vendor`/`writable`, Mailpit, porty na `127.0.0.1`) | Prod (Phase 2) pridá vlastný súbor s certbotom bez zmeny spoločných služieb |
| Web server a TLS | nginx 1.30 alpine, šablóny `docker/nginx/templates/*.template` (envsubst), HTTP/2, gzip, dev certifikát z mkcert pre `work-tracker.loc` | D-02/D-04: dev a prod sa líšia len zdrojom certifikátu a hodnotou HSTS |
| Bezpečnostné hlavičky | CSP, HSTS, XCTO, Referrer-Policy, Permissions-Policy **iba v nginx** (`add_header … always`), CI4 `CSPEnabled=false`, Debug Toolbar vypnutý | Jedno miesto pravdy, žiadne duplicity, prísna CSP bez `unsafe-*` |
| Data layer | PostgreSQL 18 alpine, CI4 `Postgre` ovládač (ext-pgsql), `numeric` vracaný ako string | NFR-04: žiadny float, `numeric(12,2)` / `numeric(7,2)` |
| DB roly | `app_owner` (vlastník DB, migrácie cez `spark migrate -g owner`), `app_rw` (aplikácia, default privileges len `SELECT, INSERT`; mutable tabuľky dostanú `UPDATE, DELETE` explicitne cez trait `GrantsPrivileges::grantMutable()`) | Fail-closed: nová tabuľka je automaticky insert-only |
| Provisioning DB | Jeden idempotentný `docker/postgres/provision.sql` (psql premenné) volaný z `initdb.d`, `make reset`, `make test-db` aj z CI | Rovnaké roly a práva v dev, test a CI DB |
| Testovacia DB | `task_tracker_test` v tom istom kontajneri, migrácia ako `tests_owner` (app_owner), testy ako `tests` (app_rw), báza `Tests\Support\PgTestCase` | D-08: overenie oprávnení v každom teste, dev dáta nedotknuté |
| Peniaze | `App\Libraries\Platform\Money` (immutable, decimal string, bcmath `bcmul(...,4)` + `bcround(...,2, HalfAwayFromZero)`), riadok = half-up(hodiny × sadzba), súčet = súčet zaokrúhlených riadkov | Jediné miesto výpočtu súm, parita s PG `round(numeric, 2)` |
| Čas | `App\Libraries\Platform\Clock` cez `service('clock')`, v testoch `FrozenClock`; pásmo `Europe/Bratislava` v PHP, CI4, PG aj `TZ` | Jediný zdroj „teraz“, PHPStan zakáže `date()`/`time()`/`new DateTime*` mimo Clock |
| Auth | Žiadna vo Phase 1 (Shield + TOTP vo Phase 2). CSRF: vlastný filter `App\Filters\HtmxCsrf` (alias `csrf`), `csrfProtection='session'`, `regenerate=false`, `redirect=false`, token v `hx-headers` na `<body>` | htmx 2 neposiela `X-Requested-With`, štandardný filter by v produkcii vrátil tichý 302 |
| Frontend | Bootstrap 5.3.8, htmx 2.0.11, Alpine CSP build 3.17.5 lokálne v `public/assets/vendor/` s verziou v názve; vlastné `app.css`/`app.js` s `?v=` z `asset_url()`; žiadny build | Žiadna SPA ani build pipeline, prísna CSP |
| htmx konvencie | `<meta name="htmx-config">` s kompletným `responseHandling` (422 swap bez chyby, 403 swap s chybou), `allowEval=false`, `historyCacheSize=0`; fragment len pri `HX-Request` bez `HX-Boosted` a bez `HX-History-Restore-Request`; filter `HtmxVary` pridá `Vary: HX-Request` | Bezpečné fragmenty, žiadne cachovanie fragmentu ako stránky |
| Preklady | Všetky UI texty cez `lang()`, `app/Language/{sk,en}`, systémové a validačné hlášky z `codeigniter4/translations`, predvolený jazyk `sk` | UI-02 (PL/CZ) príde vo Phase 12 bez zmeny vzoru |
| PDF | mPDF 8.3.1, pripnutý font DejaVu Sans v `resources/fonts/`, `tempDir` na `writable/mpdf`, bez substitúcií | Overené spikom (výsledok v PROJECT.md) |
| Kvalita | PHPStan level 6 + `codeigniter/phpstan-codeigniter` + vlastné pravidlo `Tests\Support\PhpStan\ForbiddenCallsRule`, php-cs-fixer cez `codeigniter4/devkit`, Composer skripty ako jediný zdroj pravdy pre Makefile aj CI, žiadne git hooky | D-06/D-07 |
| Používateľ v kontajneri | PHP kód (spark, phpunit, composer skripty, spike) sa spúšťa ako `www-data` (`docker compose exec -T -u www-data php …`, Makefile `EXEC`), root (`EXEC_ROOT`) len pre `composer install/require`, `cs-fix` a `make perms` | FPM workery bežia ako `www-data` nad zdieľaným `writable` volume; root-owned logy/cache/tempDir by FPM zablokovali |
| Deployment target | Lokálny dev full-stack cez `make up` na `https://work-tracker.loc` (WSL2 + Docker Desktop), CI v GitHub Actions; staging na Hetzner VPS až Phase 2 | D-01/D-05, Phase 2 Success Criteria 5 |
| Directory layout | `app/Libraries/Platform/` (Money, Clock), `app/Filters/`, `app/Helpers/`, `app/Views/{layouts,home,demo,fragments,errors}`, `app/Language/{sk,en}`, `app/Database/{Migrations,Seeds,Support}`, `docker/{php,nginx,postgres,certs}`, `scripts/`, `tests/{unit,integration,feature,_support}` | Platformové služby oddelené od doménových modulov (Phase 3+) |

## Stack Touched in Phase 1

- [ ] Project scaffold (framework, build, lint, test runner) — plány 01-01, 01-07, 01-10
- [ ] Routing — at least one real route (`/`, `/demo`, `/demo/calculate`, `/demo/calculations`) — plány 01-04, 01-08, 01-09
- [ ] Database — at least one real read AND one real write (`demo_calculations` ako `app_rw`) — plány 01-05, 01-09
- [ ] UI — at least one interactive element wired to the API (htmx formulár s CSRF a 422) — plány 01-08, 01-09
- [ ] Deployment — documented local full-stack run command (`make up` + `make smoke`) a CI — plány 01-02, 01-10

## Out of Scope (Deferred to Later Slices)

- Prihlásenie, TOTP, roly používateľov, denník prihlásení (Phase 2)
- PWA manifest, service worker, offline stránka, HTTPS staging na VPS, Let's Encrypt, prod compose súbor (Phase 2)
- Doménové tabuľky (subjekty, klienti, úlohy, časti, faktúry) a audit zmien (Phase 3+); `demo_calculations` je len dôkaz skeletonu
- Lokalizované formátovanie čísel a mien (`1 080,00 €`) a jazyky PL/CZ (Phase 12, UI-02); vo Phase 1 sa sumy zobrazujú ako kanonický decimal reťazec
- Zálohy, archív dokladov, meranie p95 na cieľovom VPS (Phase 12)
- Sessions v DB (rozhodne sa so Shield vo Phase 2), vo Phase 1 `FileHandler` na volume `writable`

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural decisions:

- Phase 2: vlastník sa prihlási heslom a povinnou TOTP na HTTPS stagingu a nainštaluje PWA na iPhone
- Phase 3: vlastník spravuje verziované subjekty a klientov (prvé mutable tabuľky cez `grantMutable()`, audit)
- Phase 4: vlastník eviduje úlohy a fakturačné časti, vidí dashboard (použije `Money`, `Clock`, layout a spodnú lištu)
- Phase 5: vlastník vystaví nemennú, očíslovanú faktúru s uloženým PDF (insert-only vzor, mPDF podľa spiku)
- Phase 6–12: dokumenty, doručenie, kurzy, dobropisy, limity, prehľady, lokalizácia a produkcia
