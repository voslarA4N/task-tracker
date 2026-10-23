# Phase 1: Základ a infraštruktúra - Research

**Researched:** 2026-10-23
**Domain:** Docker dev/prod prostredie (nginx, php-fpm 8.5, PostgreSQL 18, Mailpit), CodeIgniter 4.7.5 kostra, DB roly, trieda `Money`, `Clock`, i18n, bezpečné htmx vzory, CI, statická analýza, spike mPDF
**Confidence:** HIGH pre CI4/htmx/bcmath/Composer rozlíšenie (overené voči zdrojom a skúšobným behom), MEDIUM pre WSL2/mkcert a PG ICU kolácie (nedalo sa spustiť Docker), LOW pre výsledky mPDF pod PHP 8.5 (spike ešte nebehol)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Všetky bežné príkazy idú cez **Makefile** v koreni repozitára, ktorý obaluje `docker compose exec`. Minimálne ciele sú `up`, `down`, `shell`, `composer ARGS=…`, `spark ARGS=…`, `migrate`, `reset`, `test`, `stan`, `cs`, `cs-fix`, `check` (stan + cs + test), `certs`. Ciele sú zdokumentované v README (`make help`).
- **D-02:** Dev beží na **HTTPS s vlastnou doménou `work-tracker.loc`**. Doménu si vlastník pridá do Windows `hosts` (127.0.0.1). Certifikát generuje **mkcert**.
- **D-03:** CA z mkcert je dôveryhodná v **prehliadači vo Windows** (mkcert.exe) **aj vo WSL** (kópia CA do WSL trust store), aby curl a nástroje vo WSL prešli bez `-k`. Certifikáty sú mimo gitu (`.gitignore`). Cieľ `make certs` a krok po kroku v README.
- **D-04:** Keďže dev beží na HTTPS, cookies sú `secure` a `httpOnly` už vo vývoji a bezpečnostné hlavičky (CSP, HSTS s krátkym `max-age` v dev) sa testujú rovnako ako v produkcii. Konfigurácia nginx pre dev a prod sa líši iba zdrojom certifikátu (mkcert vs Let's Encrypt).
- **D-05:** **GitHub Actions** (repo `voslarA4N/task-tracker`), spustenie na push a PR. Kroky: build PHP obrazu, PHPUnit proti PostgreSQL 18 (service container, s rolami `app_owner` a `app_rw`), PHPStan, cs-fixer v režime `--dry-run`, kontrola, že `preload.php` nezhodí PHP (`php -d opcache.preload=… -r 'echo 1;'`).
- **D-06:** **PHPStan** s `codeigniter/phpstan-codeigniter` (level 6 ako východisko) a vlastným pravidlom alebo grep kontrolou, ktorá zakáže `date()`, `time()`, `new DateTime*()` a `float` pretypovanie na peniaze mimo `Clock`/`Money`. **php-cs-fixer** cez `codeigniter4/devkit`. Oboje sa spúšťa cez `make` aj v CI.
- **D-07:** **Žiadne git hooky.** Lokálne sa spúšťa `make check` a CI stráži pri pushi (commity cez WSL/9p ostanú rýchle).
- **D-08:** PHPUnit beží proti **samostatnej DB `task_tracker_test` v tom istom PostgreSQL kontajneri**, s rovnakými rolami. Pred behom sa zmigruje nanovo ako `app_owner` a testy bežia ako `app_rw`, aby sa overili oprávnenia. Dev dáta sa testami nikdy nedotknú.
- **D-09:** `make reset` zmaže a znova zmigruje dev DB a spustí **dev seeder**. Vo Phase 1 je takmer prázdny a v ďalších fázach pribudnú ukážkové subjekty a klienti. Seeder je chránený tak, aby nikdy nebežal v `production` prostredí.

### Claude's Discretion
- **Layout a vzhľad:** základný layout sa drží makiet `docs/mockups/mobile-home.html` (Bootstrap primárna `#0d6efd`, pozadie `#f5f6f8`, systémový font `-apple-system, system-ui`), safe-area, `100dvh`, vstupy aspoň 16 px, dotykové ciele aspoň 44 px. Vo Phase 1 stačí kostra layoutu (hlavička, obsah, miesto pre spodnú lištu) a ukážková stránka, ktorá predvedie htmx vzory (formulár s 422, CSRF). Tmavý režim nie je požiadavkou, voliteľne cez Bootstrap `data-bs-theme`, ak to nič nestojí.
- **Peniaze:** **bcmath** (`bcround` z PHP 8.4+ s half-up), nie `brick/math`, ak testy potvrdia správne half-up aj pre záporné hodnoty (dobropisy). Rozhodnutie sa zapíše do PROJECT.md Key Decisions. `Money` je immutable hodnotový objekt nad decimal reťazcom. Pravidlo: riadok = half-up(hodiny × sadzba, 2), súčet = súčet zaokrúhlených riadkov.
- **Čas:** jedna služba `Clock` (injektovaná, v testoch zmrazená). Jedno časové pásmo `Europe/Bratislava` pre aplikáciu (SK aj PL sú v CET/CEST). `TZ`, `date.timezone`, CI4 `appTimezone` a PG `timezone` sú nastavené explicitne a zhodne.
- **mPDF spike:** pripnutý font vložený do repozitára, predvolene **DejaVu Sans** (alebo Noto Sans, ak spike ukáže lepší výsledok), overenie ł, ń, ě, ť, ž, ô. Vypnuté `useSubstitutions`, `autoLangToFont` a `autoScriptToLang`, `tempDir` na volume. Spike vygeneruje jednoduchú vzorovú faktúru s tabuľkou pod `E_ALL`, zmeria čas (cieľ < 2 s, aj studený štart s cache fontov) a pamäť. Výsledok sa zapíše do PROJECT.md.
- Mapovanie portov (443 a 80 s presmerovaním, Mailpit UI na `:8025`), štruktúra `docker/` adresára, presné názvy cieľov v Makefile.

### Deferred Ideas (OUT OF SCOPE)
None. Diskusia ostala v rozsahu fázy. (Mimo rozsahu fázy podľa domény: prihlásenie a TOTP, PWA manifest a SW, staging (Phase 2), doménové tabuľky (Phase 3+).)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| NFR-04 | Peniaze `numeric(12,2)`, hodiny `numeric(7,2)`, žiadny float. Jedna trieda na výpočet súm. Súčty sa vždy zhodujú (automatické testy). | Sekcia „Money (bcmath)“: `bcround` default `HalfAwayFromZero` overený na php.net, symetrický pre záporné čísla. Paritný test proti PG `round(numeric)`. PHPStan pravidlo proti float. CI4 Postgre ovládač vracia `numeric` ako string (overiť testom). |
| NFR-07 | Rovnaké Docker obrazy pre vývoj (WSL2) aj produkciu, vo vývoji Mailpit. | Multi-stage Dockerfile (`base` / `dev` / `prod`), jedna sada compose služieb, overené verzie obrazov (Docker Hub 2026-10-22), CI buduje oba targety a kontroluje preload. |
| UI-01 | Responzívne rozhranie na Bootstrap 5.3 pre iPhone aj PC (dotykové ciele ≥ 44 px, bez horizontálneho posúvania). | Layout kostra z makiet, CSP-kompatibilný Bootstrap (bez inline `style`), `viewport-fit=cover`, `100dvh`, 16 px vstupy; automatická statická kontrola views + manuálna kontrola na iPhone/DevTools. |
</phase_requirements>

## Summary

Fáza je greenfield (Walking Skeleton): vznikne kostra z `codeigniter4/appstarter` v4.7.5, Docker Compose (nginx 1.30, php-fpm 8.5 alpine, PostgreSQL 18 alpine, Mailpit) na `https://work-tracker.loc`, a konvencie, ktoré sa neskôr opravujú draho. Takmer všetky technické riziká sa dali overiť proti zdrojom: Composer rozlíšenie celej dev sady pod `platform php 8.5.12` prešlo (jediná podmienka: `minimum-stability: dev` + `prefer-stable: true` kvôli `codeigniter4/devkit` → `roave/security-advisories dev-latest`), `bcround` má default `RoundingMode::HalfAwayFromZero` (symetrické pre záporné, vhodné pre dobropisy), htmx 2.0.11 **neposiela** `X-Requested-With` (v súbore je 0 výskytov), čo má priamy dôsledok pre CSRF filter v CI4 (pozri Pasca 1), a mPDF 8.3.1 s DejaVu Sans vygenerovalo v predbežnom behu na PHP 8.3 (len orientačne) tabuľku 40 riadkov za ~210 ms s vrcholom pamäte 22 MB.

Najdôležitejšie rozhodnutia pre plánovanie: (1) DB provisioning ako jeden idempotentný `psql` skript (`provision.sql` s premennými) volaný z `docker-entrypoint-initdb.d`, z `make reset` aj z CI (service container nemôže mountovať súbory z repa), (2) predvolené oprávnenia `app_rw` = `SELECT, INSERT` a mutable tabuľky dostanú `UPDATE, DELETE` explicitne v migrácii (fail-closed), (3) `Money` nad `bcmul(...,4)` + `bcround(...,2)` s povinným explicitným scale všade, (4) vlastný CSRF filter (náhrada za `csrf` alias), `regenerate=false`, `csrfProtection=session`, token v `hx-headers` na `<body>`, `Security::$redirect=false`, a `htmx.config.responseHandling` s explicitným 422/403 swapom (konfigurácia sa prepisuje celá, nie dopĺňa), (5) CSP len v nginx, CI4 CSP vypnuté, Debug Toolbar vypnutý, (6) Composer skripty (`composer test|stan|cs|check`) ako jediný zdroj pravdy pre Makefile aj CI.

Prostredie vlastníka momentálne **nespĺňa** predpoklady: Docker Desktop (29.7.2) je nainštalovaný, ale démon nebeží a distro Ubuntu 24.04 nemá WSL integráciu; chýba `make` aj `mkcert`; domény `work-tracker.loc` nie sú v hosts. Plán musí obsahovať krok „Wave 0 / predpoklady“ (a nič z toho sa nedá overiť bez človeka). Spike mPDF pod PHP 8.5 sa nedal spustiť v tejto session (host má PHP 8.3 a Docker nebeží), preto zostáva celý výsledok na vykonanie fázy.

**Primary recommendation:** Postaviť fázu v poradí: (1) predpoklady + repo kostra + Dockerfile/compose/nginx/TLS, (2) DB provisioning + migračné skupiny + testovacia DB + testy rolí, (3) `Money`/`Clock` + testy + PHPStan/cs-fixer, (4) layout + i18n + htmx/CSRF/CSP vzor + testy, (5) CI + mPDF spike + zápis do PROJECT.md.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| TLS terminácia, HTTP/2, bezpečnostné hlavičky (CSP, HSTS) | CDN / Static (nginx) | — | Jedno miesto pre všetky hlavičky vrátane chybových stránok nginx (`add_header ... always`); CI4 CSP vypnuté |
| CSRF overenie a 403/422 odpovede | API / Backend (CI4 filter) | Browser (htmx posiela hlavičku) | Server je zdroj pravdy; klient len prikladá token z `hx-headers` |
| Rozlíšenie fragment vs. plná stránka (`HX-Request`, `Vary`) | API / Backend | — | Kontrolér rozhoduje podľa hlavičky, filter pridá `Vary: HX-Request` |
| Výpočet súm (`Money`) | API / Backend (PHP) | Database (kontrola `round(numeric)` v testoch) | Jediné miesto výpočtu; SQL `SUM` len kontrola v testoch; Alpine náhľad nikdy nie je vstup |
| Zámky nemennosti (insert-only) | Database / Storage (GRANT) | API (služby, Phase 3+) | Posledná obrana; `app_rw` nemá UPDATE/DELETE/TRUNCATE |
| Migrácie a schéma | Database / Storage (`app_owner`) | — | Iba `spark migrate -g owner` mení schému |
| Aktuálny čas (`Clock`) | API / Backend | Database (`timezone` zhodné) | Jediný zdroj „dnes“; DB `CURRENT_DATE` sa nepoužíva pre biznis |
| Preklady (`lang()`) | API / Backend (views) | — | Všetky reťazce vo views cez `lang()`; Alpine/JS reťazce sa posielajú z `lang()` do `data-*` |
| Responzívny layout, dotykové ciele | Browser / Client (CSS) | Frontend Server (views) | Bootstrap + vlastné CSS; žiadne inline štýly |
| PDF (spike) | API / Backend (mPDF) | volume `writable/mpdf` | Pamäť a čas sa merajú v FPM, nie v prehliadači |
| E-mail (Mailpit) | API / Backend (CI4 Email) | Mailpit kontajner | Len SMTP na `mailpit:1025`, UI `:8025` |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `codeigniter4/framework` | v4.7.5 (2026-10-09) [VERIFIED: packagist p2 + skúšobné `composer update --dry-run`] | framework | Rozhodnuté projektom |
| `codeigniter4/appstarter` | v4.7.5 [VERIFIED: raw.githubusercontent.com/codeigniter4/appstarter/v4.7.5] | kostra (obsahuje `preload.php`, `phpunit.dist.xml`, `spark`) | `composer create-project` |
| PHP | 8.5 (`php:8.5-fpm-alpine`, Docker Hub aktualizovaný 2026-10-22) [VERIFIED: Docker Hub API] | runtime | Rozhodnuté projektom |
| PostgreSQL | `postgres:18-alpine` (2026-10-22) [VERIFIED: Docker Hub API] | DB | Rozhodnuté projektom |
| nginx | `nginx:1.30-alpine` (2026-10-22) [VERIFIED: Docker Hub API] | web | Rozhodnuté projektom |
| Mailpit | `axllent/mailpit:v1.32` (2026-10-22) [VERIFIED: Docker Hub API] | dev SMTP | Rozhodnuté projektom |
| bcmath (PHP ext) | súčasť PHP 8.5, `bcround()` od 8.4 [CITED: php.net/manual/en/function.bcround.php] | `Money` | Rozhodnutie z CONTEXT (nie `brick/math`) |
| Bootstrap | 5.3.8 (npm `latest`) [VERIFIED: npm view] | UI | lokálny súbor `bootstrap-5.3.8.min.css` |
| htmx | 2.0.11 (npm `latest`, `next`=4.0.0 sa nepoužíva) [VERIFIED: npm view] | fragmenty | lokálne, `htmx-2.0.11.min.js` (52 182 B) |
| Alpine.js CSP build | `@alpinejs/csp` 3.17.5 [VERIFIED: npm view], `dist/cdn.min.js` 72 242 B | drobná interaktivita bez `unsafe-eval` | lokálne `alpine-csp-3.17.5.min.js` |
| mPDF | v8.3.1 (2026-03-11), `php ~8.5.0`, vyžaduje `ext-gd`, `ext-mbstring` [VERIFIED: packagist p2] | PDF (v tejto fáze len spike, ale závislosť patrí do `require`) | rozhodnuté projektom |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `codeigniter4/translations` | v4.7.4 (2026-08-05), obsahuje `Language/sk`, `cs`, `pl` vrátane `Validation.php`, `Security.php` [VERIFIED: packagist + GitHub API] | systémové a validačné hlášky v SK/PL/CZ | Bez nej sa validačné chyby (422) zobrazia po anglicky; auto-discovery cez Composer ověriť v Wave 0 [ASSUMED] |
| `phpunit/phpunit` | **^11.5** (11.5.57, PHP >= 8.2) [VERIFIED: packagist] | testy | Framework povoľuje `^10.5.16 \|\| ^11.2`; 12.x a 13.x framework nepovoľuje. Odporúčanie: 11.5 namiesto appstarter pinu 10.5 (lepšia podpora PHP 8.5, overené rozlíšením) |
| `phpstan/phpstan` | 2.3.1 [VERIFIED: packagist] | statická analýza | cez devkit (`^2.0`) |
| `codeigniter/phpstan-codeigniter` | v2.2.0 (2026-10-06), vyžaduje `ext-sqlite3` [VERIFIED: packagist] | CI4 rozšírenie | `php:8.5-*` obraz má sqlite3 vstavané (zoznam `php -m` v README IPE) |
| `codeigniter4/devkit` | v1.3.0 [VERIFIED: packagist] | cs-fixer sada (`codeigniter/coding-standard` 1.9.2, `nexusphp/cs-config` 3.29.0, php-cs-fixer 3.95.27, psalm, rector) | D-06. Vyžaduje `minimum-stability: dev` + `prefer-stable: true` [VERIFIED: README devkit + skúšobné rozlíšenie] |
| `fakerphp/faker` 1.24.1, `mikey179/vfsstream` 1.6.12 | z appstarter | testovacie dáta | dev |
| `mlocati/docker-php-extension-installer` | 2.12.3 (2026-10-20); podporuje PHP 8.5 pre `bcmath gd intl pdo_pgsql pgsql zip opcache` [VERIFIED: GitHub releases + README] | rozšírenia v Dockerfile | `install-php-extensions` |
| `poppler-utils` (apk) | z Alpine | `pdftotext` na kontrolu diakritiky v spike (len `dev` target) | spike mPDF [ASSUMED, že dekódovanie subset fontu funguje] |
| mkcert | 1.4.4 (winget `FiloSottile.mkcert`) [VERIFIED: `winget search`] | dev certifikáty | D-02/D-03 |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| bcmath | `brick/math` | Rozhodnuté: bcmath. Zmena len ak test half-up pre záporné zlyhá (neočakáva sa) |
| devkit (psalm, rector) | priamo `codeigniter/coding-standard` + `friendsofphp/php-cs-fixer` + `nexusphp/cs-config` | Ľahšie (bez psalm/rector, bez `minimum-stability: dev`), ale D-06 hovorí devkit. Zostať pri devkit; ak `minimum-stability: dev` robí problémy, prejsť na priame balíky |
| PHPUnit 10.5 (appstarter pin) | PHPUnit 11.5 | Odporúčame 11.5; `phpunit.dist.xml` z appstarter je kompatibilný (`<source>`, `<coverage>`) |
| Vlastné PHPStan pravidlo na zakázané volania | `spaze/phpstan-disallowed-calls` | Vlastné pravidlo ≈ 40 riadkov, žiadna nová závislosť; spaze je ďalší balík na legitimitu |

**Installation (v kontajneri, nie na hoste; host má PHP 8.3 bez bcmath/gd):**
```bash
# 1) Kostra do dočasného adresára (create-project vyžaduje prázdny cieľ), potom skopírovať do koreňa repa BEZ .git
docker run --rm -v "$PWD/_tmp:/app" composer:2 create-project codeigniter4/appstarter:4.7.5 /app/starter --no-install --no-scripts
# 2) V koreni repa
composer config minimum-stability dev && composer config prefer-stable true
composer require codeigniter4/framework:^4.7 mpdf/mpdf:^8.3 codeigniter4/translations:^4.7
composer require --dev phpunit/phpunit:^11.5 codeigniter4/devkit:^1.3 codeigniter/phpstan-codeigniter:^2.2 fakerphp/faker mikey179/vfsstream
```
(Skúšobné `composer update --dry-run` s týmito balíkmi a `platform: php 8.5.12` prešlo, 114 balíkov, PHPUnit 11.5.57, mpdf 8.3.1, framework v4.7.5.)

**Frontend súbory (bez build pipeline):** `npm pack htmx.org@2.0.11 @alpinejs/csp@3.17.5 bootstrap@5.3.8` a skopírovať `dist/…` do `public/assets/vendor/` s verziou v názve; do repa pridať aj licencie. Host má Node + npm [VERIFIED: `command -v`].

## Package Legitimacy Audit

> `slopcheck` sa nepodarilo nainštalovať (na hoste chýba `pip`/`pip3`; `python3 -m pip` hlási „No module named pip“). Podľa protokolu sú preto **všetky balíky nižšie označené `[ASSUMED]`**. Kompenzačné dôkazy: existencia, vek, stiahnutia a zdrojový repozitár overené cez Packagist API (`packagist.org/packages/<pkg>.json`); všetky sú z oficiálnych organizácií alebo z prostredia, ktoré už schválil projektový výskum (`.planning/research/STACK.md`).

| Package | Registry | Age | Downloads (mesačne) | Source Repo | slopcheck | Disposition |
|---------|----------|-----|---------------------|-------------|-----------|-------------|
| codeigniter4/framework | Packagist | 9 r. (2017) | 305 422 | codeigniter4/framework | n/a | [ASSUMED] oficiálny, schválený projektom |
| codeigniter4/translations | Packagist | 8 r. (2018) | 16 701 | codeigniter4/translations | n/a | [ASSUMED] oficiálna org. CI4 |
| codeigniter4/devkit | Packagist | 4 r. (2022) | 4 948 | codeigniter4/devkit | n/a | [ASSUMED] oficiálna org. CI4 |
| codeigniter/phpstan-codeigniter | Packagist | 3 r. (2023) | 42 164 | CodeIgniter/phpstan-codeigniter | n/a | [ASSUMED] oficiálna org. CI |
| codeigniter/coding-standard | Packagist | 5 r. | 73 821 | CodeIgniter/coding-standard | n/a | [ASSUMED] tranzitívne cez devkit |
| phpstan/phpstan | Packagist | 10 r. | 16 920 852 | phpstan/phpstan | n/a | [ASSUMED] široko používaný |
| mpdf/mpdf | Packagist | 14 r. | 3 765 229 | mpdf/mpdf | n/a | [ASSUMED] schválený projektom |
| phpunit/phpunit | Packagist | 14 r. | 24 426 015 | sebastianbergmann/phpunit | n/a | [ASSUMED] štandard |
| fakerphp/faker, mikey179/vfsstream | Packagist | 6 r. / 14 r. | 17 856 043 / 2 773 601 | FakerPHP/Faker, bovigo/vfsStream | n/a | [ASSUMED] z appstarter |

**Packages removed due to slopcheck [SLOP] verdict:** žiadne (slopcheck nebehol).
**Packages flagged as suspicious [SUS]:** žiadne zistené, ale kvôli nedostupnému slopcheck plánovač vloží **jeden** `checkpoint:human-verify` pred prvým `composer require` (zobrazí zoznam z tejto tabuľky), nie jeden na balík. `postinstall` kontrola sa netýka (Composer, nie npm); npm balíky (htmx.org, @alpinejs/csp, bootstrap) sa len stiahnu cez `npm pack` a skopírujú, nespúšťajú sa skripty.

## Architecture Patterns

### System Architecture Diagram

```
 iPhone / PC prehliadač (Windows hosts: work-tracker.loc -> 127.0.0.1)
        │  HTTPS :443 (mkcert cert)   :80 -> 301 na 443
        ▼
 ┌────────────── nginx 1.30 (HTTP/2, gzip, hlavičky: CSP/HSTS/XCTO/Referrer/Permissions) ──────────────┐
 │  /assets/vendor/*  -> statika (Cache-Control immutable)        /  -> fastcgi_pass php:9000          │
 └───────────────────────────────────────────────┬──────────────────────────────────────────────────────┘
                                                  ▼
 php-fpm 8.5 (www-data, OPcache, TZ Europe/Bratislava)  ── CI4 front controller public/index.php
   Filters: forcehttps -> HtmxCsrf (verify, 403 fragment) -> controller -> HtmxVary (after)
   Controller: validate() ── chyba ──> 422 + fragment (lang())   ── ok ──> 200 fragment / HX-Redirect
   Services: Clock (frozen v testoch), Money (bcmath, string), lang()
        │ pg_connect (native ext-pgsql), skupina `default` = app_rw
        ▼
 PostgreSQL 18 (timezone Europe/Bratislava, malé buffers)
   DB task_tracker       : owner app_owner, default privileges: app_rw = SELECT, INSERT (+ sequences)
   DB task_tracker_test  : rovnaké role a privilégiá
        ▲  `spark migrate -g owner` (app_owner)          ▲ PHPUnit: tests_owner pripraví dáta, tests (app_rw) overí oprávnenia
 Mailpit (SMTP mailpit:1025, UI 127.0.0.1:8025)  <── CI4 Email
 volumes: vendor, writable (named), pgdata (named, mount /var/lib/postgresql)
```

### Recommended Project Structure
```
.
├── Makefile                      # D-01, tenký obal nad `docker compose exec php composer <script>`
├── compose.yaml                  # služby spoločné pre dev aj prod (php, nginx, db)
├── compose.override.yaml         # dev: mailpit (profile dev), bind mounty, publikované porty na 127.0.0.1
├── Dockerfile                    # targety: base -> dev -> prod
├── docker/
│   ├── nginx/{conf.d/app.conf, snippets/security-headers.conf, snippets/fastcgi-php.conf}
│   ├── php/{php.ini, opcache.ini, www.conf, entrypoint.sh}
│   ├── postgres/{provision.sql, init/10-provision.sh, postgresql.conf (alebo -c v command)}
│   └── certs/                    # mkcert výstup, v .gitignore
├── scripts/{smoke.sh, certs.sh, vendor-assets.sh}
├── .env.example / .env           # .env v gite NIE
├── .gitattributes .editorconfig  # LF, Makefile a *.sh vždy LF
├── .github/workflows/ci.yml
├── phpstan.neon.dist  .php-cs-fixer.dist.php  phpunit.dist.xml
├── app/
│   ├── Config/{App,Database,Filters,Security,Session,Cookie,Toolbar...}.php
│   ├── Controllers/Demo.php      # ukážková stránka: formulár s 422 + CSRF
│   ├── Filters/{HtmxCsrf,HtmxVary}.php
│   ├── Helpers/htmx_helper.php   # is_htmx(), htmx_fragment_or_page()
│   ├── Libraries/Platform/{Money,Clock,FrozenClock}.php
│   ├── Database/{Migrations,Seeds/DevSeeder.php}
│   ├── Language/{sk,en}/{App,Demo,Common}.php
│   ├── Views/{layouts/app.php, demo/*, fragments/*}
│   └── PhpStan/ForbiddenTimeAndFloatRule.php (alebo tools/phpstan/)
├── public/assets/{vendor/*, css/app.css, js/app.js}
├── tests/{unit,integration,_support}
└── docs/ (README sekcia dev prostredia, zápis mPDF spiku)
```

### Pattern 1: Provisioning DB rolí raz, použitie trojmiestne (init, reset, CI)
**What:** `docker/postgres/provision.sql` je pure `psql` skript s premennými (`-v owner_pw=… -v rw_pw=… -v dbname=…`), bez závislosti na Docker entrypointe. `init/10-provision.sh` ho volá pre `task_tracker` aj `task_tracker_test`; `make reset` ho volá po `DROP DATABASE … WITH (FORCE)`; CI ho spúšťa cez `psql -h localhost` po štarte service containera (service container nevie mountovať súbory z repa, a `initdb.d` sa v ňom nedá použiť).
**When to use:** vždy; `initdb.d` beží len pri prázdnom dátovom adresári [CITED: hub.docker.com/_/postgres].
**Example:**
```sql
-- Source: PostgreSQL docs (ALTER DEFAULT PRIVILEGES, psql variables); vzor, finalizuje plán
-- psql -v ON_ERROR_STOP=1 -v owner_pw=... -v rw_pw=... -v dbname=task_tracker -d postgres -f provision.sql
SELECT format('CREATE ROLE app_owner LOGIN PASSWORD %L', :'owner_pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_owner') \gexec
SELECT format('CREATE ROLE app_rw LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE', :'rw_pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_rw') \gexec
SELECT format('CREATE DATABASE %I OWNER app_owner ENCODING ''UTF8'' TEMPLATE template0', :'dbname')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'dbname') \gexec
REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO app_owner, app_rw;
ALTER DATABASE :"dbname" SET timezone TO 'Europe/Bratislava';
\connect :"dbname"
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO app_rw;
GRANT ALL ON SCHEMA public TO app_owner;
-- fail-closed: nové tabuľky vytvorené app_owner-om majú pre app_rw len SELECT, INSERT
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT ON TABLES TO app_rw;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_rw;
```
Mutable tabuľka dostane `GRANT UPDATE, DELETE ON TABLE x TO app_rw;` v **svojej** migrácii (pomocná trait/metóda `grantMutable()`), nikdy `TRUNCATE`. `DROP DATABASE … WITH (FORCE)` je dostupné od PG 13.

Pozor: `ALTER DEFAULT PRIVILEGES` je **per-databáza** a `DROP SCHEMA public CASCADE` ich zmaže, preto `make reset` zahadzuje celú DB a znova volá provision (nie `DROP SCHEMA`).

### Pattern 2: Dve (resp. štyri) DB skupiny v `Config\Database`
`default` (app_rw, `task_tracker`), `owner` (app_owner, `task_tracker`), `tests` (app_rw, `task_tracker_test`), `tests_owner` (app_owner, `task_tracker_test`). Všetky `DBDriver => 'Postgre'`, `pConnect => false`, hesla z `.env` (`database.default.password` atď.). `spark migrate -g owner` existuje (`-g` = „Set database group“) [VERIFIED: system/Commands/Database/Migrate.php v4.7.5]. Appstarter má v `$tests` skupine SQLite — **prepísať** na Postgre.
**Dôsledok pre testy:** nepoužívať `DatabaseTestTrait` s `$migrate`/`$refresh = true` (volá `regress`/`latest` a `truncate` pod skupinou `tests`, teda pod `app_rw`, čo zlyhá alebo zakryje oprávnenia). Namiesto toho `make test-db` (drop + provision + `CI_ENVIRONMENT=testing spark migrate -g tests_owner --all`) a testy s vlastnou bázou `tests/_support/PgTestCase` s metódami `owner()` (pripraví/vyčistí dáta cez `tests_owner`) a `rw()` (overí oprávnenia cez `tests`). Seeder dev dát používa `Database::connect('owner')` a v `run()` vyhodí výnimku pri `ENVIRONMENT === 'production'`.

### Pattern 3: Money nad bcmath
**What:** `final class Money` (immutable, `readonly` reťazec s presne 2 des. miestami), konštruktor privátny, `Money::of(string)`; žiadne `float` vstupy (`declare(strict_types=1)` → `TypeError`). Riadok = `bcround(bcmul($rate, $hours, 4), 2)` (hodiny a sadzba majú max 2 des. miesta → súčin má presne ≤ 4, takže `bcmul` s scale 4 nerezí); súčet = `bcadd` už zaokrúhlených riadkov. Každé volanie `bc*` **musí** dostať explicitný scale (default `bcmath.scale` je 0 a `bcmul('1.25','27.00')` by orezalo na `33`).
**Example:**
```php
<?php
declare(strict_types=1);
namespace App\Libraries\Platform;

use InvalidArgumentException;
use RoundingMode;

final class Money implements \Stringable
{
    private function __construct(private readonly string $amount) {}

    public static function of(string $value): self
    {
        if (preg_match('/^-?\d{1,10}(\.\d{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid money value: {$value}");
        }
        return new self(self::norm(bcadd($value, '0', 2)));
    }

    /** riadok faktúry: half-up (away from zero) z hodiny × sadzba */
    public function timesHours(string $hours): self
    {
        if (preg_match('/^-?\d{1,5}(\.\d{1,2})?$/D', $hours) !== 1) {
            throw new InvalidArgumentException("Invalid hours: {$hours}");
        }
        return new self(self::norm(bcround(bcmul($this->amount, $hours, 4), 2, RoundingMode::HalfAwayFromZero)));
    }

    public function plus(self $o): self  { return new self(self::norm(bcadd($this->amount, $o->amount, 2))); }
    public function minus(self $o): self { return new self(self::norm(bcsub($this->amount, $o->amount, 2))); }
    public function negate(): self       { return new self(self::norm(bcmul($this->amount, '-1', 2))); }
    public function compare(self $o): int { return bccomp($this->amount, $o->amount, 2); }
    public function __toString(): string  { return $this->amount; }

    private static function norm(string $v): string { return $v === '-0.00' ? '0.00' : $v; }
}
```
`bcround` default mode je `RoundingMode::HalfAwayFromZero` (ties od nuly; `-1.55 → -1.6`) [CITED: php.net/manual/en/function.bcround.php], čo zodpovedá PostgreSQL `round(numeric, 2)` (half away from zero) — preto je v testoch paritný test proti PG. Výsledok `bcround` s kladnou presnosťou má presne `$precision` des. miest. `ValueError` pri nevalidnom vstupe (nie `false`). Záporná nula (`-0.00`) sa normalizuje a má vlastný test (správanie `bcround` pre malé záporné hodnoty na 8.5 treba potvrdiť testom [ASSUMED]).

### Pattern 4: Clock
`App\Libraries\Platform\Clock` (`now(): DateTimeImmutable`, `today(): string` ako `Y-m-d`), registrovaný v `Config\Services::clock()` (zdieľaný); `FrozenClock extends Clock` s pevným okamihom; v testoch `Services::injectMock('clock', new FrozenClock(...))`. Jediné miesto, kde sa smie `new DateTimeImmutable`. Časové pásmo: `Config\App::$appTimezone = 'Europe/Bratislava'`, `date.timezone` v `php.ini`, `ALTER DATABASE … SET timezone` + `-c timezone=` v PG, `TZ` v kontajneroch. Test `TimezoneConsistencyTest` porovná `date_default_timezone_get()`, `config(App::class)->appTimezone` a `SHOW timezone` z DB (CI4 `Time::setTestNow()` existuje, ale `Time` je tiež zakázané mimo Clock).

### Pattern 5: Bezpečné htmx vzory (jadro)
- **CSRF konfigurácia:** `Config\Security`: `csrfProtection = 'session'`, `regenerate = false`, `tokenRandomize = true` (gzip HTML + tajomstvo v tele = BREACH; `csrf_hash()` vracia náhodne maskovaný token pri každom renderi a všetky sú platné), `redirect = false`, `headerName` ostáva `X-CSRF-TOKEN` [VERIFIED: appstarter Security.php v4.7.5].
- **Prečo `redirect = false` a vlastný filter:** `CodeIgniter\Filters\CSRF` pri `shouldRedirect()` (v `production` je default **true**) a `! $request->isAJAX()` urobí `redirect()->back()` namiesto výnimky. `isAJAX()` kontroluje hlavičku `X-Requested-With`, ktorú **htmx 2.0.11 neposiela** (0 výskytov v `htmx.js`) [VERIFIED: grep v `npm pack` obsahu]. Chybný CSRF pri htmx by sa tak v produkcii zmenil na tichý 302 a do fragmentu by sa vložila celá stránka. Navyše `SecurityException` má kód 403 a v testoch (Feature) sa vyhodí ako výnimka, nie odpoveď. Preto vlastný `HtmxCsrf` (alias `csrf` v `Filters::$aliases` ukáže naň), ktorý zachytí `SecurityException` a vráti odpoveď 403 s fragmentom (`HX-Retarget: #app-alert`, `HX-Reswap: innerHTML`) pre htmx, inak 403 stránku.
- **Token:** `<body hx-headers='{"X-CSRF-TOKEN":"<?= csrf_hash() ?>"}'>` a v klasických formulároch `csrf_field()`. `hx-headers` je dátový atribút, CSP ho nerieši; funguje aj pri `allowEval:false` (iba `js:` prefix a `hx-on` sa vypnú) [CITED: htmx.org/docs]. `hx-boost` neaktualizuje `<body>` atribúty, preto token na prvku, ktorý sa vymieňa (alebo nepoužívať boost vo Phase 1).
- **422:** kontrolér `if (! $this->validate($rules)) return $this->response->setStatusCode(422)->setBody(view('fragments/demo_form', ['errors' => $this->validator->getErrors()]));` — správy cez `lang()`/`codeigniter4/translations`.
- **htmx konfigurácia v `<meta name="htmx-config">`** (nie inline skript): pole `responseHandling` sa **nahrádza celé** (nedopĺňa), preto uviesť všetky položky vrátane predvolených [CITED: htmx.org/docs; zdroj `htmx.js` ř. 264]:
```html
<meta name="htmx-config" content='{"allowEval":false,"allowScriptTags":false,"includeIndicatorStyles":false,"historyCacheSize":0,"responseHandling":[{"code":"204","swap":false},{"code":"[23]..","swap":true},{"code":"422","swap":true,"error":false},{"code":"403","swap":true,"error":true},{"code":"[45]..","swap":false,"error":true},{"code":"...","swap":false}]}'>
```
- **`Vary` a história:** filter `HtmxVary` (after) pridá `Vary: HX-Request` cez `appendHeader`. Fragment sa vracia len ak `HX-Request: true` a zároveň **nie** `HX-Boosted` a **nie** `HX-History-Restore-Request` (htmx 2 posiela pri obnove histórie `HX-Request: true` aj `HX-History-Restore-Request: true`, pokiaľ `historyRestoreAsHxRequest` zostáva `true` — default v 2.0.11 je `true`) [VERIFIED: htmx.js ř. 281, 3368]. `historyCacheSize:0` kvôli citlivým stránkam.
- **Chybové stavy:** `htmx:responseError` / `htmx:sendError` handler v `public/assets/js/app.js` zobrazí viditeľnú správu (text z `data-msg-*` atribútu naplneného cez `lang()`), inak používateľ nevidí, že akcia zlyhala. Úspešný POST: `HX-Redirect` namiesto 302.
- **Debug Toolbar vypnúť** (odobrať `toolbar` z `Filters::$required['after']`): vkladá inline skripty/štýly a pri CSP z nginx by ho prehliadač blokoval.

### Pattern 6: CSP a hlavičky iba v nginx
`snippets/security-headers.conf` s `add_header … always` (nginx `add_header` v `location` bloku **prepíše** zdedené hlavičky — preto `include` v každom `location`, ktorý niečo pridáva):
```nginx
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'none'" always;
add_header Strict-Transport-Security "max-age=300" always;   # dev; prod 31536000; includeSubDomains
add_header X-Content-Type-Options nosniff always;
add_header Referrer-Policy strict-origin-when-cross-origin always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
```
CI4 `App::$CSPEnabled = false`, filter `secureheaders` sa nepoužije (aby neboli duplicity). `img-src data:` je nutné kvôli SVG data URI v Bootstrap CSS. Žiadne `style=""`, `on*=`, `<script>` bez `src`; indikátor htmx štýlom v `app.css` (`.htmx-indicator{opacity:0}.htmx-request .htmx-indicator{opacity:1}`). Progress cez `<progress>` alebo triedy, nie `style="width:…"`. Manipulácia `element.style.x` cez CSSOM (Alpine `x-show`, Bootstrap JS) nie je blokovaná `style-src` [ASSUMED, overiť v prehliadači v checkpointe]. Alpine CSP build: logika v `Alpine.data('name', () => ({…}))` v **externom** súbore, `x-data="name"`, bez šípkových funkcií a priradení do vnorených vlastností [CITED: alpinejs.dev/advanced/csp].

### Pattern 7: Dev TLS (nginx + mkcert)
nginx 1.30: `listen 443 ssl; http2 on;` (parameter `http2` na `listen` je zastaraný od 1.25.1 [ASSUMED]), `ssl_certificate /etc/nginx/certs/work-tracker.loc.pem; ssl_certificate_key …-key.pem;`, server na `:80` vracia `301` na HTTPS. PHP dostane `fastcgi_param HTTPS on` (cez `$https`), aby `forceGlobalSecureRequests`, `secure` cookies a `base_url()` boli správne. Nasadenie na produkcii vymení len certifikáty (D-04).
**mkcert pre Windows aj WSL (D-02/D-03), postup pre `make certs` a README:**
1. Windows (PowerShell): `winget install FiloSottile.mkcert`, potom `mkcert -install` (CA do Windows store → Chrome/Edge). Firefox na Windows treba ručne [CITED: github.com/FiloSottile/mkcert; search výsledok].
2. Certifikát z WSL cez interop: `mkcert.exe -cert-file "$(wslpath -w docker/certs/work-tracker.loc.pem)" -key-file "$(wslpath -w docker/certs/work-tracker.loc-key.pem)" work-tracker.loc localhost 127.0.0.1` [ASSUMED: interop volá .exe s Windows cestou].
3. WSL trust: `CAROOT=$(mkcert.exe -CAROOT | tr -d '\r'); sudo cp "$(wslpath -u "$CAROOT")/rootCA.pem" /usr/local/share/ca-certificates/mkcert-rootCA.crt && sudo update-ca-certificates` (Ubuntu používa `update-ca-certificates` [CITED: mkcert README]).
4. **Názov domény vo WSL:** `/etc/hosts` vo WSL **nie je** Windows `hosts`. Treba `127.0.0.1 work-tracker.loc` aj v `/etc/hosts` WSL (a `[network] generateHosts=false` v `/etc/wsl.conf` pre trvalosť, vyžaduje `wsl --shutdown`), inak `curl https://work-tracker.loc` zo WSL zlyhá na DNS, nie na certifikáte [ASSUMED: štandardné správanie WSL2]. `make certs` to má vypísať/overiť.
5. `docker/certs/` a `*.pem`/`*-key.pem` do `.gitignore` (repo je **verejný**; súkromný kľúč CA ani leaf nesmie do gitu).

### Pattern 8: PHPStan pravidlo (D-06)
Vlastná trieda `Rule<Node>` (PHPStan 2.x: `RuleErrorBuilder::message()->identifier('app.forbidden…')->build()`), registrovaná cez `services:` s tagom `phpstan.rules.rule`; zakáže `FuncCall` na `date|time|gmdate|mktime|strtotime|date_create|microtime`, `New_` s `DateTime|DateTimeImmutable|Time` a `Cast\Double` / `floatval` / `round` / `number_format` na peňažných cestách; výnimka pre `app/Libraries/Platform/Clock.php` a `Money.php` cez `excludePaths`/`ignoreErrors` s `path`. Samotný test pravidla: fixture súbor mimo bežnej analýzy + skript, ktorý očakáva nenulový exit [ASSUMED, voliteľné]. `phpstan.neon.dist`: `level: 6`, `tmpDir: build/phpstan`, `bootstrapFiles: vendor/codeigniter4/framework/system/Test/bootstrap.php`, `scanDirectories` pre CI4 helpery (vzor z Shield `phpstan.neon.dist`).

### Pattern 9: Compose/Dockerfile a WSL2
- `Dockerfile` multi-stage: `base` (php:8.5-fpm-alpine + `install-php-extensions intl pdo_pgsql pgsql gd bcmath zip` + `php.ini`/`opcache.ini`; `opcache` nie je treba inštalovať, v 8.5 je vždy zostavený [CITED: CLAUDE.md STACK]; pri chybe IPE s `opcache` vynechať), `dev` (+ composer z `composer:2`, `poppler-utils`, voliteľne Xdebug, `validate_timestamps=1`), `prod` (`composer install --no-dev --classmap-authoritative`, `COPY`, preload). Rovnaká `base` pre oba (NFR-07).
- Dev bind mount `.:/var/www/html` + named volumes `vendor:/var/www/html/vendor` a `writable:/var/www/html/writable`. Entrypoint: `mkdir -p writable/{cache,logs,session,uploads,debugbar,mpdf}`, `chown -R www-data`, a ak chýba `vendor/autoload.php` → `composer install` (nespoliehať na copy-up pod bind mountom [ASSUMED]). Named volumes vytvoria prázdne adresáre `vendor/` a `writable/` aj na hoste (ignorované gitom).
- PG: volume mountovať na `/var/lib/postgresql` (nie `/data`) [CITED: hub.docker.com/_/postgres]; `PGDATA` default v 18 je `/var/lib/postgresql/18/docker`. Heslá cez `.env`. Healthcheck `pg_isready`, `php` `depends_on: db: condition: service_healthy`.
- Pamäť a ladenie PG cez `command: ["postgres","-c","shared_buffers=64MB","-c","work_mem=4MB","-c","maintenance_work_mem=32MB","-c","effective_cache_size=192MB","-c","max_connections=20","-c","timezone=Europe/Bratislava","-c","log_timezone=Europe/Bratislava"]` (hodnoty z PITFALLS č. 14, rovnaké v dev aj prod).
- `.env` je jediný súbor pre CI4 (`DotEnv`) aj pre `docker compose` interpolation; overené: Compose v5.5.0 správne spracuje `.env` s bodkovanými kľúčmi a medzerami okolo `=` (`docker-compose config` vrátilo hodnotu `DB_OWNER_PASSWORD`) [VERIFIED: lokálny test]. PHP-FPM čistí prostredie (`clear_env=yes`), takže premenné z `environment:` kontajnera sa do CI4 nedostanú; používať `.env` súbor, nie `environment:`.
- `.gitattributes`: `* text=auto eol=lf`, `*.sh text eol=lf`, `Makefile text eol=lf`, `*.sql text eol=lf`, binárne `*.ttf *.png *.pdf binary`; `.editorconfig` s LF. Tabuľátory v Makefile musia ostať.

### Anti-Patterns to Avoid
- **`DatabaseTestTrait` s `$refresh/$migrate = true`** pod `app_rw` (pozri Pattern 2).
- **Dvojitá CSP** (nginx aj CI4) alebo CSP s `'unsafe-inline'`/`'unsafe-eval'`; nepoužívať nonce (HTML sa nesmie cachovať, a nonce nepotrebujeme, nemáme inline skripty).
- **`float`, `number_format`, `round()` na sumách**, `array_sum` na peňažných reťazcoch (potichu na float).
- **`DROP SCHEMA public CASCADE`** v `make reset` (stratia sa default privileges).
- **Zdieľanie `.env` heslá v `compose.yaml`** a commit `.env`/certifikátov (verejný repo).
- **`SEQUENCE` pre čísla dokladov** (Phase 4; mimo rozsahu, ale `provision.sql` nesmie nič takého zavádzať).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Zaokrúhľovanie half-up | `floor(x*100+0.5)/100` | `bcround(..., 2)` (default `HalfAwayFromZero`) | float chyby, nesymetria pre záporné |
| Systémové a validačné hlášky v SK/PL/CZ | vlastné preklady `Validation.php` | `codeigniter4/translations` (sk/cs/pl) | oficiálne, údržba s verziou frameworku |
| Kontrola CSRF tokenu | vlastné porovnanie/generovanie | `service('security')->verify()` vo vlastnom filtri | časovo bezpečné `hash_equals`, maskovanie |
| Lokálny CA a certifikát | `openssl` samopodpisaný cert | mkcert | dôveryhodnosť v prehliadači a WSL |
| Migračný bežec / sedenie skupín | vlastný skript | `spark migrate -g owner` | podporované frameworkom |
| Šablóna PHP-FPM + extensions | ručné `docker-php-ext-configure` | `install-php-extensions` | rieši závislosti pre alpine |
| Preklady kľúčov so skenom | vlastný parser PHP | jednoduchý PHPUnit test s regexom `lang\('([A-Za-z]+\.[A-Za-z0-9_.]+)'` + existencia kľúča v `Language/sk` | stačí malý test |
| PHPStan zákazy | grep v CI | vlastné PHPStan pravidlo (≈40 riadkov) | rozumie AST, nechytá komentáre |

**Key insight:** Vo Phase 1 nič nepíšeme od nuly okrem `Money`, `Clock`, dvoch filtrov, jedného PHPStan pravidla a provisioning skriptu; všetko ostatné je konfigurácia overených súčastí.

## Common Pitfalls

### Pasca 1: htmx CSRF zlyhá potichu v produkcii (302 namiesto 403)
**What goes wrong:** Po zmene na `production` CI4 `Security::$redirect` default `true`; keďže htmx neposiela `X-Requested-With`, `isAJAX()` je false, filter urobí `redirect()->back()`, XHR ho nasleduje a do fragmentu sa vloží celá stránka.
**Why it happens:** htmx 2 odstránil `X-Requested-With` (v 2.0.11 sa v `htmx.js` nevyskytuje).
**How to avoid:** vlastný `HtmxCsrf` filter + `redirect=false`; test „bez tokenu = 403 aj s `HX-Request`“ s `CI_ENVIRONMENT=production` režimom (nastaviť `Security::$redirect` explicitne, nie podľa `ENVIRONMENT`).
**Warning signs:** po odoslaní formulára bez tokenu sa v cieli zobrazí layout v layoute.

### Pasca 2: `responseHandling` sa prepisuje celé
**What goes wrong:** pridaný len `{"code":"422","swap":true}` zruší predvolené položky (napr. `204` bez swapu, `[23]..` swap) a nič sa neswapuje.
**How to avoid:** uviesť kompletné pole (pozri Pattern 5) a mať test, ktorý parsuje meta JSON a očakáva položky `204`, `[23]..`, `422`, `[45]..`.

### Pasca 3: bcmath bez scale
**What goes wrong:** `bcmul('1.25','27.00')` vráti `33` (scale 0), súčty sa rozídu.
**How to avoid:** `Money` je jediné miesto s `bc*`, vždy s scale; PHPStan pravidlo na priame `bc*` volania mimo `Platform/` (voliteľné); testy s ties-hodnotami (`0.125`, `1.005`, záporné).

### Pasca 4: PG default privileges sa nevzťahujú na existujúce tabuľky a sú per-DB
**What goes wrong:** po `make reset` alebo v novej DB (`task_tracker_test`) `app_rw` nič nevidí, alebo (opačne) dostane viac než treba.
**How to avoid:** provisioning pre každú DB rovnakým skriptom; test `DbRolesTest` vytvorí tabuľku ako `tests_owner` a overí `SELECT/INSERT` OK, `UPDATE/DELETE/TRUNCATE/DDL` → SQLSTATE `42501`.

### Pasca 5: Docker/WSL predpoklady chýbajú
**What goes wrong:** `docker` v WSL nie je dostupný (Docker Desktop WSL integration vypnutá, démon nebeží), `make` nie je nainštalovaný, `work-tracker.loc` neexistuje vo WSL `/etc/hosts`.
**How to avoid:** Wave 0 predpoklady ako checkpoint (pozri Environment Availability); `make doctor` cieľ kontroluje `docker info`, `make --version`, `mkcert.exe`, záznamy hosts, CA v trust store.

### Pasca 6: CRLF a práva v skriptoch
**What goes wrong:** `\r: command not found` v `entrypoint.sh`/`init/*.sh`/Makefile.
**How to avoid:** `.gitattributes` s LF pred prvým commitom skriptov, `.editorconfig`. Súbory z 9p sú v kontajneri vždy exekvovateľné; nepoužívať `chmod`-závislé veci.

### Pasca 7: Debug Toolbar a CSP
**What goes wrong:** toolbar vo vývoji vkladá inline kód; prehliadač ho blokuje a v konzole je šum, ktorý zakryje skutočné CSP porušenia.
**How to avoid:** odobrať `toolbar` z `Filters::$required`, test `ViewsCspTest`.

### Pasca 8: appstarter `.gitignore`/`writable` a prázdny `writable` volume
**What goes wrong:** `writable/` v named volume je prázdny → CI4 nemôže písať logy/session/cache (`writable/session` chýba).
**How to avoid:** entrypoint vytvorí adresáre a nastaví vlastníka; `.gitignore` `/writable/` ostáva.

### Pasca 9: Validačné hlášky po anglicky
**What goes wrong:** 422 fragment zobrazí „The name field is required“, pričom UI je SK. `lang()` kľúče `Validation.*` neexistujú v `app/Language/sk`.
**How to avoid:** `codeigniter4/translations` + test, že v SK locale validačná chyba nie je anglická.

### Pasca 10: Číselné hodnoty z DB ako float
**What goes wrong:** pri zmene driveru alebo `numberNative` môže `numeric` prísť ako float.
**How to avoid:** CI4 Postgre ovládač používa natívne `pg_*` (ext-pgsql); integračný test `assertSame('33.75', $row->amount)` na `numeric(12,2)` a `assertIsString`.

### Pasca 11: mPDF a E_ALL / fonty
**What goes wrong:** bez `default_font` mPDF použije core font (helvetica), ktorý nemá ł/ń/ě/ť; `tempDir` v `vendor/`; vendor deprecations pod PHP 8.5.
**How to avoid:** `'default_font' => 'dejavusans'`, `tempDir => writable/mpdf`, `useSubstitutions=false`, `autoScriptToLang=false`, `autoLangToFont=false`; spike beží ako samostatný skript s vlastným error handlerom, ktorý **zbiera** deprecations (neprepadne na prvej) a zapíše počet do výsledku.

## Code Examples

### nginx server (dev, po `include` snippetov)
```nginx
# Source: nginx docs + CLAUDE.md "nginx"; vzor, finalizuje plán
server { listen 80; server_name work-tracker.loc; return 301 https://$host$request_uri; }
server {
  listen 443 ssl; http2 on;
  server_name work-tracker.loc;
  ssl_certificate     /etc/nginx/certs/work-tracker.loc.pem;
  ssl_certificate_key /etc/nginx/certs/work-tracker.loc-key.pem;
  root /var/www/html/public; index index.php;
  gzip on; gzip_types text/css application/javascript image/svg+xml application/json;
  client_max_body_size 5m;
  include snippets/security-headers.conf;
  location ~ ^/assets/vendor/ { include snippets/security-headers.conf; add_header Cache-Control "public, max-age=31536000, immutable" always; try_files $uri =404; }
  location / { try_files $uri /index.php$is_args$args; }
  location = /index.php {
    include snippets/security-headers.conf; add_header Cache-Control "no-store" always;
    fastcgi_pass php:9000; fastcgi_read_timeout 30s;
    include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_param HTTPS on;
  }
  location ~ \.php$ { return 404; }
}
```

### Filtre
```php
<?php // app/Filters/HtmxCsrf.php  (Source: vychádza z CodeIgniter\Filters\CSRF v4.7.5)
declare(strict_types=1);
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\{RequestInterface, ResponseInterface};
use CodeIgniter\Security\Exceptions\SecurityException;

final class HtmxCsrf implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            service('security')->verify($request);
        } catch (SecurityException) {
            helper('htmx');
            $res = service('response')->setStatusCode(403);
            if ($request->hasHeader('HX-Request')) {
                return $res->setHeader('HX-Retarget', '#app-alert')->setHeader('HX-Reswap', 'innerHTML')
                    ->setBody(view('fragments/alert', ['message' => lang('Security.csrfFailed')]));
            }
            return $res->setBody(view('errors/403_csrf'));
        }
        return null;
    }
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
// HtmxVary::after(): $response->appendHeader('Vary', 'HX-Request');
```

### Layout kostra (iba relevantné časti)
```php
<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="htmx-config" content='…(pozri Pattern 5)…'>
  <title><?= esc(lang('App.title')) ?></title>
  <link rel="stylesheet" href="<?= asset_url('vendor/bootstrap-5.3.8.min.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
  <script defer src="<?= asset_url('vendor/htmx-2.0.11.min.js') ?>"></script>
  <script defer src="<?= asset_url('js/app.js') ?>"></script>             <!-- Alpine.data(...) registrácie, musí byť pred Alpine -->
  <script defer src="<?= asset_url('vendor/alpine-csp-3.17.5.min.js') ?>"></script>
</head>
<body hx-headers='{"X-CSRF-TOKEN":"<?= csrf_hash() ?>"}'>
  <header class="app-header">…</header>
  <main id="content" class="container-fluid"><div id="app-alert" role="alert"></div><?= $this->renderSection('content') ?></main>
  <nav class="app-tabbar" aria-label="…"><!-- miesto pre spodnú lištu --></nav>
</body></html>
```
`app.css` minimum: `body{background:#f5f6f8;font-family:-apple-system,system-ui,sans-serif;min-height:100dvh}`, `.btn,.form-control,.form-select,.app-tabbar a{min-height:44px}`, `input,select,textarea{font-size:16px}` (iOS inak zoomuje), `padding-bottom:env(safe-area-inset-bottom)`, `overflow-x:hidden` len ako poistka (nie náhrada za správny layout).

### mPDF spike (konfigurácia)
```php
// Source: mPDF docs (konfiguračné kľúče); orientačný beh na PHP 8.3: 40 riadkov, cold, 209 ms, peak 22 MB
$mpdf = new \Mpdf\Mpdf([
  'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => WRITEPATH . 'mpdf',
  'default_font' => 'dejavusans', 'useSubstitutions' => false,
  'autoScriptToLang' => false, 'autoLangToFont' => false,
]);
```
Fonty DejaVu sú súčasťou `vendor/mpdf/mpdf/ttfonts/` (DejaVuSans, Bold, Oblique, Condensed…) [VERIFIED: obsah balíka]; „pripnutý font vložený do repa“ znamená skopírovať potrebné `DejaVuSans*.ttf` do `resources/fonts/` a nastaviť `fontDir` + `fontdata`, aby výsledok nezávisel od verzie balíka. Spike meria: čas cold (prázdny `tempDir`) a warm, `memory_get_peak_usage(true)`, počet deprecations, prítomnosť „łńěťžô“ v `pdftotext` výstupe, a rovnaký beh pod FPM requestom (cez `/spike/pdf` len v `development`).

### GitHub Actions (kostra)
```yaml
# Source: GitHub Actions docs; verzie akcií k 2026-10-23 (actions/checkout v7.0.1)
name: ci
on: { push: {}, pull_request: {} }
jobs:
  check:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18-alpine
        env: { POSTGRES_PASSWORD: ci-superuser }
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U postgres" --health-interval 5s --health-timeout 5s --health-retries 10
    steps:
      - uses: actions/checkout@v7
      - run: docker build --target dev -t tt-php:ci .
      - run: docker build --target prod -t tt-php:prod .
      - name: Provision roles + DBs
        run: |
          for db in task_tracker task_tracker_test; do
            PGPASSWORD=ci-superuser psql -h localhost -U postgres -v ON_ERROR_STOP=1 \
              -v owner_pw=ci-owner -v rw_pw=ci-rw -v dbname=$db -f docker/postgres/provision.sql
          done
      - name: composer check (stan, cs --dry-run, phpunit)
        run: docker run --rm --network host -v "$PWD:/app" -w /app -e CI_ENVIRONMENT=testing tt-php:ci sh -c 'composer install --no-interaction && composer check'
      - name: preload nezhodí PHP
        run: docker run --rm tt-php:prod php -d opcache.enable_cli=1 -d opcache.preload=/var/www/html/preload.php -d opcache.preload_user=www-data -r 'echo 1;'
```
`composer.json` `scripts`: `test`, `stan`, `cs` (`php-cs-fixer fix --dry-run --diff`), `cs-fix`, `check`; Makefile len volá `docker compose exec php composer <script>` (jeden zdroj pravdy pre lokál aj CI; D-01/D-05/D-06). CI hostiteľ `localhost` z kontajnera s `--network host` ukazuje na service container.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| htmx 1 posielal `X-Requested-With` | htmx 2 ho neposiela | htmx 2.0 | CI4 `isAJAX()` nefunguje pre htmx; nepoužívať `$request->isAJAX()` ako detekciu |
| htmx predvolene neswapuje 4xx/5xx a `htmx.config.responseHandling` | konfigurovateľné pole `code/swap/error` | htmx 2.0 | 422 sa zobrazí len po úprave |
| `listen 443 ssl http2;` | `listen 443 ssl; http2 on;` | nginx 1.25.1 | použiť novú syntax |
| PG data dir `/var/lib/postgresql/data` | `/var/lib/postgresql/18/docker`, VOLUME `/var/lib/postgresql` | PG 18 image | mount na `/var/lib/postgresql` |
| PHP `round()` na float | `bcround()` s `RoundingMode` | PHP 8.4 | presné half-up bez floatov |
| appstarter `phpunit.xml.dist` | `phpunit.dist.xml` | CI4 4.7 | správny názov súboru; `<source>` namiesto `<coverage><include>` |

**Deprecated/outdated:** CI4 `Config\Security::$redirect` default `ENVIRONMENT === 'production'` pre AJAX; htmx `hx-on` s `allowEval:false`; Alpine štandardný build pod prísnou CSP.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `codeigniter4/translations` sa načíta automaticky (Composer auto-discovery `Language/`) | Standard Stack | Validácie zostanú anglicky; riešenie: skopírovať `sk/Validation.php` do `app/Language/sk/` |
| A2 | mkcert.exe sa dá volať z WSL interopom s `wslpath -w` cestami | Pattern 7 | Treba generovať cert v PowerShelli a skopírovať |
| A3 | `/etc/hosts` vo WSL nededí Windows `hosts` | Pattern 7 | Krok navyše zbytočný (neškodí) |
| A4 | `element.style.*` (CSSOM) nie je blokované `style-src 'self'` (Alpine `x-show`, Bootstrap JS) | Pattern 6 | `x-show` nefunguje; použiť `hidden`/triedy |
| A5 | `bcround` na PHP 8.5 nevráti `-0.00` pre malé záporné; normalizácia `norm()` poistí | Pattern 3 | `-0.00` v súčtoch; test to odhalí |
| A6 | Pri bind mount `.` + named volume `vendor`/`writable` copy-up z obrazu nefunguje, preto entrypoint | Pattern 9 | Zbytočný krok |
| A7 | PG alpine obraz je zostavený s ICU a `--icu-locale=sk-SK` (voliteľné) funguje | Open Questions | Kolácia ostane C/ binárna; zoradenie diakritiky v neskorších fázach treba riešiť inak |
| A8 | `pdftotext` správne dekóduje subset font z mPDF (ToUnicode) | mPDF spike | Diakritiku treba overiť vizuálne |
| A9 | Session `FileHandler` vo Phase 1 stačí a CSRF `session` mód nepotrebuje DB | Pattern 5 | Prechod na `DatabaseHandler` pri Shield (Phase 2) |
| A10 | `HX-Retarget` + `HX-Reswap` sa uplatní aj pri 403 s `swap:true` | Pattern 5 / Filter | Fragment pristane v `#content`; dá sa vyriešiť `hx-target-403` alebo JS handlerom |
| A11 | Všetky Composer balíky v audit tabuľke sú legitímne (slopcheck nebehol) | Package Legitimacy Audit | Kompenzované jedným `checkpoint:human-verify` |

## Open Questions (RESOLVED)

1. **ICU kolácia pre zoradenie slovenčiny/poľštiny**
   - Čo vieme: DB sa zakladá vo Phase 1; zmena predvolenej kolácie neskôr znamená dump/reindex. Alpine obraz používa musl (libc kolácie sú v praxi bajtové).
   - Čo nie je jasné: či `postgres:18-alpine` je zostavený s ICU (A7) a aké parametre `initdb`/`CREATE DATABASE` budú fungovať.
   - Odporúčanie: Wave 0 mini-spike v kontajneri: `CREATE DATABASE … LOCALE_PROVIDER icu ICU_LOCALE 'sk-SK' LOCALE 'C.UTF-8' TEMPLATE template0` a `SELECT 'Č' > 'C', 'Č' < 'D'`. Ak prejde, použiť pre obe DB; inak zdokumentovať a používať `COLLATE "sk-x-icu"` v dotazoch.
   - **RESOLVED:** plán 01-05 Task 1 spustí ICU mini-spike v izolovanom compose projekte `tt-probe` a podľa výsledku zvolí lokalizáciu v `provision.sql`; plán 01-10 Task 3 zapíše výsledok do PROJECT.md.
2. **Session handler vo Phase 1**
   - Odporúčanie: `FileHandler` na `writable/session` (volume), zhodnotiť v Phase 2 so Shield.
   - **RESOLVED:** plán 01-08 Task 2 ponecháva `FileHandler` na `writable/session` (volume); prechod na `DatabaseHandler` rozhodne Phase 2 so Shield.
3. **Spike mPDF pod PHP 8.5**
   - Nedalo sa spustiť (host PHP 8.3, Docker nebeží). Orientačný beh na 8.3: cold 209 ms, peak 22 MB, 0 deprecations. Výsledok na 8.5 zapísať do PROJECT.md.
   - **RESOLVED:** plán 01-06 (Task 1 CLI, Task 2 FPM + `MpdfSpikeTest`) spike vykoná a zmeria pod PHP 8.5; plán 01-10 Task 3 prenesie čísla do PROJECT.md.
4. **`composer create-project` do neprázdneho repa** — použiť dočasný adresár a kopírovať bez `.git`; Wave 0 rozhodne, či zachovať `preload.php`, `spark`, `public/`, `app/`, `tests/`.
   - **RESOLVED:** plán 01-01 Task 4: `create-project` do `_tmp/starter`, do repa sa kopírujú iba `app/`, `public/`, `tests/`, `spark`, `preload.php`, `phpunit.dist.xml`, `composer.json`, potom `rm -rf _tmp`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker Desktop (Windows) | celé prostredie | ✓ nainštalovaný, **démon nebeží**, v WSL distre `docker` nie je (WSL integration vypnutá) | 29.7.2 (docker.exe), Compose v5.5.0 | žiadny, treba zapnúť Docker Desktop + WSL integration (Settings → Resources → WSL integration) |
| GNU `make` (WSL) | D-01 Makefile | ✗ | — | `sudo apt install make` |
| mkcert | D-02/D-03 | ✗ | winget `FiloSottile.mkcert` 1.4.4 | `winget install FiloSottile.mkcert` (iné správcovia balíkov nie sú) |
| Záznam `work-tracker.loc` vo Windows hosts a WSL `/etc/hosts` | D-02 | ✗ (iné `.loc` záznamy tam sú) | — | pridať ručne (admin) |
| Porty 80, 443, 5432, 8025, 1025 | compose | ✓ voľné (overené `Get-NetTCPConnection`) | — | zmeniť mapovanie |
| git, gh, curl, openssl | repo, CI | ✓ | — | — |
| Node/npm | stiahnutie htmx/Alpine/Bootstrap | ✓ | node + npm | `curl` tarballov z registra |
| PHP/Composer na hoste | — | PHP 8.3.6 (bez bcmath, gd), Composer 2.7.1 | nepoužívať | všetko v kontajneri (`composer:2` obraz) |
| `slopcheck` | legitimita balíkov | ✗ (chýba pip) | — | jeden `checkpoint:human-verify` |
| GitHub remote (verejný repo) | CI | ✓ `voslarA4N/task-tracker`, `visibility=public` | — | — |
| RAM hosta | build | 32 GB | — | — |

**Missing dependencies with no fallback:** Docker démon + WSL integrácia (blokuje všetko vykonanie, vyžaduje človeka).
**Missing dependencies with fallback:** `make`, `mkcert`, hosts záznamy, `slopcheck`.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.x cez CI4 `CIUnitTestCase`/`FeatureTestTrait` (bootstrap `vendor/codeigniter4/framework/system/Test/bootstrap.php`) |
| Config file | `phpunit.dist.xml` (z appstarter; doplniť `failOnDeprecation="true"`, `failOnWarning`, `failOnRisky`, testsuites `unit` a `integration`, `CI_ENVIRONMENT=testing`, DB env pre skupiny `tests`/`tests_owner`) |
| Quick run command | `make test-unit` → `docker compose exec php composer test:unit` (`phpunit --testsuite unit`, bez DB, < 5 s) |
| Full suite command | `make check` → `composer check` = `phpstan analyse` + `php-cs-fixer fix --dry-run --diff` + `make test-db` + `phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| NFR-04 | hodiny × sadzba half-up na 2 des. (`1.25×27.00`, tie `0.125×1.00`, záporné `-0.125`, `-1.005`), `-0.00` normalizácia, odmietnutie `float`/čiarky/3 des. | unit | `vendor/bin/phpunit tests/unit/Platform/MoneyTest.php` | ❌ Wave 0 |
| NFR-04 | súčet = súčet zaokrúhlených riadkov; riadky a súčty sa zhodujú s `SUM(numeric)` z DB | integration | `vendor/bin/phpunit tests/integration/MoneyDbParityTest.php` | ❌ Wave 0 |
| NFR-04 | 2000 náhodných kombinácií `hours×rate` = PG `round(h::numeric*r::numeric,2)`; `numeric` sa z DB vráti ako string | integration | rovnaký súbor | ❌ Wave 0 |
| NFR-04 | žiadne `float`/`date()`/`new DateTime` mimo `Clock`/`Money` | static | `vendor/bin/phpstan analyse` (a self-test fixture) | ❌ Wave 0 |
| NFR-04 | `Clock` zmrazený v testoch, TZ konzistencia PHP/CI4/PG | unit+integration | `phpunit tests/unit/Platform/ClockTest.php tests/integration/TimezoneConsistencyTest.php` | ❌ Wave 0 |
| NFR-07 | `app_rw` nemôže UPDATE/DELETE/TRUNCATE ani DDL; môže SELECT/INSERT; migrácie bežia ako `app_owner` | integration | `phpunit tests/integration/DbRolesTest.php` | ❌ Wave 0 |
| NFR-07 | compose zdravé (`db`, `php`, `nginx`, `mailpit`), HTTPS odpoveď, bezpečnostné hlavičky, Mailpit prijme mail | smoke | `scripts/smoke.sh` (`make smoke`) | ❌ Wave 0 |
| NFR-07 | rovnaký základ obrazu pre dev aj prod: `docker build --target dev` a `--target prod`; preload nezhodí PHP | CI | job `check` v `.github/workflows/ci.yml` | ❌ Wave 0 |
| UI-01 | views neobsahujú inline `<script>` bez `src`, `style=""`, `on*=`; CSP hlavička nemá `unsafe-*` | unit (statický sken) + smoke | `phpunit tests/unit/Security/ViewsCspTest.php`, `scripts/smoke.sh` | ❌ Wave 0 |
| UI-01 | všetky `lang('X.y')` v `app/` existujú v `Language/sk` a `en`; parita kľúčov sk/en | unit | `phpunit tests/unit/I18n/LangKeysTest.php` | ❌ Wave 0 |
| UI-01 | základná stránka má `viewport-fit=cover`, `lang`, Bootstrap CSS, `htmx-config` meta s kompletným `responseHandling` | feature | `phpunit tests/feature/LayoutTest.php` | ❌ Wave 0 |
| UI-01 (SC4) | htmx POST s tokenom → 200; bez tokenu / s nesprávnym → 403 (fragment s `HX-Retarget`); neplatný vstup → 422 s lokalizovanou chybou; odpoveď má `Vary: HX-Request` | feature | `phpunit tests/feature/HtmxCsrfTest.php` | ❌ Wave 0 |
| UI-01 | dotykové ciele ≥ 44 px, bez horizontálneho posúvania na iPhone šírke 375/390 px | manual-only | DevTools device mode + skutočný iPhone cez `https://work-tracker.loc` (CA do iOS treba samostatne; alternatívne Chrome DevTools emulácia) | n/a, justifikácia: žiadny prehliadačový framework (žiadna build pipeline) |
| SC5 | mPDF PDF s „łńěťžô“ pod PHP 8.5 `E_ALL` do 2 s (cold aj warm), pamäť zmeraná | spike + test | `make spike-pdf` (skript `scripts/spikes/mpdf.php`), `phpunit tests/integration/MpdfSpikeTest.php` (limit 2000 ms, `%PDF`, `pdftotext` obsahuje znaky) | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `make test-unit` a `make stan` (do 30 s)
- **Per wave merge:** `make check`
- **Phase gate:** `make check` zelený lokálne aj v CI, `scripts/smoke.sh` zelený, manuálny checkpoint (iPhone/DevTools, CSP konzola bez porušení) pred `/gsd-verify-work`

### Wave 0 Gaps
- [ ] Docker Desktop zapnutý + WSL integrácia, `sudo apt install make`, mkcert + hosts + CA (checkpoint pre človeka)
- [ ] `composer.json` scripts, `phpunit.dist.xml` upravený (suites, `failOnDeprecation`, PG env), `tests/_support/PgTestCase.php`, `tests/unit`, `tests/integration`, `tests/feature`
- [ ] `docker/postgres/provision.sql`, `make test-db`
- [ ] Frontend súbory v `public/assets/vendor/`
- [ ] Spike kolácie (Open Question 1) a overenie `codeigniter4/translations` auto-discovery (A1)

## Security Domain

### Applicable ASVS Categories (úroveň 1, `security_asvs_level: 1`)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (Phase 2) | — |
| V3 Session Management | yes (cookie atribúty, CSRF) | `Config\Cookie` `secure=true`, `httponly=true`, `samesite=Lax`; session cookie cez rovnaké nastavenie; HTTPS aj v dev |
| V4 Access Control | čiastočne | DB roly `app_owner`/`app_rw` (least privilege), `app_rw` bez DDL |
| V5 Input Validation | yes | CI4 Validation + `esc()` na výstupe; striktné `Money::of()` regex |
| V6 Cryptography | nie (TOTP šifrovanie v Phase 2) | — |
| V7 Error Handling/Logging | yes | `display_errors=0` v prod, `logThreshold` primerané, žiadne tajomstvá v logoch |
| V14 Configuration | yes | CSP/HSTS/XCTO/Referrer/Permissions v nginx, `.env` mimo gitu, verejný repo bez certifikátov |

### Known Threat Patterns for {CI4 + htmx + nginx + PG}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| CSRF na htmx POST | Tampering | `HtmxCsrf` + `X-CSRF-TOKEN` v `hx-headers`, session mód |
| XSS (reflexia v 422 fragmente) | Tampering/Info disclosure | `esc()` všade, CSP `script-src 'self'`, `allowScriptTags:false`, `allowEval:false` |
| Zmena/vymazanie nemenných údajov cez chybu/SQLi | Tampering | `app_rw` bez UPDATE/DELETE/TRUNCATE na insert-only tabuľkách, test rolí |
| Únik tajomstiev (`.env`, mkcert kľúče) do verejného repa | Information disclosure | `.gitignore` (`.env`, `docker/certs/`, `*.pem`), kontrola `git ls-files` v CI (voliteľne gitleaks) |
| Downgrade/zachytenie spojenia | Spoofing | HTTPS, HSTS (krátky v dev), `secure` cookies |
| Cachovanie fragmentu ako stránky | Info disclosure | `Vary: HX-Request`, `Cache-Control: no-store` pre HTML |
| SQL injection | Tampering | Query Builder / viazané parametre (žiadne `db->query()` s konkatenáciou) |

## Sources

### Primary (HIGH confidence)
- raw.githubusercontent.com/codeigniter4/appstarter/v4.7.5 (composer.json, `Config/Security.php`, `Filters.php`, `ContentSecurityPolicy.php`, `Database.php`, `Exceptions.php`, `Optimize.php`, `Boot/*.php`, `phpunit.dist.xml`, `preload.php`, `.gitattributes`)
- raw.githubusercontent.com/codeigniter4/CodeIgniter4/v4.7.5 (`system/Security/Security.php`, `Filters/CSRF.php`, `Security/Exceptions/SecurityException.php`, `HTTP/Request*.php` `isAJAX`, `Common.php` `lang()` s parametrom locale, `Boot.php::preload`, `Test/DatabaseTestTrait.php`, `Commands/Database/Migrate.php` `-g`, `I18n/Time.php::setTestNow`)
- php.net/manual/en/function.bcround.php (signature, `RoundingMode::HalfAwayFromZero` default, záporné príklady, `ValueError`)
- htmx.org/docs (`responseHandling`, `htmx-config` meta, `allowEval`, `allowScriptTags`, `includeIndicatorStyles`, `historyCacheSize`, `hx-headers` CSRF vzor, `HX-History-Restore-Request`) + lokálna kontrola `htmx.org@2.0.11` tarballu (`X-Requested-With`: 0 výskytov; `historyRestoreAsHxRequest: true`; `responseHandling` pole)
- alpinejs.dev/advanced/csp (CSP build, nepodporované výrazy, `Alpine.data`)
- hub.docker.com/_/postgres (initdb.d správanie, PG 18 `VOLUME /var/lib/postgresql`, `_FILE` premenné)
- Packagist p2/API (verzie a požiadavky: framework, shield, devkit, phpstan-codeigniter, phpunit, mpdf, translations, coding-standard, cs-config, psalm) a skúšobný `composer update --dry-run` pod `platform php 8.5.12`
- Docker Hub API (php:8.5-fpm-alpine, postgres:18-alpine, nginx:1.30-alpine, axllent/mailpit:v1.32, 2026-10-22); npm registry (`htmx.org` latest 2.0.11, `alpinejs`/`@alpinejs/csp` 3.17.5, `bootstrap` 5.3.8); GitHub releases (mlocati/docker-php-extension-installer 2.12.3, actions/checkout v7.0.1)
- github.com/codeigniter4/devkit README (`minimum-stability dev`), github.com/CodeIgniter/shield `.php-cs-fixer.dist.php`, `phpstan.neon.dist` (vzory), github.com/NexusPHP/cs-config `Factory::forProjects()`
- Lokálne merania: Compose v5.5.0 a `.env` s bodkovanými kľúčmi; mPDF 8.3.1 na PHP 8.3 (orientačne)

### Secondary (MEDIUM confidence)
- github.com/FiloSottile/mkcert README (Windows inštalácia, Linux trust stores, `CAROOT`); WebSearch o WSL + CAROOT/WSLENV (blog, starší mkcert v1.4.2), nie potvrdené oficiálnou WSL sekciou
- `.planning/research/{STACK,ARCHITECTURE,PITFALLS,SUMMARY}.md` a CLAUDE.md (projektový výskum zo 2026-10-23)

### Tertiary (LOW confidence)
- A1 až A11 v Assumptions Log (najmä ICU kolácia v alpine PG, správanie WSL hosts/mkcert interop, CSSOM vs CSP, `HX-Retarget` pri 403)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, verzie a rozlíšenie overené (okrem legitimity balíkov, kde slopcheck nebehol)
- Architecture: MEDIUM-HIGH, CI4 a htmx správanie overené v zdrojoch; Docker/WSL časti neboli spustené
- Pitfalls: HIGH pre CSRF/htmx/bcmath/PG grants; MEDIUM pre WSL/mkcert; LOW pre mPDF na 8.5 (nespustené)

**Research date:** 2026-10-23
**Valid until:** 2026-11-22 (30 dní; htmx/Alpine/CI4 sa vyvíjajú pomaly, ale patch verzie PHP/nginx/PG obrazov sa menia týždenne)
